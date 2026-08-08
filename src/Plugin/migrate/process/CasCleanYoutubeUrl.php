<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Trims playlist junk from migrated YouTube watch URLs.
 *
 * D7 media stored some YouTube videos as youtube://v/<id>/l/<playlist>;
 * the converted watch URL then carries the playlist as extra path
 * segments (watch?v=<id>/l/<playlist>), which YouTube's oEmbed endpoint
 * rejects with a 400 — the video itself is fine. Keep just the video id.
 *
 * Inserted after media_remote_video in upgrade_d7_media_remote_video by
 * osu_migrations_cas_migration_plugins_alter(); cleanUrl() is also used
 * by the one-off repair sweep.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_clean_youtube_url"
 * )
 */
class CasCleanYoutubeUrl extends ProcessPluginBase {

  /**
   * Strips extra path segments after a YouTube watch video id.
   */
  public static function cleanUrl(?string $url): ?string {
    if ($url === NULL) {
      return NULL;
    }
    return preg_replace(
      '~^(https?://(?:www\.)?youtube\.com/watch\?v=[\w-]+)/.*$~',
      '$1',
      $url
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return is_string($value) ? static::cleanUrl($value) : $value;
  }

}
