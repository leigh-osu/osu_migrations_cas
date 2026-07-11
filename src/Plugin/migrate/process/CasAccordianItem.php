<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\Annotation\MigrateProcessPlugin;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\osu_migrations\OsuMediaEmbed;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\osu_migrations_cas\CasLayoutBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Custom plugin for handling paragraph accordian items from d7.
 * This bundle is misspelled in D7, and is only a single accordion instance (no grouping)
 *
 * @MigrateProcessPlugin(
 *   id = "cas_accordian_item",
 *   handle_multiples = TRUE
 * )
 */
class CasAccordianItem extends CasLayoutBase {

  /**
   * The OSU Media Embed Service.
   *
   * @var \Drupal\osu_migrations\OsuMediaEmbed
   */
  private OsuMediaEmbed $osuMediaEmbed;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $pluginId, $pluginDefinition, UuidInterface $uuid, Connection $db, EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $configFactory, MigrateLookupInterface $migrateLookup, OsuMediaEmbed $osuMediaEmbed) {
    parent::__construct($configuration, $pluginId, $pluginDefinition, $uuid, $db, $entityTypeManager, $configFactory, $migrateLookup);
    $this->osuMediaEmbed = $osuMediaEmbed;
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $accordian_heading = (isset($value[0][0]['value'])) ? $value[0][0]['value'] : "";
    $accordian_body = (isset($value[1][0])) ? $value[1][0]['value'] : "";
    $accordian_format = (isset($value[1][0]['format'])) ? $value[1][0]['format'] : "full_html";
    if ($accordian_format == 'filtered_html'){
      $accordian_format = 'basic_html';
    }

    if (!empty($accordian_heading)) {
      // Create accordion items using title and body from d7.
      $paragraph_items = [];
      // Pass it to our service to get the new embed value.
      $transformedEmbedCode = CasLegacyFilePaths::rewriteText(CasLarchInlineClasses::mapText($this->osuMediaEmbed->transformEmbedCode($accordian_body)));

      $paragraph_items[] = Paragraph::create([
        'type' => 'osu_accordion_item',
        'field_p_accordion_title' => $accordian_heading,
        'field_p_accordion_body' => [
          'value' => $transformedEmbedCode,
          'format' => $accordian_format,
        ],
      ]);

      // Create accordion section and attach accordion items.
      $paragraph_section = Paragraph::create([
        'type' => 'osu_accordion_section',
        'field_p_accordion_heading' => "",
        'field_osu_paragraph_item' => $paragraph_items,
      ]);

      // Return accordion section which gets attached to the block created by the migration.
      return $paragraph_section;
    }
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('uuid'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('migrate.lookup'),
      $container->get('osu_migrations.osu_media_embed')
    );
  }
}
