<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\osu_user_accounts\Plugin\migrate\source\UserFilteredDomainAccess;
use Drupal\user\Plugin\migrate\source\d7\User;

/**
 * D7 users with no migration-qualifying role but a recent login.
 *
 * upgrade_d7_users_with_roles only migrates users holding one of the
 * editorial roles, which leaves ~1,700 CAS people (faculty/staff who only
 * ever logged in via CAS to see their own profile) without a D10 account —
 * and their migrated profile nodes owned by uid 1. This source selects the
 * slice of those users who are still active: no qualifying role, but a
 * login within the configured window.
 *
 * Configuration:
 * - login_since: (int, required) minimum {users}.login timestamp.
 * - exclude_role_names: (string[]) users holding any of these roles are
 *   skipped — keep this in sync with upgrade_d7_users_with_roles'
 *   role_names so the two migrations partition cleanly and never both
 *   import the same uid.
 *
 * Inherits the domain_access_user row property (D7 domain_editor rows) from
 * UserFilteredDomainAccess; role-less users rarely have any, but the few
 * that do keep their domain assignments.
 *
 * @MigrateSource(
 *   id = "cas_d7_user_active_no_roles",
 *   source_module = "user"
 * )
 */
class CasUserActiveNoRoles extends UserFilteredDomainAccess {

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Deliberately bypass UserFiltered::query(): its INNER JOIN on
    // users_roles would drop the role-less users this source exists for.
    $query = User::query();

    $query->condition('u.login', (int) $this->configuration['login_since'], '>=');

    $exclude = (array) ($this->configuration['exclude_role_names'] ?? []);
    if ($exclude) {
      $sub = $this->select('users_roles', 'ur')->distinct();
      $sub->innerJoin('role', 'r', 'ur.rid = r.rid');
      $sub->fields('ur', ['uid']);
      $sub->condition('r.name', $exclude, 'IN');
      $query->condition('u.uid', $sub, 'NOT IN');
    }

    return $query;
  }

}
