<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\process;

use Drupal\migrate\Annotation\MigrateProcessPlugin;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Row;
use Drupal\paragraphs_to_layout_builder\Exception\LayoutMigrationMissingBlockException;
use Drupal\paragraphs_to_layout_builder\Exception\LayoutMigrationMissingParagraphToLayoutException;
use Drupal\paragraphs_to_layout_builder\LayoutMigrationItem;
use Drupal\osu_migrations_cas\CasLayoutBase;

/**
 * Paragraphs Layout process plugin.
 *
 * @code
 * layout_builder__layout:
 *   plugin: layout_builder_layout
 *   source_field: field_paragraphs
 * @endcode
 *
 * @MigrateProcessPlugin(
 *   id = "cas_paragraphs_layout"
 * )
 */
class CasParagraphsLayout extends CasLayoutBase {

  /**
   * Transform paragraph source values into a Layout Builder sections.
   *
   * @param mixed $value
   *   The value to be transformed.
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The migration in which this process is being executed.
   * @param \Drupal\migrate\Row $row
   *   The row from the source to process. Normally, just transforming the value
   *   is adequate but very rarely you might need to change two columns at the
   *   same time or something like that.
   * @param string $destination_property
   *   The destination property currently worked on. This is only used together
   *   with the $row above.
   *
   * @return \Drupal\layout_builder\Section[]
   *   A Layout Builder Section object populated with Section Components.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\migrate\MigrateException
   */
  public function transform(
    $value,
    MigrateExecutableInterface $migrate_executable,
    Row $row,
    $destination_property,
  ) {
    $sourceField = $this->configuration['source_field'];
    if (!isset($sourceField)) {
      throw new MigrateException('Missing source_field for paragraph layout process plugin.');
    }

    $values = $row->getSourceProperty($sourceField);
    $map = $row->getSource()['constants']['map'];
    $ignored_bundles = ['viewfield', '2_column_views'];
    $sections = [];
    if (is_array($values)) {
      foreach ($values as $delta => $item) {
        try {
          $type = $this->getParagraphType($item['value']);
          if (in_array($type, $ignored_bundles, TRUE)) {
            continue;
          }
          // Dividers were empty full-width spacer bands in D7, not content.
          // Emit a component-less section carrying the D7 size (as a
          // min-height) and colour (as a background), rather than an empty
          // block. No block is created, so skip the rest of the loop.
          if ($type === 'paragraph_divider') {
            $sections[] = $this->createDividerSection($item['value']);
            continue;
          }
          $sectionType = $this->getSectionType($type);
          $section = $this->createSection($sectionType, []);

          // Map migration IDs to their layout builder region.
          $migration_ids = [];
          if ($type == "paragraph_2_col") {
            $migration_ids[$map['paragraph_2_col_left']] = "blb_region_col_1";
            $migration_ids[$map['paragraph_2_col_right']] = "blb_region_col_2";
          }
          elseif ($type == "2_column_4_8") {
            $migration_ids[$map['2_column_4_8_left']] = "blb_region_col_1";
            $migration_ids[$map['2_column_4_8_right']] = "blb_region_col_2";
          }
          elseif ($type == "paragraph_2_column_8_4") {
            $migration_ids[$map['paragraph_2_column_8_4_left']] = "blb_region_col_1";
            $migration_ids[$map['paragraph_2_column_8_4_right']] = "blb_region_col_2";
          }
          elseif ($type == "paragraph_3_col") {
            $migration_ids[$map['paragraph_3_col_left']] = "blb_region_col_1";
            $migration_ids[$map['paragraph_3_col_center']] = "blb_region_col_2";
            $migration_ids[$map['paragraph_3_col_right']] = "blb_region_col_3";
          }
          elseif ($type == "4_column") {
            $migration_ids[$map['4_column_col1']] = "blb_region_col_1";
            $migration_ids[$map['4_column_col2']] = "blb_region_col_2";
            $migration_ids[$map['4_column_col3']] = "blb_region_col_3";
            $migration_ids[$map['4_column_col4']] = "blb_region_col_4";
          }
          elseif (array_key_exists($type, $map)) {
            $migration_ids[$map[$type]] = "blb_region_col_1";
          }
          else {
            throw new LayoutMigrationMissingParagraphToLayoutException($this->t('Missing custom paragraph migration for paragraph type @type.', ['@type' => $type]));
          }

          // Iterate through migration_ids creating components for each block and attaching to section.
          foreach ($migration_ids as $migration_id => $migration_row) {
            $migrationItem = new LayoutMigrationItem($type, $item['value'], $delta, $migration_id);
            $components = $this->createComponent($migrationItem, $section, $migration_row);

            //add classes to row that will be seen in LayoutBuilder UI
            // Backgrounds are set from the row block data in
            // CasLayoutBase::setAdjustableColumnsSectionSettings().
            if ($type == "lp_adjustable_columns") {
              $layout_settings = $section->getLayoutSettings();
              $layout_settings['regions_classes']['blb_region_col_1'] = 'd-flex flex-wrap';
              $section->setLayoutSettings($layout_settings);
            }

            // Limitations on menu migrations means we don't know what section type to use until now.
            // $components can be empty when every attached block of an
            // adjustable-columns row was skipped as missing (see
            // CasLayoutBase::handleAdjustableColumnsItems()).
            if (!empty($components[0]) && $components[0]->get('configuration')['id'] == 'inline_block:osu_menu_bar_item') {
              // Query old db to get the menu bg color option.
              $menu_style_query = $this->migrateDb->select('field_data_field_p_menu_styles', 'fdfpms');
              $menu_style_query->fields('fdfpms', ['field_p_menu_styles_value']);
              $menu_style_query->condition('fdfpms.entity_id', $item['value'], 'IN');
              $menu_bg_color = $menu_style_query->execute()->fetchField();

              $menu_section_settings = $this->setMenuBgClass($menu_bg_color);
              $section = $this->createSection('bootstrap_layout_builder:blb_col_' . count($components), [], $menu_section_settings);
            }

            $this->appendComponentsToSection($components, $section);
          }

          $sections[] = $section;

          if($type == 'lp_picbox_grid'){
            $blockId = $this->lookupBlock($migrationItem->getMigrationId(), $migrationItem->getId());
            $block = $this->entityTypeManager->getStorage('block_content')
              ->load($blockId);
            $picbox_section = $this->handlePicboxGridLayoutItems($block);
            $sections[] = $picbox_section;
          }
        }
        catch (LayoutMigrationMissingBlockException $e) {
          $this->handleMissingBlockException($migrate_executable, $e);
          continue;
        }
        catch (LayoutMigrationMissingParagraphToLayoutException $e) {
          $migrate_executable->saveMessage($e->getMessage(), $e->getCode());
          if ($migrate_executable instanceof MigrateExecutable) {
            $migrate_executable->message->display($e->getMessage());
          }
          continue;
        }
      }
    }

    return $sections;
  }

