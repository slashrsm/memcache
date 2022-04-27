<?php

namespace Drupal\memcache\Driver;

use Drupal\Component\Utility\Timer;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\memcache\MemcacheSettings;
use Drupal\memcache\DrupalMemcacheInterface;
use Psr\Log\LogLevel;

/**
 * Class DriverBase.
 */
abstract class DriverBase implements DrupalMemcacheInterface {

  use LoggerChannelTrait;

  /**
   * The memcache config object.
   *
   * @var \Drupal\memcache\MemcacheSettings
   */
  protected $settings;

  /**
   * The memcache object.
   *
   * @var \Memcache|\Memcached
   *   E.g. \Memcache|\Memcached
   */
  protected $memcache;

  /**
   * The hash algorithm to pass to hash(). Defaults to 'sha1'.
   *
   * @var string
   */
  protected $hashAlgorithm;

  /**
   * The prefix memcache key for all keys.
   *
   * @var string
   */
  protected $prefix;

  /**
   * Stats for the entire request.
   *
   * @var array
   */
  protected static $stats = [
    'all' => [],
    'ops' => [],
  ];

  /**
   * Constructs a DriverBase object.
   *
   * @param \Drupal\memcache\MemcacheSettings $settings
   *   The memcache config object.
   * @param \Memcached|\Memcache $memcache
   *   An existing memcache connection object.
   * @param string $bin
   *   The class instance specific cache bin to use.
   */
  public function __construct(MemcacheSettings $settings, $memcache, $bin = NULL) {
    $this->settings = $settings;
    $this->memcache = $memcache;

    $this->hashAlgorithm = $this->settings->get('key_hash_algorithm', 'sha1');

    $prefix = $this->settings->get('key_prefix', '');
    if ($prefix) {
      $this->prefix = $prefix . ':';
    }

    if ($bin) {
      $this->prefix .= $bin . ':';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function get($key) {
    $collect_stats = $this->statsInit();

    $full_key = $this->key($key);
    $result   = $this->memcache->get($full_key);

    // This is a multi-part value.
    if (is_object($result) && !empty($result->multi_part_data)) {
      $result = $this->piecesGet($result->data, $result->cid);
    }

    if ($collect_stats) {
      $this->statsWrite('get', 'cache', [$full_key => (bool) $result]);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function key($key) {
    $full_key = urlencode($this->prefix . '-' . $key);

    // Memcache only supports key lengths up to 250 bytes.  If we have generated
    // a longer key, we shrink it to an acceptable length with a configurable
    // hashing algorithm. Sha1 was selected as the default as it performs
    // quickly with minimal collisions.
    if (strlen($full_key) > 250) {
      $full_key = urlencode($this->prefix . '-' . hash($this->hashAlgorithm, $key));
      $full_key .= '-' . substr(urlencode($key), 0, (250 - 1) - strlen($full_key) - 1);
    }

    return $full_key;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key) {
    $collect_stats = $this->statsInit();

    $full_key = $this->key($key);
    $result = $this->memcache->delete($full_key, 0);

    if ($collect_stats) {
      $this->statsWrite('delete', 'cache', [$full_key => $result]);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function flush() {
    $collect_stats = $this->statsInit();

    $result = $this->memcache->flush();

    if ($collect_stats) {
      $this->statsWrite('flush', 'cache', ['' => $result]);
    }
  }

  /**
   * Retrieves statistics recorded during memcache operations.
   *
   * @param string $stats_bin
   *   The bin to retrieve statistics for.
   * @param string $stats_type
   *   The type of statistics to retrieve when using the Memcache extension.
   * @param bool $aggregate
   *   Whether to aggregate statistics.
   */
  public function stats($stats_bin = 'cache', $stats_type = 'default', $aggregate = FALSE) {

    // The stats_type can be over-loaded with an integer slab id, if doing a
    // cachedump.  We know we're doing a cachedump if $slab is non-zero.
    $slab = (int) $stats_type;
    $stats = [];

    foreach ($this->getBins() as $bin => $target) {
      if ($stats_bin == $bin) {
        if (isset($this->memcache)) {
          if ($this->memcache instanceof \Memcached) {
            $stats[$bin] = $this->memcache->getStats();
          }

          // The PHP Memcache extension 3.x version throws an error if the stats
          // type is NULL or not in {reset, malloc, slabs, cachedump, items,
          // sizes}. If $stats_type is 'default', then no parameter should be
          // passed to the Memcache memcache_get_extended_stats() function.
          elseif ($this->memcache instanceof \Memcache) {
            if ($stats_type == 'default' || $stats_type == '') {
              $stats[$bin] = $this->memcache->getExtendedStats();
            }

            // If $slab isn't zero, then we are dumping the contents of a
            // specific cache slab.
            elseif (!empty($slab)) {
              $stats[$bin] = $this->memcache->getStats('cachedump', $slab);
            }
            else {
              $stats[$bin] = $this->memcache->getExtendedStats($stats_type);
            }
          }
        }
      }
    }

    // Optionally calculate a sum-total for all servers in the current bin.
    if ($aggregate) {

      // Some variables don't logically aggregate.
      $no_aggregate = [
        'pid',
        'time',
        'version',
        'libevent',
        'pointer_size',
        'accepting_conns',
        'listen_disabled_num',
      ];

      foreach ($stats as $bin => $servers) {
        if (is_array($servers)) {
          foreach ($servers as $server) {
            if (is_array($server)) {
              foreach ($server as $key => $value) {
                if (!in_array($key, $no_aggregate)) {
                  if (isset($stats[$bin]['total'][$key])) {
                    $stats[$bin]['total'][$key] += $value;
                  }
                  else {
                    $stats[$bin]['total'][$key] = $value;
                  }
                }
              }
            }
          }
        }
      }
    }

    return $stats;
  }

  /**
   * Helper function to get the bins.
   */
  public function getBins() {
    $memcache_bins = \Drupal::configFactory()->getEditable('memcache.settings')->get('memcache_bins');
    if (!isset($memcache_bins)) {
      $memcache_bins = ['cache' => 'default'];
    }

    return $memcache_bins;
  }

  /**
   * Helper function to get the servers.
   */
  public function getServers() {
    $memcache_servers = \Drupal::configFactory()->getEditable('memcache.settings')->get('memcache_servers');
    if (!isset($memcache_servers)) {
      $memcache_servers = ['127.0.0.1:11211' => 'default'];
    }

    return $memcache_servers;
  }

  /**
   * Helper function to get memcache.
   */
  public function getMemcache() {
    return $this->memcache;
  }

  /**
   * Helper function to get request stats.
   */
  public function requestStats() {
    return self::$stats;
  }

  /**
   * Returns an array of available statistics types.
   */
  public function statsTypes() {
    if ($this->memcache instanceof \Memcache) {
      // TODO: Determine which versions of the PECL memcache extension have
      // these other stats types: 'malloc', 'maps', optionally detect this
      // version and expose them.  These stats are "subject to change without
      // warning" unfortunately.
      return ['default', 'slabs', 'items', 'sizes'];
    }
    else {
      // The Memcached PECL extension only offers the default statistics.
      return ['default'];
    }
  }

  /**
   * Helper function to initialize the stats for a memcache operation.
   */
  protected function statsInit() {
    static $drupal_static_fast;

    if (!isset($drupal_static_fast)) {
      $drupal_static_fast = &drupal_static(__FUNCTION__, ['variable_checked' => NULL, 'user_access_checked' => NULL]);
    }
    $variable_checked    = &$drupal_static_fast['variable_checked'];
    $user_access_checked = &$drupal_static_fast['user_access_checked'];

    // Confirm DRUPAL_BOOTSTRAP_VARIABLES has been reached. We don't use
    // drupal_get_bootstrap_phase() as it's buggy. We can use variable_get()
    // here because _drupal_bootstrap_variables() includes module.inc
    // immediately after it calls variable_initialize().
    // @codingStandardsIgnoreStart
    // if (!isset($variable_checked) && function_exists('module_list')) {
    //   $variable_checked = variable_get('show_memcache_statistics', FALSE);
    // }
    // If statistics are enabled we need to check user access.
    // if (!empty($variable_checked) && !isset($user_access_checked) && !empty($GLOBALS['user']) && function_exists('user_access')) {
    //   // Statistics are enabled and the $user object has been populated, so check
    //   // that the user has access to view them.
    //   $user_access_checked = user_access('access memcache statistics');
    // }
    // @codingStandardsIgnoreEnd
    // Return whether or not statistics are enabled and the user can access
    // them.
    if ((!isset($variable_checked) || $variable_checked) && (!isset($user_access_checked) || $user_access_checked)) {
      Timer::start('dmemcache');
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Memcache statistics to be displayed at end of page generation.
   *
   * @param string $action
   *   The action being performed (get, set, etc...).
   * @param string $bin
   *   The memcache bin the action is being performed in.
   * @param array $keys
   *   Keyed array in the form (string)$cid => (bool)$success. The keys the
   *   action is being performed on, and whether or not it was a success.
   */
  protected function statsWrite($action, $bin, array $keys) {

    // Determine how much time elapsed to execute this action.
    $time = Timer::read('dmemcache');

    // Build the 'all' and 'ops' arrays displayed by memcache_admin.module.
    foreach ($keys as $key => $success) {
      self::$stats['all'][] = [
        number_format($time, 2),
        $action,
        $bin,
        $key,
        $success ? 'hit' : 'miss',
      ];
      if (!isset(self::$stats['ops'][$action])) {
        self::$stats['ops'][$action] = [$action, 0, 0, 0];
      }
      self::$stats['ops'][$action][1] += $time;
      if ($success) {
        self::$stats['ops'][$action][2]++;
      }
      else {
        self::$stats['ops'][$action][3]++;
      }
    }
  }

  /**
   *  Split a large item into pieces and place them into memcache
   *
   * @param string $key
   *   The string with which you will retrieve this item later.
   * @param mixed $value
   *   The item to be stored.
   * @param int $exp
   *   (optional) Expiration time in seconds. If it's 0, the item never expires
   *   (but memcached server doesn't guarantee this item to be stored all the
   *   time, it could be deleted from the cache to make place for other items).
   *
   * @return bool
   */
  protected function piecesSet($key, $value, $exp = 0) {
    static $recursion = 0;
    if (!empty($value->multi_part_data) || !empty($value->multi_part_pieces)) {
      // Prevent an infinite loop.
      return FALSE;
    }

    // Recursion happens when __dmemcache_piece_cache outgrows the largest
    // memcache slice (1 MiB by default) -- prevent an infinite loop and later
    // generate a watchdog error.
    if ($recursion) {
      return FALSE;
    }
    $recursion++;

    $full_key = $this->key($key);

    // Cache the name of this key so if it is deleted later we know to also
    // delete the cache pieces.
    if (!$this->piecesCacheSet($full_key, $exp)) {
      // We're caching a LOT of large items. Our piece_cache has exceeded the
      // maximum memcache object size (default of 1 MiB).
      $piece_cache = &drupal_static('dmemcache_piece_cache', array());

      register_shutdown_function(function ($count) {
        \Drupal::logger('memcache')->log(
          LogLevel::ERROR,
          new TranslatableMarkup(
            'Too many over-sized cache items (@count) has caused the dmemcache_piece_cache to exceed the maximum memcache object size (default of 1 MiB). Now relying on memcache auto-expiration to eventually clean up over-sized cache pieces upon deletion.',
            [
              '@count' => $count,
            ]
          ));

      }, count($piece_cache));
    }

    if (Settings::get('memcache_log_data_pieces', 2)) {
      Timer::start('memcache_split_data');
    }

    // We need to split the item into pieces, so convert it into a string.
    if (is_string($value)) {
      $data = $value;
      $serialized = FALSE;
    }
    else {
      $serialize_function = $this->serializeFunction();
      $data = $serialize_function($value);
      $serialized = TRUE;
    }

    // Account for any metadata stored alongside the data.
    $max_len = Settings::get('memcache_data_max_length', 1048576) - (512 + strlen($full_key));
    $pieces = str_split($data, $max_len);

    $piece_count = count($pieces);

    // Create a placeholder item containing data about the pieces.
    $cache = new \stdClass;
    // $key gets run through ::key() later inside ::set().
    $cache->cid = $key;
    $cache->created = REQUEST_TIME;
    $cache->expire = $exp;
    $cache->data = new \stdClass;
    $cache->data->serialized = $serialized;
    $cache->data->piece_count = $piece_count;
    $cache->multi_part_data = TRUE;
    $result = $this->set($cache->cid, $cache, $exp);

    // Create a cache item for each piece of data.
    foreach ($pieces as $id => $piece) {
      $cache = new \stdClass;
      $cache->cid = $this->piecesKey($key, $id);
      $cache->created = REQUEST_TIME;
      $cache->expire = $exp;
      $cache->data = $piece;
      $cache->multi_part_piece = TRUE;

      $result &= $this->set($cache->cid, $cache, $exp);
    }

    if (Settings::get('memcache_log_data_pieces', 2) && $piece_count >= Settings::get('memcache_log_data_pieces', 2)) {
      if (function_exists('format_size')) {
        $data_size = format_size(strlen($data));
      }
      else {
        $data_size = number_format(strlen($data)) . ' byte';
      }
      register_shutdown_function(function ($time, $bytes, $pieces, $key) {
        \Drupal::logger('memcache')->log(
          LogLevel::WARNING,
          new TranslatableMarkup(
            'Spent @time ms splitting @bytes object into @pieces pieces, cid = @key',
            [
              '@time' => $time,
              '@bytes' => $bytes,
              '@pieces' => $pieces,
              '@key' => $key,
            ]
          ));

      }, Timer::read('memcache_split_data'), $data_size, $piece_count, $this->key($key));
    }

    $recursion--;

    // TRUE if all pieces were saved correctly.
    return $result;
  }

  /**
   * Retrieve a value from the cache.
   *
   * @param $item
   *   The placeholder cache item from ::piecesSet().
   * @param $key
   *   The key with which the item was stored.
   *
   * @return object|bool
   *   The item which was originally saved or FALSE.
   */
  protected function piecesGet($item, $key) {
    // Create a list of keys for the pieces of data.
    for ($id = 0; $id < $item->piece_count; $id++) {
      $keys[] = $this->piecesKey($key, $id);
    }

    // Retrieve all the pieces of data and append them together.
    $pieces = $this->getMulti($keys);
    $data = '';
    foreach ($pieces as $piece) {
      // The piece may be NULL if it didn't exist in memcache. If so,
      // we have to just return false for the entire set because we won't
      // be able to reconstruct the original data without all the pieces.
      if (!$piece) {
        return FALSE;
      }
      $data .= $piece->data;
    }
    unset($pieces);

    // If necessary unserialize the item.
    if (empty($item->serialized)) {
      return $data;
    }
    else {
      $unserialize_function = $this->unserializeFunction();
      return $unserialize_function($data);
    }
  }

  /**
   * Generates a key name for a multi-part data piece based on the sequence ID.
   *
   * @param int $id
   *   The sequence ID of the data piece.
   * @param int $key
   *   The original CID of the cache item.
   *
   * @return string
   */
  protected function piecesKey($key, $id) {
    return $this->key('_multi'. (string)$id . "-$key");
  }

  /**
   * Track active keys with multi-piece values, necessary for efficient cleanup.
   *
   * We can't use variable_get/set for tracking this information because if the
   * variables array grows >1M and has to be split into pieces we'd get stuck in
   * an infinite loop. Storing this information in memcache means it can be lost,
   * but in that case the pieces will still eventually be auto-expired by
   * memcache.
   *
   * @param string $cid
   *   The cid of the root multi-piece value.
   * @param integer $exp
   *   Timestamp when the cached item expires. If NULL, the $cid will be deleted.
   *
   * @return bool
   *   TRUE on succes, FALSE otherwise.
   */
  protected function piecesCacheSet($cid, $exp = NULL) {
    // Always refresh cached copy to minimize multi-thread race window.
    $piece_cache = &drupal_static('dmemcache_piece_cache_' . $this->prefix, array());
    $piece_cache = $this->get('__dmemcache_piece_cache');
    if (!is_array($piece_cache)) {
      $piece_cache = array();
    }

    if (isset($exp)) {
      if ($exp <= 0) {
        // If no expiration time is set, defaults to 30 days.
        $exp = REQUEST_TIME + 2592000;
      }
      $piece_cache[$cid] = $exp;
    }
    else {
      unset($piece_cache[$cid]);
    }

    return $this->set('__dmemcache_piece_cache', $piece_cache);
  }

  /**
   * Determine if a key has multi-piece values.
   *
   * @param string $name
   *   The cid to check for multi-piece values.
   *
   * @return integer
   *   Expiration time if key has multi-piece values, otherwise FALSE.
   */
  function piecesCacheGet($name) {
    static $drupal_static_fast;
    if (!isset($drupal_static_fast)) {
      $drupal_static_fast['piece_cache_' . $this->prefix] = &drupal_static('dmemcache_piece_cache_' . $this->prefix, FALSE);
    }
    $piece_cache = &$drupal_static_fast['piece_cache'];

    if (!is_array($piece_cache)) {
      $piece_cache = $this->get('__dmemcache_piece_cache');
      // On a website with no over-sized cache pieces, initialize the variable so
      // we never load it more than once per page versus once per DELETE.
      if (!is_array($piece_cache)) {
        $this->set('__dmemcache_piece_cache', array());
      }
    }

    if (isset($piece_cache[$name])) {
      // Return the expiration time of the multi-piece cache item.
      return $piece_cache[$name];
    }
    // Item doesn't have multiple pieces.
    return FALSE;
  }

  /**
   * Determine which serialize extension to use: serialize (none), igbinary,
   * or msgpack.
   *
   * By default we prefer the igbinary extension, then the msgpack extension,
   * then the core serialize functions. This can be overridden in settings.php.
   */
  protected function serializeExtension() {
    static $extension = NULL;
    if ($extension === NULL) {
      $preferred = strtolower(Settings::get('memcache_serialize'));
      // Fastpath if we're forcing php's own serialize function.
      if ($preferred == 'serialize') {
        $extension = $preferred;
      }
      // Otherwise, find an available extension favoring configuration.
      else {
        $igbinary_available = extension_loaded('igbinary');
        $msgpack_available = extension_loaded('msgpack');
        if ($preferred == 'igbinary' && $igbinary_available) {
          $extension = $preferred;
        }
        elseif ($preferred == 'msgpack' && $msgpack_available) {
          $extension = $preferred;
        }
        else {
          // No (valid) serialize extension specified, try igbinary.
          if ($igbinary_available) {
            $extension = 'igbinary';
          }
          // Next try msgpack.
          else if ($msgpack_available) {
            $extension = 'msgpack';
          }
          // Finally fall back to core serialize.
          else {
            $extension = 'serialize';
          }
        }
      }
    }
    return $extension;
  }

  /**
   * Return proper serialize function.
   */
  protected function serializeFunction() {
    switch ($this->serializeExtension()) {
      case 'igbinary':
        return 'igbinary_serialize';
      case 'msgpack':
        return 'msgpack_pack';
      default:
        return 'serialize';
    }
  }

  /**
   * Return proper unserialize function.
   */
  function unserializeFunction() {
    switch ($this->serializeExtension()) {
      case 'igbinary':
        return 'igbinary_unserialize';
      case 'msgpack':
        return 'msgpack_unpack';
      default:
        return 'unserialize';
    }
  }
}
