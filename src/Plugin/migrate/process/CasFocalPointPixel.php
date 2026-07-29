<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Converts a D7 "x,y" percentage focal point to an absolute pixel value.
 *
 * Configuration:
 * - axis: 'x' or 'y' — which coordinate to produce.
 *
 * Source value: [focal_point, width, height]. Mirrors
 * FocalPointManager::relativeToAbsolute(): pixel = round(pct/100 * dim).
 *
 * @MigrateProcessPlugin(
 *   id = "cas_focal_point_pixel"
 * )
 */
class CasFocalPointPixel extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    [$focal, $width, $height] = $value;
    $parts = explode(',', (string) $focal);
    if (count($parts) !== 2) {
      throw new MigrateSkipRowException("Malformed focal point '$focal'");
    }
    $axis = $this->configuration['axis'] ?? 'x';
    $pct = (float) ($axis === 'x' ? $parts[0] : $parts[1]);
    $dim = (int) ($axis === 'x' ? $width : $height);
    if ($dim <= 0) {
      throw new MigrateSkipRowException('Missing image dimensions');
    }
    return (int) round($pct / 100 * $dim);
  }

}
