<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\webform_migrate\Plugin\migrate\source\d7\D7WebformSubmission;

/**
 * D7 webform submission source that fixes two webform_migrate mismatches.
 *
 * webform_migrate's D7Webform lowercases element keys (strtolower), but the
 * stock D7WebformSubmission keys the submitted data by the raw D7 form_key --
 * so components whose D7 key had uppercase (e.g. "..._OSU") render blank
 * because the element key is "..._osu". It also stores file-component values as
 * the raw D7 fid, which no longer exists in D10.
 *
 * This source lowercases the data keys to match the element keys and remaps
 * file fids through the file migrations (which run before submissions).
 *
 * @MigrateSource(
 *   id = "cas_d7_webform_submission",
 *   core = {7},
 *   source_module = "webform",
 *   destination_module = "webform"
 * )
 */
class CasD7WebformSubmission extends D7WebformSubmission {

  /**
   * {@inheritdoc}
   *
   * Reimplemented from the parent to lowercase the destination form key and
   * remap file-component fids; the parent has no seam to hook either in.
   */
  protected function buildSubmittedData($sid) {
    $query = $this->select('webform_submitted_data', 'wfsd');
    $query->innerJoin('webform_component', 'wc', 'wc.nid=wfsd.nid AND wc.cid=wfsd.cid');
    $query->fields('wfsd', ['no', 'data'])
      ->fields('wc', ['form_key', 'extra', 'type', 'pid']);
    $wf_submissions = $query->condition('sid', $sid)->execute();

    $submitted_data = [];
    foreach ($wf_submissions as $wf_submission) {
      $extra = unserialize($wf_submission['extra']);
      $src_form_key = $wf_submission['form_key'];
      $destination_form_key = $wf_submission['pid'] ? "{$src_form_key}_{$wf_submission['pid']}" : $src_form_key;
      // Match D7Webform::prepareRow(), which lowercases the element keys.
      $destination_form_key = strtolower($destination_form_key);
      $is_multiple = !empty($extra['multiple']) || $wf_submission['type'] === 'grid';

      // File components store the D7 fid; remap it to the migrated D10 file id.
      $data_value = $wf_submission['data'];
      if ($wf_submission['type'] === 'file' && $data_value !== NULL && $data_value !== '') {
        $data_value = $this->remapFileId($data_value);
      }

      $item = $is_multiple
        ? $submitted_data[$destination_form_key] ?? []
        : $data_value;

      if ($is_multiple && $data_value !== NULL && $data_value !== '') {
        $item[$wf_submission['no']] = $data_value;
      }

      // Cannot check !empty(), because that would exclude '0' and '' values.
      if ($item !== NULL && $item !== []) {
        $submitted_data[$destination_form_key] = $item;
      }
    }
    return $submitted_data;
  }

  /**
   * Remaps a D7 fid to its migrated D10 file id via the file migration maps.
   *
   * @param string|int $fid
   *   The D7 file id stored in the submission.
   *
   * @return string|int
   *   The D10 file id, or the original value if it is not a migrated fid.
   */
  protected function remapFileId($fid) {
    if (!ctype_digit((string) $fid)) {
      return $fid;
    }
    $db = \Drupal::database();
    foreach (['migrate_map_upgrade_d7_file_private', 'migrate_map_upgrade_d7_files'] as $table) {
      if ($db->schema()->tableExists($table)) {
        $dest = $db->query('SELECT destid1 FROM {' . $table . '} WHERE sourceid1 = :s', [':s' => (int) $fid])->fetchField();
        if ($dest) {
          return $dest;
        }
      }
    }
    return $fid;
  }

}
