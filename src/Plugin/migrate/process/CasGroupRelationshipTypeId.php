<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolve a group type + relation plugin to its relationship type id.
 *
 * Group derives the id of a group_content_type from the group type and the
 * relation plugin: "<group_type>-<plugin_id>" with colons replaced by dashes,
 * falling back to "group_content_type_<md5 prefix>" when that exceeds
 * EntityTypeInterface::BUNDLE_MAX_LENGTH (32). So the readable id flips to an
 * opaque hash purely on length — renaming a node bundle from "profile" to
 * "osu_profile" is enough to change it.
 *
 * Hard-coding the result in a migration means the value silently stops matching
 * if a bundle is ever renamed, and the failure surfaces far away: Group's
 * admin UI computes the id itself, finds no entity, and fatals on a null
 * relationship type. Asking the storage handler for the id keeps the migration
 * readable and correct by construction.
 *
 * @code
 * type:
 *   plugin: cas_group_relationship_type_id
 *   group_type: basic_group
 *   relation_plugin: 'group_node:osu_profile'
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "cas_group_relationship_type_id",
 *   handle_multiples = FALSE
 * )
 */
class CasGroupRelationshipTypeId extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
    foreach (['group_type', 'relation_plugin'] as $key) {
      if (empty($this->configuration[$key])) {
        throw new \InvalidArgumentException(sprintf('cas_group_relationship_type_id requires a "%s".', $key));
      }
    }
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
    $group_type = $this->configuration['group_type'];
    $relation_plugin = $this->configuration['relation_plugin'];

    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('group_content_type');
    $relationship_type_id = $storage->getRelationshipTypeId($group_type, $relation_plugin);

    // The id is computed, so it is returned whether or not the matching config
    // entity exists. Load it to be sure the plugin is actually installed on the
    // group type: without this the migration would happily write rows against a
    // bundle that does not exist.
    if ($storage->load($relationship_type_id) === NULL) {
      throw new MigrateSkipRowException(sprintf('Relation plugin %s is not installed on group type %s (expected relationship type %s).', $relation_plugin, $group_type, $relationship_type_id));
    }

    return $relationship_type_id;
  }

}
