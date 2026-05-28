<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\menu_link_content\Plugin\migrate\source\MenuLink;

/**
 * Drupal 7 OG menu link source.
 *
 * Returns the D7 menu links that belong to an Organic Groups menu. Rather than
 * matching on the conventional `menu-og-<gid>` name, this restricts to every
 * menu listed in the D7 {og_menu} table (which also covers custom-named group
 * menus such as `menu-about-cas` or `menu-academics`), while excluding the
 * standard system menus that may also appear there.
 *
 * @MigrateSource(
 *   id = "cas_og_menu_link",
 *   source_module = "og_menu"
 * )
 */
class CasOgMenuLink extends MenuLink {

  /**
   * System menus that must never be treated as group menus.
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
  public function query() {
    $query = parent::query();
    // Restrict to menu names registered as OG menus in the source site.
    $og_menus = $this->select('og_menu', 'om')->fields('om', ['menu_name']);
    $query->condition('ml.menu_name', $og_menus, 'IN');
    // Never migrate global/system menus as group menus.
    $query->condition('ml.menu_name', self::SYSTEM_MENUS, 'NOT IN');
    return $query;
  }

}
