<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\osu_migrate_content\Plugin\migrate\source\OsuBiblioReference;

/**
 * Biblio reference source with D7 domain assignment data.
 *
 * The stock osu_biblio_reference source carries no domain data, so all
 * migrated publications lost their D7 domain_access rows (the only content
 * type where that happened — node migrations use d7_node_domain_access).
 * This subclass adds the same three source properties that
 * domain_access_migrate's NodeDomainAccess provides, and
 * osu_migrations_cas_migration_plugins_alter() swaps it into the
 * upgrade_d7_biblio_publication migration along with the field_domain_*
 * process mappings.
 *
 * @MigrateSource(
 *   id = "cas_biblio_reference_domain",
 *   source_provider = "biblio",
 *   source_module = "biblio"
 * )
 */
class CasBiblioReferenceDomain extends OsuBiblioReference {

  /**
   * Contributor auth_types that are editors of the containing work.
   *
   * Secondary Author (2) is biblio's EndNote-style convention for the
   * editor of the book/proceedings a chapter appears in; Series Editor
   * (10) and Editor (14) are literal. Contributors placed in the
   * secondary slot (auth_category 2) with the type left at its Author
   * default count too — biblio renders the slot, not the type, as
   * "Secondary Authors". Everything else stays an author (including
   * Corporate Author — an organization credited as author).
   */
  protected const EDITOR_AUTH_TYPES = [2, 10, 14];

  /**
   * {@inheritdoc}
   *
   * The parent query selects only b.* and n.title, but the migration maps
   * status/promote/sticky/created/changed from the source: on initial import
   * the NULLs fall back to entity defaults, but --update re-imports crash on
   * the NOT NULL status column. Select the real node values.
   */
  public function query() {
    $query = parent::query();
    $query->fields('n', ['status', 'promote', 'sticky', 'created', 'changed']);
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields();
    $fields['domain_access_node'] = $this->t('Node Domain Access');
    $fields['domain_all_affiliates'] = $this->t('Node available on all domains');
    $fields['domain_source'] = $this->t('Node canonical domain');
    $fields['editors'] = $this->t('Editors (contributors with an editor auth_type)');
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $nid = $row->getSourceProperty('nid');

    $sites = $this->select('domain_access', 'da')
      ->fields('da', ['realm'])
      ->condition('da.realm', 'domain_site')
      ->condition('da.nid', $nid)
      ->execute()
      ->fetchCol();
    if ($sites) {
      $row->setSourceProperty('domain_all_affiliates', 1);
    }

    $target_ids = [];
    $domains = $this->select('domain_access', 'da')
      ->fields('da', ['gid'])
      ->condition('da.realm', 'domain_id')
      ->condition('da.nid', $nid)
      ->execute()
      ->fetchCol();
    foreach ($domains as $domain) {
      $machine_names = $this->select('domain', 'd')
        ->fields('d', ['machine_name'])
        ->condition('d.domain_id', $domain)
        ->execute()
        ->fetchCol();
      if ($machine_names) {
        $target_ids[] = ['target_id' => $machine_names[0]];
      }
    }
    $row->setSourceProperty('domain_access_node', $target_ids);

    $source_domain = [];
    $domains = $this->select('domain_source', 'ds')
      ->fields('ds', ['domain_id'])
      ->condition('ds.nid', $nid)
      ->execute()
      ->fetchCol();
    foreach ($domains as $domain) {
      $machine_names = $this->select('domain', 'd')
        ->fields('d', ['machine_name'])
        ->condition('d.domain_id', $domain)
        ->execute()
        ->fetchCol();
      if ($machine_names) {
        $source_domain = $machine_names[0];
      }
    }
    $row->setSourceProperty('domain_source', $source_domain);

    $result = parent::prepareRow($row);

    // Re-split contributors by role: the parent's selectContributors()
    // merges every contributor into 'author' regardless of auth_type,
    // which read the editors of edited volumes and chapters as co-authors.
    // Rows carry cid for the cas_biblio_authors term lookup.
    $query = $this->select('biblio_contributor', 'bc');
    $query->fields('bc', ['auth_type', 'auth_category', 'cid']);
    $query->fields('bcd', ['name']);
    $query->innerJoin('biblio_contributor_data', 'bcd', 'bc.cid = bcd.cid');
    $query->condition('bc.nid', $nid);
    $query->condition('bc.vid', $row->getSourceProperty('vid'));
    $query->orderBy('bc.rank');
    $authors = [];
    $editors = [];
    foreach ($query->execute() as $record) {
      $item = ['cid' => $record['cid'], 'name' => $record['name']];
      if (in_array((int) $record['auth_type'], self::EDITOR_AUTH_TYPES, TRUE)
        || (int) $record['auth_category'] === 2) {
        $editors[] = $item;
      }
      else {
        $authors[] = $item;
      }
    }
    $row->setSourceProperty('author', $authors);
    $row->setSourceProperty('editors', $editors);

    return $result;
  }

}
