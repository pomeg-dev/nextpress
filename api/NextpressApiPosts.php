<?php

defined('ABSPATH') or die('You do not have access to this file');

require_once(plugin_dir_path(__FILE__) . 'NextpressPostFormatter.php');

class NextpressApiPosts
{
    public function __construct()
    {
        $this->_init();
    }

    public static function _init()
    {
        add_action('rest_api_init', array('NextpressApiPosts', 'register_routes'));

        // Modify archive block rest requests.
        add_action('rest_api_init', array('NextpressApiPosts', 'rest_archive_mods'));
    }

    public static function register_routes()
    {
        register_rest_route('nextpress', '/posts', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiPosts', 'get_posts'),
            'permission_callback' => '__return_true',
            'args' => self::get_collection_params(),
        ));
    }

    public static function get_posts($request)
    {
        $params = $request->get_params();
        $args = self::prepare_query_args($params);

        $query = new WP_Query($args);
        $posts = $query->posts;

        $formatted_posts = array_map(function ($post) use ($params) {
            return NextpressPostFormatter::format_post($post, $params['include_content']);
        }, $posts);

        $response = new WP_REST_Response($formatted_posts);
        $total = $query->found_posts;
        $total_pages = $query->max_num_pages;

        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', $total_pages);

        return $response;
    }

    private static function prepare_query_args($params)
    {
        $valid_params = self::get_collection_params();
        $args = array();

        foreach ($params as $key => $value) {
            if (isset($valid_params[$key])) {
                $args[$key] = $value;
            }
        }

        // Ensure we always have these defaults
        $args = wp_parse_args($args, array(
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => get_option('posts_per_page'),
        ));

        return $args;
    }

    public static function get_collection_params()
    {
        return array(
            'page' => array(
                'description' => 'Current page of the collection.',
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'description' => 'Maximum number of items to be returned in result set.',
                'type' => 'integer',
                'default' => 10,
                'sanitize_callback' => 'absint',
            ),
            'search' => array(
                'description' => 'Limit results to those matching a string.',
                'type' => 'string',
            ),
            'after' => array(
                'description' => 'Limit response to posts published after a given ISO8601 compliant date.',
                'type' => 'string',
                'format' => 'date-time',
            ),
            'author' => array(
                'description' => 'Limit result set to posts assigned to specific authors.',
                'type' => 'array',
                'items' => array(
                    'type' => 'integer',
                ),
                'default' => array(),
            ),
            'author_exclude' => array(
                'description' => 'Ensure result set excludes posts assigned to specific authors.',
                'type' => 'array',
                'items' => array(
                    'type' => 'integer',
                ),
                'default' => array(),
            ),
            'post__in' => array(
                'description' => 'Limit result set to posts specified in an array.',
                'type' => 'array',
                'items' => array(
                    'type' => 'integer',
                ),
                'default' => array(),
            ),
            'post__not_in' => array(
                'description' => 'Ensure result set excludes specific IDs.',
                'type' => 'array',
                'items' => array(
                    'type' => 'integer',
                ),
                'default' => array(),
            ),
            'category' => array(
                'description' => 'Limit result set to all items that have the specified term assigned in the categories taxonomy.',
                'type' => 'array',
                'items' => array(
                    'type' => 'integer',
                ),
                'default' => array(),
            ),
            'category_name' => array(
                'description' => 'Limit result set to all items that have the specified term assigned in the categories taxonomy.',
                'type' => 'array',
                'items' => array(
                    'type' => 'string',
                ),
                'default' => array(),
            ),
            'offset' => array(
                'description' => 'Offset the result set by a specific number of items.',
                'type' => 'integer',
            ),
            'order' => array(
                'description' => 'Order sort attribute ascending or descending.',
                'type' => 'string',
                'default' => 'desc',
                'enum' => array('asc', 'desc'),
            ),
            'orderby' => array(
                'description' => 'Sort collection by object attribute.',
                'type' => 'string',
                'default' => 'date',
                'enum' => array('author', 'date', 'id', 'include', 'modified', 'parent', 'relevance', 'slug', 'include_slugs', 'title'),
            ),
            'slug' => array(
                'description' => 'Limit result set to posts with one or more specific slugs.',
                'type' => 'array',
                'items' => array(
                    'type' => 'string',
                ),
                'default' => array(),
            ),
            'status' => array(
                'default' => 'publish',
                'description' => 'Limit result set to posts assigned one or more statuses.',
                'type' => 'array',
                'items' => array(
                    'enum' => array_merge(array_keys(get_post_stati()), array('any')),
                    'type' => 'string',
                ),
            ),
            'tax_relation' => array(
                'description' => 'Limit result set based on relationship between multiple taxonomies.',
                'type' => 'string',
                'enum' => array('AND', 'OR'),
            ),
            'include_content' => array(
                'description' => 'Include the content of the post.',
                'type' => 'boolean',
                'default' => false,
            ),
            // Add more parameters as needed
        );
    }

    public static function rest_archive_mods()
    {
      foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
        add_filter('rest_' . $post_type . '_query', array('NextpressApiPosts', 'rest_filter_by_custom_taxonomy'), 10, 2);
        add_filter('rest_prepare_' . $post_type, array('NextpressApiPosts', 'rest_modify_post_object'), 10, 3);
      }
    }

    public static function rest_filter_by_custom_taxonomy( $args, $request )
    {
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

    public static function rest_modify_post_object( $response, $post, $request )
    {
        if ( ! function_exists( 'get_fields' ) ) return $response;
    
        if ( isset( $post ) && isset( $request['is_archive'] ) && $request['is_archive'] ) {
            $response->data = NextpressPostFormatter::format_post($post, $params['include_content']);
        }
        return $response;
    }
}
