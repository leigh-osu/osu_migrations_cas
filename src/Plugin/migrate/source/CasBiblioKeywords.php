<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * D7 biblio keywords (the biblio_keyword_data dictionary).
 *
 * One row per distinct keyword; the per-node assignments live in
 * biblio_keyword and are carried by the publication migration's keywords
 * source property (see OsuBiblioReference::selectKeywords()).
 *
 * @MigrateSource(
 *   id = "cas_biblio_keywords",
 *   source_module = "biblio"
 * )
 */
class CasBiblioKeywords extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('biblio_keyword_data', 'bkd')->fields('bkd', ['kid', 'word']);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'kid' => $this->t('Keyword ID'),
      'word' => $this->t('Keyword'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return ['kid' => ['type' => 'integer']];
  }

}
