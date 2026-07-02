<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

/**
 * Splits D7 OG menus into canonical group menus and extra menus.
 *
 * A group's canonical menu becomes its D10 group_content_menu; any further
 * OG menus it owns (e.g. The Source's per-issue menus, EMT's Academics menu)
 * migrate to standalone D10 menus so they can be placed as menu blocks with
 * visibility groups where needed.
 */
trait CasOgMenuCanonicalTrait {

  /**
   * System menus that must never be treated as group menus.
   */
  protected static array $systemMenus = [
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
   * Gets D7 OG menu names keyed by owning gid, system menus excluded.
   */
  protected function getOgMenusByGroup(): array {
    $rows = $this->select('og_menu', 'om')
      ->fields('om', ['gid', 'menu_name'])
      ->execute()
      ->fetchAll();
    $by_gid = [];
    foreach ($rows as $row) {
      if (!in_array($row['menu_name'], self::$systemMenus, TRUE)) {
        $by_gid[$row['gid']][] = $row['menu_name'];
      }
    }
    return $by_gid;
  }

  /**
   * Gets the canonical OG menu name for each group.
   *
   * `menu-og-<gid>` when the group has one, otherwise the group's first
   * remaining OG menu (covers custom-named single group menus).
   *
   * @return string[]
   *   Canonical menu names.
   */
  protected function getCanonicalOgMenuNames(): array {
    $canonical = [];
    foreach ($this->getOgMenusByGroup() as $gid => $names) {
      $og_name = "menu-og-$gid";
      $canonical[] = in_array($og_name, $names, TRUE) ? $og_name : reset($names);
    }
    return $canonical;
  }

  /**
   * Gets the non-canonical (extra) OG menu names across all groups.
   *
   * @return string[]
   *   Extra menu names, empty array if none.
   */
  protected function getExtraOgMenuNames(): array {
    $extra = [];
    $canonical = $this->getCanonicalOgMenuNames();
    foreach ($this->getOgMenusByGroup() as $names) {
      foreach ($names as $name) {
        if (!in_array($name, $canonical, TRUE)) {
          $extra[] = $name;
        }
      }
    }
    return $extra;
  }

}
