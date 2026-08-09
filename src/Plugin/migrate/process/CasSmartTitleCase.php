<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * AP-style Title Case that refuses to break names, acronyms and tokens.
 *
 * Rules, in order:
 * - a word with a capital anywhere after its first letter is never touched
 *   (McCarthy, USDA, PhD, OSU's, iPhone);
 * - a word must START with a letter to be touched (9th, 8773rev, 100%,
 *   [node:...] tokens are split out and skipped entirely);
 * - single-letter initials (A. / A) and name particles / latin
 *   abbreviations (de, van, von, spp., var., ...) are left as they are;
 * - AP small words (articles, conjunctions and prepositions of three
 *   letters or fewer) are lowercased unless first or last word — every
 *   word of four or more letters is capitalized (From, With, Through);
 * - hyphen/slash compounds title-case each part (Small-Scale), the
 *   edge-of-title rule applying to the outermost parts only.
 *
 * @MigrateProcessPlugin(
 *   id = "cas_smart_title_case"
 * )
 */
class CasSmartTitleCase extends ProcessPluginBase {

  protected const SMALL_WORDS = [
    'a', 'an', 'the',
    'and', 'but', 'or', 'nor', 'for', 'so', 'yet',
    'as', 'at', 'by', 'if', 'in', 'of', 'off', 'on', 'out', 'per', 'to', 'up', 'via', 'vs',
  ];

  protected const LEAVE_AS_IS = [
    // Name particles.
    'de', 'del', 'della', 'da', 'das', 'dos', 'du', 'la', 'le', 'van', 'von', 'der', 'den', 'ter', 'ten', 'y', 'e',
    // Latin / botanical abbreviations.
    'spp', 'sp', 'var', 'subsp', 'ssp', 'cv', 'et', 'al',
  ];

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!is_string($value) || trim($value) === '') {
      return $value;
    }
    // Whitespace and [tokens] survive verbatim as their own segments.
    $segments = preg_split('/(\s+|\[[^\]]*\])/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $word_keys = [];
    foreach ($segments as $i => $segment) {
      if (trim($segment) !== '' && !str_starts_with($segment, '[')) {
        $word_keys[] = $i;
      }
    }
    if (!$word_keys) {
      return $value;
    }
    $first = reset($word_keys);
    $last = end($word_keys);
    foreach ($word_keys as $i) {
      $segments[$i] = $this->word($segments[$i], $i === $first, $i === $last);
    }
    return implode('', $segments);
  }

  /**
   * Title-cases one whitespace-delimited word, part by part.
   */
  protected function word(string $word, bool $is_first, bool $is_last): string {
    $parts = preg_split('~([-/])~u', $word, -1, PREG_SPLIT_DELIM_CAPTURE);
    $part_keys = [];
    foreach ($parts as $i => $part) {
      if ($part !== '-' && $part !== '/' && $part !== '') {
        $part_keys[] = $i;
      }
    }
    foreach ($part_keys as $n => $i) {
      $parts[$i] = $this->part(
        $parts[$i],
        ($is_first && $n === 0) || ($is_last && $n === count($part_keys) - 1)
      );
    }
    return implode('', $parts);
  }

  /**
   * Title-cases one hyphen-free part.
   */
  protected function part(string $part, bool $edge): string {
    // Must start with a letter (leaves 9th, 8773rev, 100% alone).
    if (!preg_match('/^\p{L}/u', $part)) {
      return $part;
    }
    // A capital after the first letter: hands off.
    if (preg_match('/\p{Lu}/u', mb_substr($part, 1))) {
      return $part;
    }
    // Initials: single letter, optionally followed by a period.
    if (preg_match('/^\p{L}\.?$/u', preg_replace('/[)\'"’”\],.:;!?]+$/u', '', $part))
      && mb_substr($part, 0, 1) === mb_strtoupper(mb_substr($part, 0, 1))) {
      return $part;
    }
    $bare = mb_strtolower(preg_replace('/[^\p{L}]+$/u', '', $part));
    if (in_array($bare, self::LEAVE_AS_IS, TRUE)) {
      return $part;
    }
    $head = mb_substr($part, 0, 1);
    $tail = mb_substr($part, 1);
    if (!$edge && in_array($bare, self::SMALL_WORDS, TRUE)) {
      return mb_strtolower($head) . $tail;
    }
    return mb_strtoupper($head) . $tail;
  }

}
