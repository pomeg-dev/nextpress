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
   * Get the correct frontend URL with multiple fallback strategies
   */
  private function get_frontend_url() {
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
    
    // Local development.
    return "http://localhost:3000";
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
    return $this->get_frontend_url();
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
      if ( $theme ) {
        if ( is_array( $theme ) ) {
          $theme = implode( ',', $theme );
        }
        $blocks_url .= '?theme=' . $theme;
      }
  
      $response = wp_remote_get(
        $blocks_url,
        [
          'timeout' => 20,
          'sslverify' => ! $this->dev_mode,
          'redirection' => 2
        ]
      );
  
      if ( is_wp_error( $response ) ) {
        error_log( 'API request failed: ' . $response->get_error_message() );
        return false;
      }
  
      $response_code = wp_remote_retrieve_response_code( $response );
      if ( $response_code !== 200 ) {
        error_log( 'API request failed with response code: ' . $response_code );
        return false;
      }
  
      $body = wp_remote_retrieve_body( $response );
      $data = json_decode( $body, true );
  
      if ( json_last_error() !== JSON_ERROR_NONE ) {
        error_log( 'Failed to parse API response: ' . json_last_error_msg() );
        return false;
      }
  
      set_transient( $cache_key, $data );
      return $data;
    }
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