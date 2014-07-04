<?php

/**
 * @file
 * Contains \Drupal\memcache\MemcacheBackendFactory.
 */

namespace Drupal\memcache;

use Drupal\Core\Site\Settings;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Class DatabaseBackendFactory.
 */
class MemcacheBackendFactory {

  /**
   * The lock backend that should be used.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The settings object.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * The memcache factory object.
   *
   * @var \Drupal\memcache\DrupalMemcacheFactory
   */
  protected $memcacheFactory;

  /**
   * Constructs the DatabaseBackendFactory object.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   * @param \Drupal\Core\Site\Settings $settings
   * @param \Drupal\memcache\DrupalMemcacheFactory $memcache_factory
   */
  function __construct(LockBackendInterface $lock, Settings $settings, DrupalMemcacheFactory $memcache_factory) {
    $this->lock = $lock;
    $this->settings = $settings;
    $this->memcacheFactory = $memcache_factory;
  }

  /**
   * Gets MemcacheBackend for the specified cache bin.
   *
   * @param $bin
   *   The cache bin for which the object is created.
   *
   * @return \Drupal\memcache\MemcacheBackend
   *   The cache backend object for the specified cache bin.
   */
  public function get($bin) {
    return new MemcacheBackend($bin, $this->memcacheFactory->get($bin), $this->lock, $this->settings);
  }

}
