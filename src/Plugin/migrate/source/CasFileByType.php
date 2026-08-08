<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\osu_migrations_media\Plugin\migrate\source\FileByType;

/**
 * FileByType with an opt-out for webform submission uploads.
 *
 * The private-scheme media migrations sweep every D7 private file, which
 * wrapped ~2.3k webform submission uploads (applicant CVs and the like) in
 * media entities. The files themselves must migrate (webform submissions
 * reference them) but they do not belong in the media library, where every
 * editor can browse and search them. With exclude_webform_only: true the
 * source drops files whose ONLY D7 usage is the webform module; a file
 * also used by content keeps its media entity.
 *
 * @MigrateSource(
 *  id = "cas_d7_file_by_type",
 *  source_module = "file"
 * )
 */
class CasFileByType extends FileByType {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    if (!empty($this->configuration['exclude_webform_only'])) {
      $other_usage = $this->select('file_usage', 'o')
        ->fields('o', ['fid'])
        ->condition('o.module', 'webform', '<>');
      $webform_only = $this->select('file_usage', 'w')
        ->fields('w', ['fid'])
        ->condition('w.module', 'webform')
        ->condition('w.fid', $other_usage, 'NOT IN');
      $query->condition('f.fid', $webform_only, 'NOT IN');
    }
    return $query;
  }

}
