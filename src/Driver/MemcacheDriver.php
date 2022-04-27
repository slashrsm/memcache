<?php

namespace Drupal\memcache\Driver;

/**
 * Class MemcacheDriver.
 */
class MemcacheDriver extends DriverBase {

  /**
   * {@inheritdoc}
   */
  public function set($key, $value, $exp = 0, $flag = FALSE) {
    $collect_stats = $this->statsInit();

    $full_key = $this->key($key);

    // The PECL Memcache library throws an E_NOTICE level error, which
    // $php_errormsg doesn't catch, so we need to log it ourselves.
    // Catch it with our own error handler.
    drupal_static_reset('_dmemcache_error_handler');
    set_error_handler('_dmemcache_error_handler');
    $result = $this->memcache->set($full_key, $value, $flag, $exp);
    // Restore the Drupal error handler.
    restore_error_handler();

    if (empty($result)) {
      // If the object was too big, split the value into pieces and cache
      // them individually.
      $dmemcache_errormsg = &drupal_static('_dmemcache_error_handler');
      if (!empty($dmemcache_errormsg) && (strpos($dmemcache_errormsg, 'SERVER_ERROR object too large for cache') !== FALSE || strpos($dmemcache_errormsg, 'SERVER_ERROR out of memory storing object') !== FALSE)) {
        $result = $this->piecesSet($key, $value, $exp);
      }
    }

    if ($collect_stats) {
      $this->statsWrite('set', 'cache', [$full_key => (int) $result]);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function add($key, $value, $expire = 0) {
    $collect_stats = $this->statsInit();

    $full_key = $this->key($key);
    $result = $this->memcache->add($full_key, $value, FALSE, $expire);

    if ($collect_stats) {
      $this->statsWrite('add', 'cache', [$full_key => (int) $result]);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getMulti(array $keys) {
    $collect_stats = $this->statsInit();
    $multi_stats   = [];

    $full_keys = [];

    foreach ($keys as $key => $cid) {
      $full_key = $this->key($cid);
      $full_keys[$cid] = $full_key;

      if ($collect_stats) {
        $multi_stats[$full_key] = FALSE;
      }
    }

    $results = $this->memcache->get($full_keys);

    // If $results is FALSE, convert it to an empty array.
    if (!$results) {
      $results = [];
    }

    if ($collect_stats) {
      foreach ($multi_stats as $key => $value) {
        $multi_stats[$key] = isset($results[$key]) ? TRUE : FALSE;
      }
    }

    // Convert the full keys back to the cid.
    $cid_results = [];

    // Order isn't guaranteed, so ensure the return order matches that
    // requested. So base the results on the order of the full_keys, as they
    // reflect the order of the $cids passed in.
    foreach (array_intersect($full_keys, array_keys($results)) as $cid => $full_key) {
      $value = $results[$full_key];

      // This is a multi-part value.
      if (is_object($value) && !empty($value->multi_part_data)) {
        $value = $this->piecesGet($value->data, $value->cid);
      }

      $cid_results[$cid] = $value;
    }

    if ($collect_stats) {
      $this->statsWrite('getMulti', 'cache', $multi_stats);
    }

    return $cid_results;
  }

  /**
   * A temporary error handler which keeps track of the most recent error.
   */
  public static function errorHandler($errno, $errstr) {
    $dmemcache_errormsg = &drupal_static(__FUNCTION__);
    $dmemcache_errormsg = $errstr;
    return TRUE;
  }
}
