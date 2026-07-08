<?php

namespace Drupal\osu_migrations_cas;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\paragraphs_to_layout_builder\Exception\LayoutMigrationMissingBlockException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\paragraphs_to_layout_builder\LayoutBase;
use Drupal\paragraphs_to_layout_builder\LayoutMigrationItem;

/**
 * Base class for layout process plugins.
 *
 * @package Drupal\paragraphs_to_layout_builder
 */
class CasLayoutBase extends LayoutBase {

  /**
   * Maps D7 larch background classes to manzanita cas-bg-* classes.
   *
   * Class suffixes mirror the variable names in manzanita's
   * _cas_variables.scss and madrone's _osu_variables.scss, hyphenated
   * (e.g. $rogue_wave-400 -> cas-bg-rogue-wave-400). The classes are
   * generated from the $cas-bg-colors map in manzanita's
   * components/_cas_backgrounds.scss.
   */
  protected const LARCH_BACKGROUND_CLASS_MAP = [
    'larch-bg-orange' => 'cas-bg-osuorange',
    'larch-bg-white' => 'cas-bg-bucktoothwhite',
    'larch-bg-trans-white' => 'cas-bg-trans-white',
    'larch-bg-black' => 'cas-bg-black',
    'larch-bg-trans-black' => 'cas-bg-trans-black',
    'larch-bg-red' => 'cas-bg-alert-red',
    'larch-bg-pinestand' => 'cas-bg-pine-400',
    'larch-bg-hightide' => 'cas-bg-rogue-wave-400',
    'larch-bg-luminance' => 'cas-bg-luminance-400',
    'larch-bg-stratosphere' => 'cas-bg-stratosphere',
    'larch-bg-reindeermoss' => 'cas-bg-pine-200',
    'larch-bg-seafoam' => 'cas-bg-rogue-wave-200',
    'larch-bg-candela' => 'cas-bg-candela',
    'larch-bg-moondust' => 'cas-bg-moondust',
    'larch-bg-hopbine' => 'cas-bg-hop-bine',
    'larch-bg-roguewave' => 'cas-bg-rogue-wave-600',
    'larch-bg-solarflare' => 'cas-bg-luminance-600',
    'larch-bg-starcanvas' => 'cas-bg-stratosphere-600',
    'larch-bg-till' => 'cas-bg-till',
    'larch-bg-coastline' => 'cas-bg-coastline',
    'larch-bg-highdesert' => 'cas-bg-high-desert',
    'larch-bg-crater' => 'cas-bg-crater',
  ];

  /**
   * Translates a D7 larch background class to its manzanita class.
   *
   * @param string $larch_class
   *   The field_lp_background_color / field_lp_col_bg_color value.
   *
   * @return string
   *   The manzanita cas-bg-* class; unknown values fall back to a plain
   *   larch- to cas- prefix swap so they remain identifiable in markup.
   */
  protected static function mapLarchBackgroundClass(string $larch_class): string {
    return self::LARCH_BACKGROUND_CLASS_MAP[$larch_class]
      ?? str_replace('larch-', 'cas-', $larch_class);
  }

  /**
   * Maps paragraph bundle type to bootstrap layout builder section type.
   *
   * @param string $paragraphType
   *   Name of the paragraph bundle.
   *
   * @return string
   *   Name of the layout builder section
   */
  public static function getSectionType(string $paragraphType): string {
    /*
     * Paragraph Types of:
     *   paragraph_1_col_clean
     *   paragraph_divider
     *   paragraph_accordion
     *   paragraph_alert
     * all map to bootstrap_layout_builder:blb_col_1 along with any other
     * paragraph type not listed here specifically.
     */
    // Returns a BLB layout_option ID (e.g. blb_col_2_67_33). createSection()
    // resolves this to the underlying Layout Builder plugin (blb_col_2).
    return match ($paragraphType) {
      "paragraph_1_col",
      "1_column_background_video",
      "paragraph_3_col" => "bootstrap_layout_builder:blb_col_3_three_equal_columns",
      "4_column",
      "paragraph_menu" => "bootstrap_layout_builder:blb_col_4_four_equal_columns",
      "paragraph_2_col" => "bootstrap_layout_builder:blb_col_2_two_equal_columns",
      "2_column_4_8" => "bootstrap_layout_builder:blb_col_2_33_67",
      "paragraph_2_column_8_4" => "bootstrap_layout_builder:blb_col_2_67_33",
      default => "bootstrap_layout_builder:blb_col_1"
    };
  }

