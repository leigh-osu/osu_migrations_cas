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
 *   element fall back to the theme color. Only style/class/font ATTRIBUTES
 *   are touched; embedded <style> blocks are left as-is.
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
    return $text;
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
