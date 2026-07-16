<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\Core\Database\Database;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Yields image media whose alt/title can be backfilled from D7 field text.
 *
 * The media-image migration already carries the D7 *file-level* alt/title
 * (field_file_image_alt_text / _title_text). D7 also stored per-delta alt/title
 * on each image *field* instance (field_data_field_*_alt / _title), which the
 * fid-only migration dropped. This source finds media whose image alt or title
 * is still empty and for which the D7 field text has a single agreed value, and
 * yields the complete desired field_media_image value (target_id + backfilled
 * alt/title). Emitting target_id is deliberate: the companion migration rewrites
 * the whole field, so the existing file reference must be preserved.
 *
 * It only yields media that actually change, so re-runs converge. File-level
 * values always win: alt/title are only filled when currently empty.
 *
 * Source connection is the D10 database (the media lives here); the D7 field
 * text and the fid->mid map are read directly via explicit connections.
 *
 * @MigrateSource(
 *   id = "cas_media_alt_backfill",
 *   source_module = "media"
 * )
 */
class CasMediaAltBackfill extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The migrate (D7) source database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $d7;

  /**
   * The D10 database connection (media + migrate map live here).
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $d10;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, $d7, $d10) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->d7 = $d7;
    $this->d10 = $d10;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    $key = $container->get('settings')->get('migrate_source_connection', 'migrate');
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      Database::getConnection('default', $key),
      Database::getConnection('default', 'default'),
    );
  }

  /**
   * Aggregates the D7 per-delta field alt/title, keyed by fid.
   *
   * @return array
   *   fid => ['alt' => [distinct values...], 'title' => [distinct values...]].
   */
  protected function fieldTextByFid(): array {
    $by_fid = [];
    // Every D7 per-delta image field exposes <field>_fid/_alt/_title columns.
    $cols = $this->d7->query("SELECT TABLE_NAME, COLUMN_NAME
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME LIKE 'field_data_field_%'
        AND COLUMN_NAME LIKE '%\\_alt'")->fetchAllKeyed();
    foreach ($cols as $table => $alt_col) {
      $field = substr($table, strlen('field_data_'));
      $fid_col = $field . '_fid';
      $title_col = $field . '_title';
      $q = $this->d7->select($table, 't')->condition('t.deleted', 0);
      $q->addField('t', $fid_col, 'fid');
      $q->addField('t', $alt_col, 'alt');
      $q->addField('t', $title_col, 'title');
      foreach ($q->execute() as $r) {
        if (empty($r->fid)) {
          continue;
        }
        $alt = trim((string) $r->alt);
        $title = trim((string) $r->title);
        if ($alt !== '') {
          $by_fid[$r->fid]['alt'][$alt] = TRUE;
        }
        if ($title !== '') {
          $by_fid[$r->fid]['title'][$title] = TRUE;
        }
      }
    }
    return $by_fid;
  }

  /**
   * Returns the single agreed value from a distinct-value set, or NULL.
   */
  protected function agreed(?array $set): ?string {
    if (!$set) {
      return NULL;
    }
    return count($set) === 1 ? (string) array_key_first($set) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $field_text = $this->fieldTextByFid();

    // Reverse the media-image map: mid => fid (1 file : 1 media).
    $mid_to_fid = [];
    foreach ($this->d10->query('SELECT sourceid1 AS fid, destid1 AS mid FROM {migrate_map_upgrade_d7_media_images} WHERE destid1 IS NOT NULL') as $r) {
      $mid_to_fid[(int) $r->mid] = (int) $r->fid;
    }

    // Current image field values (single-delta image field).
    $rows = [];
    $res = $this->d10->query("SELECT entity_id AS mid, field_media_image_target_id AS target_id,
        field_media_image_alt AS alt, field_media_image_title AS title
      FROM {media__field_media_image} WHERE delta = 0");
    foreach ($res as $r) {
      $mid = (int) $r->mid;
      $fid = $mid_to_fid[$mid] ?? NULL;
      if (!$fid || !isset($field_text[$fid])) {
        continue;
      }
      $cur_alt = trim((string) $r->alt);
      $cur_title = trim((string) $r->title);

      // File-level value wins; only fill when empty and D7 agrees.
      $new_alt = $cur_alt !== '' ? $cur_alt : ($this->agreed($field_text[$fid]['alt'] ?? NULL) ?? '');
      $new_title = $cur_title !== '' ? $cur_title : ($this->agreed($field_text[$fid]['title'] ?? NULL) ?? '');

      if ($new_alt === $cur_alt && $new_title === $cur_title) {
        // Nothing to change.
        continue;
      }
      $rows[] = [
        'mid' => $mid,
        'target_id' => (int) $r->target_id,
        'alt' => $new_alt,
        'title' => $new_title,
      ];
    }

    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'mid' => $this->t('Media id'),
      'target_id' => $this->t('Existing image file id (preserved)'),
      'alt' => $this->t('Backfilled alt text'),
      'title' => $this->t('Backfilled title text'),
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
    return 'cas_media_alt_backfill';
  }

  /**
   * {@inheritdoc}
   */
  public function count($refresh = FALSE) {
    return iterator_count($this->initializeIterator());
  }

}
