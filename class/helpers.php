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
   * Cache service.
   */
  public $cache;

  /**
   * Polylang
   */
  public $languages = [];
  public $default_language = '';

  public function __construct() {
    $this->cache = new Cache();
    // Setup languages using Polylang.
    add_action( 'init', [ $this, 'init_polylang' ] );

    // Clear caches if GET param set.
    add_action( 'init', [ $this, 'clear_wp_cache' ] );

    // Database query monitoring for performance debugging.
    add_action( 'init', [ $this, 'maybe_enable_query_monitoring' ] );
  }

  /**
   * Get working Docker URL for local development
   */
  private function get_docker_url( $return_local = false ) {
    if ( $return_local ) return 'http://localhost:3000';

    $cached = $this->cache_get( 'docker_url', 'nextpress' );
    if ( $cached !== false ) {
      return $cached;
    }

    // Test if host.docker.internal actually works (cached for 60s to avoid blocking every request).
    $test_url = 'http://host.docker.internal:3000';
    $response = wp_remote_get( $test_url, [ 'timeout' => 2 ] );
    $url = ! is_wp_error( $response ) ? 'http://host.docker.internal:3000' : 'http://localhost:3000';

    $this->cache_set( 'docker_url', $url, 'nextpress', 60 );

    return $url;
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
    
    $blocks_cache = $this->cache_get( $cache_key, 'nextpress_blocks' );

    if ( $blocks_cache && ! empty( $blocks_cache ) ) {
      return $blocks_cache;
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
      $current_requests = $this->cache_get( $rate_limit_key, 'nextpress_blocks' ) ?: 0;

      // Check if circuit breaker is active (after too many failures)
      if ( $this->cache_get( $circuit_breaker_key, 'nextpress_blocks' ) ) {
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
      $this->cache_set( $rate_limit_key, $current_requests + 1, 'nextpress_blocks', 30 );
  
      if ( is_wp_error( $response ) ) {
        error_log( 'API request failed: ' . $response->get_error_message() );
        $this->handle_api_failure( $url_hash, $circuit_breaker_key );
        return false;
      }

      $response_code = wp_remote_retrieve_response_code( $response );
      if ( $response_code !== 200 ) {
        error_log( 'API request failed with response code: ' . $response_code );
        $this->handle_api_failure( $url_hash, $circuit_breaker_key );
        return false;
      }
      
      // Reset failure count on successful request
      $this->cache_delete( 'blocks_api_failures_' . $url_hash, 'nextpress_blocks' );
  
      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );

      if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'Failed to parse API response: ' . json_last_error_msg() );
        return false;
      }

      // CRITICAL FIX: Set proper cache expiration (was missing, causing no caching!)
      // Cache blocks for 12 hours - they rarely change
      $cache_ttl = apply_filters( 'nextpress_blocks_cache_ttl', 12 * HOUR_IN_SECONDS );
      $this->cache_set( $cache_key, $data, 'nextpress_blocks', $cache_ttl );
      return $data;
    }
  }

  /**
   * Track consecutive API failures and activate circuit breaker after threshold.
   */
  private function handle_api_failure( $url_hash, $circuit_breaker_key ) {
    $failure_count_key = 'blocks_api_failures_' . $url_hash;
    $failure_count = $this->cache_get( $failure_count_key, 'nextpress_blocks' ) ?: 0;
    $failure_count++;

    if ( $failure_count >= 3 ) {
      $this->cache_set( $circuit_breaker_key, true, 'nextpress_blocks', 300 );
      $this->cache_delete( $failure_count_key, 'nextpress_blocks' );
      error_log( 'Circuit breaker activated due to repeated API failures' );
    } else {
      $this->cache_set( $failure_count_key, $failure_count, 'nextpress_blocks', 300 );
    }
  }

  /**
   * Delegation wrappers — all cache logic lives in the Cache class.
   * These preserve the existing $helpers->cache_*() call signatures.
   */
  public function set_transient_no_autoload( $transient, $value, $expiration = 0 ) {
    return $this->cache->set_transient_no_autoload( $transient, $value, $expiration );
  }

  public function cache_set( $key, $value, $group = '', $expiration = 0 ) {
    return $this->cache->set( $key, $value, $group, $expiration );
  }

  public function cache_get( $key, $group = '' ) {
    return $this->cache->get( $key, $group );
  }

  public function cache_delete( $key, $group = '' ) {
    return $this->cache->delete( $key, $group );
  }

  public function cache_flush_group( $group ) {
    return $this->cache->flush_group( $group );
  }

  /**
   * Revalidate Next.js route.
   */
  public function revalidate_fetch_route( $tag ) {
    $request_url = $this->get_api_url() . "/revalidate?tag=" . $tag;
    return wp_remote_get( $request_url, [ 'timeout' => 1 ] );
  }

  /**
   * Revalidate specific Next.js path.
   */
  public function revalidate_specific_path( $path ) {
    $request_url = $this->get_api_url() . "/revalidate?path=" . urlencode( $path );
    return wp_remote_get( $request_url, [ 'timeout' => 1 ] );
  }

  /**
   * Check if a save_post callback should bail (autosave, revision, or nav menu item).
   *
   * @param int $post_id The post ID being saved.
   * @return bool True if the callback should return early.
   */
  public function should_skip_save( $post_id ) {
    if ( wp_doing_cron() ) return true;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return true;
    if ( wp_is_post_revision( $post_id ) ) return true;

    $post = get_post( $post_id );
    if ( ! $post ) return true;
    return false;
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
   * Clear all caches when ?clear is present and user is admin.
   */
  public function clear_wp_cache() {
    if ( isset( $_GET['clear'] ) && current_user_can( 'manage_options' ) ) {
      wp_cache_flush();
      global $wpdb;
      $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'");
      if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::runcommand('transient delete --all --network');
        $sites = \WP_CLI::runcommand('site list --field=url', ['return' => true]);
        $site_urls = explode("\n", trim($sites));
        foreach ($site_urls as $url) {
          \WP_CLI::runcommand("--url={$url} transient delete --all");
        }
      }
    }
  }

  /**
   * Enable query monitoring for REST API requests.
   *
   * Enable via: add_filter('nextpress_enable_query_monitoring', '__return_true');
   * Or via query param: ?nextpress_debug_queries=1
   */
  public function maybe_enable_query_monitoring() {
    $enabled = apply_filters( 'nextpress_enable_query_monitoring', false );

    if ( isset( $_GET['nextpress_debug_queries'] ) && current_user_can( 'manage_options' ) ) {
      $enabled = true;
    }

    if ( ! $enabled ) {
      return;
    }

    if ( ! defined( 'SAVEQUERIES' ) ) {
      define( 'SAVEQUERIES', true );
    }

    add_action( 'rest_api_init', function() {
      add_filter( 'rest_post_dispatch', [ $this, 'log_slow_queries_for_rest_request' ], 10, 3 );
    });
  }

  /**
   * Log slow queries for REST API requests.
   */
  public function log_slow_queries_for_rest_request( $result, $server, $request ) {
    global $wpdb;

    if ( empty( $wpdb->queries ) ) {
      return $result;
    }

    $slow_query_threshold = apply_filters( 'nextpress_slow_query_threshold', 1.0 );
    $slow_queries = [];
    $total_time = 0;

    foreach ( $wpdb->queries as $query ) {
      $time = $query[1];
      $total_time += $time;

      if ( $time > $slow_query_threshold ) {
        $slow_queries[] = [
          'time' => $time,
          'sql' => $query[0],
          'trace' => $query[2]
        ];
      }
    }

    if ( ! empty( $slow_queries ) ) {
      error_log( sprintf(
        'Nextpress Slow Query Report for %s:',
        $request->get_route()
      ));
      error_log( sprintf(
        'Total queries: %d | Total time: %.4fs | Slow queries: %d',
        count( $wpdb->queries ),
        $total_time,
        count( $slow_queries )
      ));

      foreach ( $slow_queries as $i => $query ) {
        error_log( sprintf(
          '[Slow Query #%d] Time: %.4fs | SQL: %s',
          $i + 1,
          $query['time'],
          substr( $query['sql'], 0, 200 )
        ));
      }
    }

    if ( function_exists( 'rest_get_server' ) ) {
      header( sprintf( 'X-DB-Queries: %d', count( $wpdb->queries ) ) );
      header( sprintf( 'X-DB-Time: %.4fs', $total_time ) );
    }

    return $result;
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