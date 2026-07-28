<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Turns the D7 group short-name slug into a display-worthy short name.
 *
 * In D7, field_short_name held lowercase machine slugs (URL/domain prefixes:
 * "cbarc", "cropandsoil", "hyslop") rather than display text — only 5 of 179
 * values were ever entered with real casing. The breadcrumb and header lead
 * with this field, so the migration derives a human form using the group
 * title as evidence:
 *
 * 1. Explicit override map — curated display names for the slugs the rules
 *    below cannot resolve (mostly acronyms that do not match the title's
 *    initials, and renamed groups).
 * 2. Already mixed-case (FEPP, CormOceanProject) — editor-entered; kept.
 * 3. Segmentation — the slug is a concatenation of consecutive title words:
 *    "cropandsoil" -> "Crop and Soil", "jenniferfieldlab" ->
 *    "Jennifer Field Lab" (title casing preserved).
 * 4. Acronym — the slug spells the initials of the title words (with or
 *    without stopwords): "cbarc" -> "CBARC", "sorec" -> "SOREC".
 * 5. Fallback — ucwords over -/_ separators.
 *
 * Usage:
 * @code
 * field_group_short_name:
 *   plugin: cas_group_short_name
 *   source:
 *     - field_short_name
 *     - title
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "cas_group_short_name",
 *   handle_multiples = TRUE
 * )
 */
class CasGroupShortName extends ProcessPluginBase {

  /**
   * Curated display names for slugs the automatic rules cannot resolve.
   */
  protected const OVERRIDES = [
    '2020iyph' => '2020 IYPH',
    'academics2' => 'Academics 2',
    'aecs' => 'AECS',
    'agbiotech' => 'Ag Biotech',
    'ag_program_eou' => 'Ag Program at EOU',
    'anrs' => 'ANRS',
    'appliedecon' => 'Applied Economics',
    'bee' => 'BEE',
    'bee2' => 'BEE 2',
    'bpp' => 'BPP',
    'bpp-gsa' => 'BPP GSA',
    'brr' => 'BRR',
    'campusarb' => 'Campus Arboretum',
    'chapple-lab' => 'Chapple Lab',
    'climate-emotions' => 'Climate Emotions',
    'climate-smart-potato' => 'Climate-Smart Potato',
    'clubs-orgs' => 'Clubs & Organizations',
    'comes' => 'COMES',
    'comes2' => 'COMES 2',
    'dairy-center' => 'Dairy Center',
    'digital-ag' => 'Digital Ag',
    'emt-gs-guide' => 'EMT Grad Guide',
    'endophyte-lab' => 'Endophyte Lab',
    'environmental_chem' => 'Environmental Chemistry',
    'eoarcunion' => 'EOARC Union',
    'faces-agsci' => 'Faces of AgSci',
    'fec-lab' => 'FEC Lab',
    'fieldcropweeds' => 'Field Crop Weeds',
    'flock' => 'FLOCK',
    'foodsci' => 'Food Science',
    'foodweb' => 'Food Web',
    'fungicidebooklet' => 'Fungicide Booklet',
    'fw-gs-guide' => 'FW Grad Guide',
    'fwcs' => 'FWCS',
    'hort-dev' => 'Hort Dev',
    'hort_graduate' => 'Hort Graduate Program',
    'infews' => 'INFEWS',
    'jenniferduringerlab' => 'Duringer Lab',
    'juliayuecuilab' => 'Julia Cui Lab',
    'marcus-annalora-lab' => 'Marcus & Annalora Lab',
    'marine-social-sci' => 'Marine Social Science',
    'mes-weather' => 'MES Weather',
    'mi' => 'MI',
    'moretti-lab' => 'Moretti Lab',
    'newsroom' => 'Newsroom',
    'nurspest' => 'Nursery IPM',
    'oilseed-fiber-crops' => 'Oilseed & Fiber Crops',
    'oipmc' => 'OIPMC',
    'oipmc-old' => 'OIPMC (old)',
    'organic_grad_cert' => 'Organic Grad Certificate',
    'osuseafoodlab' => 'OSU Seafood Lab',
    'petersonsymposium' => 'Peterson Symposium',
    'residentialbeekeeper' => 'Residential Beekeeping',
    'schmidt-lab' => 'Schmidt Lab',
    'stacisimonichlab' => 'Simonich Lab',
    'state-fisheries-lab' => 'State Fisheries Lab',
    'sulikowskilab' => 'Sulikowski Lab',
    'vegweedsci' => 'Veg Weed Science',
    'weed-science-hort' => 'Weed Science Hort',
    'western-center-dce' => 'Western Center DCE',
  ];

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    [$short, $title] = $value + [NULL, ''];
    // D7 field values arrive as delta arrays.
    if (is_array($short)) {
      $short = $short[0]['value'] ?? reset($short) ?: NULL;
      if (is_array($short)) {
        $short = $short['value'] ?? NULL;
      }
    }
    if ($short === NULL || $short === '') {
      return NULL;
    }
    $short = trim((string) $short);
    $title = trim((string) $title);

    if (isset(self::OVERRIDES[$short])) {
      return self::OVERRIDES[$short];
    }

    // Editor-entered casing: trust it.
    if (preg_match('/[A-Z]/', $short)) {
      return $short;
    }

    // Title words with their original casing; apostrophes and hyphens stay
    // inside a word so "Park’s" segments as one unit.
    preg_match_all("/[\p{L}\p{N}]+(?:[-'’][\p{L}\p{N}]+)*/u", $title, $m);
    $words = $m[0];
    $norm = fn(string $w): string => mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $w));

    // Consecutive-title-word segmentation.
    $slug_norm = $norm($short);
    $count = count($words);
    for ($i = 0; $i < $count; $i++) {
      $acc = '';
      $take = [];
      for ($j = $i; $j < $count; $j++) {
        $acc .= $norm($words[$j]);
        $take[] = $words[$j];
        if ($acc === $slug_norm) {
          return implode(' ', $take);
        }
        if (strlen($acc) > strlen($slug_norm)) {
          break;
        }
      }
    }

    // Acronym of the title's initials (hyphen parts count separately),
    // tried with and without stopwords.
    $stop = ['and', 'of', 'the', 'for', 'in', 'on', 'to', 'a', 'an'];
    $letters = preg_replace('/[^a-z]/', '', mb_strtolower($short));
    foreach ([TRUE, FALSE] as $skip_stop) {
      $initials = '';
      foreach ($words as $w) {
        if ($skip_stop && in_array(mb_strtolower($w), $stop, TRUE)) {
          continue;
        }
        foreach (explode('-', $w) as $part) {
          if ($part !== '') {
            $initials .= mb_strtolower(mb_substr($part, 0, 1));
          }
        }
      }
      if ($letters !== '' && $letters === $initials) {
        return mb_strtoupper($short);
      }
    }

    // Fallback: word-case the separator parts.
    return implode(' ', array_map(
      fn($p) => mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1),
      preg_split('/[-_]+/', $short) ?: [$short]
    ));
  }

}