  /**
   * Gets the type of paragraph given a paragraph id.
   *
   * Uses basic static caching since this may be called multiple times for the
   * same paragraphs.
   *
   * @param string $id
   *   The paragraph id.
   *
   * @return string
   *   The paragraph bundle.
   */
  public function getParagraphType($id) {
    $types = &drupal_static(__FUNCTION__);
    if (!isset($types[$id])) {
      $query = $this->migrateDb->select('paragraphs_item', 'p');
      $query->fields('p', ['bundle']);
      $query->condition('p.item_id', $id, '=');
      $types[$id] = $query->execute()->fetchField();
    }
    return $types[$id];
  }

  /**
   * Build a component-less section for a D7 divider paragraph.
   *
   * D7 dividers (paragraphs-item--paragraph_divider.tpl.php) were empty
   * edge-to-edge spacer bands: field_p_divider_size set the height and
   * field_p_divider_color the background. Reproduce that as a Layout Builder
   * section with no components, carrying the height and colour as
   * bootstrap_styles section settings rather than migrating an empty block.
   *
   * The horizontal-line variant (field_p_divider_additional) is intentionally
   * not reproduced; every divider becomes a plain spacer band.
   *
   * @param int|string $itemId
   *   The D7 paragraphs_item id of the divider.
   *
   * @return \Drupal\layout_builder\Section
   *   An edge-to-edge (w-100) blb_col_1 section with no components.
   */
  protected function createDividerSection($itemId) {
    // D7 size -> min-height utility. The osu-min-h-25/50 classes are provided
    // by manzanita (_cas_min_height.scss); 100 and up come from madrone.
    $size = $this->migrateDb->select('field_data_field_p_divider_size', 'd')
      ->fields('d', ['field_p_divider_size_value'])
      ->condition('d.entity_id', $itemId)
      ->execute()
      ->fetchField();
    $min_height = match ($size) {
      'medium' => 'osu-min-h-50',
      'large' => 'osu-min-h-100',
      // 'small' and any unexpected/empty value default to the smallest step.
      default => 'osu-min-h-25',
    };

    // D7 colour -> osu-bg-* background. White (and empty) stay transparent, so
    // no background class is added. Targets exist in madrone/manzanita's
    // bg palettes; values mirror osu_paragraphs/styles/_divider.less.
    $color = $this->migrateDb->select('field_data_field_p_divider_color', 'd')
      ->fields('d', ['field_p_divider_color_value'])
      ->condition('d.entity_id', $itemId)
      ->execute()
      ->fetchField();
    $background = match ($color) {
      'orange' => 'osu-bg-osuorange',
      'green' => 'osu-bg-pine-stand',
      'yellow' => 'osu-bg-luminance',
      'blue' => 'osu-bg-stratosphere',
      'black' => 'osu-bg-black',
      'gray' => 'osu-bg-coastline',
      default => NULL,
    };

    $bootstrap_styles = ['min_height' => ['class' => $min_height]];
    if ($background !== NULL) {
      // BackgroundColor::build() reads background.background_type unconditionally
      // (bootstrap_styles/.../Style/BackgroundColor.php), so the sibling
      // 'background' key must be present or it warns on every render. The class
      // itself lives in background_color.
      $bootstrap_styles['background'] = ['background_type' => 'color'];
      $bootstrap_styles['background_color'] = ['class' => $background];
    }

    // w-100 = edge-to-edge; no components = an empty styled band.
    return $this->createSection('bootstrap_layout_builder:blb_col_1', [], [
      'container' => 'w-100',
      'container_wrapper' => ['bootstrap_styles' => $bootstrap_styles],
    ]);
  }

