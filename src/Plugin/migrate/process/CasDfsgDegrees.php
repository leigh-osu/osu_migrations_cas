<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Expands D7 field_dfsg_degrees field-collection items into one column.
 *
 * The graduate fact sheets' "degrees offered" blocks live in D7
 * field_collection_item entities (field_dfsg_degrees) with three sub-fields:
 * field_degree_title, field_dfsg_degree_level and
 * field_dfsg_degree_description. D10 models them as three parallel
 * multi-value fields on degree_fact_sheet, so this plugin runs once per
 * destination field with a 'column' setting (title | level | description)
 * and emits the values in D7 delta order.
 *
 * Deltas must stay aligned across the three fields; some D7 items have no
 * description, and Drupal drops empty text items on save, so the description
 * column substitutes a single space to hold the slot.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_dfsg_degrees",
 *   handle_multiples = TRUE
 * )
 */
class CasDfsgDegrees extends ProcessPluginBase {

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value) || !is_array($value)) {
      return [];
    }
    $column = $this->configuration['column'] ?? 'title';

    $item_ids = [];
    foreach ($value as $item) {
      if (!empty($item['value'])) {
        $item_ids[] = $item['value'];
      }
    }
    if (!$item_ids) {
      return [];
    }

    $db = Database::getConnection('default', 'migrate');
    $sub_fields = [
      'title' => ['field_degree_title', 'field_degree_title_value'],
      'level' => ['field_dfsg_degree_level', 'field_dfsg_degree_level_value'],
      'description' => ['field_dfsg_degree_description', 'field_dfsg_degree_description_value'],
    ];
    [$field, $value_column] = $sub_fields[$column];
    $rows = $db->select("field_data_$field", 'f')
      ->fields('f', ['entity_id', $value_column])
      ->condition('entity_type', 'field_collection_item')
      ->condition('entity_id', $item_ids, 'IN')
      ->execute()
      ->fetchAllKeyed();

    $result = [];
    foreach ($item_ids as $id) {
      $text = trim($rows[$id] ?? '');
      if ($column === 'description') {
        // A single space keeps an empty slot so deltas stay aligned with
        // the title and level fields.
        $result[] = [
          'value' => $text !== '' ? $text : ' ',
          'format' => 'full_html',
        ];
      }
      else {
        $result[] = $text;
      }
    }
    return $result;
  }

}
