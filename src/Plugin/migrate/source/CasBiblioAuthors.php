<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * D7 biblio contributors (the biblio_contributor_data dictionary).
 *
 * One row per distinct person credited on at least one biblio entry —
 * authors and editors alike; both publication fields reference the same
 * Publication Authors vocabulary so a person's term collects everything
 * they touched. Keyed by cid for the publication migration's lookups.
 *
 * @MigrateSource(
 *   id = "cas_biblio_authors",
 *   source_module = "biblio"
 * )
 */
class CasBiblioAuthors extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('biblio_contributor_data', 'bcd')->fields('bcd', ['cid', 'name']);
    // Only people actually credited on an entry.
    $query->innerJoin('biblio_contributor', 'bc', 'bc.cid = bcd.cid');
    $query->distinct();
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'cid' => $this->t('Contributor ID'),
      'name' => $this->t('Contributor name'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['cid' => ['type' => 'integer']];
  }

}