  /**
   * Set the Menu bar section options.
   *
   * @param string $paragraph_style
   *
   * @return array
   *   Layout builder Section settings.
   */
  private function setMenuBgClass(string $paragraph_style) {
    $menu_section_settings = [
      'container' => 'container',
      'container_wrapper' => [
        'bootstrap_styles' => [
          'background' => [
            'background_type' => 'color',
          ],
        ],
      ],
    ];
    switch ($paragraph_style) {
      case 'menu-orange':
        $menu_section_settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-osuorange';
        $menu_section_settings['container_wrapper']['bootstrap_styles']['text_color']['class'] = 'osu-text-bucktoothwhite';
        break;

      case 'menu-gray':
        $menu_section_settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-light-grey';
        break;

      case 'menu-blue':
        $menu_section_settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-moondust';
        break;

      case 'menu-black':
        $menu_section_settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-page-alt-2';
        $menu_section_settings['container_wrapper']['bootstrap_styles']['text_color']['class'] = 'osu-text-bucktoothwhite';
        break;

      case 'menu-green':
        $menu_section_settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-crater';
        $menu_section_settings['container_wrapper']['bootstrap_styles']['text_color']['class'] = 'osu-text-bucktoothwhite';
        break;

      default:
        $menu_section_settings['container_wrapper']['bootstrap_styles']['background_color']['class'] = 'osu-bg-page-default';
        break;
    }
    return $menu_section_settings;
  }

  /**
   * Append components to a section.
   *
   * @param array $components
   *   The components to append.
   * @param mixed $section
   *   The section to append the components to.
   */
  public function appendComponentsToSection($components, $section) {
    foreach ($components as $component) {
      $section->appendComponent($component);
    }
  }

  /**
   * Additional blocks need to be queried and placed in a section for picbox grid Layout.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block
   *   The block containing IDs of the Grid Item blocks.
   *
   * @return \Drupal\layout_builder\Section
   * A Layout Builder Section object populated with Section Components.
   *
 */
  protected function handlePicboxGridLayoutItems($block) {
    $extra_data = unserialize($block->get('field_block_serialized_data')->value);
    $block_ids = explode(',', $extra_data['migration']['attached_block_ids']);
    $columns = $extra_data['migration']['picbox_columns'];
    $components = [];
    foreach ($block_ids as $index => $block_id) {
      $block_revision_id = $this->blockContentStorage->getLatestRevisionId($block_id);
      $block_type = 'osu_card';
      $additional = array();
      // Using mod 4 and adding 1 we should always return column 1-4.
      $row = 'blb_region_col_' . ($index % $columns + 1);
//      $additional = $this->getAdditionalBlockSettings($block, $row, $item);
      $components[] = $this->createSectionComponent($block_type, $block_revision_id, $row, $additional, $index, 'picbox');
    }
    $settings = array();
    $section = $this->createSection('bootstrap_layout_builder:blb_col_' . $columns, [], $settings);
    $this->appendComponentsToSection($components, $section);
    return $section;
  }

}
