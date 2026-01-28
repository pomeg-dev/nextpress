<?php
/**
 * Nextpress posts router class
 * Adds the /posts rest route for fetching all wp posts
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Posts {
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

    // Adds post_type__not_in param.
    add_action( 'pre_get_posts', [ $this, '_pre_get_posts' ] );
    add_filter( 'posts_where', [ $this, '_posts_where' ], 10, 2 );
    add_filter( 'posts_where_paged', [ $this, '_posts_where' ], 10, 2 );

    // Add selective cache invalidation
    add_action( 'save_post', [ $this, 'invalidate_posts_cache' ] );
    add_action( 'delete_post', [ $this, 'invalidate_posts_cache' ] );
    add_action( 'wp_trash_post', [ $this, 'invalidate_posts_cache' ] );
    add_action( 'untrash_post', [ $this, 'invalidate_posts_cache' ] );

    // Register main posts route.
    add_action('rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/posts',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_posts' ],
        'permission_callback' => '__return_true',
      ]
    );

    register_rest_route(
      'nextpress',
      '/tax_list/(?P<taxonomy>[a-zA-Z0-9_-]+)',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_tax_terms' ],
        'permission_callback' => '__return_true',
      ]
    );

    register_rest_route(
      'nextpress',
      '/tax_term/(?P<taxonomy>[a-zA-Z0-9_-]+)/(?P<term>[a-zA-Z0-9_-]+)',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_the_term' ],
        'permission_callback' => '__return_true',
      ]
    );
  }

  public function get_posts( $request ) {
    $params = $request->get_params();

    // Set cache tags.
    $cache_tag = isset( $params['cache_tag'] ) && $params['cache_tag'] 
      ? $params['cache_tag'] 
      : false;
    
    if ( $cache_tag ) {
      $cached_tags = get_transient( 'np_cache_tags' ) ?: [];
      $cached_tags[] = $cache_tag;
      $cached_tags = array_unique( $cached_tags );
      set_transient( 'np_cache_tags', $cached_tags, HOUR_IN_SECONDS );
      unset( $params['cache_tag'] );
    }

    // Run query.
    $args = $this->prepare_query_args( $params );
    if ( isset( $params['slug_only'] ) && $params['slug_only'] ) {
      $args['fields'] = 'ids';
    }

    // If publicly_queryable is true.
    if ( isset( $args['publicly_queryable'] ) && $args['publicly_queryable'] ) {
      $queryable_post_types = get_post_types( ['publicly_queryable' => true] );
      if ( ! in_array( 'page', $queryable_post_types ) ) {
        $queryable_post_types['page'] = 'page';
      }
      unset( $queryable_post_types['attachment'] );
      $args['post_type'] = $queryable_post_types;
    }

    // Use wp caching with optimized key generation.
    $key = $this->generate_cache_key( 'posts_query', $args );
    $query = new \WP_Query( $args );
    $posts = $query->posts;

    $formatted_posts = array_map( function ( $post ) use ( $params ) {
      $include_content = isset( $params['include_content'] )
        ? $params['include_content']
        : false;
      $include_metadata = isset( $params['include_metadata'] )
        ? $params['include_metadata']
        : true;
      return isset( $params['slug_only'] ) && $params['slug_only']
        ? $this->formatter->get_slug( $post ) 
        : $this->formatter->format_post( $post, $include_content, $include_metadata );
    }, $posts );

    $response = new \WP_REST_Response( $formatted_posts );
    $total = $query->found_posts;
    $total_pages = $query->max_num_pages;

    $response->header( 'X-WP-Total', $total );
    $response->header( 'X-WP-TotalPages', $total_pages );

    return $response;
  }

  public function get_tax_terms( $request ) {
    $taxonomy = $request->get_param( 'taxonomy' );
    $hide_empty = $request->get_param( 'hide_empty' );
    $terms = get_terms(
      [
        'taxonomy' => $taxonomy,
        'hide_empty' => $hide_empty || false,
      ]
    );

    $term_list = [];
    if ( $terms && ! is_wp_error( $terms ) ) {
      foreach ( $terms as $term ) {
        $term_list[] = [
          'term_id' => $term->term_id,
          'slug' => $term->slug,
          'name' => $term->name,
          'description' => $term->description,
          'url' => get_term_link( $term ),
        ];
      }
    }

    $response = new \WP_REST_Response( $term_list );
    return $response;
  }

  public function get_the_term( $request ) {
    $taxonomy = $request->get_param( 'taxonomy' );
    $term_slug = $request->get_param( 'term' );
    $term = get_term_by( 'slug', $term_slug, $taxonomy );
    if ( $term && ! is_wp_error( $term ) ) {
      $response = new \WP_REST_Response( apply_filters( 'nextpress_term_object', $term ) );
      return $response;
    }
    return new \WP_REST_Response( [] );
  }

  private function prepare_query_args( $params ) {
    $args = [];

    foreach ( $params as $key => $value ) {
      $value = ( is_string( $value ) && strpos( $value, ',' ) !== false )
        ? explode( ',', $value ) 
        : $value;
      $args[ $key ] = $value;
    }

    $args = $this->filter_query_args( $args );

    // Ensure we always have these defaults
    $args = wp_parse_args(
      $args,
      [
        'post_type' => isset( $args['post_type'] ) 
          ? $args['post_type']
          : 'any',
        'post_status' => isset( $args['post_status'] ) 
          ? $args['post_status']
          : 'publish',
        'posts_per_page' => isset( $args['posts_per_page'] ) 
          ? $args['posts_per_page'] 
          : get_option( 'posts_per_page' ),
      ]
    );

    return $args;
  }

  private function filter_query_args( $args ) {
    $filters = [];
    $args_map = [
      'search' => 's',
      'per_page' => 'posts_per_page',
      'status' => 'post_status',
      'page' => 'paged'
    ];

    $arrays_map = [ 'post__not_in', 'post__in' ];

    foreach ( $args as $key => $value ) {
      // Remap single values to arrays.
      if ( in_array( $key, $arrays_map ) && ! is_array( $value ) ) {
        $args[ $key ] = [ $value ];
      }

      // Map args to WP_Query args.
      if ( isset( $args_map[ $key ] ) ) {
        $args[ $args_map[ $key ] ] = $value;
        unset( $args[ $key ] );
      }

      // Save tax terms.
      if ( strpos( $key, 'filter_' ) !== false ) {
        $taxonomy = str_replace( 'filter_', '', $key );
        $filters[ $taxonomy ] = [ 'terms' => $value ];
      }
    }
    
    // Set tax query for each filter.
    foreach ( $filters as $taxonomy => $tax ) {
      $terms = $tax['terms'];
      if ( is_array( $terms ) ) {
        $all_numeric = !empty( $terms ) && array_reduce( $terms, function( $carry, $term ) {
          return $carry && is_numeric( $term );
        }, true );
        $field = $all_numeric ? 'term_id' : 'slug';
      } else {
        $field = is_numeric( $terms ) ? 'term_id' : 'slug';
      }

      $args['tax_query'][] = [
        'taxonomy' => sanitize_text_field( $taxonomy ),
        'field'    => $field,
        'terms'    => is_array( $terms ) 
            ? array_map( 'sanitize_text_field', $terms )
            : sanitize_text_field( $terms ),
      ];
    }

    return $args;
  }

  public function _pre_get_posts( $query ) {
    if ( $post_type__not_in = $query->get( 'post_type__not_in' ) ) {
      $query->set( 'post_type', 'any' );
    }
  }

  public function _posts_where( $where, $query ) {
    if ( $post_type__not_in = $query->get( 'post_type__not_in' ) ) {
      global $wpdb;
      preg_match( "#(AND {$wpdb->posts}.post_type IN) \('([^)]+)'\)#", $where, $matches );
      if( 3 == count( $matches ) ) {
        $post_types = explode( "', '", $matches[2] );
        if ( ! is_array( $post_type__not_in ) ) {
          $post_type__not_in = array( $post_type__not_in );
        }
        $post_type__not_in = array_map( 'esc_sql', $post_type__not_in );
        $post_types = implode( "','", array_diff( $post_types, $post_type__not_in ) );
        $new_sql = "{$matches[1]} ('{$post_types}')";
        $where = str_replace( $matches[0], $new_sql, $where );
      }
    }
    return $where;
  }

  /**
   * Generate optimized cache key from args
   */
  private function generate_cache_key( $prefix, $args ) {
    // Extract only the most important cache-affecting parameters
    $key_parts = [
      $args['post_type'] ?? 'any',
      $args['post_status'] ?? 'publish',
      $args['posts_per_page'] ?? get_option( 'posts_per_page' ),
      $args['paged'] ?? 1,
      $args['s'] ?? '',
      isset( $args['post__in'] ) ? implode( ',', (array) $args['post__in'] ) : '',
      isset( $args['post__not_in'] ) ? implode( ',', (array) $args['post__not_in'] ) : '',
      isset( $args['tax_query'] ) ? md5( wp_json_encode( $args['tax_query'] ) ) : '',
    ];
    
    // Create deterministic string and hash it
    $key_string = implode( '|', array_filter( $key_parts ) );
    return $prefix . '_' . md5( $key_string );
  }

  /**
   * Selectively invalidate posts cache when posts are updated
   */
  public function invalidate_posts_cache( $post_id ) {
    // Early returns.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
      return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
      return;
    }

    $post = get_post( $post_id );
    if ( ! $post ) return;
    
    $post_type = $post->post_type;
    if ( $post_type === "nav_menu_item" ) return;

    // Revalidate post IDs.
    $ids_revalidated = false;
    $cached_tags = get_transient( 'np_cache_tags' ) ?: [];
    if ( $cached_tags ) {
      foreach ( $cached_tags as $tag ) {
        if ( preg_match( '/post-ids-(.+)/', $tag, $matches ) ) {
          $post_ids = explode( '-', $matches[1] );
          if ( in_array( $post_id, $post_ids ) ) {
            $this->helpers->revalidate_fetch_route( $tag );
            $ids_revalidated = true;
          }
        }
      }
    }
    
    // Revalidate cpt.
    if ( ! $ids_revalidated ) {
      $this->helpers->revalidate_fetch_route( "post-type-{$post_type}" );
    }
  }
}