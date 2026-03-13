<?php
/**
 * Helpers class injected as dependancy for others
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Helpers {
  /**
   * API URLs
   */
  public $dev_mode = true;
  public $frontend_url = 'http://localhost:3000';
  public $docker_url = 'http://host.docker.internal:3000';
  public $api_endpoint = '/api';
  public $api_url;
  public $blocks_endpoint = '/api/blocks';
  public $blocks_url;

  /**
   * Polylang
   */
  public $languages = [];
  public $default_language = '';

  public function __construct() {
    // Setup languages using Polylang.
    add_action( 'init', [ $this, 'init_polylang' ] );
  }

  /**
   * Get working Docker URL for local development
   */
  private function get_docker_url( $return_local = false ) {
    if ( $return_local ) return 'http://localhost:3000';
 
    // Test if host.docker.internal actually works
    $test_url = 'http://host.docker.internal:3000';
    $response = wp_remote_get( $test_url, [ 'timeout' => 2 ] );
    
    if ( ! is_wp_error( $response ) ) {
      return 'http://host.docker.internal:3000';
    }
    
    return 'http://localhost:3000'; // Final fallback
  }

  /**
   * Get the correct frontend URL with multiple fallback strategies
   */
  private function get_frontend_url( $return_local = false ) {
    // Try ACF field first (most reliable when available)
    if ( function_exists( 'get_field' ) ) {
      $frontend_url = get_field( 'frontend_url', 'option' );
      if ( $frontend_url ) {
        return rtrim( $frontend_url, '/' );
      }
    }
    
    // Fallback to WordPress option (more reliable than ACF)
    $frontend_url = get_option( 'options_frontend_url' );
    if ( $frontend_url ) {
      return rtrim( $frontend_url, '/' );
    }
    
    // Local development: try Docker URLs
    return $this->get_docker_url( $return_local );
  }

  /**
   * Get API URL
   */
  private function get_api_url() {
    return $this->get_frontend_url() . $this->api_endpoint;
  }

  /**
   * Get blocks URL
   */
  private function get_blocks_url() {
    return $this->get_frontend_url() . $this->blocks_endpoint;
  }

  /**
   * Public getter for frontend URL (for backwards compatibility)
   */
  public function get_frontend_url_public() {
    return $this->get_frontend_url( true );
  }

  /**
   * Fetch all themes/blocks from nextjs api
   */
  public function fetch_blocks_from_api( $theme = null, $source = '' ) {
    $cache_parts = ['next_blocks'];
    if ( $theme ) {
      if ( is_array( $theme ) ) {
        sort( $theme );
        $cache_parts[] = implode( '_', $theme );
      } else {
        $cache_parts[] = $theme;
      }
    }
    if ( $source ) {
      $cache_parts[] = $source;
    }
    
    $cache_key = implode( '_', $cache_parts );
    if ( strlen( $cache_key ) > 150 ) {
      $cache_key = 'next_blocks_' . md5( $cache_key );
    }
    
    $blocks_cache = get_transient( $cache_key );

    if ( $blocks_cache && ! empty( $blocks_cache ) ) {
      $data = maybe_unserialize( $blocks_cache );
      return $data;
    } else {
      $blocks_url = $this->get_blocks_url();

      // Failsafe if not localhost.
      if (
        ( 
          strpos( $blocks_url, 'host.docker' ) !== false || 
          strpos( $blocks_url, 'localhost' ) !== false
        ) && strpos( site_url(), 'localhost' ) === false
      ) {
        return;
      }

      if ( $theme ) {
        if ( is_array( $theme ) ) {
          $theme = implode( ',', $theme );
        }
        $blocks_url .= '?theme=' . $theme;
      }

      // Rate limiting: Track request count and implement circuit breaker
      $url_hash = md5( $blocks_url );
      $rate_limit_key = 'blocks_api_requests';
      $circuit_breaker_key = 'blocks_api_circuit_breaker_' . $url_hash;
      $current_requests = get_transient( $rate_limit_key ) ?: 0;
      
      // Check if circuit breaker is active (after too many failures)
      if ( get_transient( $circuit_breaker_key ) ) {
        error_log( 'API requests blocked by circuit breaker' );
        return;
      }
      
      // Rate limit: max 3 requests per 30 seconds to prevent bursts
      if ( $current_requests >= 3 ) {
        error_log( 'API request rate limit exceeded' );
        return;
      }
  
      $response = wp_remote_get(
        $blocks_url,
        [
          'timeout' => 20,
          'sslverify' => ! $this->dev_mode,
          'redirection' => 2
        ]
      );

      // Increment request counter
      $this->set_transient_no_autoload( $rate_limit_key, $current_requests + 1, 30 );
  
      if ( is_wp_error( $response ) ) {
        error_log( 'API request failed: ' . $response->get_error_message() );
        
        // Circuit breaker: Track consecutive failures
        $failure_count_key = 'blocks_api_failures_' . $url_hash;
        $failure_count = get_transient( $failure_count_key ) ?: 0;
        $failure_count++;
        
        // After 3 consecutive failures, activate circuit breaker for 5 minutes
        if ( $failure_count >= 3 ) {
          $this->set_transient_no_autoload( $circuit_breaker_key, true, 300 ); // 5 minutes
          delete_transient( $failure_count_key );
          error_log( 'Circuit breaker activated due to repeated API failures' );
        } else {
          $this->set_transient_no_autoload( $failure_count_key, $failure_count, 300 );
        }
        
        return false;
      }
  
      $response_code = wp_remote_retrieve_response_code( $response );
      if ( $response_code !== 200 ) {
        error_log( 'API request failed with response code: ' . $response_code );
        
        // Circuit breaker: Track consecutive failures
        $failure_count_key = 'blocks_api_failures_' . $url_hash;
        $failure_count = get_transient( $failure_count_key ) ?: 0;
        $failure_count++;
        
        // After 3 consecutive failures, activate circuit breaker for 5 minutes
        if ( $failure_count >= 3 ) {
          $this->set_transient_no_autoload( $circuit_breaker_key, true, 300 ); // 5 minutes
          delete_transient( $failure_count_key );
          error_log( 'Circuit breaker activated due to repeated API failures' );
        } else {
          $this->set_transient_no_autoload( $failure_count_key, $failure_count, 300 );
        }
        
        return false;
      }
      
      // Reset failure count on successful request
      delete_transient( 'blocks_api_failures_' . $url_hash );
  
      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );

      if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'Failed to parse API response: ' . json_last_error_msg() );
        return false;
      }

      // CRITICAL FIX: Set proper cache expiration (was missing, causing no caching!)
      // Cache blocks for 12 hours - they rarely change
      $cache_ttl = apply_filters( 'nextpress_blocks_cache_ttl', 12 * HOUR_IN_SECONDS );
      $this->set_transient_no_autoload( $cache_key, $data, $cache_ttl );
      return $data;
    }
  }

  /**
   * CRITICAL FIX: Set transient with autoload='no' to prevent options table bloat
   *
   * WordPress set_transient() creates options with autoload='yes' by default when
   * no object cache is available. This causes massive performance issues as ALL
   * autoloaded options are loaded on EVERY request.
   *
   * @param string $transient Transient name (without _transient_ prefix)
   * @param mixed $value Transient value
   * @param int $expiration Time until expiration in seconds
   * @return bool True on success, false on failure
   */
  public function set_transient_no_autoload( $transient, $value, $expiration = 0 ) {
    global $wpdb;

    // First, set the transient normally
    $result = set_transient( $transient, $value, $expiration );

    // Then, ensure autoload is set to 'no' for both the transient and its timeout
    // This prevents these from being loaded on every page request
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

  /**
   * REDIS-AWARE CACHE: Set cache value using Redis if available, fallback to transient
   *
   * This method provides a unified caching interface that:
   * - Uses wp_cache_set() when Redis/object cache is available (optimal performance)
   * - Falls back to set_transient_no_autoload() when no object cache (prevents autoload bloat)
   *
   * @param string $key Cache key
   * @param mixed $value Value to cache
   * @param string $group Cache group (used for wp_cache, prepended to transient key)
   * @param int $expiration Time until expiration in seconds
   * @return bool True on success, false on failure
   */
  public function cache_set( $key, $value, $group = '', $expiration = 0 ) {
    // Prefer object cache (Redis) when available - this is the optimal path
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_set( $key, $value, $group, $expiration );
    }

    // Fall back to transient with autoload='no' to prevent database bloat
    $transient_key = $group ? $group . '_' . $key : $key;
    return $this->set_transient_no_autoload( $transient_key, $value, $expiration );
  }

  /**
   * REDIS-AWARE CACHE: Get cache value using Redis if available, fallback to transient
   *
   * @param string $key Cache key
   * @param string $group Cache group (used for wp_cache, prepended to transient key)
   * @return mixed|false Cached value or false if not found
   */
  public function cache_get( $key, $group = '' ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_get( $key, $group );
    }

    $transient_key = $group ? $group . '_' . $key : $key;
    return get_transient( $transient_key );
  }

  /**
   * REDIS-AWARE CACHE: Delete cache value
   *
   * @param string $key Cache key
   * @param string $group Cache group
   * @return bool True on success, false on failure
   */
  public function cache_delete( $key, $group = '' ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_delete( $key, $group );
    }

    $transient_key = $group ? $group . '_' . $key : $key;
    return delete_transient( $transient_key );
  }

  /**
   * REDIS-AWARE CACHE: Flush entire cache group
   *
   * @param string $group Cache group to flush
   * @return bool True on success
   */
  public function cache_flush_group( $group ) {
    if ( wp_using_ext_object_cache() ) {
      return wp_cache_flush_group( $group );
    }

    // Without object cache, we need to manually delete matching transients
    // This is slower but necessary for the fallback path
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
   * Revalidate Next.js route.
   */
  public function revalidate_fetch_route( $tag ) {
    $request_url = $this->get_api_url() . "/revalidate?tag=" . $tag;
    return wp_remote_get( $request_url );
  }

  /**
   * Revalidate specific Next.js path.
   */
  public function revalidate_specific_path( $path ) {
    $request_url = $this->get_api_url() . "/revalidate?path=" . urlencode( $path );
    return wp_remote_get( $request_url );
  }

  /**
   * Get homepage post object.
   */
  public function get_homepage() {
    if ( get_option( 'show_on_front' ) ===  'page' ) {
      return get_post( get_option( 'page_on_front' ) );
    } else {
      return get_page_by_path( 'home' );
    }
  }

  /**
   * Setup Polylang vars
   */
  public function init_polylang() {
    if ( function_exists( 'pll_the_languages' ) ) {
      $languages = pll_the_languages( [ 'raw' => 1 ] );
      $default = pll_default_language();
      if ( is_array( $languages ) && ! empty( $languages ) ) {
        foreach ( $languages as $key => $lang ) {
          unset( $languages[ $key ]['id'] );
          unset( $languages[ $key ]['order'] );
          unset( $languages[ $key ]['slug'] );
          unset( $languages[ $key ]['flag'] );
          unset( $languages[ $key ]['current_flag'] );
          unset( $languages[ $key ]['no_translation'] );
          unset( $languages[ $key ]['classes'] );
          unset( $languages[ $key ]['link_classes'] );
          unset( $languages[ $key ]['current_lang'] );
          $languages[ $key ]['default_language'] = $key === $default;
        }
      }
      $this->languages = $languages;
      $this->default_language = $default;
    }
  }
}