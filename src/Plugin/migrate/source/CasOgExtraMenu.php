<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\system\Plugin\migrate\source\Menu;

/**
 * Drupal 7 extra (non-canonical) OG menu source.
 *
 * Returns the D7 {menu_custom} rows for OG menus that are NOT a group's
 * canonical menu (e.g. The Source's per-issue menus, EMT's Academics menu).
 * These become standalone D10 menus rather than group content menus.
 *
 * @MigrateSource(
 *   id = "cas_og_extra_menu",
 *   source_module = "og_menu"
 * )
 */
class CasOgExtraMenu extends Menu {

  use CasOgMenuCanonicalTrait;

  /**
   * {@inheritDoc}
   */
  public function query() {
    $query = parent::query();
    $extra = $this->getExtraOgMenuNames();
    // Match nothing when there are no extra menus.
    $query->condition('m.menu_name', $extra ?: [''], 'IN');
    return $query;
  }

}
