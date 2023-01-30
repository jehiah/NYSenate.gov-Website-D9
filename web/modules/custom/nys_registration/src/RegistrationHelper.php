<?php

namespace Drupal\nys_registration;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\nys_sage\Service\SageApi;
use Drupal\taxonomy\Entity\Term;

/**
 * Helper/service methods relevant to user registration.
 */
class RegistrationHelper {

  /**
   * Drupal's Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * NYS SAGE service.
   *
   * @var \Drupal\nys_sage\Service\SageApi
   */
  protected SageApi $sageApi;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, SageApi $sageApi) {
    $this->entityTypeManager = $entityTypeManager;
    $this->sageApi = $sageApi;
  }

  /**
   * Attempts to find the senate district for an address.
   *
   * @param array $address_parts
   *   An array representing an address, as expressed in the address module.
   *   This method recognizes 'address_line1', 'address_line2', 'locality',
   *   'administrative_area', and 'postal_code'.  Generally, all parts are
   *   required for SAGE to geocode an address.
   *
   * @return \Drupal\taxonomy\Entity\Term|null
   *   Returns NULL if no district assignment was made, or the term could not
   *   be loaded.  Otherwise, the taxonomy term for the district.
   *
   * @see http://sage.nysenate.gov:8080/docs/html/index.html#common-query-parameters
   */
  public function getDistrictFromAddress(array $address_parts): ?Term {
    $zip = explode('-', $address_parts['postal_code'] ?? '');
    $params = array_filter([
      'addr1' => $address_parts['address_line1'] ?? '',
      'addr2' => $address_parts['address_line2'] ?? '',
      'city' => $address_parts['locality'] ?? '',
      'state' => $address_parts['administrative_area'] ?? '',
      'zip5' => $zip[0] ?? '',
      'zip4' => $zip[1] ?? '',
    ]);

    // SAGE returns a district number.  Try to load the district entity.
    $district = $this->sageApi->districtAssign($params);
    try {
      /** @var \Drupal\taxonomy\Entity\Term|null $district_term */
      $district_term = current(
        $this->entityTypeManager
          ->getStorage('taxonomy_term')
          ->loadByProperties(['field_district_number' => $district])
      ) ?: NULL;
    }
    catch (\Throwable) {
      $district_term = NULL;
    }

    return $district_term;
  }

  /**
   * Get the Senators' district page.
   *
   * @param \Drupal\taxonomy\Entity\Term $senator
   *   The senators' taxonomy term.
   *
   * @return string
   *   returns the alias for the page
   */
  public function getMicrositeDistrictAlias(Term $senator) {

    $nids = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'field_microsite_page_type' => '200001',
      'field_senator_multiref' => $senator->id(),
    ]);
    foreach ($nids as $nid => $value) {
      $district_node = $this->entityTypeManager->getStorage('node')->load($nid);
    }
    $district_url = \Drupal::service('path_alias.manager')->getPathByAlias($district_node->toUrl()->toString());
    return $district_url;
  }

  /**
   * Get the Senators' party affilation from value.
   *
   * @param array $parties
   *   The senators' field party value.
   *
   * @return string
   *   returns the full name of the party affiliation
   */
  public function getPartyAffilation(array $parties) {
    $party_names = [];
    foreach ($parties as $party) {
      switch ($party['value']) {
        case 'R':
          $party_names[] = 'Republican';
          break;

        case 'C':
          $party_names[] = 'Conservative';
          break;

        case 'CNST':
          $party_names[] = 'Constitution';
          break;

        case 'D':
          $party_names[] = 'Democrat';
          break;

        case 'G':
          $party_names[] = 'Green';
          break;

        case 'IP':
          $party_names[] = 'Independence Party';
          break;

        case 'I':
          $party_names[] = 'Independent/No Party';
          break;

        case 'Ind':
          $party_names[] = 'Independent Party';
          break;

        case 'L':
          $party_names[] = 'Liberal';
          break;

        case 'LIBT':
          $party_names[] = 'Libertarian';
          break;

        case 'NL':
          $party_names[] = 'Natural Law';
          break;

        case 'RFM':
          $party_names[] = 'Reform Party';
          break;

        case 'RTL':
          $party_names[] = 'Right to Life';
          break;

        case 'SJ':
          $party_names[] = 'Save Jobs';
          break;

        case 'SC':
          $party_names[] = 'School Choice';
          break;

        case 'SWP':
          $party_names[] = 'Socialist Workers';
          break;

        case 'WEP':
          $party_names[] = 'Women\'s Equality Party';
          break;

        case 'WF':
          $party_names[] = 'Working Families';
          break;
      }
    }
    $party = implode(' ', $party_names);
    return $party;
  }

}
