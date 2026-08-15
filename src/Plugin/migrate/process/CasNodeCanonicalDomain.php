<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Canonical (source) domain for a node: D7's pin, else its only domain.
 *
 * D7 stored -5 ("use active domain") for most nodes, which migrates as no
 * source — accurate, but group-based view grants union past Domain Access,
 * so single-domain content renders on domains it was never assigned to and
 * nothing redirects it home (e.g. the Applied Economics group home served
 * happily on agsci). When a node is accessible on exactly one domain there
 * is nothing to choose: that domain IS canonical, so pin it and Domain
 * Source's redirect brings strays back.
 *
 * Multi-domain nodes are left unpinned on purpose: D7 editors chose "use
 * active domain" for them, and forcing a canonical there would bounce
 * agsci readers to subsites on shared content — a policy change to make
 * deliberately, not en passant.
 *
 * Expects [domain_source, domain_access_node] like cas_group_canonical_domain.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_node_canonical_domain",
 *   handle_multiples = TRUE
 * )
 */
class CasNodeCanonicalDomain extends ProcessPluginBase {

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    [$source, $access] = $value;
    if (!empty($source) && is_string($source)) {
      return $source;
    }
    $domains = [];
    foreach ((array) $access as $item) {
      if (!empty($item['target_id'])) {
        $domains[$item['target_id']] = TRUE;
      }
    }
    return count($domains) === 1 ? array_key_first($domains) : NULL;
  }

}
