<?php

/**
 * @file
 * Contains \Drupal\memcache\DrupalMemcacheFactory.
 */

namespace Drupal\memcache;

use Psr\Log\LogLevel;

use Drupal\memcache\Driver\MemcacheConnection;
use Drupal\memcache\Driver\MemcachedConnection;

/**
 * Factory class for creation of Memcache objects.
 */
class DrupalMemcacheFactory {

  /**
   * The settings object.
   *
   * @var \Drupal\memcache\DrupalMemcacheConfig
   */
  protected $settings;

  /**
   * @var string
   */
  protected $driverClass;

  /**
   * @var string
   */
  protected $drupalClass;

  /**
   * @var bool
   */
  protected $memcachePersistent;

  /**
   * @var \Drupal\memcache\Driver\MemcacheConnectionInterface[]
   */
  protected $memcacheCache = [];

  /**
   * @var array
   */
  protected $memcacheServers = [];

  /**
   * @var array
   */
  protected $memcacheBins = [];

  /**
   * @var array
   */
  protected $failedConnectionCache = [];

  /**
   * Constructs a DrupalMemcacheFactory object.
   *
   * @param \Drupal\memcache\DrupalMemcacheConfig $settings
   */
  public function __construct(DrupalMemcacheConfig $settings) {
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
      $this->flush();
    }

    if (empty($this->memcacheCache) || empty($this->memcacheCache[$bin])) {
      // If there is no cluster for this bin in $memcache_bins, cluster is
      // 'default'.
      $cluster = empty($this->memcacheBins[$bin]) ? 'default' : $this->memcacheBins[$bin];

      // If this bin isn't in our $memcacheBins configuration array, and the
      // 'default' cluster is already initialized, map the bin to 'default'
      // because we always map the 'default' bin to the 'default' cluster.
      if (empty($this->memcacheBins[$bin]) && !empty($this->memcacheCache['default'])) {
        $this->memcacheCache[$bin] = &$this->memcacheCache['default'];
      }
      else {
        // Create a new Memcache object. Each cluster gets its own Memcache
        // object.
        /** @var \Drupal\memcache\Driver\MemcacheConnectionInterface $memcache */
        $memcache = new $this->driverClass($this->settings);

        // A variable to track whether we've connected to the first server.
        $init = FALSE;

        // Link all the servers to this cluster.
        foreach ($this->memcacheServers as $s => $c) {
          if ($c == $cluster && !isset($this->failedConnectionCache[$s])) {
            if ($memcache->addServer($s, $this->memcachePersistent) && !$init) {
              $init = TRUE;
            }

            if (!$init) {
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
        else {
          throw new MemcacheException('Memcache instance could not be initialized. Check memcache is running and reachable');
        }
      }
    }

    return empty($this->memcacheCache[$bin]) ? FALSE : new $this->drupalClass($this->settings, $this->memcacheCache[$bin]->getMemcache(), $bin);
  }

  /**
   * Initializes memcache settings.
   */
  protected function initialize() {
    // If an extension is specified in settings.php, use that when available.
    $preferred = $this->settings->get('extension', NULL);

    if (isset($preferred) && class_exists($preferred)) {
      $extension = $preferred;
    }
    // If no extension is set, default to Memcached.
    elseif (class_exists('Memcached')) {
      $extension = \Memcached::class;
    }
    elseif (class_exists('Memcache')) {
      $extension = \Memcache::class;
    }
    else {
      throw new MemcacheException('No Memcache extension found');
    }

    // @todo Make driver class configurable?
    $this->driverClass = MemcachedConnection::class;
    $this->drupalClass = DrupalMemcached::class;

    if ($extension === \Memcache::class) {
      $this->driverClass = MemcacheConnection::class;
      $this->drupalClass = DrupalMemcache::class;
    }

    // Values from settings.php
    $this->memcacheServers = $this->settings->get('servers', ['127.0.0.1:11211' => 'default']);
    $this->memcacheBins = $this->settings->get('bins', ['default' => 'default']);

    // Indicate whether to connect to memcache using a persistent connection.
    // Note: this only affects the Memcache PECL extension, and does not affect
    // the Memcached PECL extension.  For a detailed explanation see:
    // http://drupal.org/node/822316#comment-4427676
    $this->memcachePersistent = $this->settings->get('persistent', FALSE);
  }

  /**
   * Flushes the memcache bin/server/cache mappings and closes connections.
   */
  protected function flush() {
    foreach ($this->memcacheCache as $cluster) {
      $cluster->close();
    }

    $this->memcacheCache = [];
  }

}
