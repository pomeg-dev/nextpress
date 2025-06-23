<?php
/**
 * Nextpress router class
 * Adds the /router rest route for fetching posts by path, used in main frontend [[...slug]]/page.tsx component
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Router {
  /**
   * Helpers.
   */
  public $helpers;

  /**
   * Post formatter.
   */
  public $formatter;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->formatter = new Post_Formatter();
    add_action('rest_api_init', [ $this, 'register_routes' ] );
    
    // Add cache invalidation hooks
    add_action( 'save_post', [ $this, 'invalidate_router_cache' ] );
    add_action( 'delete_post', [ $this, 'invalidate_router_cache' ] );
    add_action( 'wp_trash_post', [ $this, 'invalidate_router_cache' ] );
    add_action( 'untrash_post', [ $this, 'invalidate_router_cache' ] );
  }

  /**
   * Rest api init callback
   */
  public function register_routes() {
    register_rest_route(
      'nextpress', 
      '/router(/(?P<path>[a-zA-Z0-9-\/]+))?$',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_post_by_path' ],
      ]
    );
  }

  /**
   * register_routes callback
   */
  public function get_post_by_path( $data ) {
    $path = apply_filters( 'nextpress_path', $data['path'] );
    $include_content = $data->get_param( 'include_content' ) !== 'false';
    $is_draft = $data->get_param( 'p' ) !== null;
    
    // Create cache key based on path and parameters
    $cache_key = 'nextpress_router_' . md5( serialize( [
      'path' => $path,
      'include_content' => $include_content,
      'is_draft' => $is_draft,
      'p' => $data->get_param( 'p' ),
      'page_id' => $data->get_param( 'page_id' )
    ] ) );
    
    // Skip cache for drafts and preview requests
    if ( ! $is_draft ) {
      $cached_post = get_transient( $cache_key );
      if ( $cached_post !== false ) {
        return $cached_post;
      }
    }
    
    $page_for_posts_id = get_option( 'page_for_posts' );
    $page_for_posts_url = get_permalink( get_option( 'page_for_posts' ) );
    $page_for_posts_path = trim( str_replace( site_url(), '', $page_for_posts_url ), '/' );

    if ( $path && strpos( $path, '404' ) !== false ) {
      return apply_filters( 'nextpress_post_not_found', [ '404' => true ] );
    }

    if ( ! $path ) {
      $post_id = $data->get_param( 'p' ) ?? $data->get_param( 'page_id' );
      $post = $post_id 
        ? get_post( $post_id ) 
        : $this->helpers->get_homepage();
    } else if ( $page_for_posts_path == $path ) {
      $post = get_post( $page_for_posts_id );
    } else {
      $post_id = url_to_postid( $path );
      $post = get_post( $post_id );
    }

    if ( ! $post ) {
      $path_parts = explode( '/', $path );
      $slug = end( $path_parts );

      $post_status = ['draft', 'pending', 'auto-draft', 'future', 'private', 'revision'];
      $post = get_posts(
        [
          'post_type' => 'any',
          'post_status' => $post_status,
          'name' => $slug,
          'posts_per_page' => 1
        ]
      );
      $post = ! empty( $post ) ? $post[0] : null;
      if ( ! $post ) {
        $post = get_posts(
          [
            'post_status' => $post_status,
            'title' => $slug,
            'posts_per_page' => 1
          ]
        );
        $post = ! empty( $post ) ? $post[0] : null;
      }
    }

    if ( ! $post ) {
      $not_found_result = apply_filters( 'nextpress_post_not_found', [ '404' => true ] );
      // Cache 404 results for shorter time
      if ( ! $is_draft ) {
        set_transient( $cache_key, $not_found_result, 5 * MINUTE_IN_SECONDS );
      }
      return $not_found_result;
    }

    $formatted_post = $this->formatter->format_post( $post, $include_content );
    
    // Cache the result (skip for drafts)
    if ( ! $is_draft ) {
      set_transient( $cache_key, $formatted_post, HOUR_IN_SECONDS );
    }
    
    return $formatted_post;
  }

  /**
   * Invalidate router cache when posts are updated
   */
  public function invalidate_router_cache( $post_id ) {
    // Get all transients with nextpress_router_ prefix and delete them
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_nextpress_router_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_nextpress_router_%'" );
    
    // Also clear posts query cache since router depends on it
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_posts_query_%'" );
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_posts_query_%'" );
  }
}