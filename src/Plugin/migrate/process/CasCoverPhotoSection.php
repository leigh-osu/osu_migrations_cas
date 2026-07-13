<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds a leading layout section that renders the migrated cover photo.
 *
 * D7 feature pages showed field_cover_photo as a large inline image directly
 * under the page title, with field_introduction below it. The migration
 * stores the photo in field_osu_meta_image (a media reference, normally only
 * the og:image meta tag), so nothing renders it. This plugin emits a
 * one-column Bootstrap Layout Builder section containing a field block for
 * field_osu_meta_image in the "fluid_image" media view mode, intended to be
 * placed before the default (body) section:
 *
 * @code
 * cover_temp:
 *   plugin: cas_cover_photo_section
 *   source: field_cover_photo
 * layout_builder__layout:
 *   plugin: get
 *   source:
 *     - '@cover_temp'
 *     - '@default_temp'
 *     - '@paragraphs_temp'
 * @endcode
 *
 * Returns NULL when the node has no cover photo; the
 * paragraphs_to_layout_builder PreRowSaveSubscriber drops the empty value
 * when it flattens the combined section arrays.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_cover_photo_section",
 *   handle_multiples = TRUE
 * )
 */
class CasCoverPhotoSection extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The uuid generator.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  private UuidInterface $uuid;

  /**
   * Constructs a new object of the class.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UuidInterface $uuid) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->uuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // No cover photo on the source row: contribute no section.
    if (empty($value) || empty(array_filter(array_column((array) $value, 'fid')))) {
      return NULL;
    }

    $field = $this->configuration['field_name'] ?? 'field_osu_meta_image';
    $bundle = $this->configuration['bundle'] ?? 'page';

    $component = new SectionComponent($this->uuid->generate(), 'blb_region_col_1', [
      'id' => "field_block:node:$bundle:$field",
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity'],
      'formatter' => [
        'type' => 'entity_reference_entity_view',
        'label' => 'hidden',
        'settings' => ['view_mode' => 'fluid_image'],
        'third_party_settings' => [],
      ],
    ]);

    return [
      new Section('bootstrap_layout_builder:blb_col_1', ['container' => 'container'], [$component]),
    ];
  }

}
