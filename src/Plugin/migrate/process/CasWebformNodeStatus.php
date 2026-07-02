<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;

/**
 * Resolves a webform node's field-level status from the webform entity.
 *
 * The node `webform` field carries its own status property that overrides the
 * webform entity's status when the form is rendered on the node, and D7 has no
 * per-node equivalent -- the D7 {webform}.status flag was already migrated
 * onto the webform entity by d7_webform. Mirroring that entity status here
 * keeps closed forms closed no matter which of the two levels webform_node
 * consults, without re-reading the D7 database.
 *
 * Expects the migrated webform id (the output of a migration_lookup against
 * d7_webform) as its input value.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_webform_node_status",
 *   source_module = "webform"
 * )
 */
class CasWebformNodeStatus extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $webform = is_string($value) && $value !== '' ? Webform::load($value) : NULL;
    return $webform ? $webform->get('status') : WebformInterface::STATUS_OPEN;
  }

}
