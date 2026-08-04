<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Plugin\MigratePluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adjustable Columns serialized data Process plugin.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_adjustable_columns_section",
 *   handle_multiples = TRUE
 * )
 */
class CasAdjustableColumnsSection extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $migrateDb;

  /**
   * The Drupal migrate lookup service.
   *
   * @var \Drupal\migrate\MigrateLookupInterface
   */
  private MigrateLookupInterface $migrateLookup;

  /**
   * The migrate process plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigratePluginManagerInterface
   */
  private MigratePluginManagerInterface $processPluginManager;

  /**
   * Constructs a new object of the class.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\migrate\MigrateLookupInterface $migrateLookup
   *   The migrate lookup interface.
   * @param \Drupal\migrate\Plugin\MigratePluginManagerInterface $processPluginManager
   *   The migrate process plugin manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrateLookupInterface $migrateLookup, MigratePluginManagerInterface $processPluginManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->migrateDb = Database::getConnection('default', 'migrate');
    $this->migrateLookup = $migrateLookup;
    $this->processPluginManager = $processPluginManager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('migrate.lookup'), $container->get('plugin.manager.migrate.process'));
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $extra_data = array();
    $block_ids = [];
    foreach ($value as $item) {
      $block_id = $this->migrateLookup->lookup('field_collection_field_lp_adj_column__to__layout_builder', [$item['value']]);
      $block_ids[] = reset($block_id)['id'];
    }
    $extra_data['migration']['adjustable_columns_section']['attached_block_ids'] = implode(',', $block_ids);
    $field_list = array(
      'field_lp_row_min_height',
      'field_lp_row_padding',
      'field_lp_background_color',
      'field_lp_row_class',
      'field_lp_row_style'
    );
    foreach ($field_list as $field) {
      $field_data = $row->getSourceProperty($field);
      if (isset($field_data[0])) {
        $extra_data['migration']['adjustable_columns_section'][$field] = $field_data;
      }
    }
    // Resolve the D7 entity_background reference to "mid,image|parallax" now,
    // via the entity_background plugin, so the layout migration can use it
    // without needing access to the D7 database.
    $eb_data = $row->getSourceProperty('eb_background');
    if (isset($eb_data[0]['value'])) {
      $eb_plugin = $this->processPluginManager->createInstance('entity_background');
      $resolved = $eb_plugin->transform($eb_data[0], $migrate_executable, $row, $destination_property);
      if (!empty($resolved) && is_string($resolved)) {
        $extra_data['migration']['adjustable_columns_section']['eb_background'] = $resolved;
      }
    }
    // Resolve the background video file to its migrated local video media
    // entity. A couple of D7 rows point the "video" field at an image
    // (animated gif); fall back to an image background for those.
    $video_data = $row->getSourceProperty('field_lp_background_video');
    if (isset($video_data[0]['fid'])) {
      $video_mids = $this->migrateLookup->lookup('upgrade_d7_media_local_video', [$video_data[0]['fid']]);
      $first_video = reset($video_mids);
      if ($first_video) {
        $extra_data['migration']['adjustable_columns_section']['field_lp_background_video'] = $first_video['mid'];
      }
      elseif (!isset($extra_data['migration']['adjustable_columns_section']['eb_background'])) {
        $image_mids = $this->migrateLookup->lookup([
          'upgrade_d7_media_images',
          'cas_media_private_images',
        ], [$video_data[0]['fid']]);
        $first_image = reset($image_mids);
        if ($first_image) {
          $extra_data['migration']['adjustable_columns_section']['eb_background'] = $first_image['mid'] . ',image';
        }
      }
    }
    return serialize($extra_data);
  }
}
