<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Yields image media whose field_media_caption should be seeded.
 *
 * field_media_caption (from osu_media) is the platform's visible caption field
 * but is left empty by migration. This seeds it from the media's own image
 * alt/title (alt preferred, then title) for every media that has one and whose
 * caption is still empty. Intended to run *after* cas_media_alt_backfill so the
 * gallery field text already living on the media alt flows through to caption.
 *
 * Reads only the D10 database; only yields media that need a caption, so
 * re-runs converge and existing captions are never overwritten.
 *
 * @MigrateSource(
 *   id = "cas_media_caption_seed",
 *   source_module = "media"
 * )
 */
class CasMediaCaptionSeed extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The D10 database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $d10;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, $d10) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->d10 = $d10;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      Database::getConnection('default', 'default'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    // Media that already have a non-empty caption are skipped.
    $has_caption = [];
    foreach ($this->d10->query("SELECT entity_id AS mid FROM {media__field_media_caption} WHERE field_media_caption_value IS NOT NULL AND field_media_caption_value <> ''") as $r) {
      $has_caption[(int) $r->mid] = TRUE;
    }

    $rows = [];
    $res = $this->d10->query("SELECT entity_id AS mid, field_media_image_alt AS alt, field_media_image_title AS title
      FROM {media__field_media_image} WHERE delta = 0");
    foreach ($res as $r) {
      $mid = (int) $r->mid;
      if (isset($has_caption[$mid])) {
        continue;
      }
      $alt = trim((string) $r->alt);
      $title = trim((string) $r->title);
      $caption = $alt !== '' ? $alt : $title;
      if ($caption === '') {
        continue;
      }
      // field_media_caption is a 255-char string field.
      if (mb_strlen($caption) > 255) {
        $caption = mb_substr($caption, 0, 255);
      }
      $rows[] = ['mid' => $mid, 'caption' => $caption];
    }

    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'mid' => $this->t('Media id'),
      'caption' => $this->t('Caption (image alt, else title)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['mid' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'cas_media_caption_seed';
  }

  /**
   * {@inheritdoc}
   */
  public function count($refresh = FALSE) {
    return iterator_count($this->initializeIterator());
  }

}
