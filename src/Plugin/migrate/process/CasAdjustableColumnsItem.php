<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Adjustable Columns serialized data Process plugin.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_adjustable_columns_item",
 *   handle_multiples = TRUE
 * )
 */
class CasAdjustableColumnsItem extends ProcessPluginBase {

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $extra_data = array();
    $field_list = array(
      'field_lp_col_xs_width',
      'field_lp_col_xs_offset',
      'field_lp_col_sm_width',
      'field_lp_col_sm_offset',
      'field_lp_col_md_width',
      'field_lp_col_md_offset',
      'field_lp_col_lg_width',
      'field_lp_col_lg_offset',
      'field_lp_col_padding',
      'field_lp_col_class',
      'field_lp_col_style',
      'field_lp_col_bg_color',
      'field_lp_col_image',
      'field_lp_col_view',
      'field_lp_col_block'
    );
    foreach ($field_list as $field) {
      $field_data = $row->getSourceProperty($field);
      if (isset($field_data[0])) {
        $extra_data['migration']['adjustable_columns_item'][$field] = $field_data;
      }
    }
    return serialize($extra_data);
  }
}