  /**
   * Creates a Layout Builder section.
   *
   * @param string $layout
   *   The layout template id to use for this section.
   * @param \Drupal\layout_builder\SectionComponent[] $components
   *   An array of section components to add to the section.
   * @param array $settings
   *   An array of settings for the layout.
   *
   * @return \Drupal\layout_builder\Section
   *   The created section.
   */
  public function createSection($layout, array $components = [], array $settings = []) {
    // Get default section settings and merge with passed in settings.
    $settings = $settings + $this->getDefaultSectionSettings($layout);
    // BLB layout_option IDs (e.g. blb_col_2_67_33) are section settings, not
    // Layout Builder plugin IDs. Resolve to the real plugin (blb_col_N).
    $real_layout = preg_match('/^bootstrap_layout_builder:(blb_col_\d+)/', $layout, $m)
      ? 'bootstrap_layout_builder:' . $m[1]
      : $layout;
    return new Section($real_layout, $settings, $components);
  }

  /**
   * Gets default section settings for the given $layout.
   *
   * @param string $layout
   *   The layout template id to use for this section.
   *
   * @return array
   *   default section settings for type $layout
   */
  private static function getDefaultSectionSettings($layout) {
    switch ($layout) {
      case 'bootstrap_layout_builder:blb_col_1':
        return ['container' => 'container'];

      case 'bootstrap_layout_builder:blb_col_2':
      case 'bootstrap_layout_builder:blb_col_2_two_equal_columns':
        return [
          'breakpoints' => [
            'extra_wide_desktop' => 'blb_col_2_two_equal_columns',
            'desktop' => 'blb_col_2_two_equal_columns',
            'tablet' => 'blb_col_2_two_equal_columns',
            'mobile' => 'blb_col_1_full_width',
          ],
          'layout_regions_classes' => [
            'blb_region_col_1' => [
              'col-xxl-6',
              'col-lg-6',
              'col-md-6',
              'col-12',
            ],
            'blb_region_col_2' => [
              'col-xxl-6',
              'col-lg-6',
              'col-md-6',
              'col-12',
            ],
          ],
          'container' => 'container-fluid',
          'remove_gutters' => '0',
        ];

      case 'bootstrap_layout_builder:blb_col_2_33_67':
        return [
          'breakpoints' => [
            'extra_wide_desktop' => 'blb_col_2_33_67',
            'desktop' => 'blb_col_2_33_67',
            'tablet' => 'blb_col_2_33_67',
            'mobile' => 'blb_col_1_full_width',
          ],
          'layout_regions_classes' => [
            'blb_region_col_1' => [
              'col-xxl-4',
              'col-lg-4',
              'col-md-4',
              'col-12',
            ],
            'blb_region_col_2' => [
              'col-xxl-8',
              'col-lg-8',
              'col-md-8',
              'col-12',
            ],
          ],
          'container' => 'container-fluid',
          'remove_gutters' => '0',
        ];

      case 'bootstrap_layout_builder:blb_col_2_67_33':
        return [
          'breakpoints' => [
            'extra_wide_desktop' => 'blb_col_2_67_33',
            'desktop' => 'blb_col_2_67_33',
            'tablet' => 'blb_col_2_67_33',
            'mobile' => 'blb_col_1_full_width',
          ],
          'layout_regions_classes' => [
            'blb_region_col_1' => [
              'col-xxl-8',
              'col-lg-8',
              'col-md-8',
              'col-12',
            ],
            'blb_region_col_2' => [
              'col-xxl-4',
              'col-lg-4',
              'col-md-4',
              'col-12',
            ],
          ],
          'container' => 'container-fluid',
          'remove_gutters' => '0',
        ];

      case 'bootstrap_layout_builder:blb_col_3':
      case 'bootstrap_layout_builder:blb_col_3_three_equal_columns':
        return [
          'breakpoints' => [
            'extra_wide_desktop' => 'blb_col_3_three_equal_columns',
            'desktop' => 'blb_col_3_three_equal_columns',
            'tablet' => 'blb_col_3_three_equal_columns',
            'mobile' => 'blb_col_1_full_width',
          ],
          'layout_regions_classes' => [
            'blb_region_col_1' => [
              'col-xxl-4',
              'col-lg-4',
              'col-md-4',
              'col-12',
            ],
            'blb_region_col_2' => [
              'col-xxl-4',
              'col-lg-4',
              'col-md-4',
              'col-12',
            ],
            'blb_region_col_3' => [
              'col-xxl-4',
              'col-lg-4',
              'col-md-4',
              'col-12',
            ],
          ],
          'container' => 'w-100',
          'remove_gutters' => '1',
        ];

      case 'bootstrap_layout_builder:blb_col_4':
      case 'bootstrap_layout_builder:blb_col_4_four_equal_columns':
        return [
          'breakpoints' => [
            'desktop' => 'blb_col_4_four_equal_columns',
            'tablet' => 'blb_col_4_four_equal_columns',
            'mobile' => 'blb_col_1_full_width',
          ],
          'layout_regions_classes' => [
            'blb_region_col_1' => [
              'col-lg-3',
              'col-md-3',
              'col-12',
            ],
            'blb_region_col_2' => [
              'col-lg-3',
              'col-md-3',
              'col-12',
            ],
            'blb_region_col_3' => [
              'col-lg-3',
              'col-md-3',
              'col-12',
            ],
            'blb_region_col_4' => [
              'col-lg-3',
              'col-md-3',
              'col-12',
            ],
          ],
          'container' => 'container',
          'remove_gutters' => '0',
        ];

      default:
        return [];
    }
  }

