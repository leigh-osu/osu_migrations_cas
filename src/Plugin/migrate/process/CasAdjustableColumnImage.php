<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Adjustable Columns serialized data Process plugin.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_adjustable_column_image",
 * )
 */
class CasAdjustableColumnImage extends ProcessPluginBase {

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
      if (isset($row->source)) {
        $image = $row->source;
      }
    return $value;
  }
}
