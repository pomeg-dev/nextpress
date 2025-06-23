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

  /**
   * Cache group for transients
   */
  private $cache_group = 'nextpress_router';

  /**
   * Cache expiration time (1 hour)
   */
  private $cache_expiration = 3600;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->formatter = new Post_Formatter();
    add_action('rest_api_init', [ $this, 'register_routes' ] );
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
        'permission_callback' => '__return_true',
      ]
    );
  }

  /**
   * Get cache key for a given path
   */
  private function get_cache_key( $path, $post_id = null ) {
    $key_parts = [ 'router' ];
    
    if ( $path ) {
      $key_parts[] = sanitize_title( $path );
    }
    
    if ( $post_id ) {
      $key_parts[] = $post_id;
    }
    
    return implode( '_', $key_parts );
  }

  /**
   * Clear cache for a specific post
   */
  public function clear_post_cache( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return;
    
    // Clear cache by post ID
    $cache_key = $this->get_cache_key( null, $post_id );
    wp_cache_delete( $cache_key, $this->cache_group );
    
    // Clear cache by post path
    $post_url = get_permalink( $post );
    $post_path = trim( str_replace( site_url(), '', $post_url ), '/' );
    if ( $post_path ) {
      $path_cache_key = $this->get_cache_key( $post_path );
      wp_cache_delete( $path_cache_key, $this->cache_group );
    }
  }

  /**
   * register_routes callback - Performance optimized
   */
  public function get_post_by_path( $data ) {
    $path = apply_filters( 'nextpress_path', $data['path'] );
    
    // Handle 404 early
    if ( $path && strpos( $path, '404' ) !== false ) {
      return apply_filters( 'nextpress_post_not_found', [ '404' => true ] );
    }

    // Generate cache key
    $post_id = $data->get_param( 'p' ) ?? $data->get_param( 'page_id' );
    $cache_key = $this->get_cache_key( $path, $post_id );
    
    // Try to get from cache first
    $cached_result = wp_cache_get( $cache_key, $this->cache_group );
    if ( $cached_result !== false ) {
      return $cached_result;
    }

    // Cache page_for_posts settings to avoid repeated option calls
    static $page_for_posts_id = null;
    static $page_for_posts_path = null;
    
    if ( $page_for_posts_id === null ) {
      $page_for_posts_id = get_option( 'page_for_posts' );
      if ( $page_for_posts_id ) {
        $page_for_posts_url = get_permalink( $page_for_posts_id );
        $page_for_posts_path = trim( str_replace( site_url(), '', $page_for_posts_url ), '/' );
      }
    }

    $post = null;

    if ( ! $path ) {
      // Handle homepage or direct post ID
      if ( $post_id ) {
        $post = get_post( $post_id );
      } else {
        $post = $this->helpers->get_homepage();
      }
    } else if ( $page_for_posts_path == $path ) {
      // Handle blog page
      $post = get_post( $page_for_posts_id );
    } else {
      // Try to get post by URL first (most efficient)
      $post_id = url_to_postid( site_url( '/' . $path ) );
      if ( $post_id ) {
        $post = get_post( $post_id );
      }
    }

    // If no post found, try fallback searches (but optimize them)
    if ( ! $post && $path ) {
      $post = $this->find_post_by_slug_optimized( $path );
    }

    // Handle not found case
    if ( ! $post ) {
      $result = apply_filters( 'nextpress_post_not_found', [ '404' => true ] );
      // Cache 404 results for shorter time (5 minutes)
      wp_cache_set( $cache_key, $result, $this->cache_group, 300 );
      return $result;
    }

    // Format and cache the result
    $formatted_post = $this->formatter->format_post( $post, true );
    wp_cache_set( $cache_key, $formatted_post, $this->cache_group, $this->cache_expiration );
    
    return $formatted_post;
  }

  /**
   * Optimized slug-based post finding
   */
  private function find_post_by_slug_optimized( $path ) {
    $path_parts = explode( '/', $path );
    $slug = end( $path_parts );
    
    // Use a more targeted query with better performance
    global $wpdb;
    
    // First try: exact slug match with published posts only
    $post_id = $wpdb->get_var( $wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} 
       WHERE post_name = %s 
       AND post_status = 'publish' 
       AND post_type IN ('post', 'page') 
       LIMIT 1",
      $slug
    ) );
    
    if ( $post_id ) {
      return get_post( $post_id );
    }
    
    // Second try: other post statuses (only if needed)
    $post_statuses = ['draft', 'pending', 'auto-draft', 'future', 'private'];
    $placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
    
    $post_id = $wpdb->get_var( $wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} 
       WHERE post_name = %s 
       AND post_status IN ($placeholders)
       AND post_type != 'revision'
       LIMIT 1",
      array_merge( [$slug], $post_statuses )
    ) );
    
    if ( $post_id ) {
      return get_post( $post_id );
    }
    
    // Last resort: title match (least efficient, avoid if possible)
    $post_id = $wpdb->get_var( $wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} 
       WHERE post_title = %s 
       AND post_status IN ('publish', 'draft', 'pending', 'future', 'private')
       LIMIT 1",
      $slug
    ) );
    
    return $post_id ? get_post( $post_id ) : null;
  }

  /**
   * Hook into post save/update to clear relevant caches
   */
  public function init_cache_clearing() {
    add_action( 'save_post', [ $this, 'clear_post_cache' ] );
    add_action( 'delete_post', [ $this, 'clear_post_cache' ] );
    add_action( 'wp_trash_post', [ $this, 'clear_post_cache' ] );
    add_action( 'untrash_post', [ $this, 'clear_post_cache' ] );
  }
}