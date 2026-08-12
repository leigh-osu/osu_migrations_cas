<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * D10 group role for a membership, from the user's D7 OG role in that group.
 *
 * upgrade_d7_user_og_memberships originally stamped basic_group-content_author
 * on every membership it created, so the 353 users who were plain members of a
 * group in D7 — no og_users_roles row at all — arrived holding page CRUD,
 * menu management and edit-group there. This plugin looks the (uid, gid) pair
 * up in D7 and grants content_author only when the user actually held an OG
 * role in that group (group manager, group author, paragraph editor, …).
 * Plain members yield nothing; skip_on_empty after this leaves them with bare
 * membership, whose insider Member role is view + update-own-profile.
 *
 * The D7 tiers all collapse to the one D10 working role on purpose — this
 * restores only the held-a-role/held-nothing line, not the ladder.
 *
 * Expects the d7_og_membership_user source row (etid = uid, gid).
 *
 * @code
 * group_roles:
 *   - plugin: cas_og_membership_role
 *     source: etid
 *   - plugin: skip_on_empty
 *     method: process
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "cas_og_membership_role"
 * )
 */
class CasOgMembershipRole extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The migrate source database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connection = $connection;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // The migrate source DB key is configurable; default to 'migrate'.
    $key = $container->get('settings')->get('migrate_source_connection', 'migrate');
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      Database::getConnection('default', $key),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $held_role = (bool) $this->connection->queryRange(
      'SELECT 1 FROM {og_users_roles} WHERE uid = :uid AND gid = :gid',
      0, 1,
      [':uid' => (int) $value, ':gid' => (int) $row->getSourceProperty('gid')]
    )->fetchField();
    return $held_role ? ($this->configuration['role'] ?? 'basic_group-content_author') : NULL;
  }

}
