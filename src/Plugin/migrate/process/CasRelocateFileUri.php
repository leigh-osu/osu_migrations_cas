<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\osu_migrations_cas\CasFileRelocation;

/**
 * Rewrites a D7 public file URI to its relocated destination.
 *
 * Inserted ahead of upgrade_d7_files' file_copy step by
 * osu_migrations_cas_migration_plugins_alter(), so the file is written to
 * public://<year>/<name> instead of the flat files root. The row already
 * carries the D7 timestamp the year is derived from, so no lookup is needed
 * here.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_relocate_file_uri"
 * )
 */
class CasRelocateFileUri extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_string($value) || $value === '') {
      return $value;
    }
    $timestamp = $row->getSourceProperty('timestamp');
    return CasFileRelocation::uri($value, $timestamp ? (int) $timestamp : NULL);
  }

}
