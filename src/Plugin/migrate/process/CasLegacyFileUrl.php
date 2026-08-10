<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Repairs hardcoded D7 file URLs stored as whole link-field values.
 *
 * The rich-text repair (cas_legacy_file_paths) only sees URLs delimited by
 * quotes or parentheses inside markup, so link fields — where D7 editors
 * pasted /sites/agscid7/files/<rel> straight into the URL box — were left
 * untouched and point at a directory that no longer exists. This plugin runs
 * the same resolution (missing files are copied from the D7 filesystem on
 * demand) over the bare URL, and is appended to every link-field pipeline by
 * osu_migrations_cas_migration_plugins_alter().
 *
 * It accepts whatever shape the pipeline hands it: a bare uri string (an
 * `field/uri` sub-key), a single {uri, title} item, or the multi-value list a
 * sub_process produces.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_legacy_file_url"
 * )
 */
class CasLegacyFileUrl extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return self::rewriteValue($value);
  }

  /**
   * Rewrites the URLs in one pipeline value, whatever shape it has.
   */
  protected static function rewriteValue($value) {
    if (is_string($value)) {
      return CasLegacyFilePaths::rewriteUrl($value);
    }
    if (!is_array($value)) {
      return $value;
    }
    // A single link item: only the URL column is a URL. D7 source rows use
    // 'url', the D10 destination column is 'uri'; either may reach us
    // depending on where the step lands in the pipeline.
    foreach (['uri', 'url'] as $key) {
      if (isset($value[$key]) && is_string($value[$key])) {
        $value[$key] = CasLegacyFilePaths::rewriteUrl($value[$key]);
        return $value;
      }
    }
    // A multi-value list of items; anything else (a keyed structure with no
    // URL column) is left alone.
    if (array_is_list($value)) {
      foreach ($value as $delta => $item) {
        $value[$delta] = self::rewriteValue($item);
      }
    }
    return $value;
  }

}
