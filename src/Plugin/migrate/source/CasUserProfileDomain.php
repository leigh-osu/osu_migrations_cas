<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\user\Plugin\migrate\source\d7\User;

/**
 * D7 user source with the user's domain assignments.
 *
 * Profiles are migrated from D7 users (upgrade_d7_user_to_profile), which
 * carry no node-style domain data — so all profiles landed without a domain
 * assignment. D7 stores the user-to-domain relation in the domain_editor
 * table; this subclass exposes it as domain_access_node in the same shape
 * the node migrations use, and osu_migrations_cas_migration_plugins_alter()
 * swaps it into upgrade_d7_user_to_profile with a field_domain_access
 * mapping. (Semantics: "user may edit on domain" is used as "this person's
 * profile belongs on that domain".)
 *
 * @MigrateSource(
 *   id = "cas_d7_user_profile_domain",
 *   source_module = "user"
 * )
 */
class CasUserProfileDomain extends User {

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields();
    $fields['domain_access_node'] = $this->t('Domains the user is assigned to');
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $uid = $row->getSourceProperty('uid');

    $target_ids = [];
    $domains = $this->select('domain_editor', 'de')
      ->fields('de', ['domain_id'])
      ->condition('de.uid', $uid)
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

    return parent::prepareRow($row);
  }

}
