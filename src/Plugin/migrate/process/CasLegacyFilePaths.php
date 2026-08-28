<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\File\FileExists;
use Drupal\Core\Site\Settings;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\osu_migrations_cas\CasFileRelocation;

/**
 * Repairs hardcoded D7 file URLs in rich text, copying unmanaged files.
 *
 * D7 editors hardcoded src/href URLs like /sites/agscid7/files/<rel> into
 * markup. Managed files were migrated by upgrade_d7_files and exist in D10
 * under the same relative path, so those URLs only need the site-directory
 * rename. Unmanaged files (on the D7 filesystem but in no database table)
 * are copied on demand from the D7 files mount (see
 * .ddev/docker-compose.d7files.yaml) before the URL is rewritten to
 * /sites/agsci.oregonstate.edu/files/<rel>. References whose file exists in
 * neither filesystem were dead on D7 too and are left untouched (logged).
 *
 * The plugin is inserted after every osu_media_wysiwyg_filter step by
 * osu_migrations_cas_migration_plugins_alter(), and rewriteText() is called
 * directly by the CAS plugins that transform rich text internally.
 *
 * Link fields hold a bare URL rather than markup, so they go through
 * rewriteUrl() instead — see the cas_legacy_file_url plugin.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_legacy_file_paths"
 * )
 */
class CasLegacyFilePaths extends ProcessPluginBase {

  /**
   * D10 public files URL prefix that replaces the legacy prefixes.
   */
  protected const NEW_PREFIX = '/sites/agsci.oregonstate.edu/files/';

  /**
   * Regex alternation of the D7 site directories this source's editors used.
   *
   * A subclass covering another D7 source (e.g. MMI) overrides this together
   * with the other class constants; every regex and lookup below binds late
   * (static::), so the whole resolution pipeline follows.
   */
  protected const SITE_DIRS = 'agscid7|agsci|default';

  /**
   * Substring identifying this site's own hosts in absolute URLs.
   *
   * sites/default/files URLs are rewritten only when the host contains it.
   */
  protected const HOST_NEEDLE = 'agsci';

  /**
   * Settings key overriding the D7 files tree path.
   */
  protected const D7_FILES_SETTING = 'cas_migrate_d7_files_path';

  /**
   * Default D7 files tree path (the ddev mount).
   */
  protected const D7_FILES_DEFAULT = '/var/www/d7/sites/agscid7/files';

  /**
   * Logger channel for unresolved-reference warnings.
   */
  protected const LOGGER_CHANNEL = 'osu_migrations_cas';

  /**
   * Per-request cache of already-resolved URLs (old => new or NULL to keep),
   * keyed by concrete class so sibling subclasses never share entries.
   */
  protected static array $resolved = [];

  /**
   * Rewrites legacy D7 file URLs in an HTML string.
   */
  public static function rewriteText(?string $text): ?string {
    if ($text === NULL || $text === '' || strpos($text, '/files/') === FALSE) {
      return $text;
    }
    $callback = function (array $match): string {
      $new = static::resolve($match[0], $match['dir'], $match['rel']);
      return $new ?? $match[0];
    };
    $dirs = static::SITE_DIRS;
    $patterns = [
      // Quoted src/href values: parentheses, spaces and the *other* quote
      // character are all valid filename characters there, delimited only by
      // the matching closing quote (one pattern per quote type because a
      // lookbehind must be fixed-length).
      '~(?<=")(?:https?://[^/"]+)?/sites/(?<dir>' . $dirs . ')/files/(?<rel>[^"?#]+?)(?:[?#][^"]*)?(?=")~i',
      "~(?<=')(?:https?://[^/']+)?/sites/(?<dir>" . $dirs . ")/files/(?<rel>[^'?#]+?)(?:[?#][^']*)?(?=')~i",
      // Unquoted CSS url(...) values: parentheses delimit the URL instead.
      '~(?<=\()(?:https?://[^/"\'()]+)?/sites/(?<dir>' . $dirs . ')/files/(?<rel>[^"\'()?#\s]+)(?:[?#][^"\'()]*)?(?=\))~i',
    ];
    foreach ($patterns as $pattern) {
      $text = preg_replace_callback($pattern, $callback, $text);
    }
    return $text;
  }

