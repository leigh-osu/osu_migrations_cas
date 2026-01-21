<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;

/**
 * @MigrateProcessPlugin(
 *   id = "cas_link_merge"
 * )
 */
class CasLinkMerge extends ProcessPluginBase {

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $link_uri = $value['url'] ?? '';
    $textsource = $row->getSourceProperty('field_lp_picbox_box_headline');
    $title = (isset($textsource[0]['value'])) ? $textsource[0]['value'] : '';

    return [
      'uri' => $link_uri,
      'title' => $title,
    ];
  }
}
