<?php

/**
 * @file
 * Contains \Drupal\memcache\MemcacheBackendFactory.
 */

namespace Drupal\memcache;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\memcache\Driver\MemcacheDriverFactory;

/**
 * Class MemcacheBackendFactory.
 */
class MemcacheBackendFactory implements CacheFactoryInterface {

  /**
   * The memcache factory object.
   *
   * @var \Drupal\memcache\Driver\MemcacheDriverFactory
   */
  protected $memcacheFactory;

  /**
   * The cache tags checksum provider.
   *
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  protected $checksumProvider;

  /**
   * Constructs the DatabaseBackendFactory object.
   *
   * @param \Drupal\memcache\Driver\MemcacheDriverFactory $memcache_factory
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksum_provider
   */
  function __construct(MemcacheDriverFactory $memcache_factory, CacheTagsChecksumInterface $checksum_provider) {
    $this->memcacheFactory = $memcache_factory;
    $this->checksumProvider = $checksum_provider;
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
    return new MemcacheBackend(
      $bin,
      $this->memcacheFactory->get($bin),
      $this->checksumProvider
    );
  }

}
