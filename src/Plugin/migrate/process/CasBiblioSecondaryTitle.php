<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Routes biblio_secondary_title to the right field for the biblio type.
 *
 * In D7 biblio the secondary title's meaning depends on the entry type:
 * for articles it is the journal, for book chapters the containing book's
 * title, for books and reports the series. The stock migration flattened
 * everything into field_pub_journal. This plugin is mapped once per target
 * field with a 'target' of journal | book | series and returns the value
 * only when the row's type routes there (empty otherwise), so the three
 * mappings split the one source column.
 *
 * Source must be [biblio_type, biblio_secondary_title]; biblio_type arrives
 * as the type NAME (OsuBiblioReference::prepareRow() resolves the tid).
 *
 * @MigrateProcessPlugin(
 *   id = "cas_biblio_secondary_title"
 * )
 */
class CasBiblioSecondaryTitle extends ProcessPluginBase {

  /**
   * Biblio type name => target bucket; anything unlisted is 'journal'.
   */
  protected const TYPE_TARGETS = [
    'Book Chapter' => 'book',
    'Book' => 'series',
    'Report' => 'series',
    'Extension Publication' => 'series',
  ];

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    [$type, $secondary] = is_array($value) ? array_pad($value, 2, NULL) : [NULL, $value];
    $secondary = trim((string) $secondary);
    if ($secondary === '') {
      return NULL;
    }
    $bucket = self::TYPE_TARGETS[(string) $type] ?? 'journal';
    return $bucket === ($this->configuration['target'] ?? 'journal') ? $secondary : NULL;
  }

}
