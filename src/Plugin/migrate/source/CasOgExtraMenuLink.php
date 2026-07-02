<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\menu_link_content\Plugin\migrate\source\MenuLink;

/**
 * Drupal 7 extra (non-canonical) OG menu link source.
 *
 * Returns the D7 menu links belonging to OG menus that are NOT a group's
 * canonical menu. Companion to cas_og_extra_menu; the links land in the
 * standalone D10 menus it creates.
 *
 * @MigrateSource(
 *   id = "cas_og_extra_menu_link",
 *   source_module = "og_menu"
 * )
 */
class CasOgExtraMenuLink extends MenuLink {

  use CasOgMenuCanonicalTrait;

  /**
   * {@inheritDoc}
   */
  public function query() {
    $query = parent::query();
    $extra = $this->getExtraOgMenuNames();
    // Match nothing when there are no extra menus.
    $query->condition('ml.menu_name', $extra ?: [''], 'IN');
    return $query;
  }

}
