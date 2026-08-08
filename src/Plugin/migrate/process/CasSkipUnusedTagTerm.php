<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Skips D7 tags terms no migrating node references.
 *
 * The D7 tags vocabulary carries 90 terms with no field_data_field_tags
 * rows at all (all live usage is on article/feature_story, which migrate
 * to story). Without this, they arrive as dead terms in story_tags —
 * autocomplete and term-list clutter with no term page worth keeping.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_skip_unused_tag_term"
 * )
 */
class CasSkipUnusedTagTerm extends ProcessPluginBase {

  /**
   * D7 tids with at least one node reference, keyed by tid.
   *
   * @var array|null
   */
  protected static ?array $usedTids = NULL;

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (static::$usedTids === NULL) {
      static::$usedTids = Database::getConnection('default', 'migrate')
        ->query('SELECT DISTINCT field_tags_tid FROM {field_data_field_tags}')
        ->fetchAllKeyed(0, 0);
    }
    if (!isset(static::$usedTids[$value])) {
      throw new MigrateSkipRowException("D7 tags term $value has no node usage; not migrated.");
    }
    return $value;
  }

}