  /**
   * Creates a component from a paragraph.
   *
   * @param \Drupal\paragraphs_to_layout_builder\LayoutMigrationItem $item
   *   A migration item instance.
   * @param \Drupal\layout_builder\Section $section
   *   The layout builder section this block will be applied to.
   * @param string $row
   *   The region the component belongs within.
   *
   * @return \Drupal\layout_builder\SectionComponent[]
   *   A Layout Builder SectionComponent.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\migrate\MigrateException
   * @throws \Drupal\paragraphs_to_layout_builder\Exception\LayoutMigrationMissingBlockException
   */
  public function createComponent(LayoutMigrationItem $item, Section $section, string $row = 'blb_region_col_1') {
    $block_id = $this->lookupBlock($item->getMigrationId(), $item->getId());

    $query = $this->db->select('block_content_field_data', 'b')
      ->fields('b', ['type'])
      ->condition('b.id', $block_id, '=');
    $block_type = $query->execute()->fetchField();
    if (!$block_type) {
      throw new MigrateException(sprintf('An unknown error occurred trying to find the block type from migration item type %s with id %s.', $item->getType(), $item->getId()));
    }

    // Get block and set any additional settings on component
    // or section as needed.
    $block_revision_id = $this->blockContentStorage->getLatestRevisionId($block_id);

    /** @var \Drupal\block_content\Entity\BlockContent $block */
    $block = $this->entityTypeManager->getStorage('block_content')
      ->load($block_id);

    // Menu Paragraph Bundle.
    if ($item->getMigrationId() == 'paragraph_menu__to__layout_builder') {
      return $this->handleMenuItems($block);
    }

    // Adjustable Columns
    if ($item->getMigrationId() == 'paragraph_lp_adjustable_columns__to__layout_builder') {
      return $this->handleAdjustableColumnsItems($block, $section, $row, $item);
    }

    // Set column settings for 1_col (and its background-video variant, which
    // shares the same content field and p-left/p-right styles vocabulary).
    if ($item->getMigrationId() === 'paragraph_1_col__to__layout_builder'
      || $item->getMigrationId() === 'paragraph_1_column_background_video__to__layout_builder') {
      if ($block->get('field_styles')->value != NULL) {
        if (str_contains($block->get('field_styles')->value, 'left')) {
          $row = 'blb_region_col_1';
        }
        elseif (str_contains($block->get('field_styles')->value, 'right')) {
          $row = 'blb_region_col_3';
        }
      }
      else {
        $row = 'blb_region_col_2';
      }
    }

    $additional = $this->getAdditionalBlockSettings($block, $row, $item);
    $this->setAdditionalSectionSettings($section, $block, $item);

    return [$this->createSectionComponent($block_type, $block_revision_id, $row, $additional, $item->getDelta())];
  }

  /**
   * Looks up a block from a given migration.
   *
   * @param string $migration_id
   *   The migration id to search.
   * @param string $id
   *   The source id from the migration.
   *
   * @return int
   *   The block id of the located block.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\migrate\MigrateException
   * @throws \Drupal\paragraphs_to_layout_builder\Exception\LayoutMigrationMissingBlockException
   */
  public function lookupBlock($migration_id, $id) {
    $source = [$id];
    $block_ids = $this->migrateLookup->lookup($migration_id, $source);
    if (empty($block_ids)) {
      throw new LayoutMigrationMissingBlockException(
        sprintf('Unable to find related migrated block for source id %s in migration %s', $id, $migration_id),
        MigrationInterface::MESSAGE_WARNING
      );
    }

    return reset($block_ids)['id'];
  }

