<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a group's canonical (home) domain from its D7 domain data.
 *
 * Expects a two-element source: [domain_source, domain_access_node] as
 * provided by the d7_node_domain_access source plugin, where domain_source is
 * the D7-pinned canonical domain machine name (empty when D7 used
 * DOMAIN_SOURCE_USE_ACTIVE = -5) and domain_access_node is the list of
 * assigned domains as [['target_id' => machine_name], ...].
 *
 * Resolution order:
 *   1. The D7-pinned domain_source, when set.
 *   2. The single assigned domain, when there is only one.
 *   3. The single non-default (non-top-level) domain, when exactly one.
 *   4. The first assigned domain.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_group_canonical_domain"
 * )
 */
class CasGroupCanonicalDomain extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    [$domain_source, $domain_access] = $value + [NULL, []];

    // 1. D7 pinned a canonical domain.
    if (is_string($domain_source) && $domain_source !== '') {
      return $domain_source;
    }

    $assigned = [];
    foreach ((array) $domain_access as $item) {
      if (!empty($item['target_id'])) {
        $assigned[] = $item['target_id'];
      }
    }
    if (!$assigned) {
      return NULL;
    }

    // 2. Only one domain assigned.
    if (count($assigned) === 1) {
      return $assigned[0];
    }

    // 3. Exactly one non-default (non-top-level) domain.
    $default = $this->entityTypeManager->getStorage('domain')->loadDefaultDomain();
    if ($default) {
      $non_default = array_values(array_diff($assigned, [$default->id()]));
      if (count($non_default) === 1) {
        return $non_default[0];
      }
    }

    // 4. Fall back to the first assigned domain.
    return $assigned[0];
  }

}
