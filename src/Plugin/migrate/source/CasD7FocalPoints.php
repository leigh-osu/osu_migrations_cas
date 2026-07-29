<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * D7 focal_point rows for image files, with the image dimensions.
 *
 * D7 stored the editor-set focus as "x,y" percentages in the focal_point
 * table; D10's focal_point module stores absolute pixel coordinates in crop
 * entities. file_entity's file_metadata table provides the width/height
 * (PHP-serialized ints) needed for the conversion (done in the
 * cas_focal_point_pixel process plugin).
 *
 * @MigrateSource(
 *   id = "cas_d7_focal_points",
 *   source_module = "focal_point"
 * )
 */
class CasD7FocalPoints extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('focal_point', 'fp');
    $query->innerJoin('file_managed', 'fm', 'fp.fid = fm.fid');
    $query->innerJoin('file_metadata', 'w', "fp.fid = w.fid AND w.name = 'width'");
    $query->innerJoin('file_metadata', 'h', "fp.fid = h.fid AND h.name = 'height'");
    $query->fields('fp', ['fid', 'focal_point'])
      ->fields('fm', ['uri']);
    $query->addField('w', 'value', 'width_serialized');
    $query->addField('h', 'value', 'height_serialized');
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $row->setSourceProperty('width', (int) unserialize($row->getSourceProperty('width_serialized')));
    $row->setSourceProperty('height', (int) unserialize($row->getSourceProperty('height_serialized')));
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'fid' => $this->t('File ID'),
      'focal_point' => $this->t('Focal point as "x,y" percentages'),
      'uri' => $this->t('File URI'),
      'width' => $this->t('Image width in pixels'),
      'height' => $this->t('Image height in pixels'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['fid' => ['type' => 'integer', 'alias' => 'fp']];
  }

}
