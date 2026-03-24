<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * Process plugin to add single images to body text.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_paragraph_image"
 * )
 */
class CasParagraphImage extends ProcessPluginBase {

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // $value is the body text from the previous plugin (osu_media_wysiwyg_filter)

    // Get the officer_image data from configuration
    $officer_image = $row->getSourceProperty('temp_paragraph_image');

    if (empty($officer_image)) {
      return $value;
    }

    // Extract the file ID
    $fid = is_array($officer_image) && isset($officer_image[0]['fid']) ? $officer_image[0]['fid'] : NULL;

    if (!$fid) {
      return $value;
    }

    // Look up the migrated media entity
    $media_id = $this->lookupMigratedMedia($fid);

    if (!$media_id) {
      return $value;
    }

    // Build the media embed markup
    $media_embed = '<drupal-media data-entity-type="media" data-entity-uuid="' . $this->getMediaUuid($media_id) . '" data-align="right"></drupal-media>';

    // Prepend to the body value
    return $media_embed . $value;
  }

  protected function lookupMigratedMedia($fid) {
    $query = \Drupal::database()->select('migrate_map_upgrade_d7_media_images', 'map');
    $query->fields('map', ['destid1']);
    $query->condition('map.sourceid1', $fid);
    return $query->execute()->fetchField();
  }

  protected function getMediaUuid($media_id) {
    $media = \Drupal::entityTypeManager()
      ->getStorage('media')
      ->load($media_id);
    return $media ? $media->uuid() : NULL;
  }
}
