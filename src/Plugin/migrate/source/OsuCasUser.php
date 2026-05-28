<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * D7 CAS user source (uid -> cas_name) that does not require the source module.
 *
 * The stock contrib `d7_cas_user` source declares source_module="cas", so
 * DrupalSqlBase::checkRequirements() throws when the D7 `cas` module is flagged
 * disabled in the source {system} table — which is the case in our source dump
 * even though the {cas_user} table is fully populated. That makes the migration
 * silently disappear from `drush migrate:status`.
 *
 * This plugin gates on the existence of the {cas_user} TABLE instead of the
 * module-enabled flag, so it survives source re-imports without any manual
 * `UPDATE {system} SET status=1` step.
 *
 * @MigrateSource(
 *   id = "osu_cas_user",
 *   source_module = "cas"
 * )
 */
class OsuCasUser extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('cas_user', 'c')->fields('c');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'uid' => $this->t('The user identifier.'),
      'cas_name' => $this->t('Unique CAS authentication name.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'uid' => [
        'type' => 'integer',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Deliberately bypasses the DrupalSqlBase source_module check. We require the
   * source {cas_user} table to exist (and the source DB to be reachable, which
   * getDatabase() enforces) rather than the D7 cas module being enabled.
   */
  public function checkRequirements() {
    if (!$this->getDatabase()->schema()->tableExists('cas_user')) {
      throw new RequirementsException('Source database is missing the cas_user table.', ['source_table' => 'cas_user']);
    }
  }

}
