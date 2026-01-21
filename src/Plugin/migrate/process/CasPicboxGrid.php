<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Picbox Grid Process plugin.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_picbox_grid",
 *   handle_multiples = TRUE
 * )
 */
class CasPicboxGrid extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection object.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private Connection $migrateDb;

  /**
   * The Drupal migrate lookup service.
   *
   * @var \Drupal\migrate\MigrateLookupInterface
   */
  private MigrateLookupInterface $migrateLookup;

  /**
   * Constructs a new object of the class.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\migrate\MigrateLookupInterface $migrateLookup
   *   The migrate lookup interface.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrateLookupInterface $migrateLookup) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->migrateDb = Database::getConnection('default', 'migrate');
    $this->migrateLookup = $migrateLookup;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('migrate.lookup'));
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $block_ids = [];
    $extra_data = array();
    $picbox_columns = $row->getSourceProperty('field_lp_picbox_cols_max');
    foreach ($value as $item) {
      $block_id = $this->migrateLookup->lookup('field_collection_field_lp_picbox__to__layout_builder', [$item['value']]);
      $block_ids[] = reset($block_id)['id'];
    }
    $extra_data['migration']['picbox_columns'] = $picbox_columns[0]['value'];
    $extra_data['migration']['attached_block_ids'] = implode(',', $block_ids);
    return serialize($extra_data);
  }

}
