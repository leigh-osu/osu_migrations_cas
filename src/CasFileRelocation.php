<?php

namespace Drupal\osu_migrations_cas;

use Drupal\Core\Database\Database;

/**
 * Spreads D7's flat public files root into year subdirectories.
 *
 * D7 dropped 19,260 files directly into sites/agscid7/files — enough that
 * listing the directory takes about a minute even on the CLI, and slow enough
 * to hurt backups, deploys and any tooling that walks the tree. The migration
 * is the moment to fix it: the public files URL is already changing
 * (/sites/agscid7/files/ -> /sites/agsci.oregonstate.edu/files/), so moving a
 * file adds no breakage that the rename was not already causing, and the
 * rewrite machinery for hardcoded URLs already exists.
 *
 * Root-level files move to public://<year>/<name>, keyed on the D7
 * file_managed timestamp — 18,236 managed files spread over 2014..2026 in
 * buckets of 312 to 3,496. Two deliberate exclusions:
 * - Files already in a subdirectory (33,923 of them) are left exactly where
 *   they are: they are organised, and moving them is risk for no gain.
 * - Files with no file_managed row (~1,000 unmanaged ones that only exist on
 *   the D7 filesystem) have no timestamp to key on, so they stay in the root.
 *
 * Nothing else needs to change for images, which are 90% of the root and are
 * referenced through media entities by file ID. Only hardcoded URLs — mostly
 * the ~1,758 documents — need rewriting, which CasLegacyFilePaths does using
 * this same mapping so the two can never disagree.
 *
 * @see \Drupal\osu_migrations_cas\Plugin\migrate\process\CasRelocateFileUri
 * @see \Drupal\osu_migrations_cas\Plugin\migrate\process\CasLegacyFilePaths
 */
class CasFileRelocation {

  /**
   * Per-request cache of relative path => relocated relative path.
   */
  protected static array $resolved = [];

  /**
   * Relocates a public:// URI.
   *
   * @param string $uri
   *   The D7 file URI, e.g. public://flyer.pdf.
   * @param int|null $timestamp
   *   The file's D7 timestamp when the caller already has it (the file
   *   migration does); otherwise it is looked up.
   *
   * @return string
   *   The relocated URI, or the original when it is not a relocatable
   *   root-level public file.
   */
  public static function uri(string $uri, ?int $timestamp = NULL): string {
    if (!str_starts_with($uri, 'public://')) {
      return $uri;
    }
    $relative = substr($uri, strlen('public://'));
    return 'public://' . self::relativePath($relative, $timestamp);
  }

  /**
   * Relocates a path relative to the public files root.
   *
   * @param string $relative
   *   Path relative to the files root, e.g. "flyer.pdf" or "main/flyer.pdf".
   * @param int|null $timestamp
   *   The file's D7 timestamp, when known.
   *
   * @return string
   *   The relocated relative path, or the original when it stays put.
   */
  public static function relativePath(string $relative, ?int $timestamp = NULL): string {
    // Already in a subdirectory, or nothing to work with.
    if ($relative === '' || str_contains($relative, '/')) {
      return $relative;
    }
    if ($timestamp !== NULL) {
      return $timestamp > 0 ? date('Y', $timestamp) . '/' . $relative : $relative;
    }
    if (array_key_exists($relative, self::$resolved)) {
      return self::$resolved[$relative];
    }
    $looked_up = self::lookupTimestamp($relative);
    return self::$resolved[$relative] = $looked_up === NULL
      ? $relative
      : date('Y', $looked_up) . '/' . $relative;
  }

  /**
   * Reads a root-level file's D7 timestamp.
   *
   * @return int|null
   *   The timestamp, or NULL when the file is unmanaged (or the D7 database
   *   is not reachable, in which case the file must stay where it is rather
   *   than be relocated inconsistently).
   */
  protected static function lookupTimestamp(string $relative): ?int {
    try {
      $database = Database::getConnection('default', 'migrate');
    }
    catch (\Exception $e) {
      return NULL;
    }
    try {
      $timestamp = $database->select('file_managed', 'f')
        ->fields('f', ['timestamp'])
        ->condition('f.uri', 'public://' . $relative)
        ->range(0, 1)
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      return NULL;
    }
    return $timestamp ? (int) $timestamp : NULL;
  }

}
