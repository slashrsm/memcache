<?php

namespace Drupal\memcache\Cache;

use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\CacheTagsChecksumTrait;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\memcache\Invalidator\TimestampInvalidatorInterface;

/**
 * Cache tags invalidations checksum implementation by timestamp invalidation.
 */
class TimestampCacheTagsChecksum implements CacheTagsChecksumInterface, CacheTagsInvalidatorInterface {

  use CacheTagsChecksumTrait {
    getCurrentChecksum as traitGetCurrentChecksum;
  }

  /**
   * The timestamp invalidator object.
   *
   * @var \Drupal\memcache\Invalidator\TimestampInvalidatorInterface
   */
  protected $invalidator;

  /**
   * Constructs a TimestampCacheTagsChecksum object.
   *
   * @param \Drupal\memcache\Invalidator\TimestampInvalidatorInterface $invalidator
   *   The timestamp invalidator object.
   */
  public function __construct(TimestampInvalidatorInterface $invalidator) {
    $this->invalidator = $invalidator;
  }

  /**
   * {@inheritdoc}
   */
  public function doInvalidateTags(array $tags) {
    foreach ($tags as $tag) {
      $this->tagCache[$tag] = $this->invalidator->invalidateTimestamp($tag);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentChecksum(array $tags) {
    return min($this->invalidator->getCurrentTimestamp(), $this->traitGetCurrentChecksum($tags));
  }

  /**
   * {@inheritdoc}
   */
  public function isValid($checksum, array $tags) {
    if (empty($tags)) {
      // If there weren't any tags, the checksum should always be 0 or FALSE.
      return $checksum == 0;
    }
    return $checksum == $this->calculateChecksum($tags);
  }

  /**
   * Calculates the current checksum for a given set of tags.
   *
   * @param array $tags
   *   The array of tags to calculate the checksum for.
   *
   * @return int
   *   The calculated checksum.
   */
  protected function calculateChecksum(array $tags) {

    $query_tags = array_diff($tags, array_keys($this->tagCache));
    if ($query_tags) {
      $tag_invalidations = $this->invalidator->getLastInvalidationTimestamps($query_tags);
      $this->tagCache += $tag_invalidations;
      $invalid = array_diff($query_tags, array_keys($tag_invalidations));
      if (!empty($invalid)) {
        // Invalidate any missing tags now. This is necessary because we cannot
        // zero-optimize our tag list -- we can't tell the difference between
        // a tag that has never been invalidated and a tag that was
        // garbage-collected by the backend!
        //
        // This behavioral difference is the main change that allows us to use
        // an unreliable backend to track cache tag invalidation.
        //
        // Invalidating the tag will cause it to start being tracked, so it can
        // be matched against the checksums stored on items.
        // All items cached after that point with the tag will end up with
        // a valid checksum, and all items cached before that point with the tag
        // will have an invalid checksum, because missing invalidations will
        // keep moving forward in time as they get garbage collected and are
        // re-invalidated.
        //
        // The main effect of all this is that a tag going missing
        // will automatically cause the cache items tagged with it to no longer
        // have the correct checksum.
        foreach ($invalid as $invalid_tag) {
          $this->invalidator->invalidateTimestamp($invalid_tag);
        }
      }
    }

    // The checksum is equal to the *most recent* invalidation of an applicable
    // tag. If the item is untagged, the checksum is always 0.
    return max([0] + array_intersect_key($this->tagCache, array_flip($tags)));
  }

  /**
   * {@inheritdoc}
   */
  protected function getTagInvalidationCounts(array $tags) {
    // Note that the CacheTagsChecksumTrait assumes that the checksum strategy
    // uses integer counters on each cache tag, but here we use timestamps. We
    // return an empty array since we don't fit that mould. Currently only
    // \Drupal\Core\Cache\CacheTagsChecksumTrait::calculateChecksum uses this
    // method, which we override.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function getDatabaseConnection() {
    // This is not injected to avoid a dependency on the database in the
    // critical path. It is only needed during cache tag invalidations.
    return \Drupal::database();
  }
}
