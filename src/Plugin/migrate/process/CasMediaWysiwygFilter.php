<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\osu_migrations\Plugin\migrate\process\OsuMediaWysiwygFilter;
use Drupal\osu_migrations\OsuMediaEmbed;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process Plugin to transform Drupal 7 embed to Drupal 9.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_media_wysiwyg_filter"
 *  )
 *
 */
class CasMediaWysiwygFilter extends OsuMediaWysiwygFilter
{

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property)
  {
// $value is now an array: [0 => field_lp_col_text, 1 => field_lp_col_image]
    $text_field = $value[0];
    $col_image = $value[1];

    $text = $text_field[0]['value'] ?? '';
//    $format = $text_field[0]['format'] ?? 'full_html';
    $format = 'full_html';

    // Transform the text
    $text = $this->osuMediaEmbed->transformEmbedCode($text);

    // Add the image if it exists
    if (!empty($col_image) && isset($col_image[0]['fid'])) {
      $source_fid = $col_image[0]['fid'];

      $migration_plugin_manager = \Drupal::service('plugin.manager.migration');
      $migration = $migration_plugin_manager->createInstance('upgrade_d7_media_images');

      $destination_ids = $migration->getIdMap()->lookupDestinationIds([$source_fid]);

      if (!empty($destination_ids[0][0])) {
        $media_id = $destination_ids[0][0];
        $media = \Drupal::entityTypeManager()->getStorage('media')->load($media_id);

        if ($media) {
          $media_uuid = $media->uuid();
          $embed_code = '<drupal-media data-entity-type="media" data-entity-uuid="' . $media_uuid . '" data-align="center"></drupal-media>';
          $text = $text . "\n\n" . $embed_code;
        }
      }
    }

    return [
      [
        'value' => $text,
        'format' => $format,
      ]
    ];
  }
}
