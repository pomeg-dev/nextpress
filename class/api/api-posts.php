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
    $args = $this->prepare_query_args( $params );
    $query = new \WP_Query( $args );
    $posts = $query->posts;

    $formatted_posts = array_map( function ( $post ) use ( $params ) {
      $include_content = isset( $params['include_content'] )
        ? $params['include_content']
        : false;
      return $this->formatter->format_post( $post, $include_content );
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
    $terms = get_terms(
      [
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
      ]
    );

    $term_list = [];
    if ( $terms && ! is_wp_error( $terms ) ) {
      foreach ( $terms as $term ) {
        $term_list[] = [
          'term_id' => $term->term_id,
          'slug' => $term->slug,
          'name' => $term->name,
        ];
      }
    }

    $response = new \WP_REST_Response( $term_list );
    return $response;
  }

  public function get_the_term( $request ) {
    $taxonomy = $request->get_param( 'taxonomy' );
    $term_slug = $request->get_param( 'term' );
    $term = get_term_by( 'name', $term_slug, $taxonomy );
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

    foreach ( $args as $key => $value ) {
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
      $args['tax_query'][] = [
        'taxonomy' => sanitize_text_field( $taxonomy ),
        'field'    => 'slug',
        'terms'    => sanitize_text_field( $tax['terms'] ),
      ];
    }

    return $args;
  }
}