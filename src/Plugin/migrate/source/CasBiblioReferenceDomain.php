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

    return parent::prepareRow($row);
  }

}
