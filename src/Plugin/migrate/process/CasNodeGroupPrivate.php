<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Effective D7 og_access visibility for a node: 1 = group members only.
 *
 * D7 computed a node's visibility from two layers, OR-ing grants across
 * groups. This flattens that to one boolean for field_group_private:
 * - group_content_access 2 ("Private") is private outright;
 * - group_content_access 1 ("Public") is public outright;
 * - 0 / no row ("Use group defaults") inherits: private only when the node
 *   belongs to at least one group and every one of its groups is itself
 *   private (group_access 1) — one public group makes the node public,
 *   matching og_access's OR of realm grants.
 *
 * Source value is the D7 nid; the D7 tables are read over the migrate
 * connection.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_node_group_private"
 * )
 */
class CasNodeGroupPrivate extends ProcessPluginBase {

  /**
   * D7 gids of private groups (group_access = 1), loaded once.
   *
   * @var int[]|null
   */
  protected static ?array $privateGroups = NULL;

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $nid = (int) $value;
    if (!$nid) {
      return 0;
    }
    $db = Database::getConnection('default', 'migrate');

    $explicit = $db->select('field_data_group_content_access', 'ca')
      ->fields('ca', ['group_content_access_value'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->execute()
      ->fetchField();
    if ($explicit !== FALSE && $explicit !== NULL) {
      if ((int) $explicit === 2) {
        return 1;
      }
      if ((int) $explicit === 1) {
        return 0;
      }
    }

    $gids = $db->select('og_membership', 'og')
      ->fields('og', ['gid'])
      ->condition('entity_type', 'node')
      ->condition('group_type', 'node')
      ->condition('etid', $nid)
      ->execute()
      ->fetchCol();
    if (!$gids) {
      return 0;
    }
    if (self::$privateGroups === NULL) {
      self::$privateGroups = array_map('intval', $db->select('field_data_group_access', 'ga')
        ->fields('ga', ['entity_id'])
        ->condition('entity_type', 'node')
        ->condition('group_access_value', 1)
        ->execute()
        ->fetchCol());
    }
    foreach ($gids as $gid) {
      if (!in_array((int) $gid, self::$privateGroups, TRUE)) {
        return 0;
      }
    }
    return 1;
  }

}
