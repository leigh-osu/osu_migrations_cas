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
 * Custom plugin for handling paragraph lp_vertical_tabs items from d7.
 * Will use accordion bundle with a vertical tabs view in D10
 *
 * @MigrateProcessPlugin(
 *   id = "cas_vertical_tabs_item",
 *   handle_multiples = TRUE
 * )
 */
class CasVerticalTabsItem extends CasLayoutBase {

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
    $verticalTabItemIds = $value[0];
    if (!empty($verticalTabItemIds)) {
      $d7_vertical_tabs = $this->getVerticalTabItems($verticalTabItemIds);

      // Create accordion items using title and body from d7.
      $paragraph_items = [];
      foreach ($d7_vertical_tabs as $vertical_tab) {
        // Get the current value.
        $bodyValue = $vertical_tab->field_lp_vert_tab_contents_value;
        // Pass it to our service to get the new embed value.
        $transformedEmbedCode = CasLarchInlineClasses::mapText($this->osuMediaEmbed->transformEmbedCode($bodyValue));
        $vertical_tab_format = (isset($vertical_tab->field_lp_vert_tab_contents_format)) ? $vertical_tab->field_lp_vert_tab_contents_format : "full_html";
        if ($vertical_tab_format == 'filtered_html'){
          $vertical_tab_format = 'basic_html';
        }

        $paragraph_items[] = Paragraph::create([
          'type' => 'osu_accordion_item',
          'field_p_accordion_title' => $vertical_tab->field_lp_vert_tab_title_value,
          'field_p_accordion_body' => [
            'value' => $transformedEmbedCode,
            'format' => $vertical_tab_format,
          ],
        ]);
      }

      // Create accordion section and attach accordion items.
      $paragraph_section = Paragraph::create([
        'type' => 'osu_accordion_section',
        'field_p_accordion_heading' => '',
        'field_osu_paragraph_item' => $paragraph_items,
      ]);

      // Return accordion section which gets attached to the block created by the
      // migration.
      return $paragraph_section;
    }
    return $value;
  }

  /**
   * Query Migration source database for all Paragraph Accordion Bundles.
   *
   * @param mixed $value
   *   The id of the paragraph.
   *
   * @return \Drupal\Core\Database\StatementInterface|null
   *   A prepared statement, or NULL if the query is not valid.
   */
  private function getVerticalTabItems($value) {
    $entity_ids = [];
    $revision_ids = [];
    foreach ($value as $id) {
      $entity_ids[] = $id['value'];
      $revision_ids[] = $id['revision_id'];
    }

    $query = $this->migrateDb->select('field_data_field_lp_vert_tab_title', 'title');
    $query->leftJoin(
      'field_data_field_lp_vert_tab_contents',
      'content',
      'title.entity_id = content.entity_id && title.revision_id = content.revision_id'
    );
    $query->fields('title', ['field_lp_vert_tab_title_value']);
    $query->fields('content', ['field_lp_vert_tab_contents_value']);
    $query->fields('content', ['field_lp_vert_tab_contents_format']);
    $query->condition('title.entity_id', $entity_ids, 'IN');
    $query->condition('title.revision_id', $revision_ids, 'IN');
    return $query->execute();
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
