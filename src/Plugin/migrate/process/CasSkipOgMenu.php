<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Skips D7 OG menus so they are not migrated as standalone D10 menus.
 *
 * OG menus are migrated into each group's own menu by cas_og_menu_group_menu.
 * This plugin throws a MigrateSkipRowException when the given menu name is
 * registered in the D7 {og_menu} table (excluding global/system menus), and
 * otherwise passes the value through unchanged.
 *
 * It is injected into the `upgrade_d7_menu` (menu config) migration by
 * osu_migrations_cas_migration_plugins_alter(). Because upgrade_d7_menu_links
 * resolves its menu_name with a migration_lookup against upgrade_d7_menu
 * followed by skip_on_empty, skipping the menu here also causes the matching OG
 * menu links to be skipped by the generic links migration -- leaving
 * cas_og_menu_group_menu as their sole importer and preventing ~184 empty
 * standalone "menu-og-*" menus from being created.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_skip_og_menu",
 *   source_module = "og_menu"
 * )
 */
class CasSkipOgMenu extends ProcessPluginBase {

  /**
   * Global/system menus that may appear in {og_menu} but must NOT be skipped.
   */
  protected const SYSTEM_MENUS = [
    'main-menu',
    'navigation',
    'management',
    'user-menu',
    'tools',
    'admin',
    'account',
    'devel',
    'footer',
  ];

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (is_string($value) && !in_array($value, self::SYSTEM_MENUS, TRUE)) {
      $db = Database::getConnection('default', 'migrate');
      if ($db->schema()->tableExists('og_menu')) {
        $is_og = (bool) $db->select('og_menu', 'om')
          ->condition('menu_name', $value)
          ->countQuery()
          ->execute()
          ->fetchField();
        if ($is_og) {
          throw new MigrateSkipRowException(sprintf('Skipping OG menu "%s" (migrated as a group menu).', $value));
        }
      }
    }
    return $value;
  }

}
