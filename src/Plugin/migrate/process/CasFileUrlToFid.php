<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Database\Database;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a D7 public-files URL to its source file_managed fid.
 *
 * Several D7 text fields (e.g. group field_information_sheet) store an absolute
 * URL to a public file rather than a managed-file reference. This plugin
 * rewrites that URL to a `public://` URI and looks the URI up in the migrate
 * source database's {file_managed} table, returning the fid so it can be fed to
 * a migration_lookup against a file/media migration.
 *
 * Example:
 * @code
 * field_group_info_sheet:
 *   plugin: sub_process
 *   source: field_information_sheet
 *   process:
 *     target_id:
 *       - plugin: cas_file_url_to_fid
 *         source: value
 *       - plugin: migration_lookup
 *         migration: upgrade_d7_media_documents
 *         no_stub: true
 *       - plugin: skip_on_empty
 *         method: process
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "cas_file_url_to_fid"
 * )
 */
class CasFileUrlToFid extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The migrate source database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connection = $connection;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // The migrate source DB key is configurable; default to 'migrate'.
    $key = $container->get('settings')->get('migrate_source_connection', 'migrate');
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      Database::getConnection('default', $key),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value) || !is_string($value)) {
      throw new MigrateSkipProcessException();
    }

    // Optional explicit scheme; defaults to public.
    $scheme = $this->configuration['scheme'] ?? 'public';

    // Rewrite ".../sites/<site>/files/<path>" to "<scheme>://<path>".
    $uri = preg_replace('#^https?://[^/]+/sites/[^/]+/files/#', $scheme . '://', trim($value));

    // If the URL did not match the public files pattern, there is nothing to
    // resolve - skip rather than guess.
    if ($uri === $value || strpos($uri, $scheme . '://') !== 0) {
      throw new MigrateSkipProcessException();
    }

    $fid = $this->connection->select('file_managed', 'fm')
      ->fields('fm', ['fid'])
      ->condition('uri', $uri)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$fid) {
      throw new MigrateSkipProcessException();
    }

    return $fid;
  }

}
