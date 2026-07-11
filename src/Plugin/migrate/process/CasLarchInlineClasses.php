<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Rewrites inline larch text-color classes to native osu-text-* names.
 *
 * D7 editors used the larch theme's .osu-orange and .larch-white utility
 * classes directly in WYSIWYG markup (class attributes on spans, headings,
 * etc.). Those class names do not exist in D10; the targets are madrone's
 * native osu-text-* utilities (generated from its $osu-colors-text map),
 * which are also offered in the bootstrap_styles text-color palette.
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
   * Maps D7 larch inline text-color classes to native osu-text-* classes.
   */
  protected const INLINE_CLASS_MAP = [
    'osu-orange' => 'osu-text-osuorange',
    'larch-white' => 'osu-text-bucktoothwhite',
  ];

  /**
   * Rewrites mapped class names inside class attributes of an HTML string.
   *
   * Only tokens inside class="..." / class='...' attributes are touched, so
   * the same words appearing in prose, URLs or other attributes are left
   * alone.
   */
  public static function mapText(?string $text): ?string {
    if ($text === NULL || $text === '' || strpos($text, 'class') === FALSE) {
      return $text;
    }
    return preg_replace_callback(
      '/\bclass\s*=\s*(["\'])(.*?)\1/is',
      function (array $match): string {
        $classes = preg_split('/\s+/', trim($match[2])) ?: [];
        foreach ($classes as &$class) {
          $class = self::INLINE_CLASS_MAP[$class] ?? $class;
        }
        unset($class);
        return 'class=' . $match[1] . implode(' ', $classes) . $match[1];
      },
      $text
    );
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
