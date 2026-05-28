<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\group_content_menu\GroupContentMenuInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolve a D7 OG menu name to its D10 group menu name.
 *
 * Looks up the owning group id (gid) for an OG menu in the source {og_menu}
 * table, loads the migrated D10 group (groups keep their D7 nid as the group
 * id, so gid == group id), finds the group's auto-created group_content_menu,
 * and returns the prefixed menu name used by menu_link_content
 * (e.g. "group_menu-12").
 *
 * @MigrateProcessPlugin(
 *   id = "cas_og_menu_name",
 *   source_module = "og_menu"
 * )
 */
class CasOgMenuName extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a Migrate Process Plugin object.
   */
  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $migrate_database = Database::getConnection('default', 'migrate');
    $migrate_query = $migrate_database->select('og_menu', 'ogmenu');
    $migrate_query->fields('ogmenu', ['gid']);
    $migrate_query->condition('ogmenu.menu_name', $value);
    $og_id = $migrate_query->execute()->fetchField();
    if ($og_id !== FALSE) {
      /** @var \Drupal\group\Entity\Storage\GroupStorage $group_storage */
      $group_storage = $this->entityTypeManager->getStorage('group');
      /** @var \Drupal\group\Entity\Group $group */
      $group = $group_storage->load($og_id);
      if ($group === NULL) {
        throw new MigrateSkipRowException(sprintf('No migrated group %s for OG Menu %s.', $og_id, $value));
      }
      $group_menu_content = group_content_menu_get_menus_per_group($group);
      $group_menu_content = reset($group_menu_content);
      if (empty($group_menu_content)) {
        throw new MigrateSkipRowException(sprintf('No group menu for group %s (OG Menu %s).', $og_id, $value));
      }
      $group_content_menu_id = $group_menu_content->get('entity_id')
        ->first()
        ->getValue()['target_id'];
      return GroupContentMenuInterface::MENU_PREFIX . $group_content_menu_id;
    }
    throw new MigrateSkipRowException(sprintf('No group found for OG Menu %s.', $value));
  }

}
