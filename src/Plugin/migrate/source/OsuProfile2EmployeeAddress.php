<?php

namespace Drupal\osu_migrations_cas\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\osu_user_to_profiles\Plugin\migrate\source\OsuProfile2;

/**
 * D7 OSU Employee profile2 source that flattens the office address.
 *
 * Extends the base profile2 source (which already exposes every profile2
 * field, e.g. phone_other / phone_fax) and adds a single `profile_address`
 * source property assembled from the D7 chain:
 *
 *   profile2(osu_employee)
 *     -> field_data_building_and_room (field_collection_item)
 *       -> field_data_office_location (building entity)
 *         -> field_data_building_location (location entity)
 *           -> location address fields
 *       -> field_data_room_number
 *
 * The resulting `profile_address` is an associative array shaped for the D10
 * `address` field, or NULL when the employee has no resolvable address.
 *
 * @code
 * source:
 *   plugin: d7_osu_profile2_employee_address
 *   profile2_type: osu_employee
 * @endcode
 *
 * @MigrateSource(
 *   id = "d7_osu_profile2_employee_address",
 *   source_module = "profile2"
 * )
 */
class OsuProfile2EmployeeAddress extends OsuProfile2 {

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = parent::fields();
    $fields['profile_address'] = $this->t('Flattened office address for the D10 address field.');
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $result = parent::prepareRow($row);
    if ($result === FALSE) {
      return FALSE;
    }
    $row->setSourceProperty('profile_address', $this->fetchAddress($row->getSourceProperty('pid')));
    return $result;
  }

  /**
   * Resolve the flattened address for a single profile.
   *
   * @param int $pid
   *   The D7 profile2 id.
   *
   * @return array|null
   *   Address array keyed for the D10 address field, or NULL when none.
   */
  protected function fetchAddress($pid) {
    $query = $this->select('field_data_building_and_room', 'bar');
    $query->condition('bar.entity_type', 'profile2')
      ->condition('bar.entity_id', $pid)
      ->condition('bar.deleted', 0);
    $query->join('field_collection_item', 'fci', 'fci.item_id = bar.building_and_room_value');
    $query->leftJoin('field_data_office_location', 'ol', "ol.entity_type = 'field_collection_item' AND ol.entity_id = fci.item_id AND ol.deleted = 0");
    $query->leftJoin('field_data_room_number', 'rm', "rm.entity_type = 'field_collection_item' AND rm.entity_id = fci.item_id AND rm.deleted = 0");
    $query->leftJoin('field_data_building_location', 'bl', "bl.entity_type = 'building' AND bl.entity_id = ol.office_location_target_id AND bl.deleted = 0");
    $query->leftJoin('field_data_location_address_one', 'a1', "a1.entity_type = 'location' AND a1.entity_id = bl.building_location_target_id AND a1.deleted = 0");
    $query->leftJoin('field_data_location_address_two', 'a2', "a2.entity_type = 'location' AND a2.entity_id = bl.building_location_target_id AND a2.deleted = 0");
    $query->leftJoin('field_data_location_city', 'c', "c.entity_type = 'location' AND c.entity_id = bl.building_location_target_id AND c.deleted = 0");
    $query->leftJoin('field_data_location_state', 's', "s.entity_type = 'location' AND s.entity_id = bl.building_location_target_id AND s.deleted = 0");
    $query->leftJoin('field_data_location_zip', 'z', "z.entity_type = 'location' AND z.entity_id = bl.building_location_target_id AND z.deleted = 0");
    $query->addField('a1', 'location_address_one_value', 'addr1');
    $query->addField('a2', 'location_address_two_value', 'addr2');
    $query->addField('rm', 'room_number_value', 'room');
    $query->addField('c', 'location_city_value', 'city');
    $query->addField('s', 'location_state_value', 'state');
    $query->addField('z', 'location_zip_value', 'zip');
    $query->range(0, 1);
    $record = $query->execute()->fetchAssoc();

    // No usable address: leave the field empty.
    if (empty($record) || empty($record['addr1'])) {
      return NULL;
    }

    // Fold the room number into address line 2.
    $line2_parts = [];
    if (!empty($record['addr2'])) {
      $line2_parts[] = $record['addr2'];
    }
    if (!empty($record['room'])) {
      $line2_parts[] = 'Rm ' . $record['room'];
    }

    return [
      'country_code' => 'US',
      'administrative_area' => $record['state'] ?: 'OR',
      'locality' => $record['city'] ?: '',
      'postal_code' => $record['zip'] ?: '',
      'address_line1' => $record['addr1'],
      'address_line2' => implode(', ', $line2_parts),
    ];
  }

}
