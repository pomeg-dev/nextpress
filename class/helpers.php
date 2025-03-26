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
  public $frontend_url = 'http://localhost:3000';
  public $docker_url = 'http://host.docker.internal:3000';
  public $api_endpoint = '/api';
  public $api_url;
  public $blocks_endpoint = '/api/blocks';
  public $blocks_url;

  public function __construct() {
    // Set API urls.
    $this->api_url = $this->docker_url . $this->api_endpoint;
    $this->blocks_url = $this->docker_url . $this->blocks_endpoint;

    if ( function_exists( 'get_field' ) ) {
      $frontend_url = get_field('frontend_url', 'option');
      if ( $frontend_url ) {
        $this->frontend_url = rtrim( $frontend_url, '/' );
        $this->api_url = $this->frontend_url . $this->api_endpoint;
        $this->blocks_url = $this->frontend_url . $this->blocks_endpoint;
      }
    }
  }

  /**
   * Fetch all themes/blocks from nextjs api
   */
  public function fetch_blocks_from_api( $theme = null ) {
    $blocks_url = $this->blocks_url;
    if ( $theme ) {
      // $theme might be a string or an array of strings.
      if ( is_array( $theme ) ) {
        $theme = implode( ',', $theme );
      }
      $blocks_url .= '?theme=' . $theme;
    }

    $response = wp_remote_get(
      $blocks_url,
      [
        'timeout' => 15, // Increase timeout to 15 seconds
        'sslverify' => false // Only use this for local development!
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

    return $data;
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
}