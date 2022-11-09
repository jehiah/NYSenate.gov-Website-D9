<?php

namespace Drupal\nys_bills;

use Drupal\node\NodeInterface;
use Drupal\Core\Database\Connection;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Helper class for nys_bills module.
 *
 * @todo Bills should be a custom entity.  Most methods in this class will be
 *   part of that new class.
 */
class BillsHelper {

  /**
   * Defines a prefix for cache entries related to this class.
   */
  const CACHE_BIN_PREFIX = 'nys_bills';

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The CacheBackend Interface.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructor class for Bills Helper.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection object.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The backend cache.
   */
  public function __construct(Connection $connection, EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache_backend) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache_backend;
  }

  /**
   * Get the Cache.
   */
  protected function getCache(string $name): bool|object {
    return $this->cache->get(static::CACHE_BIN_PREFIX . ':' . $name);
  }

  /**
   * Sets a value in the nys_bills cache.
   */
  protected function setCache(string $name, $value): void {
    $this->cache->set(static::CACHE_BIN_PREFIX . ':' . $name, $value);
  }

  /**
   * Removes a value from the nys_bills cache.
   */
  protected function removeCache(string $name): void {
    $this->cache->delete(static::CACHE_BIN_PREFIX . ':' . $name);
  }

  /**
   * Provides a reference to Drupal's storage service for taxonomy terms.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('node');
  }

  /**
   * Validates that a node is a bill or resolution.
   */
  public function isBill(NodeInterface $node): bool {
    return in_array($node->bundle(), ['bill', 'resolution']);
  }

  /**
   * Builds the legislative alias for the active version of a bill.
   *
   * @return string
   *   '/legislation/bills/<session>/<base_print>', with no version.
   *   Returns an empty string if $node is not a bill node.
   */
  public function buildActiveAlias(NodeInterface $node): string {
    $ret = '';
    if ($this->isBill($node)) {
      $session = $node->field_ol_session->value ?? '';
      $base = strtoupper($node->field_ol_base_print_no->value ?? '');
      if ($session && $base) {
        $ret = '/legislation/bills/' . $session . '/' . $base;
      }
    }
    return $ret;
  }

  /**
   * Builds the legislation alias for a bill node.
   *
   * @return string
   *   '/legislation/bills/<session>/<base_print>/amendment/<version>', where
   *   version could be the word 'original', or a single letter.  Returns an
   *   empty string if $node is not a bill node.
   */
  public function buildAlias(NodeInterface $node): string {
    $version = strtoupper($node->field_ol_version->value ?? '') ?: 'original';
    return $this->isBill($node)
      ? $this->buildActiveAlias($node) . '/amendment/' . $version
      : '';
  }

  /**
   * Loads all bills related to a specified print number and session year.
   *
   * @param string $base_print
   *   The base print number (i.e., no version marker) of a bill or resolution.
   * @param string $session
   *   A session year.
   *
   * @return array
   *   An empty array of failure, otherwise as returned from loadMultiple().
   */
  public function loadBillVersions(string $base_print, string $session): array {
    try {
      $storage = $this->getStorage();
      $results = $storage->getQuery()
        ->condition('type', ['bill', 'resolution'], 'IN')
        ->condition('field_ol_base_print_no', $base_print)
        ->condition('field_ol_session', $session)
        ->execute();
      $bills = $storage->loadMultiple($results);
    }
    catch (\Throwable) {
      $bills = [];
    }

    return $bills;

  }

  /**
   * Generates a cache key for the versions of a base print.
   *
   * Note that the returned key lacks the static::CACHE_BIN_PREFIX.
   */
  public function generateBillVersionCacheKey(NodeInterface $node): string {
    if (!$this->isBill($node)) {
      throw new \InvalidArgumentException('Node must be a bill or resolution');
    }
    $node_type = $node->bundle();
    $base_print = $node->field_ol_base_print_no->value ?? '';
    $session = $node->field_ol_session->value ?? '';

    // A quick sanity check.
    if (!($session && $base_print)) {
      throw new \InvalidArgumentException('Invalid print number or session');
    }

    // Generate the key to be used.
    return implode(':', [
      'versions',
      $node_type,
      $session,
      $base_print,
    ]);
  }

  /**
   * Finds all amendments for a passed bill/resolution node.
   *
   * @return array
   *   In the form ['<bill_title>' => <node_id>, ...]
   *   While technically possible for the return to be an empty array, that
   *   is indicative of an error condition; the return should include (at
   *   least) the passed node's information.
   */
  public function getBillVersions(NodeInterface $node): array {
    try {
      $cid = $this->generateBillVersionCacheKey($node);
      $ret = $this->getCache($cid)->data ?? NULL;
    }
    catch (\Throwable) {
      $ret = [];
      $cid = '';
    }

    if (is_null($ret) && $cid) {
      $ret = [];
      $base_print = $node->field_ol_base_print_no->value;
      $session = $node->field_ol_session->value;
      /** @var \Drupal\node\Entity\Node $bill */
      foreach ($this->loadBillVersions($base_print, $session) as $bill) {
        $ret[$bill->getTitle()] = $bill->id();
      }
      $this->setCache($cid, $ret);
    }

    return $ret;
  }

  /**
   * Wrapper to allow for loading by session and base print number.
   */
  public function loadBillBySessionPrint(string $session, string $base_print, string $version = ''): ? NodeInterface {
    return $this->loadBillByTitle($this->formatTitleParts($session, $base_print, $version));
  }

  /**
   * Loads a bill Node by print number (title).
   *
   * @param string $print_num
   *   A bill print number, such as '2021-S123B'.
   *
   * @return \Drupal\node\Entity\NodeInterface|null
   *   If multiple or no bills are found, NULL is returned.
   */
  public function loadBillByTitle(string $print_num): NodeInterface {
    try {
      $nodes = $this->getStorage()->loadByProperties([
        'type' => 'bill',
        'title' => $print_num,
      ]);
      /** @var \Drupal\node\Entity\Node|NULL $ret */
      $ret = current($nodes) ?: NULL;
    }
    catch (\Throwable) {
      $ret = NULL;
    }
    return $ret;
  }

  /**
   * Clears caches for all amendments under a bill's base print number.
   *
   * @param \Drupal\node\Entity\NodeInterface $node
   *   A bill or resolution node.
   */
  public function clearBillVersionsCache(NodeInterface $node): void {
    if ($this->isBill($node)) {

      // Clear the version lookup cache.
      $this->removeCache($this->generateBillVersionCacheKey($node));

      // Clear the node cache for all versions.
      // E.g., if S100B gets updated, S100 and S100A are also invalidated.
      $tags = array_map(
        function ($nid) {
          return "node:$nid";
        },
        array_keys($this->getBillVersions($node))
      );
      if (count($tags)) {
        $this->cache->invalidateMultiple($tags);
      }

    }
  }

  /**
   * Formats the "press finish" title for a bill.
   *
   * @param \Drupal\node\Entity\NodeInterface $node
   *   Full bill node object.
   *
   * @return string
   *   Returns a full bill name with Chamber + Bundle + BillName, or an empty
   *   string if $node is not a bill or resolution.
   */
  public function formatFullBillTitle(NodeInterface $node): string {
    return $this->isBill($node)
      ? ucfirst($node->field_ol_chamber->value) . ' ' . ucfirst($node->bundle()) . ' ' . $node->label()
      : '';
  }

  /**
   * Generates the standard-format title for a bill node.
   *
   * @see formatTitleParts()
   */
  public function formatTitle(NodeInterface $node, string $version = '', string $separator = '-'): string {
    return !$this->isBill($node)
      ? ''
      : $this->formatTitleParts(
        $node->field_ol_session->value,
        $node->field_ol_base_print_no->value,
        $version,
        $separator
      );
  }

  /**
   * Resolve Amendment Sponsors.
   */
  public function resolveAmendmentSponsors($amendment, $chamber) {
    $ret = [];
    $cycle = ['co', 'multi'];
    $senators = $this->getSenatorNameMapping();
    foreach ($cycle as $type) {
      $ret[$type] = [];
      $propname = "field_ol_{$type}_sponsor_names";

      $sponsors = json_decode($amendment->{$propname}->value);
      foreach ($sponsors as $one_sponsor) {
        switch ($chamber) {
          case 'senate':
            $termid = $this->getSenatorTidFromMemberId($one_sponsor->memberId);
            if (!empty($termid)) {
              $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($termid);
              $ret[$type][] = $this->entityTypeManager->getViewBuilder('taxonomy_term')->view($term, 'sponsor_list_bill_detail');
            }
            break;

          case 'assembly':
            $ret[$type][] = [
              '#theme' => 'bill_sponsor_assembly',
              '#content' => [
                'fullName' => $one_sponsor->fullName,
              ],
            ];
            break;
        }
      }
    }

    return $ret;

  }

  /**
   * Retrieves the senator node id associated with an OpenLeg member id.
   *
   * @param int $member_id
   *   Member id.
   *
   * @return int
   *   node_id
   *
   * @see https://bitbucket.org/mediacurrent/nys_nysenate/src/develop/sites/all/modules/custom/nys_utils/nys_utils.module
   * function nys_utils_get_senator_nid_from_member_id from D7
   */
  public function getSenatorTidFromMemberId($member_id) {
    $preloaded = &drupal_static(__FUNCTION__, []);

    if (!array_key_exists($member_id, $preloaded)) {
      $query = "SELECT entity_id FROM taxonomy_term__field_ol_member_id WHERE field_ol_member_id_value = :memberid";
      $queryargs = [
        ':memberid' => $member_id,
      ];
      $preloaded[$member_id] = $this->connection->query($query, $queryargs)->fetchField();
    }
    return $preloaded[$member_id];
  }

  /**
   * Generates the standard-format title, given a print number and session.
   *
   * @param string $session
   *   The bill's session year.
   * @param string $base_print
   *   The bill's base print number (i.e., no version marker).
   * @param string $version
   *   An optional version marker. For the base print, leave blank.
   * @param string $separator
   *   Defaults to '-'.
   */
  public function formatTitleParts(string $session, string $base_print, string $version = '', string $separator = '-'): string {
    return $session . $separator . strtoupper($base_print) . strtoupper($version);
  }

  /**
   * Given a bill/resolution node, returns the node of the active amendment.
   *
   * @return \Drupal\node\Entity\Node|null
   *   Returns NULL if multiple or no bills were found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function loadActiveVersion(NodeInterface $node): ? NodeInterface {
    $title = $this->buildAliasformatTitle($node, $node->field_ol_active_version->value);
    if ($node->getTitle() == $title) {
      $ret = $node;
    }
    else {
      $result = $this->getStorage()
        ->loadByProperties(['title' => $title, 'type' => $node->bundle()]);
      $ret = current($result) ?: NULL;
    }
    return $ret;
  }

  /**
   * Standardizes the session year string for display.
   *
   * The odd-numbered year needs to be the first year in the legislative cycle
   * identifier in order to match Senate procedure.
   *
   * @param int $session_year
   *   A session year.
   *
   * @return string
   *   The legislative cycle, ready for display.
   */
  public function standardizeSession(int $session_year): string {
    if (($session_year % 2) > 0) {
      $ret = $session_year . '-' . ($session_year + 1);
    }
    else {
      $ret = ($session_year - 1) . '-' . $session_year;
    }
    return $ret;
  }

  /**
   * Audits URL aliases (path_auto) for all amendments as a bill is saved.
   *
   * This is necessary because:
   *  - The original amendment's field_ol_version is NULL/empty, and needs to
   *    be replaced with 'original' in the alias,
   *  - The canonical URL for a bill (meaning, a URL with no version specified)
   *    must point to the active amendment, which can change.
   *
   * There is an edge case caused by the timing of LBDC updates when a new
   * amendment is published.  In this scenario, a bill will reference a new
   * amendment which has not yet been imported.  This typically manifests as
   * an amendment showing the '/node/xxx' URL instead of the alias, or a 404
   * or redirection response.  In we detect a case like this, we set the
   * canonical URL back to "original", per F.S., 2022-10-27.
   *
   * @param \Drupal\node\Entity\NodeInterface $node
   *   The node being saved.
   */
  public function validateAliases(NodeInterface $node): void {
    if ($this->clearBillVersionsCacheisBill($node)) {
      // Get the session year, base print, and version.
      $session = $node->field_ol_session->value ?? '';
      $base_print = $node->field_ol_base_print_no->value ?? '';
      $version = $node->field_ol_version->value ?? '';
      $active = $node->field_ol_active_version->value ?? '';

      // The alias to the specific amendment (i.e., .../amendment/original)
      $alias = $this->buildAlias($node);

      // The alias to the active amendment (/legislation/bills/2021/S100)
      $canon = $this->buildActiveAlias($node);

      // The actual internal path, e.g., '/node/1234'.
      // The 'alias' option turns off AliasPathProcessor.  Yes, it is very
      // counter-intuitive, but it is what it is.
      // See path_alias\PathProcessor\AliasPathProcessor::processOutbound()
      try {
        $int_path = $node->toUrl('canonical', ['alias' => TRUE])->toString();
      }
      catch (\Throwable) {
        $int_path = '';
      }

      // Leave is anything is weird.
      if (!($session && $base_print && $alias && $canon && $int_path)) {
        return;
      }

      try {
        // Insert/update the specific alias ($path points to $alias)
        $this->getPathAlias(['path' => $int_path, 'alias' => $alias])->save();

        // If this is the active version, set canonical also: $path -> $canon.
        if ($active == $version) {
          $existing = $this->getPathAlias(['alias' => $canon]);
          $existing->setPath($int_path)->save();
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('nys_bills')
          ->error('BillsHelper was unable to create or update an alias', ['message' => $e->getMessage()]);
      }
    }
  }

  /**
   * Returns a cached mapping of senator names, keyed by the nid.
   *
   * @see https://bitbucket.org/mediacurrent/nys_nysenate/src/develop/sites/all/modules/custom/nys_utils/nys_utils.module
   * function get_senator_name_mapping() from D7
   */
  public function getSenatorNameMapping() {
    $cache_key = 'nys_utils_get_senator_name_mapping';
    $cache = $this->cache->get($cache_key);
    if (!$cache) {

      $senator_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'senator',
      ]);

      $senator_mappings = [];
      foreach ($senator_terms as &$term) {
        $senator_mappings[$term->id()] = [
          'short_name' => $term->get('field_senator_name')[0]->given ?? '',
          'full_name' => $term->get('field_senator_name')[0]->title ?? '',
        ];
      }
      $this->cache->set($cache_key, $senator_mappings);
    }
    else {
      return $cache->data;
    }
  }

  /**
   * Loads (or creates) a path alias entity.
   *
   * @param array $values
   *   Can contain keys for 'path' and/or 'alias'.
   *
   * @return \Drupal\path_alias\Entity\PathAlias|null
   *   If the entity system throws an exception, this method returns NULL.  If
   *   $values is an empty array, a newly-created PathAlias object is returned.
   *   If $values has 'path', 'alias', or 'both', but no matching alias is
   *   found, a new PathAlias is created (but not saved) from those values.  If
   *   a matching alias is found, it is returned as loaded.   *
   */
  protected function getPathAlias(array $values = []): ? PathAlias {
    try {
      /** @var \Drupal\path_alias\PathAliasStorage $storage */
      $storage = $this->entityTypeManager->getStorage('path_alias');
    }
    catch (\Throwable) {
      return NULL;
    }
    $props = [];
    foreach (['path', 'alias'] as $field) {
      if ($values[$field] ?? '') {
        $props[$field] = $values[$field];
      }
    }
    if (!$props) {
      return $storage->create();
    }
    $entities = $storage->loadByProperties($values);
    if (!$entities) {
      return $storage->create($props);
    }
    else {
      return current($entities);
    }
  }

  /**
   * Get the Bill metadata.
   *
   * Loads identifying metadata from bill nodes specified by provided
   * node IDs.  Identifying data consists of nid, title, session, print
   * number, and base print number.
   *
   * @param int|array $nids
   *   Node IDs to load.
   */
  public function getBillMetadata($nids) {
    $ret = [];

    if (is_numeric($nids)) {
      $nids = [$nids];
    }

    if (count($nids)) {
      $query = $this->connection->select('node_field_data', 'n');
      $query->leftJoin('node__field_ol_session', 'sess', 'n.nid=sess.entity_id');
      $query->leftJoin('node__field_ol_print_no', 'pn', 'n.nid=pn.entity_id');
      $query->leftJoin('node__field_ol_base_print_no', 'bpn', 'n.nid=bpn.entity_id');
      $query->addField('n', 'nid');
      $query->addField('n', 'title');
      $query->addField('sess', 'field_ol_session_value', 'session');
      $query->addField('pn', 'field_ol_print_no_value', 'print_num');
      $query->addField('bpn', 'field_ol_base_print_no_value', 'base_print_num');
      $query->condition('n.type', 'bill');
      $query->condition('n.nid', $nids, 'IN');

      $ret = $query->execute()->fetchAllAssoc('nid');
    }
    return $ret;
  }

  /**
   * Discovers bill for their multi-session root.
   *
   * @param int $tid
   *   The tid of the taxonomy term.
   */
  public function loadBillsFromTid($tid) {
    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', 'bill')
      ->condition('field_bill_multi_session_root', [$tid], 'IN');
    $result = $query->execute();

    return $result;
  }

  /**
   * Helper function to return previous versions of a bill.
   *
   * @param string $prev_vers_session
   *   OL Session.
   * @param string $prev_vers_print_no
   *   Print Number.
   *
   * @return array
   *   Array of query results.
   */
  public function getPrevVersions($prev_vers_session, $prev_vers_print_no) {
    // We're using drupal_html_class() ensure that parameters have no spaces in
    // them.
    $cid = 'nysenate_bill_prev_versions_' .
      str_replace(' ', '', $prev_vers_session) . '-' .
      str_replace(' ', '', $prev_vers_print_no);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->condition('type', ['bill', 'resolution'], 'IN')
      ->condition('field_ol_session.value', $prev_vers_session)
      ->condition('field_ol_print_no.value', $prev_vers_print_no)
      ->range(0, 1);
    $prev_vers_result = $query->execute();

    // Cache data for later use.
    $this->cache->set($cid, $prev_vers_result);

    return $prev_vers_result;
  }

  /**
   * Query the database for previous versions of opposite chamber bills.
   *
   * @param int $nid
   *   The Node id.
   */
  public function getOppositeChamberPrevVersions($nid) {
    $related_metadata = [];

    // Get the multi-session root TID for the "same as" bill.
    $query = $this->connection->select('node__field_bill_multi_session_root', 'f');
    $query->addField('f', 'field_bill_multi_session_root_target_id');
    $query->condition('f.bundle', 'bill');
    $query->condition('f.deleted', 0);
    $query->condition('f.entity_id', $nid);
    $query->range(0, 1);
    $same_as_tid = $query->execute()->fetchField();

    // If a TID is found, add all related bills to the metadata collection.
    if ($same_as_tid) {
      $related_bills = $this->loadBillsFromTid($same_as_tid);
      $metadata = $this->getBillMetadata($related_bills);

      // Load all bills associated with this bill's taxonomy root.
      $related_metadata = array_filter($metadata, function ($v) {
        return $v->print_num === $v->base_print_num;
      });
    }

    return $related_metadata;

  }

  /**
   * Finds featured legislation quote, if it exists.
   *
   * @param array $amended_versions
   *   The bill amended versions.
   */
  public function findsFeaturedLegislationQuote(array $amended_versions) {
    $amendments = [];
    // Loop over amendments, and finds featured legislation quote, if it exists.
    foreach ($amended_versions as $title => $nid) {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      $amendments[$title]['node'] = $node;
      // @todo Query for quotes.
    }

    return $amendments;
  }

}