  /**
   * Rewrites a legacy D7 file URL held as a whole link-field value.
   *
   * Unlike rewriteText(), which finds URLs delimited by quotes or parentheses
   * inside markup, this matches the entire string: a link field stores the
   * bare URL, optionally behind a Drupal URI scheme (internal:/…, base:…).
   * The scheme prefix, the host and any query/fragment are preserved.
   *
   * @return string|null
   *   The rewritten URL, or the original when it is not a legacy file URL or
   *   the file could not be located.
   */
  public static function rewriteUrl(?string $url): ?string {
    if ($url === NULL || $url === '' || stripos($url, '/files/') === FALSE) {
      return $url;
    }
    $matched = preg_match(
      '~^(?<scheme>internal:|base:)?(?<host>https?://[^/]+)?/sites/(?<dir>' . static::SITE_DIRS . ')/files/(?<rel>[^?#]+)(?<suffix>[?#].*)?$~i',
      $url,
      $m
    );
    if (!$matched) {
      return $url;
    }
    // resolve() keys its cache — and its host check for the shared
    // sites/default directory — on the plain URL, so hand it the value with
    // the Drupal scheme prefix removed.
    $new = static::resolve(
      $m['host'] . '/sites/' . $m['dir'] . '/files/' . $m['rel'],
      $m['dir'],
      $m['rel']
    );
    return $new === NULL ? $url : $m['scheme'] . $m['host'] . $new . ($m['suffix'] ?? '');
  }

  /**
   * Resolves one legacy URL: ensures the file exists in D10, returns new URL.
   *
   * @return string|null
   *   The rewritten URL, or NULL to leave the original untouched.
   */
  protected static function resolve(string $url, string $site_dir, string $rel): ?string {
    $cache = &self::$resolved[static::class];
    if (isset($cache) && array_key_exists($url, $cache)) {
      return $cache[$url];
    }

    // sites/default/files also occurs in absolute links to OTHER OSU sites;
    // only rewrite those when the host is (or was) this site. Relative URLs
    // and the site's own directories are unambiguously ours.
    if (strcasecmp($site_dir, 'default') === 0
      && preg_match('~^https?://([^/]+)~i', $url, $host)
      && stripos($host[1], static::HOST_NEEDLE) === FALSE) {
      return $cache[$url] = NULL;
    }

    $rel_decoded = rawurldecode($rel);

    // D6-era imagecache URLs (…/files/main/imagecache/<preset>/<rest>): the
    // derivative directories no longer exist and many of the originals were
    // reorganised. Try, in order: the path with the imagecache/<preset>/
    // segment stripped (with and without the leading prefix), then a unique
    // basename match anywhere in the D7 files tree (preferring non-styles,
    // non-'mainsite' copies).
    if (preg_match('~^(?<pre>(?:[^/]+/)*?)imagecache/[^/]+/(?<rest>.+)$~', $rel_decoded, $ic)) {
      $candidates = array_unique([$ic['pre'] . $ic['rest'], $ic['rest']]);
      $rel_decoded = NULL;
      foreach ($candidates as $candidate) {
        if (file_exists('public://' . static::relocatedPath($candidate))
          || file_exists(static::d7FilesPath() . '/' . $candidate)) {
          $rel_decoded = $candidate;
          break;
        }
      }
      if ($rel_decoded === NULL) {
        $rel_decoded = static::findByBasename(basename($ic['rest']));
      }
      if ($rel_decoded === NULL) {
        \Drupal::logger(static::LOGGER_CHANNEL)->warning(
          'Legacy imagecache URL @url: no original found; left as-is.',
          ['@url' => $url]
        );
        return $cache[$url] = NULL;
      }
    }

    // Editors' markup often keeps a path from before a D7 reorganisation:
    // node 4774 links /sites/agsci/files/main/aaa/artwork/thumb/<name> for
    // images that actually sit in art/artwork/thumb/. The file is there, just
    // not where the URL says, so a path-only check drops it and
    // strip_dead_legacy_refs.php then removes the reference -- 175 files
    // across 79 pages in the media fidelity audit. Fall back to locating the
    // file by basename, exactly as the imagecache branch above already does.
    // findByBasename() returns NULL unless the match is unique, so an
    // ambiguous name is left alone rather than resolved to the wrong file.
    if (!file_exists(static::d7FilesPath() . '/' . $rel_decoded)) {
      $by_basename = static::findByBasename(basename($rel_decoded));
      if ($by_basename !== NULL) {
        $rel_decoded = $by_basename;
      }
    }

    // Root-level files are relocated into a year subdirectory at migrate time,
    // so the URL has to point at the new location while the D7 source path
    // stays as it was. relocatedPath() is the single source of that mapping.
    $relocated = static::relocatedPath($rel_decoded);
    $destination = 'public://' . $relocated;

    if (!file_exists($destination)) {
      $d7_files = static::d7FilesPath();
      $source = $d7_files . '/' . $rel_decoded;
      if (!file_exists($source) && strcasecmp($site_dir, 'default') === 0) {
        $source = dirname($d7_files) . '/../default/files/' . $rel_decoded;
      }
      if (!file_exists($source)) {
        \Drupal::logger(static::LOGGER_CHANNEL)->warning(
          'Legacy file URL @url: file missing in D10 and on the D7 filesystem; left as-is.',
          ['@url' => $url]
        );
        return $cache[$url] = NULL;
      }
      try {
        $file_system = \Drupal::service('file_system');
        $dir = dirname($destination);
        $file_system->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
        $file_system->copy($source, $destination, FileExists::Replace);
      }
      catch (\Exception $e) {
        \Drupal::logger(static::LOGGER_CHANNEL)->warning(
          'Legacy file URL @url: copy failed (@msg); left as-is.',
          ['@url' => $url, '@msg' => $e->getMessage()]
        );
        return $cache[$url] = NULL;
      }
    }

    // Re-encode the decoded relative path segment by segment so the new URL
    // is valid regardless of how the original was encoded.
    $encoded = implode('/', array_map('rawurlencode', explode('/', $relocated)));
    return $cache[$url] = static::NEW_PREFIX . $encoded;
  }

