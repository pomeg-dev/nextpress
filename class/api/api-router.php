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
    add_action( 'pre_post_update', [ $this, 'invalidate_old_url' ] );
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
      '/router(?:/(?P<path>.+))?',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_post_by_path' ],
        'permission_callback' => '__return_true',
      ]
    );
  }

  /**
   * register_routes callback
   */
  public function get_post_by_path( $data ) {
    $post_id = $data->get_param( 'p' ) ?? $data->get_param( 'page_id' );
    $path = apply_filters( 'nextpress_path', $data['path'] );
    $include_content = $data->get_param( 'include_content' ) !== 'false';

    // CRITICAL FIX: Add cache stampede protection for router endpoint
    // Uses Redis-aware helper that automatically uses Redis when available
    $cache_key = 'nextpress_router_' . md5( $path . '_' . ( $include_content ? '1' : '0' ) );
    if ( $post_id && ! $path ) {
      $cache_key = 'nextpress_router_' . md5( $post_id . '_' . ( $include_content ? '1' : '0' ) );
    }
    $mutex_key = $cache_key . '_lock';

    // Check cache first
    $cached = $this->helpers->cache_get( $cache_key, 'nextpress_router' );
    if ( $cached !== false ) {
      return $cached;
    }

    // Mutex lock to prevent stampede
    $lock_acquired = false;
    $wait_count = 0;
    while ( ! $lock_acquired && $wait_count < 10 ) {
      // Try to acquire lock (only succeeds if key doesn't exist)
      if ( wp_using_ext_object_cache() ) {
        $lock_acquired = wp_cache_add( $mutex_key, 1, 'nextpress_router', 30 );
      } else {
        // Transient-based locking for fallback
        $lock_acquired = ( get_transient( 'nextpress_router_' . $mutex_key ) === false );
        if ( $lock_acquired ) {
          set_transient( 'nextpress_router_' . $mutex_key, 1, 30 );
        }
      }

      if ( ! $lock_acquired ) {
        usleep( 500000 ); // Wait 500ms
        $wait_count++;
        // Check if cache appeared while waiting
        $cached = $this->helpers->cache_get( $cache_key, 'nextpress_router' );
        if ( $cached !== false ) {
          return $cached;
        }
      }
    }

    $page_for_posts_id = get_option( 'page_for_posts' );
    $page_for_posts_url = get_permalink( get_option( 'page_for_posts' ) );
    $page_for_posts_path = trim( str_replace( site_url(), '', $page_for_posts_url ), '/' );

    if ( $path && strpos( $path, '404' ) !== false ) {
      return apply_filters( 'nextpress_post_not_found', [ '404' => true ] );
    }

    if ( ! $path ) {
      $post = $post_id
        ? get_post( $post_id )
        : $this->helpers->get_homepage();
    } else if ( $page_for_posts_path == $path ) {
      $post = get_post( $page_for_posts_id );
    } else {
      // Handle Polylang language paths (e.g., /en, /fr)
      if ( function_exists( 'pll_languages_list' ) ) {
        $languages = pll_languages_list();
        if ( in_array( $path, $languages ) ) {
          // This is a language homepage, get the translated homepage
          $homepage = $this->helpers->get_homepage();
          if ( $homepage ) {
            $translated_homepage_id = pll_get_post( $homepage->ID, $path );
            $post = $translated_homepage_id ? get_post( $translated_homepage_id ) : $homepage;
          } else {
            $post = $homepage;
          }
        } else {
          $post_id = url_to_postid( $path );
          $post = get_post( $post_id );
        }
      } else {
        $post_id = url_to_postid( $path );
        $post = get_post( $post_id );
      }
    }

    if ( ! $post ) {
      $not_found_result = apply_filters( 'nextpress_post_not_found', [ '404' => true ] );
      return $not_found_result;
    }

    $formatted_post = $this->formatter->format_post( $post, $include_content );

    // Store in cache for 1 hour (uses Redis when available)
    $cache_ttl = apply_filters( 'nextpress_router_cache_ttl', HOUR_IN_SECONDS );
    $this->helpers->cache_set( $cache_key, $formatted_post, 'nextpress_router', $cache_ttl );

    // Release mutex lock
    $this->helpers->cache_delete( $mutex_key, 'nextpress_router' );

    return $formatted_post;
  }

  /**
   * Invalidate router cache for current post.
   */
  public function invalidate_old_url( $post_id ) {
     // Early returns.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
      return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
      return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) return;
    if ( $post->post_type === "nav_menu_item" ) return;

    $post_path = str_replace( home_url(), '', get_permalink( $post ) );
    $post_path = trim( $post_path, '/' );
    $this->helpers->revalidate_specific_path( '/' . $post_path );
  }

  /**
   * Selectively invalidate router cache when posts are updated
   */
  public function invalidate_router_cache( $post_id ) {
    // Early returns.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
      return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
      return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) return;
    if ( $post->post_type === "nav_menu_item" ) return;

    // CRITICAL FIX: Clear cache group to invalidate all router cache
    $this->helpers->cache_flush_group( 'nextpress_router' );

    // Get the paths that need invalidation
    $paths_to_invalidate = $this->get_paths_to_invalidate( $post );

    // Invalidate specific cache keys instead of all
    foreach ( $paths_to_invalidate as $path ) {
      $this->helpers->revalidate_specific_path( '/' . $path );
    }

    // Only clear homepage cache if this is the homepage or affects global content
    if ( $this->affects_homepage( $post ) ) {
      $this->helpers->revalidate_specific_path( '/' );
    }
  }

  /**
   * Get paths that need cache invalidation for a specific post
   */
  private function get_paths_to_invalidate( $post ) {
    $paths = [];
    
    // Always invalidate the post's own path
    $post_path = str_replace( home_url(), '', get_permalink( $post ) );
    $paths[] = trim( $post_path, '/' );
    
    // Add archive pages if this post affects them
    if ( $post->post_type === 'post' ) {
      $page_for_posts = get_option( 'page_for_posts' );
      if ( $page_for_posts ) {
        $archive_path = str_replace( home_url(), '', get_permalink( $page_for_posts ) );
        $paths[] = trim( $archive_path, '/' );
      }
    }
    
    // Add category/taxonomy archive pages
    $taxonomies = get_object_taxonomies( $post->post_type );
    foreach ( $taxonomies as $taxonomy ) {
      $terms = get_the_terms( $post->ID, $taxonomy );
      if ( $terms && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
          $term_link = get_term_link( $term );
          if ( ! is_wp_error( $term_link ) ) {
            $term_path = str_replace( home_url(), '', $term_link );
            $paths[] = trim( $term_path, '/' );
          }
        }
      }
    }
    
    return array_unique( array_filter( $paths ) );
  }

  /**
   * Check if post affects homepage
   */
  private function affects_homepage( $post ) {
    $homepage_id = get_option( 'page_on_front' );
    $page_for_posts_id = get_option( 'page_for_posts' );
    
    return (
      $post->ID == $homepage_id ||
      $post->ID == $page_for_posts_id
    );
  }
}