  /**
   * Additional blocks need to be queried for menu items.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block
   *   The block containing IDs of the menu item blocks.
   *
   * @return array
   *   layout builder block settings array
   */
  protected function handleMenuItems($block) {
    $block_ids = explode(',', $block->get('body')->value);
    $components = [];
    foreach ($block_ids as $index => $block_id) {
      $query = $this->db->select('block_content_field_data', 'b')
        ->fields('b', ['type'])
        ->condition('b.id', $block_id, '=');
      $block_type = $query->execute()->fetchField();
      $block_revision_id = $this->blockContentStorage->getLatestRevisionId($block_id);
      $row = 'blb_region_col_' . ($index + 1);
      $components[] = $this->createSectionComponent($block_type, $block_revision_id, $row, [], 0);
    }
    return $components;
  }

  /**
   * Additional blocks need to be queried for adjustable columns items.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block
   *   The block containing IDs of the adjustable columns item blocks.
   * @param \Drupal\paragraphs_to_layout_builder\LayoutMigrationItem $item
   *   A migration item instance.
   * @param \Drupal\layout_builder\Section $section
   *   The layout builder section this block will be applied to.
   * @param string $row
   *   The region the component belongs within.
   * @return array
   *   layout builder block settings array
   */
  protected function handleAdjustableColumnsItems($block, $section, $row, $item) {
    // top block is the controlling row element
    $row_extra_data = unserialize($block->get('field_block_serialized_data')->value);
    $row_data = $row_extra_data['migration']['adjustable_columns_section'];
    $block_ids = explode(',', $row_data['attached_block_ids']);
    $components = [];

    // Apply row-level settings (background, etc.) from the top block to the
    // section itself.
    $this->setAdjustableColumnsSectionSettings($section, $row_data);

    //now deal with the attached blocks
    foreach ($block_ids as $index => $block_id) {
      $attached_block = $this->entityTypeManager->getStorage('block_content')->load($block_id);
      $attached_block_extra_data = unserialize($attached_block->get('field_block_serialized_data')->value);
      $block_revision_id = $this->blockContentStorage->getLatestRevisionId($block_id);
      $block_type = 'paragraph_block';

      $attached_block_additional_settings = [];
      $elements = [
        'block_attributes',
        'block_title_attributes',
        'block_content_attributes',
      ];

      foreach ($elements as $element) {
        $attached_block_additional_settings['component_attributes'][$element] = [
          'id' => '',
          'class' => '',
          'style' => '',
          'data' => '',
        ];
      }

      $classes = [];
      $styles = [];

      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_xs_width'][0]['value'])) {
        $classes[] = 'col-xs-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_xs_width'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_xs_offset'][0]['value'])) {
        $classes[] = 'offset-xs-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_xs_offset'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_sm_width'][0]['value'])) {
        $classes[] = 'col-sm-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_sm_width'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_sm_offset'][0]['value'])) {
        $classes[] = 'offset-sm-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_sm_offset'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_md_width'][0]['value'])) {
        $classes[] = 'col-md-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_md_width'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_md_offset'][0]['value'])) {
        $classes[] = 'offset-md-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_md_offset'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_lg_width'][0]['value'])) {
        $classes[] = 'col-lg-' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_lg_width'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_lg_offset'][0]['value'])) {
        $classes[] = 'offset-lg-' . $attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_lg_offset'][0]['value'];
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_padding'][0]['value'])) {
        if (str_contains($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_padding'][0]['value'], '{')) {
          $styles[] = $attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_padding'][0]['value'];
        } else{
          $styles[] = 'padding: ' .$attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_padding'][0]['value'] .';';
        }
      }

      // multiple value fields
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_class'])) {
        foreach ($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_class'] as $key => $value) {
          if (str_contains($value['value'], '{')) {
            $styles[] = $value['value'];
          } else {
            $classes[] = $value['value'];;
          }
        }
      }
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_style'])) {
        foreach ($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_style'] as $key => $value) {
          if (str_contains($value['value'], '{')) {
            $styles[] = $value['value'];
          } else{
            $styles[] = $value['value'];
          }
        }
      }

      //backgrounds
      if (isset($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_bg_color'][0]['value'])) {
        $background_color = self::mapLarchBackgroundClass($attached_block_extra_data['migration']['adjustable_columns_item']['field_lp_col_bg_color'][0]['value']);
        $attached_block_additional_settings['bootstrap_styles']['block_style']['background']['background_type'] = 'color';
        $attached_block_additional_settings['bootstrap_styles']['block_style']['background_color']['class'] = $background_color;
      }

      $attached_block_additional_settings['component_attributes']['block_attributes']['class'] = implode(' ', $classes);
      $attached_block_additional_settings['component_attributes']['block_attributes']['style'] = implode(' ', $styles);

//      'field_lp_col_view',
//      'field_lp_col_block'

      $component = $this->createSectionComponent($block_type, $block_revision_id, $row, $attached_block_additional_settings, $index);

      $components[] = $component;
    }

    return $components;
  }

  /**
   * Applies row-level adjustable columns settings to the section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The layout builder section the row settings apply to.
   * @param array $row_data
   *   The unserialized adjustable_columns_section migration data from the
   *   controlling row block.
   */
  protected function setAdjustableColumnsSectionSettings(Section $section, array $row_data): void {
    $settings = $section->getLayoutSettings();

    // Background video takes precedence, matching D7 where the video was
    // rendered over the row background. Resolved to a local video media id at
    // migrate time by the cas_adjustable_columns_section process plugin.
    if (!empty($row_data['field_lp_background_video']) && is_scalar($row_data['field_lp_background_video'])) {
      $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'video';
      $settings['container_wrapper']['bootstrap_styles']['background_media']['video']['media_id'] = (string) $row_data['field_lp_background_video'];
    }
    // Entity background, resolved to "mid,image|parallax" at migrate time by
    // the cas_adjustable_columns_section process plugin.
    elseif (!empty($row_data['eb_background']) && is_string($row_data['eb_background'])) {
      $eb_fc = explode(',', $row_data['eb_background']);
      $eb_fc_id = $eb_fc[0];
      $eb_fc_type = ($eb_fc[1] ?? '') === 'parallax' ? 'fixed' : 'not_fixed';
      $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'image';
      $settings['container_wrapper']['bootstrap_styles']['background_media']['image']['media_id'] = $eb_fc_id;
      $settings['container_wrapper']['bootstrap_styles']['background_media']['background_options'] = [
        'background_position' => 'center',
        'background_repeat' => 'no-repeat',
        'background_attachment' => $eb_fc_type,
        'background_size' => 'cover',
      ];
    }
    elseif (isset($row_data['field_lp_background_color'][0]['value'])) {
      $background_color = self::mapLarchBackgroundClass($row_data['field_lp_background_color'][0]['value']);
      $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
      $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = $background_color;
    }

    // Row padding, min-height and styles were free-form CSS values rendered
    // as inline styles on the row in D7 (see larch
    // paragraphs-item--lp-adjustable-columns.tpl.php), so carry them over
    // verbatim as inline styles on the section container wrapper — the same
    // element that gets the background above. Row classes go on the wrapper
    // as classes, also matching the D7 template.
    $styles = [];
    if (isset($row_data['field_lp_row_padding'][0]['value']) && trim($row_data['field_lp_row_padding'][0]['value']) !== '') {
      $styles[] = 'padding: ' . trim($row_data['field_lp_row_padding'][0]['value']) . ';';
    }
    if (isset($row_data['field_lp_row_min_height'][0]['value']) && trim($row_data['field_lp_row_min_height'][0]['value']) !== '') {
      $styles[] = 'min-height: ' . trim($row_data['field_lp_row_min_height'][0]['value']) . ';';
    }
    // Multiple value field: each style value gets a trailing ";" if missing.
    foreach ($row_data['field_lp_row_style'] ?? [] as $style_item) {
      $style = trim($style_item['value'] ?? '');
      if ($style !== '') {
        $styles[] = str_ends_with($style, ';') ? $style : $style . ';';
      }
    }
    if ($styles) {
      // Must be an array: bootstrap_styles appends to the style attribute
      // with [] (see BackgroundMedia::build()), which fatals on a string.
      $settings['container_wrapper_attributes']['style'] = $styles;
    }

    // Multiple value field: each class value is a wrapper class.
    $classes = [];
    foreach ($row_data['field_lp_row_class'] ?? [] as $class_item) {
      $class = trim($class_item['value'] ?? '');
      if ($class !== '') {
        $classes[] = $class;
      }
    }
    if ($classes) {
      $settings['container_wrapper_attributes']['class'] = $classes;
    }

    $section->setLayoutSettings($settings);
  }

  /**
   * Creates a layout builder section component.
   *
   * @param string $block_type
   *   The block type machine name to embed as an inline block for.
   * @param int|string $block_latest_revision_id
   *   The numeric block content revision id.
   * @param string $row
   *   The weight of the component.
   * @param array $additional
   *   The region of the layout the component will reside in.
   * @param int $weight
   *   Additional section settings.
   * @param string $view_mode
   *   The view mode of component
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   Returns the layout builder section component that gets added.
   */
  public function createSectionComponent($block_type, $block_latest_revision_id, $row, array $additional, $weight = 0, $view_mode = 'full') {
    return SectionComponent::fromArray([
      'uuid' => $this->uuid->generate(),
      'region' => $row,
      'configuration' => [
        'id' => "inline_block:{$block_type}",
        'label' => 'Layout Builder Inline Block',
        'provider' => 'layout_builder',
        'label_display' => '0',
        'view_mode' => $view_mode,
        'block_revision_id' => $block_latest_revision_id,
        'block_serialized' => NULL,
        'context_mapping' => [],
      ],
      'additional' => $additional,
      'weight' => $weight,
    ]);
  }

  /**
   * Gets additional settings applied to the block based on field_styles.
   *
   * @param \Drupal\block_content\Entity\BlockContent|\Drupal\Core\Block\BlockPluginInterface $block
   *   The block we need settings for.
   * @param string $row
   *   The region the component belongs within.
   * @param \Drupal\paragraphs_to_layout_builder\LayoutMigrationItem $item
   *   A migration item instance.
   *
   * @return array
   *   layout builder block settings array
   */
  protected function getAdditionalBlockSettings($block, string $row, LayoutMigrationItem $item) {
    $additional = [];
    // Set a default padding.
    $additional['bootstrap_styles']['block_style']['padding']['class'] = 'p-4-5';
    if ($block->bundle() === 'paragraph_block' && $block->get('field_styles') && (
        ($row == 'blb_region_col_1' && str_contains($block->get('field_styles')->value, 'black-bg-left'))
        || ($row == 'blb_region_col_2' && str_contains($block->get('field_styles')->value, 'black-bg-right')))) {
      // 2 column additional settings
      $additional = [
        'bootstrap_styles' => [
          'block_style' => [
            'background' => [
              'background_type' => 'color',
            ],
            'background_color' => [
              'class' => 'osu-bg-page-alt-2',
            ],
            'text_color' => [
              'class' => 'osu-text-bucktoothwhite',
            ],
            'padding' => [
              'class' => 'p-3',
            ],
            'margin' => [
              'class' => 'm-1',
            ],
          ],
        ],
      ];
    }
    elseif ($item->getType() == 'paragraph_2_col' &&
      $block->hasField('field_eb_background_fc') &&
      $block->get('field_eb_background_fc')->value !== NULL) {
      $mid = $block->get('field_eb_background_fc')->value;
      $additional = [
        'bootstrap_styles' => [
          'block_style' => [
            'background' => [
              'background_type' => 'image',
            ],
            'background_media' => [
              'image' => [
                'media_id' => $mid,
              ],
            ],
            'background_options' => [
              'background_position' => 'center',
              'background_repeat' => 'no-repeat',
              'background_attachment' => 'not_fixed',
              'background_size' => 'cover',
            ],
            'min_height' => [
              'class' => 'osu-min-h-600',
            ],
          ],
        ],
        'component_attributes' => [
          "block_attributes" => [
            "id" => "",
            "class" => "h-100",
            "style" => "",
            "data" => "",
          ],
          "block_title_attributes" => [
            "id" => "",
            "class" => "",
            "style" => "",
            "data" => "",
          ],
          "block_content_attributes" => [
            "id" => "",
            "class" => "",
            "style" => "",
            "data" => "",
          ],
        ],
      ];
    }
    elseif ($item->getType() == 'paragraph_1_col' || $item->getType() == '1_column_background_video') {
      if ($block->get('body')->value !== NULL) {
        $additional = [
          'bootstrap_styles' => [
            'block_style' => [
              'background' => [
                'background_type' => 'color',
              ],
              'background_color' => [
                'class' => 'osu-bg-page-alt-1',
              ],
              'text_alignment' => [
                'class' => '_none',
              ],
              'padding' => [
                'class' => 'p-4',
              ],
              'margin' => [
                'class' => '_none',
              ],
              'margin_left' => [
                'class' => 'ms-4',
              ],
              'margin_top' => [
                'class' => 'mt-5',
              ],
              'margin_right' => [
                'class' => 'me-4',
              ],
              'margin_bottom' => [
                'class' => 'mb-5',
              ],
              'border' => [
                'border_style' => [
                  'class' => NULL,
                ],
                'border_width' => [
                  'class' => '_none',
                ],
                'border_color' => [
                  'class' => NULL,
                ],
                'rounded_corners' => [
                  'class' => 'bs-border-radius-1',
                ],
                'border_left_style' => [
                  'class' => NULL,
                ],
                'border_left_width' => [
                  'class' => '_none',
                ],
                'border_left_color' => [
                  'class' => NULL,
                ],
                'border_top_style' => [
                  'class' => NULL,
                ],
                'border_top_width' => [
                  'class' => '_none',
                ],
                'border_top_color' => [
                  'class' => NULL,
                ],
                'border_right_style' => [
                  'class' => NULL,
                ],
                'border_right_width' => [
                  'class' => '_none',
                ],
                'border_right_color' => [
                  'class' => NULL,
                ],
                'border_bottom_style' => [
                  'class' => NULL,
                ],
                'border_bottom_width' => [
                  'class' => '_none',
                ],
                'border_bottom_color' => [
                  'class' => NULL,
                ],
                'rounded_corner_top_left' => [
                  'class' => '_none',
                ],
                'rounded_corner_top_right' => [
                  'class' => '_none',
                ],
                'rounded_corner_bottom_left' => [
                  'class' => '_none',
                ],
                'rounded_corner_bottom_right' => [
                  'class' => '_none',
                ],
              ],
            ],
          ],
        ];
      }
    }
    return $additional;
  }

  /**
   * Some blocks need to set additional settings on the section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The layout builder section this block will be applied to.
   * @param \Drupal\block_content\Entity\BlockContent $block
   *   The block we get settings from.
   * @param \Drupal\paragraphs_to_layout_builder\LayoutMigrationItem $item
   *   A migration item instance.
   */
  protected function setAdditionalSectionSettings(Section $section, $block, LayoutMigrationItem $item) {
    $settings = $section->getLayoutSettings();
    if ($item->getType() === 'paragraph_1_col_clean') {
      // 1 column margin settings
      $settings['container_wrapper']['bootstrap_styles']['padding']['class'] = '_none';
      $settings['container_wrapper']['bootstrap_styles']['padding_left']['class'] = '_none';
      $settings['container_wrapper']['bootstrap_styles']['padding_top']['class'] = 'pt-5';
      $settings['container_wrapper']['bootstrap_styles']['padding_right']['class'] = '_none';
      $settings['container_wrapper']['bootstrap_styles']['padding_bottom']['class'] = 'pb-5';
      $settings['container_wrapper']['bootstrap_styles']['margin']['class'] = '_none';
      switch ($block->get('field_styles')->value) {
        case '67':
          $settings['container'] = 'container-fluid';
          break;

        case '0':
        case '10':
        case '20':
          $settings['container'] = 'container';
          break;
      }
      $section->setLayoutSettings($settings);
    }
    elseif ($item->getType() === 'paragraph_1_col') {
      if ($block->get('field_eb_background_fc')->value !== NULL) {
        $eb_fc = explode(',', $block->get('field_eb_background_fc')->value);
        $eb_fc_id = $eb_fc[0];
        $eb_fc_type = $eb_fc[1] === 'parallax' ? 'fixed' : 'not_fixed';
        $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'image';
        $settings['container_wrapper']['bootstrap_styles']['background_media']['image']['media_id'] = $eb_fc_id;
        $settings['container_wrapper']['bootstrap_styles']['background_media']['background_options'] = [
          'background_position' => 'center',
          'background_repeat' => 'no-repeat',
          'background_attachment' => $eb_fc_type,
          'background_size' => 'cover',
        ];
        $settings['container_wrapper']['bootstrap_styles']['items_alignment']['class'] = 'osu-align-items-center';
        $settings['container_wrapper']['bootstrap_styles']['min_height'] = ['class' => 'osu-min-h-600'];
        $section->setLayoutSettings($settings);
      }
    }
    elseif ($item->getType() === '1_column_background_video') {
      // paragraph_1_col variant with a video background; the block holds the
      // resolved local_video media id (see the migration yml).
      if ($block->get('field_eb_background_fc')->value !== NULL) {
        $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'video';
        $settings['container_wrapper']['bootstrap_styles']['background_media']['video']['media_id'] = $block->get('field_eb_background_fc')->value;
        $settings['container_wrapper']['bootstrap_styles']['items_alignment']['class'] = 'osu-align-items-center';
        $settings['container_wrapper']['bootstrap_styles']['min_height'] = ['class' => 'osu-min-h-600'];
        $section->setLayoutSettings($settings);
      }
    }
    elseif ($item->getType() == 'paragraph_divider') {
      // Default settings for dividers.
      $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
      $settings['container_wrapper']['bootstrap_styles']['background_color'] = ['class' => 'osu-bg-page-alt-2'];
      $settings['container_wrapper']['bootstrap_styles']['min_height'] = ['class' => 'osu-min-h-100'];
      if ($block->get('field_styles')->value !== NULL) {
        $block_styles_value = $block->get('field_styles')->value;

        if (str_contains($block_styles_value, 'black')) {
          $settings['container_wrapper']['bootstrap_styles']['background_color'] = ['class' => 'osu-bg-page-alt-2'];
        }
        elseif (str_contains($block_styles_value, 'white')) {
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-page-alt-1';
        }
        elseif (str_contains($block_styles_value, 'orange')) {
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-osuorange';
        }
        elseif (str_contains($block_styles_value, 'gray')) {
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-light-grey';
        }
        elseif (str_contains($block_styles_value, 'blue')) {
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-moondust';
        }
        elseif (str_contains($block_styles_value, 'green')) {
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-crater';
        }

        // Divider thickness.
        if (str_contains($block_styles_value, 'medium')) {
          $settings['container_wrapper']['bootstrap_styles']['min_height'] = ['class' => 'osu-min-h-200'];
        }
        elseif (str_contains($block_styles_value, 'large')) {
          $settings['container_wrapper']['bootstrap_styles']['min_height'] = ['class' => 'osu-min-h-300'];
        }
      }
      $section->setLayoutSettings($settings);
    }
    elseif ($item->getType() == 'paragraph_2_col') {
      if ($block->get('field_styles')->value !== NULL &&
        (str_contains($block->get('field_styles')->value, 'black-bg-left') ||
          str_contains($block->get('field_styles')->value, 'black-bg-right'))) {
        $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
        $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-page-alt-2';
        $settings['container_wrapper']['bootstrap_styles']['text_color']['class'] = 'osu-text-bucktoothwhite';
      }

      // Set two-columns to a min of 600px.
      $settings['container_wrapper']['bootstrap_styles']['min_height'] = ['class' => 'osu-min-h-600'];
      $settings['container'] = 'container-fluid';
      $section->setLayoutSettings($settings);
    }
    elseif ($item->getType() === 'paragraph_alert') {
      if ($block->get('field_styles')->value !== NULL) {
        $block_styles_value = $block->get('field_styles')->value;
        if (str_contains($block_styles_value, 'default-light')) {
          $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-light-grey';
        }
        elseif (str_contains($block_styles_value, 'default-dark')) {
          $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-page-alt-2';
          $settings['container_wrapper']['bootstrap_styles']['text_color']['class'] = 'osu-text-bucktoothwhite';
        }
        elseif (str_contains($block_styles_value, 'info')) {
          $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-moondust';
        }
        elseif (str_contains($block_styles_value, 'success')) {
          $settings['container_wrapper']['bootstrap_styles']['background']['background_type'] = 'color';
          $settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-crater';
        }
      }
      $section->setLayoutSettings($settings);
    }
  }

  /**
   * Loads default layout builder sections for a content type.
   *
   * @param string $bundle
   *   The content type to load defaults from.
   * @param string $entity
   *   Optional entity type, default to node.
   *
   * @return \Drupal\layout_builder\Section[]
   *   An array of the default layout builder section objects loaded from
   *   config.
   */
  protected function loadDefaultSections(string $bundle, string $entity = 'node'): array {
    $config = $this->configFactory->get("core.entity_view_display.{$entity}.{$bundle}.default");
    $sections_array = $config->get('third_party_settings.layout_builder.sections');
    $sections = [];

    if (!empty($sections_array)) {
      foreach ($sections_array as $section) {
        $sections[] = Section::fromArray($section);
      }
    }

    return $sections;
  }

  /**
   * Handles exceptions for missing blocks.
   *
   * Writes a message to the migrate map table and displays the message.
   *
   * @param \Drupal\migrate\MigrateExecutableInterface $migrateExecutable
   *   The current migration executable.
   * @param \Drupal\paragraphs_to_layout_builder\Exception\LayoutMigrationMissingBlockException $e
   *   The exception thrown when unable to find a block.
   */
  protected function handleMissingBlockException(MigrateExecutableInterface $migrateExecutable, LayoutMigrationMissingBlockException $e) {
    $migrateExecutable->saveMessage($e->getMessage(), $e->getCode());
    if ($migrateExecutable instanceof MigrateExecutable) {
      $migrateExecutable->message->display($e->getMessage());
    }
  }

}