  /**
   * Maps a D7-relative file path to its D10 public-filesystem location.
   *
   * The agsci migration relocates root-level files into year subdirectories;
   * sources that keep D7 uris verbatim (MMI) override this to the identity.
   */
  protected static function relocatedPath(string $rel): string {
    return CasFileRelocation::relativePath($rel);
  }

  /**
   * The D7 files tree path (settings override or the ddev mount default).
   */
  protected static function d7FilesPath(): string {
    return rtrim(Settings::get(
      static::D7_FILES_SETTING,
      static::D7_FILES_DEFAULT
    ), '/');
  }

  /**
   * Finds a file in the D7 tree by basename; NULL unless unambiguous.
   *
   * Builds a one-time basename index of the tree (styles/ derivative
   * directories excluded). 'main/' and 'mainsite/' are duplicated trees, so
   * a main/ + mainsite/ pair still counts as one match (main/ wins).
   *
   * @return string|null
   *   The relative path of the single match, or NULL when absent/ambiguous.
   */
  protected static function findByBasename(string $basename): ?string {
    static $indexes = [];
    $index = &$indexes[static::class];
    if ($index === NULL) {
      $index = [];
      $root = static::d7FilesPath();
      $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME;
      $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, $flags),
        function ($current, $key, $iterator) {
          return $iterator->hasChildren() ? basename($current) !== 'styles' : TRUE;
        }
      ));
      $prefix_len = strlen($root) + 1;
      foreach ($it as $pathname) {
        $index[basename($pathname)][] = substr($pathname, $prefix_len);
      }
    }
    $matches = $index[$basename] ?? [];
    // mainsite/ mirrors main/: collapse the pair.
    $normalized = array_unique(array_map(
      fn(string $m) => preg_replace('~^mainsite/~', 'main/', $m),
      $matches
    ));
    // Directories were renamed over the years without the old copy being
    // removed, so one basename often resolves to several byte-identical
    // files: Sara-Aikins200.jpg sits in both ambassadors/2009-10/ and
    // ambassadors-agriculture-forestry-and-natural-resources/2009-10/. Of the
    // 155 filenames the media fidelity audit found missing, 94 were blocked
    // by that ambiguity alone. When every candidate is the same size they are
    // the same image and any of them will do; differing sizes stay ambiguous
    // and are left alone rather than guessed at.
    if (count($normalized) > 1) {
      $sizes = [];
      foreach ($normalized as $candidate) {
        $path = static::d7FilesPath() . '/' . $candidate;
        $sizes[] = file_exists($path) ? filesize($path) : NULL;
      }
      $sizes = array_unique($sizes, SORT_REGULAR);
      if (count($sizes) === 1 && reset($sizes) !== NULL) {
        $normalized = [reset($normalized)];
      }
    }
    if (count($normalized) !== 1) {
      return NULL;
    }
    $result = reset($normalized);
    // The collapse may point at main/ when only the mainsite/ twin exists.
    if (!file_exists(static::d7FilesPath() . '/' . $result)
      && !file_exists('public://' . static::relocatedPath($result))) {
      $twin = preg_replace('~^main/~', 'mainsite/', $result);
      if (file_exists(static::d7FilesPath() . '/' . $twin)) {
        return $twin;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (is_string($value)) {
      return static::rewriteText($value);
    }
    if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
      $value['value'] = static::rewriteText($value['value']);
    }
    return $value;
  }

}
