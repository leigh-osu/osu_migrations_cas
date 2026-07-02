<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\menu_link_content\Plugin\migrate\source\MenuLink;

/**
 * Drupal 7 OG menu link source.
 *
 * Returns the D7 menu links that belong to a group's canonical Organic Groups
 * menu. A group's canonical menu is `menu-og-<gid>` when it exists, otherwise
 * its only non-system OG menu (which covers custom-named group menus such as
 * `menu-about-cas`). Groups can own additional OG menus (e.g. The Source's
 * per-issue menus); those are NOT part of the group nav and are migrated to
 * standalone menus by cas_og_extra_menu / cas_og_extra_menu_links instead.
 *
 * @MigrateSource(
 *   id = "cas_og_menu_link",
 *   source_module = "og_menu"
 * )
 */
class CasOgMenuLink extends MenuLink {

  use CasOgMenuCanonicalTrait;

  /**
   * {@inheritDoc}
   */
  public function query() {
    $query = parent::query();
    // Restrict to each group's canonical OG menu.
    $query->condition('ml.menu_name', $this->getCanonicalOgMenuNames(), 'IN');
    return $query;
  }

}
