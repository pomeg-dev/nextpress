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

    // Allow filtering by tax name.
    add_action( 'rest_api_init', [ $this, 'modify_rest_query' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/posts',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_posts' ],
        'permission_callback' => '__return_true',
        'args' => $this->get_collection_params(),
      ]
    );
  }

  public function get_posts( $request ) {
    $params = $request->get_params();
    $args = $this->prepare_query_args( $params );

    $query = new \WP_Query( $args );
    $posts = $query->posts;

    $formatted_posts = array_map( function ( $post ) use ( $params ) {
        return $this->formatter->format_post( $post, $params['include_content'] );
    }, $posts );

    $response = new \WP_REST_Response( $formatted_posts );
    $total = $query->found_posts;
    $total_pages = $query->max_num_pages;

    $response->header( 'X-WP-Total', $total );
    $response->header( 'X-WP-TotalPages', $total_pages );

    return $response;
  }

  private function prepare_query_args( $params ) {
    $valid_params = $this->get_collection_params();
    $args = [];

    foreach ($params as $key => $value) {
      if ( isset( $valid_params[ $key ] ) ) {
        $args[ $key ] = $value;
      }
    }

    // Ensure we always have these defaults
    $args = wp_parse_args(
      $args,
      [
        'post_type' => 'any',
        'post_status' => 'publish',
        'posts_per_page' => isset( $args['per_page'] ) 
          ? $args['per_page'] 
          : get_option( 'posts_per_page' ),
      ]
    );

    return $args;
  }

  public function modify_rest_query() {
    $post_types = get_post_types( [ 'public' => true ], 'names' );
    foreach ( $post_types as $post_type ) {
      add_filter( 'rest_' . $post_type . '_query', [ $this, 'rest_filter_by_custom_taxonomy' ], 10, 2 );
    }
  }

  public function rest_filter_by_custom_taxonomy( $args, $request ) {
    // Gather requested filter terms.
    $params = $request->get_params();
    $filters = [];
    foreach ( $params as $key => $value ) {
      if ( strpos( $key, 'filter_' ) !== false ) {
        $rest_tax = str_replace( 'filter_', '', $key );
        $filters[ $rest_tax ] = [ 'terms' => $value ];
      }
    }

    if ( ! $filters ) {
      return $args;
    }

    // Get proper slugs for taxonomies.
    $taxonomies = get_taxonomies( array( 'public'   => true ), 'objects' );
    foreach ( $taxonomies as $key => $tax ) {
      if ( isset( $filters[ $tax->rest_base ] ) ) {
        $filters[ $tax->rest_base ]['slug'] = $key;
      }
    }

    // Set tax query for each filter.
    foreach ( $filters as $rest_tax => $tax ) {
      $args['tax_query'][] = [
        'taxonomy' => sanitize_text_field( $tax['slug'] ),
        'field'    => 'slug',
        'terms'    => sanitize_text_field( $tax['terms'] ),
      ];
    }

    return $args;
  }

  public function get_collection_params() {
    return [
      'page' => [
        'description' => 'Current page of the collection.',
        'type' => 'integer',
        'default' => 1,
        'sanitize_callback' => 'absint',
      ],
      'per_page' => [
        'description' => 'Maximum number of items to be returned in result set.',
        'type' => 'integer',
        'default' => 10,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'search' => [
        'description' => 'Limit results to those matching a string.',
        'type' => 'string',
      ],
      'after' => [
        'description' => 'Limit response to posts published after a given ISO8601 compliant date.',
        'type' => 'string',
        'format' => 'date-time',
      ],
      'author' => [
        'description' => 'Limit result set to posts assigned to specific authors.',
        'type' => 'array',
        'items' => [
          'type' => 'integer',
        ],
        'default' => [],
      ],
      'author_exclude' => [
        'description' => 'Ensure result set excludes posts assigned to specific authors.',
        'type' => 'array',
        'items' => [
          'type' => 'integer',
        ],
        'default' => [],
      ],
      'post__in' => [
        'description' => 'Limit result set to posts specified in an array.',
        'type' => 'array',
        'items' => [
          'type' => 'integer',
        ],
        'default' => [],
      ],
      'post__not_in' => [
        'description' => 'Ensure result set excludes specific IDs.',
        'type' => 'array',
        'items' => [
          'type' => 'integer',
        ],
        'default' => [],
      ],
      'category' => [
        'description' => 'Limit result set to all items that have the specified term assigned in the categories taxonomy.',
        'type' => 'array',
        'items' => [
          'type' => 'integer',
        ],
        'default' => [],
      ],
      'category_name' => [
        'description' => 'Limit result set to all items that have the specified term assigned in the categories taxonomy.',
        'type' => 'array',
        'items' => [
          'type' => 'string',
        ],
        'default' => [],
      ],
      'offset' => [
        'description' => 'Offset the result set by a specific number of items.',
        'type' => 'integer',
      ],
      'order' => [
        'description' => 'Order sort attribute ascending or descending.',
        'type' => 'string',
        'default' => 'desc',
        'enum' => ['asc', 'desc'],
      ],
      'orderby' => [
        'description' => 'Sort collection by object attribute.',
        'type' => 'string',
        'default' => 'date',
        'enum' => ['author', 'date', 'id', 'include', 'modified', 'parent', 'relevance', 'slug', 'include_slugs', 'title', 'post__in'],
      ],
      'slug' => [
        'description' => 'Limit result set to posts with one or more specific slugs.',
        'type' => 'array',
        'items' => [
          'type' => 'string',
        ],
        'default' => [],
      ],
      'status' => [
        'default' => 'publish',
        'description' => 'Limit result set to posts assigned one or more statuses.',
        'type' => 'array',
        'items' => [
          'enum' => array_merge( array_keys( get_post_stati() ), ['any'] ),
          'type' => 'string',
        ],
      ],
      'tax_relation' => [
        'description' => 'Limit result set based on relationship between multiple taxonomies.',
        'type' => 'string',
        'enum' => ['AND', 'OR'],
      ],
      'include_content' => [
        'description' => 'Include the content of the post.',
        'type' => 'boolean',
        'default' => false,
      ],
      // Add more parameters as needed
    ];
  }
}