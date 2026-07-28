<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Replaces the migrated footer block's social images with Font Awesome.
 *
 * The D7 footer block (block_custom bid 356, "LARCH bottom") links to the
 * college's social channels through <a><img></a> pairs whose images point at
 * the dead larch theme (/sites/all/themes/larch/images/social-icons/…) and
 * assorted other icon files. In the FOOTER BLOCK ONLY, every image inside a
 * social-network link becomes a Font Awesome brand icon (madrone bundles FA6
 * with the brands webfonts), keyed on the anchor's domain, with a
 * visually-hidden label as the accessible name.
 *
 * Deliberately scoped to the footer block: content elsewhere keeps its
 * images (video thumbnails link to YouTube, newsletters carry Mailchimp
 * icon art, departments have their own badge images) — see the bid gate in
 * transform().
 *
 * Inserted after every osu_media_wysiwyg_filter step alongside
 * cas_larch_inline_classes / cas_legacy_file_paths (see
 * osu_migrations_cas_migration_plugins_alter()); it no-ops for every row
 * that is not an allowlisted custom block.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_social_icon_images"
 * )
 */
class CasSocialIconImages extends ProcessPluginBase {

  /**
   * D7 block_custom bids whose social images become Font Awesome icons.
   */
  protected const FOOTER_BIDS = [356];

  /**
   * Network key => [href needles, FA classes, accessible label].
   */
  protected const NETWORKS = [
    'facebook' => [['facebook.com'], 'fa-brands fa-facebook-f', 'Facebook'],
    'instagram' => [['instagram.com'], 'fa-brands fa-instagram', 'Instagram'],
    'twitter' => [['twitter.com', '//x.com', 'www.x.com'], 'fa-brands fa-x-twitter', 'X (Twitter)'],
    'youtube' => [['youtube.com', 'youtu.be'], 'fa-brands fa-youtube', 'YouTube'],
    'linkedin' => [['linkedin.com'], 'fa-brands fa-linkedin-in', 'LinkedIn'],
    'tiktok' => [['tiktok.com'], 'fa-brands fa-tiktok', 'TikTok'],
    'flickr' => [['flickr.com'], 'fa-brands fa-flickr', 'Flickr'],
    'pinterest' => [['pinterest.com'], 'fa-brands fa-pinterest-p', 'Pinterest'],
  ];

  /**
   * Rewrites every image inside a social-network link in an HTML string.
   */
  public static function rewriteText(?string $text): ?string {
    if ($text === NULL || $text === '' || stripos($text, '<img') === FALSE) {
      return $text;
    }
    return preg_replace_callback(
      '~(?<open><a[^>]+href=["\'](?<href>[^"\']+)["\'][^>]*>)(?<inner>(?:(?!</a>).)*?<img(?:(?!</a>).)*?)(?<close></a>)~is',
      function (array $m): string {
        $network = self::network($m['href']);
        if ($network === NULL) {
          return $m[0];
        }
        [, $classes, $label] = self::NETWORKS[$network];
        $inner = preg_replace(
          '~<img[^>]*>~i',
          '<span class="' . $classes . ' fa-2x me-2" aria-hidden="true"></span>'
            . '<span class="visually-hidden">' . $label . '</span>',
          $m['inner']
        );
        // Hover text: the network name on the link (the old <img alt> role),
        // unless the editor already set a title.
        $open = $m['open'];
        if ($inner !== $m['inner'] && !preg_match('~\stitle=~i', $open)) {
          $open = preg_replace('~>$~', ' title="' . $label . '">', $open);
        }
        return $open . $inner . $m['close'];
      },
      $text
    );
  }

  /**
   * Which network a link href belongs to, or NULL.
   */
  protected static function network(string $href): ?string {
    $href = strtolower($href);
    foreach (self::NETWORKS as $key => [$needles]) {
      foreach ($needles as $needle) {
        if (str_contains($href, $needle)) {
          return $key;
        }
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Footer block rows only; every other migration row passes through.
    $bid = $row->getSourceProperty('bid');
    if ($bid === NULL || !in_array((int) $bid, self::FOOTER_BIDS, TRUE)) {
      return $value;
    }
    if (is_string($value)) {
      return static::rewriteText($value);
    }
    if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
      $value['value'] = static::rewriteText($value['value']);
    }
    return $value;
  }

}
