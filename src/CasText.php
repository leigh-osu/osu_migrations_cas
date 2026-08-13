<?php

namespace Drupal\osu_migrations_cas;

/**
 * Small text-sanitising helpers shared by CAS migration pipelines.
 */
final class CasText {

  /**
   * Reduces a D7 rich link title to the plain text D10 link fields expect.
   *
   * 35 of the 3,497 D7 menu links carried markup in the TITLE column —
   * mostly the '<span class="count">$90M</span><br/>total R&D expenditures'
   * infographic pattern. D10 link titles are plain text and render escaped,
   * so the tags showed literally (e.g. the third menu bar on node 247167).
   * Tag boundaries become spaces so "Questions?<br/>Contact an advisor"
   * reads "Questions? Contact an advisor", entities are decoded (the field
   * re-escapes at render), and whitespace collapses.
   */
  public static function plainLinkTitle(?string $title): ?string {
    if ($title === NULL || $title === '') {
      return $title;
    }
    $text = preg_replace('/<[^>]*>/', ' ', $title);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
  }

}
