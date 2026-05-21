<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\Annotation\MigrateProcessPlugin;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\redirect\Entity\Redirect;

/**
 * Skips D7 path-redirect rows that collide on redirect.hash.
 *
 * The D7 source contains multiple redirect rows that reduce to the same
 * (source path + query + language) tuple. The redirect module derives a unique
 * `hash` from exactly those values, so the second row of each pair hits
 * "SQLSTATE[23000] 1062 Duplicate entry ... for key 'redirect.hash'" and the
 * whole row fails.
 *
 * This plugin reproduces Redirect::generateHash() for the current row and, if
 * a redirect with that hash already exists, skips the row (recorded as
 * ignored) instead of letting it fail. It is idempotent, so re-running the
 * migration is safe too. Otherwise it passes the value through unchanged.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_redirect_hash_dedupe"
 * )
 */
class CasRedirectHashDedupe extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $source_path = (string) $row->getSourceProperty('source');

    // Derive the source query the same way the redirect entity will: D7
    // source_options is a serialized array that may contain a 'query' array.
    $source_query = [];
    $source_options = $row->getSourceProperty('source_options');
    if (!empty($source_options)) {
      $options = @unserialize($source_options, ['allowed_classes' => FALSE]);
      if (is_array($options) && !empty($options['query']) && is_array($options['query'])) {
        $source_query = $options['query'];
      }
    }

    // The migration assigns 'und' when the D7 language is empty; match that so
    // the computed hash equals the one the saved entity will generate.
    $language = $row->getSourceProperty('language');
    if (empty($language)) {
      $language = 'und';
    }

    $hash = Redirect::generateHash($source_path, $source_query, $language);

    $existing = \Drupal::entityTypeManager()
      ->getStorage('redirect')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('hash', $hash)
      ->range(0, 1)
      ->execute();

    if (!empty($existing)) {
      throw new MigrateSkipRowException(sprintf(
        'Duplicate redirect hash for source "%s" (language "%s"); a redirect with this hash already exists, skipping rid %s.',
        $source_path,
        $language,
        (string) $row->getSourceProperty('rid')
      ));
    }

    return $value;
  }

}
