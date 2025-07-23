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
    $path = apply_filters( 'nextpress_path', $data['path'] );
    $include_content = $data->get_param( 'include_content' ) !== 'false';
    $is_draft = $data->get_param( 'p' ) !== null;
    
    // Create optimized cache key
    $cache_key = $this->generate_router_cache_key( 
      $path, 
      $include_content, 
      $is_draft, 
      $data->get_param( 'p' ), 
      $data->get_param( 'page_id' ) 
    );
    
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
      return $not_found_result;
    }

    $formatted_post = $this->formatter->format_post( $post, $include_content );
    
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
    // Homepage is affected if:
    // 1. This IS the homepage
    // 2. This is a sticky post
    // 3. This post appears in global widgets/menus
    // 4. This is the page_for_posts
    
    $homepage_id = get_option( 'page_on_front' );
    $page_for_posts_id = get_option( 'page_for_posts' );
    
    return (
      $post->ID == $homepage_id ||
      $post->ID == $page_for_posts_id ||
      is_sticky( $post->ID ) ||
      $post->post_type === 'nav_menu_item'
    );
  }

  /**
   * Generate optimized router cache key
   */
  private function generate_router_cache_key( $path, $include_content, $is_draft, $p, $page_id ) {
    // Build key from simple string concatenation instead of serialize()
    $key_parts = [
      'path' => $path ?: 'home',
      'content' => $include_content ? '1' : '0',
      'draft' => $is_draft ? '1' : '0',
      'p' => $p ?: '',
      'page_id' => $page_id ?: ''
    ];
    
    // Create deterministic string - much faster than serialize()
    $key_string = implode( '|', array_filter( $key_parts ) );
    return 'nextpress_router_' . md5( $key_string );
  }
}