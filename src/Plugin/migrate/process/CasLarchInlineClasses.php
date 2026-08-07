<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Rewrites inline larch text-color markup in migrated rich text.
 *
 * Two jobs:
 * - .larch-white (used by D7 editors on dark backgrounds) is rewritten to
 *   madrone's native osu-text-bucktoothwhite utility.
 * - Orange text styling is REMOVED outright — brand-orange text is reserved
 *   for links in the D10 design, so the larch .osu-orange class, inline
 *   style="color: <orange>" declarations (mostly on Hx headings and spans)
 *   and <font color="<orange>"> attributes are all stripped, letting the
 *   element fall back to the theme color.
 * - Embedded <style> blocks keep their layout rules (table borders etc.)
 *   but lose any rule whose whole selector list targets links (a { ... },
 *   Word's a:link / span.MsoHyperlink): those repaint every link on the
 *   page and fight the theme's links-are-orange rule.
 *
 * The plugin is inserted after every osu_media_wysiwyg_filter step by
 * osu_migrations_cas_migration_plugins_alter(), and mapText() is called
 * directly by the CAS process plugins that transform rich text internally
 * (CasMediaWysiwygFilter, CasVerticalTabsItem, CasAccordianItem).
 *
 * @MigrateProcessPlugin(
 *   id = "cas_larch_inline_classes"
 * )
 */
class CasLarchInlineClasses extends ProcessPluginBase {

  /**
   * Maps D7 larch inline text-color classes; '' removes the class.
   */
  protected const INLINE_CLASS_MAP = [
    'osu-orange' => '',
    'larch-white' => 'osu-text-bucktoothwhite',
  ];

  /**
   * Brand oranges seen in D7 content: #d73f09 / #dc4405 and their rgb()
   * forms (case-insensitive; \s* around rgb components).
   */
  protected const ORANGE_PATTERN = '(?:#d73f09|#dc4405|rgb\(\s*215\s*,\s*63\s*,\s*9\s*\)|rgb\(\s*220\s*,\s*68\s*,\s*5\s*\))';

  /**
   * Rewrites color markup inside attributes of an HTML string.
   *
   * Only tokens inside class="..." / style="..." / <font color="..."> are
   * touched, so the same words appearing in prose, URLs or other attributes
   * are left alone.
   */
  public static function mapText(?string $text): ?string {
    if ($text === NULL || $text === '') {
      return $text;
    }
    if (strpos($text, 'class') !== FALSE) {
      $text = preg_replace_callback(
        '/\bclass\s*=\s*(["\'])(.*?)\1/is',
        function (array $match): string {
          $classes = preg_split('/\s+/', trim($match[2])) ?: [];
          foreach ($classes as &$class) {
            $class = self::INLINE_CLASS_MAP[$class] ?? $class;
          }
          unset($class);
          $classes = array_filter($classes, static fn ($c) => $c !== '');
          return 'class=' . $match[1] . implode(' ', $classes) . $match[1];
        },
        $text
      );
    }
    // Drop orange color declarations from inline style attributes; drop the
    // whole attribute when nothing else remains. The lookbehind keeps
    // background-color/border-color declarations out of reach.
    if (stripos($text, 'style') !== FALSE) {
      $text = preg_replace_callback(
        '/\s*\bstyle\s*=\s*(["\'])(.*?)\1/is',
        function (array $match): string {
          $style = preg_replace(
            '/(?<![-a-z])color\s*:\s*' . self::ORANGE_PATTERN . '\s*;?/i',
            '',
            $match[2]
          );
          $style = trim($style, " ;\t\n\r");
          return $style === '' ? '' : ' style=' . $match[1] . $style . $match[1];
        },
        $text
      );
    }
    // Legacy <font color="#d73f09"> attributes.
    $text = preg_replace(
      '/(<font\b[^>]*?)\s+color\s*=\s*(["\'])\s*' . self::ORANGE_PATTERN . '\s*\2/i',
      '$1',
      $text
    );
    // Embedded <style> blocks: drop rules that exist solely to restyle
    // links; keep everything else in the block.
    if (stripos($text, '<style') !== FALSE) {
      $text = preg_replace_callback(
        '~(<style\b[^>]*>)(.*?)(</style>)~is',
        function (array $m): string {
          $css = preg_replace_callback(
            '~([^{}]+)\{[^{}]*\}~s',
            function (array $rule): string {
              $selectors = array_map('trim', explode(',', trim($rule[1])));
              foreach ($selectors as $selector) {
                $linkish = preg_match('~^a([.:#\[][^\s{]*)?$~i', $selector)
                  || stripos($selector, 'MsoHyperlink') !== FALSE;
                if (!$linkish) {
                  // A non-link selector shares the rule; keep it whole.
                  return $rule[0];
                }
              }
              return '';
            },
            $m[2]
          );
          return $m[1] . $css . $m[3];
        },
        $text
      );
    }
    return static::normalizeButtons($text);
  }

  /**
   * Normalizes legacy D7 button markup to the CAS light scheme.
   *
   * D7 content carries Bootstrap-2/3 and larch-palette button variants
   * (btn-primary, btn-stratosphere, btn-moondust, color-active, inline
   * color styles, btn-large/small/mini sizes). CAS buttons are one look:
   * btn cas-button-light (manzanita), so drop the variant/color junk, map
   * the old size names, and let the scheme own the colors.
   */
  public static function normalizeButtons(?string $text): ?string {
    if ($text === NULL || $text === '' || stripos($text, 'btn') === FALSE) {
      return $text;
    }
    $dom = \Drupal\Component\Utility\Html::load($text);
    $xpath = new \DOMXPath($dom);
    $changed = FALSE;
    $size_map = [
      'btn-large' => 'btn-lg',
      'btn-small' => 'btn-sm',
      'btn-mini' => 'btn-sm',
      'btn-block' => 'w-100',
    ];
    foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " btn ")]') as $el) {
      $classes = preg_split('~\s+~', trim($el->getAttribute('class')));
      $out = [];
      $orange = FALSE;
      foreach ($classes as $class) {
        // D7 rendered btn-primary/btn-osu as OSU orange with white text
        // (osu_buttons.css) -- those become the dark scheme.
        if (preg_match('~^(btn-primary;?|btn-osu|osu-btn-primary)$~', $class)) {
          $orange = TRUE;
          $changed = TRUE;
          continue;
        }
        if ($class === 'color-active'
          || preg_match('~^btn-(secondary|info|success|warning|danger|default|navbar|link|inverse|stratosphere|teal|reindeer-moss|moondust|sand|pine-stand|luminance)$~', $class)) {
          $changed = TRUE;
          continue;
        }
        $out[] = $size_map[$class] ?? $class;
        if (isset($size_map[$class])) {
          $changed = TRUE;
        }
      }
      if (!in_array('cas-button-light', $out, TRUE) && !in_array('cas-button-dark', $out, TRUE)) {
        $out[] = $orange ? 'cas-button-dark' : 'cas-button-light';
        $changed = TRUE;
      }
      $el->setAttribute('class', implode(' ', array_unique($out)));
      $style = $el->getAttribute('style');
      if ($style !== '') {
        $clean = trim(preg_replace('~(?:^|;)\s*(?:color|background(?:-color)?)\s*:[^;]*~i', '', $style), "; \t");
        if ($clean !== $style) {
          $changed = TRUE;
          if ($clean === '') {
            $el->removeAttribute('style');
          }
          else {
            $el->setAttribute('style', $clean);
          }
        }
      }
    }
    return $changed ? \Drupal\Component\Utility\Html::serialize($dom) : $text;
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (is_string($value)) {
      return static::mapText($value);
    }
    // Field item arrays ('value'/'format' pairs) pass through unchanged apart
    // from their text value.
    if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
      $value['value'] = static::mapText($value['value']);
    }
    return $value;
  }

}
