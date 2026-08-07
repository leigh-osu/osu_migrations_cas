<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\File\FileExists;
use Drupal\Core\Site\Settings;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

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
   * Per-request cache of already-resolved URLs (old => new or NULL to keep).
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
      $new = self::resolve($match[0], $match['dir'], $match['rel']);
      return $new ?? $match[0];
    };
    $patterns = [
      // Quoted src/href values: parentheses, spaces and the *other* quote
      // character are all valid filename characters there, delimited only by
      // the matching closing quote (one pattern per quote type because a
      // lookbehind must be fixed-length).
      '~(?<=")(?:https?://[^/"]+)?/sites/(?<dir>agscid7|agsci|default)/files/(?<rel>[^"?#]+?)(?:[?#][^"]*)?(?=")~i',
      "~(?<=')(?:https?://[^/']+)?/sites/(?<dir>agscid7|agsci|default)/files/(?<rel>[^'?#]+?)(?:[?#][^']*)?(?=')~i",
      // Unquoted CSS url(...) values: parentheses delimit the URL instead.
      '~(?<=\()(?:https?://[^/"\'()]+)?/sites/(?<dir>agscid7|agsci|default)/files/(?<rel>[^"\'()?#\s]+)(?:[?#][^"\'()]*)?(?=\))~i',
    ];
    foreach ($patterns as $pattern) {
      $text = preg_replace_callback($pattern, $callback, $text);
    }
    return $text;
  }

  /**
   * Resolves one legacy URL: ensures the file exists in D10, returns new URL.
   *
   * @return string|null
   *   The rewritten URL, or NULL to leave the original untouched.
   */
  protected static function resolve(string $url, string $site_dir, string $rel): ?string {
    if (array_key_exists($url, self::$resolved)) {
      return self::$resolved[$url];
    }

    // sites/default/files also occurs in absolute links to OTHER OSU sites;
    // only rewrite those when the host is (or was) this site. Relative URLs
    // and the agscid7 directory are unambiguously ours.
    if (strcasecmp($site_dir, 'default') === 0
      && preg_match('~^https?://([^/]+)~i', $url, $host)
      && stripos($host[1], 'agsci') === FALSE) {
      return self::$resolved[$url] = NULL;
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
        if (file_exists('public://' . $candidate) || file_exists(self::d7FilesPath() . '/' . $candidate)) {
          $rel_decoded = $candidate;
          break;
        }
      }
      if ($rel_decoded === NULL) {
        $rel_decoded = self::findByBasename(basename($ic['rest']));
      }
      if ($rel_decoded === NULL) {
        \Drupal::logger('osu_migrations_cas')->warning(
          'Legacy imagecache URL @url: no original found; left as-is.',
          ['@url' => $url]
        );
        return self::$resolved[$url] = NULL;
      }
    }

    $destination = 'public://' . $rel_decoded;

    if (!file_exists($destination)) {
      $d7_files = self::d7FilesPath();
      $source = $d7_files . '/' . $rel_decoded;
      if (!file_exists($source) && strcasecmp($site_dir, 'default') === 0) {
        $source = dirname($d7_files) . '/../default/files/' . $rel_decoded;
      }
      if (!file_exists($source)) {
        \Drupal::logger('osu_migrations_cas')->warning(
          'Legacy file URL @url: file missing in D10 and on the D7 filesystem; left as-is.',
          ['@url' => $url]
        );
        return self::$resolved[$url] = NULL;
      }
      try {
        $file_system = \Drupal::service('file_system');
        $dir = dirname($destination);
        $file_system->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
        $file_system->copy($source, $destination, FileExists::Replace);
      }
      catch (\Exception $e) {
        \Drupal::logger('osu_migrations_cas')->warning(
          'Legacy file URL @url: copy failed (@msg); left as-is.',
          ['@url' => $url, '@msg' => $e->getMessage()]
        );
        return self::$resolved[$url] = NULL;
      }
    }

    // Re-encode the decoded relative path segment by segment so the new URL
    // is valid regardless of how the original was encoded.
    $encoded = implode('/', array_map('rawurlencode', explode('/', $rel_decoded)));
    return self::$resolved[$url] = self::NEW_PREFIX . $encoded;
  }

  /**
   * The D7 files tree path (settings override or the ddev mount default).
   */
  protected static function d7FilesPath(): string {
    return rtrim(Settings::get(
      'cas_migrate_d7_files_path',
      '/var/www/d7/sites/agscid7/files'
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
    static $index = NULL;
    if ($index === NULL) {
      $index = [];
      $root = self::d7FilesPath();
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
    if (count($normalized) !== 1) {
      return NULL;
    }
    $result = reset($normalized);
    // The collapse may point at main/ when only the mainsite/ twin exists.
    if (!file_exists(self::d7FilesPath() . '/' . $result) && !file_exists('public://' . $result)) {
      $twin = preg_replace('~^main/~', 'mainsite/', $result);
      if (file_exists(self::d7FilesPath() . '/' . $twin)) {
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
