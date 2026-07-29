<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Returns the id of an existing focal_point crop for a file uri, if any.
 *
 * focal_point auto-creates a centered default crop whenever an image
 * file/media entity is saved — which happens en masse during the media
 * migrations, BEFORE cas_focal_points runs. Supplying the existing crop id
 * makes the entity:crop destination update that crop in place instead of
 * stacking a duplicate (Crop::findCrop returns the first match, which would
 * otherwise be the stale default).
 *
 * @MigrateProcessPlugin(
 *   id = "cas_existing_crop_id"
 * )
 */
class CasExistingCropId extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $cid = \Drupal::database()->queryRange(
      'SELECT cid FROM {crop_field_data} WHERE uri = :uri AND type = :type ORDER BY cid ASC',
      0, 1,
      [':uri' => $value, ':type' => 'focal_point']
    )->fetchField();
    return $cid ?: NULL;
  }

}
