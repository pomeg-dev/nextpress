<?php
/**
 * Redis-aware cache service.
 *
 * Uses wp_cache (Redis/object cache) when available, falls back to transients
 * with autoload='no' to prevent options table bloat.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Cache {

  /**
   * Set a cache value.
   *
   * @param string $key        Cache key.
   * @param mixed  $value      Value to cache.
   * @param string $group      Cache group (used for wp_cache, prepended to transient key).
   * @param int    $expiration Time until expiration in seconds.
   * @return bool
   */
  public function set( $key, $value, $group = '', $expiration = 0 ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_set( $key, $value, $group, $expiration );
    }

    $transient_key = $group ? $group . '_' . $key : $key;
    return $this->set_transient_no_autoload( $transient_key, $value, $expiration );
  }

  /**
   * Get a cache value.
   *
   * @param string $key   Cache key.
   * @param string $group Cache group.
   * @return mixed|false
   */
  public function get( $key, $group = '' ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_get( $key, $group );
    }

    $transient_key = $group ? $group . '_' . $key : $key;
    return get_transient( $transient_key );
  }

  /**
   * Delete a cache value.
   *
   * @param string $key   Cache key.
   * @param string $group Cache group.
   * @return bool
   */
  public function delete( $key, $group = '' ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_delete( $key, $group );
    }

    $transient_key = $group ? $group . '_' . $key : $key;
    return delete_transient( $transient_key );
  }

  /**
   * Flush an entire cache group.
   *
   * @param string $group Cache group to flush.
   * @return bool
   */
  public function flush_group( $group ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_flush_group( $group );
    }

    global $wpdb;
    $pattern = $group . '_%';
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $pattern,
        '_transient_timeout_' . $pattern
      )
    );
    return true;
  }

  /**
   * Set transient with autoload='no' to prevent options table bloat.
   *
   * @param string $transient  Transient name (without _transient_ prefix).
   * @param mixed  $value      Transient value.
   * @param int    $expiration Time until expiration in seconds.
   * @return bool
   */
  public function set_transient_no_autoload( $transient, $value, $expiration = 0 ) {
    global $wpdb;

    $result = set_transient( $transient, $value, $expiration );

    $option_names = [
      '_transient_' . $transient,
      '_transient_timeout_' . $transient
    ];

    foreach ( $option_names as $option_name ) {
      $wpdb->query(
        $wpdb->prepare(
          "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name = %s",
          $option_name
        )
      );
    }

    return $result;
  }
}
