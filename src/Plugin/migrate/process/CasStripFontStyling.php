<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\Component\Utility\Html;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Strips typeface and size styling from migrated rich text.
 *
 * D7 plant-variety-release content is full of pasted-in font coding:
 * style="font-family: ...; font-size: ..." declarations and legacy
 * <font face="..." size="..."> tags. Those fight the theme's typography,
 * so they are removed — but ONLY the typeface/size information. Semantic
 * styling survives untouched: <b>/<strong>/<em>, font-weight and
 * font-style declarations, and any other style declarations on the same
 * elements.
 *
 * Inserted after osu_media_wysiwyg_filter in the cas_pvr_to_pvr pipelines
 * by osu_migrations_cas_migration_plugins_alter(); stripText() is also
 * callable directly by one-off repair sweeps.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_strip_font_styling"
 * )
 */
class CasStripFontStyling extends ProcessPluginBase {

  /**
   * Removes font-family/font-size styling from an HTML string.
   */
  public static function stripText(?string $text): ?string {
    if ($text === NULL || $text === ''
      || (stripos($text, 'font-family') === FALSE
        && stripos($text, 'font-size') === FALSE
        && stripos($text, '<font') === FALSE)) {
      return $text;
    }
    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $changed = FALSE;

    // Inline styles: drop only the font-family / font-size declarations.
    // The explicit names keep font-weight (bold) and font-style (italic)
    // out of reach.
    foreach ($xpath->query('//*[@style]') as $el) {
      $style = $el->getAttribute('style');
      $clean = trim(preg_replace('~(?:^|;)\s*font-(?:family|size)\s*:[^;]*~i', '', $style), "; \t\n\r");
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

    // Legacy <font> tags: remove face/size, then unwrap the tag entirely
    // when nothing else (e.g. a color attribute) remains on it.
    $fonts = [];
    foreach ($xpath->query('//font') as $el) {
      $fonts[] = $el;
    }
    foreach ($fonts as $el) {
      foreach (['face', 'size'] as $attr) {
        if ($el->hasAttribute($attr)) {
          $el->removeAttribute($attr);
          $changed = TRUE;
        }
      }
      if (!$el->hasAttributes()) {
        while ($el->firstChild) {
          $el->parentNode->insertBefore($el->firstChild, $el);
        }
        $el->parentNode->removeChild($el);
        $changed = TRUE;
      }
    }

    return $changed ? Html::serialize($dom) : $text;
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (is_string($value)) {
      return static::stripText($value);
    }
    if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
      $value['value'] = static::stripText($value['value']);
    }
    return $value;
  }

}
