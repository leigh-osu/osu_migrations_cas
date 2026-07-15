<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a D7 public-files URL to a D10 document media entity id.
 *
 * Some D7 content (e.g. the enterprise_budgets field_pdf / field_aeb_xls
 * link_field columns) stores a URL to a public file rather than a managed-file
 * reference. Many of those files were never registered in the D7
 * {file_managed} table, so a migration_lookup against upgrade_d7_files /
 * upgrade_d7_media_documents misses them.
 *
 * This plugin works purely from the physical file already staged in the
 * destination public files directory: it rewrites the URL to a `public://`
 * URI, and — if the file exists on disk — finds or creates a managed File and
 * a wrapping `document` Media entity, returning the media id. Both the File and
 * the Media are looked up by URI/fid before creation, so the plugin is
 * idempotent across migration re-runs. When the file is not present on disk
 * (e.g. an off-site URL or a dead reference) it returns NULL so a following
 * skip_on_empty leaves the field empty.
 *
 * Example:
 * @code
 * field_aeb_pdf:
 *   plugin: sub_process
 *   source: field_pdf
 *   process:
 *     target_id:
 *       - plugin: cas_file_url_to_media
 *         source: url
 *       - plugin: skip_on_empty
 *         method: process
 * @endcode
 *
 * Configuration:
 * - scheme: (optional) destination stream wrapper scheme. Defaults to 'public'.
 * - bundle: (optional) media bundle to create. Defaults to 'document'.
 * - source_field: (optional) media source field. Defaults to 'field_media_file'.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_file_url_to_media"
 * )
 */
class CasFileUrlToMedia extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritDoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value) || !is_string($value)) {
      return NULL;
    }

    $scheme = $this->configuration['scheme'] ?? 'public';
    $bundle = $this->configuration['bundle'] ?? 'document';
    $source_field = $this->configuration['source_field'] ?? 'field_media_file';

    // Rewrite an absolute or root-relative ".../sites/<site>/files/<path>" to
    // "<scheme>://<path>". Handles both "https://host/sites/x/files/..." and
    // "/sites/x/files/...".
    $uri = preg_replace('#^(https?://[^/]+)?/sites/[^/]+/files/#', $scheme . '://', trim($value));

    // If the URL did not match the public files pattern there is nothing to
    // resolve - leave the field empty rather than guess.
    if ($uri === trim($value) || strpos($uri, $scheme . '://') !== 0) {
      return NULL;
    }

    // The physical file must already be staged in the destination files
    // directory; if it is not present we cannot build usable media.
    $real_path = $this->fileSystem->realpath($uri);
    if (!$real_path || !is_file($real_path)) {
      return NULL;
    }

    $file_storage = $this->entityTypeManager->getStorage('file');
    $media_storage = $this->entityTypeManager->getStorage('media');

    // Find or create the managed File for this URI.
    $files = $file_storage->loadByProperties(['uri' => $uri]);
    $file = $files ? reset($files) : NULL;
    if (!$file) {
      $file = $file_storage->create([
        'uri' => $uri,
        'filename' => $this->fileSystem->basename($uri),
        'status' => 1,
      ]);
      $file->save();
    }
    $fid = $file->id();

    // Find or create the document Media wrapping this file.
    $existing = $media_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', $bundle)
      ->condition($source_field . '.target_id', $fid)
      ->range(0, 1)
      ->execute();
    if ($existing) {
      return reset($existing);
    }

    $media = $media_storage->create([
      'bundle' => $bundle,
      'name' => $this->fileSystem->basename($uri),
      'status' => 1,
      $source_field => [
        'target_id' => $fid,
        'display' => 1,
      ],
    ]);
    $media->save();

    return $media->id();
  }

}
