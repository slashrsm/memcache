<?php

/**
 * @file
 * Contains \Drupal\memcache\DrupalMemcacheFactory.
 */

namespace Drupal\memcache;

use Drupal\Core\Site\Settings;

/**
 * Factory class for creation of Memcache objects.
 */
class DrupalMemcacheFactory {

  /**
   * The settings object.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * @var string
   */
  protected $extension;

  /**
   * @var bool
   */
  protected $memcachePersistent;

  /**
   * @var array
   */
  protected $memcacheCache = array();

  /**
   * @var array
   */
  protected $memcacheServers = array();

  /**
   * @var array
   */
  protected $memcacheBins = array();

  /**
   * @var array
   */
  protected $failedConnectionCache = array();

  /**
   *
   */
  public function __construct(Settings $settings) {
    $this->settings = $settings;

    $this->initialize();
  }

  /**
   * Returns a Memcache object based on settings and the bin requested.
   *
   * @param string $bin
   *   The bin which is to be used.
   *
   * @param bool $flush
   *   Rebuild the bin/server/cache mapping.
   *
   * @return \Drupal\memcache\DrupalMemcacheInterface
   *   A Memcache object.
   */
  public function get($bin = NULL, $flush = FALSE) {
    if ($flush) {
      $this->memcacheFlush();
    }

    if (empty($this->memcacheCache) || empty($this->memcacheCache[$bin])) {
      // If there is no cluster for this bin in $memcache_bins, cluster is
      // 'default'.
      $cluster = empty($this->memcacheBins[$bin]) ? 'default' : $this->memcacheBins[$bin];

      // If this bin isn't in our $memcacheBins configuration array, and the
      // 'default' cluster is already initialized, map the bin to 'cache'
      // because we always map the 'cache' bin to the 'default' cluster.
      if (empty($this->memcacheBins[$bin]) && !empty($this->memcacheCache['cache'])) {
        $this->memcacheCache[$bin] = &$this->memcacheCache['cache'];
      }
      else {
        // Create a new Memcache object. Each cluster gets its own Memcache
        // object.
        // @todo Can't add a custom memcache class here yet.
        if ($this->extension == 'Memcached') {
          $memcache = new DrupalMemcached($bin, $this->settings);
        }
        elseif ($this->extension == 'Memcache') {
          $memcache = new DrupalMemcache($bin, $this->settings);
        }

        // A variable to track whether we've connected to the first server.
        $init = FALSE;

        // Link all the servers to this cluster.
        foreach ($this->memcacheServers as $s => $c) {
          if ($c == $cluster && !isset($this->failedConnectionCache[$s])) {
            if ($memcache->addServer($s, $this->memcachePersistent) && !$init) {
              $init = TRUE;
            }

            if (!$init) {
              // We can't use watchdog because this happens in a bootstrap phase
              // where watchdog is non-functional. Register a shutdown handler
              // instead so it gets recorded at the end of page load.
              register_shutdown_function('watchdog', 'memcache', 'Failed to connect to memcache server: !server', array('!server' => $s), WATCHDOG_ERROR);
              $this->failedConnectionCache[$s] = FALSE;
            }
          }
        }

        if ($init) {
          // Map the current bin with the new Memcache object.
          $this->memcacheCache[$bin] = $memcache;

          // Now that all the servers have been mapped to this cluster, look for
          // other bins that belong to the cluster and map them too.
          foreach ($this->memcacheBins as $b => $c) {
            if (($c == $cluster) && ($b != $bin)) {
              // Map this bin and cluster by reference.
              $this->memcacheCache[$b] = &$this->memcacheCache[$bin];
            }
          }
        }
      }
    }

    return empty($this->memcacheCache[$bin]) ? FALSE : $this->memcacheCache[$bin];
  }

  /**
   * Initializes memcache settings.
   */
  protected function initialize() {
    // If an extension is specified in settings.php, use that when available.
    $preferred = $this->settings->get('memcache_extension', NULL);
    if (isset($preferred) && class_exists($preferred)) {
      $this->extension = $preferred;
    }
    // If no extension is set, default to Memcache. The Memcached extension has
    // some features that the older extension lacks but also an unfixed bug that
    // affects cache clears.
    // @see http://pecl.php.net/bugs/bug.php?id=16829
    elseif (class_exists('Memcache')) {
      $this->extension = 'Memcache';
    }
    elseif (class_exists('Memcached')) {
      $this->extension = 'Memcached';
    }

    // Values from settings.php
    $this->memcacheServers = $this->settings->get('memcache_servers', array('127.0.0.1:11211' => 'default'));
    $this->memcacheBins = $this->settings->get('memcache_bins', array('cache' => 'default'));

    // Indicate whether to connect to memcache using a persistent connection.
    // Note: this only affects the Memcache PECL extension, and does not affect
    // the Memcached PECL extension.  For a detailed explanation see:
    // http://drupal.org/node/822316#comment-4427676
    $this->memcachePersistent = $this->settings->get('memcache_persistent', FALSE);
  }

  /**
   * Flushes the memcache bin/server/cache mappings.
   */
  protected function memcacheFlush() {
    foreach ($this->memcacheCache as $cluster) {
      memcache_close($cluster);
    }

    $this->memcacheCache = array();
  }

}
