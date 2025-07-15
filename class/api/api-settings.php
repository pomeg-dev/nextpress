<?php
/**
 * Nextpress settings router class
 * Adds the /settings rest route for fetching all wp settings
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Settings {
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

    // Register main settings route.
    add_action('rest_api_init', [ $this, 'register_routes' ] );

    // Add ACF/Yoast filters.
    add_filter( 'nextpress_settings', [ $this, 'add_acf_to_nextpress_settings' ] );
    add_filter( 'nextpress_settings', [ $this, 'add_yoast_base_to_nextpress_settings'] );

    // Format default template content.
    add_filter( 'nextpress_settings', [ $this, 'format_default_template'] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/settings',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_settings' ],
        'args' => [
          'keys' => [
            'description' => 'Comma-separated list of specific setting keys to return',
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ],
      ]
    );
  }

  public function get_settings( $data ) {
    if ( is_multisite() ) {
      switch_to_blog( get_current_blog_id() );
    }
    
    // Cache settings for 1 hour
    $cache_key = 'nextpress_settings_' . get_current_blog_id();
    $all_settings = wp_cache_get( $cache_key );
    
    if ( false === $all_settings ) {
      try {
        $all_settings = apply_filters( "nextpress_settings", $this->load_options_without_transients() );
        wp_cache_set( $cache_key, $all_settings, '', 3600 );
      } catch ( Exception $e ) {
        error_log( 'Nextpress settings error: ' . $e->getMessage() );
        return new \WP_Error( 'settings_error', 'Failed to load settings', array( 'status' => 500 ) );
      }
    }

    // Add blog page slug.
    $page_for_posts = get_option( 'page_for_posts' );
    $blog_page = get_post( $page_for_posts );
    if ( $blog_page ) {
      $all_settings['page_for_posts_slug'] = $blog_page->post_name;
    }

    // Filter specific keys if requested
    if ( isset( $data['keys'] ) && ! empty( $data['keys'] ) ) {
      $requested_keys = array_map( 'trim', explode( ',', $data['keys'] ) );
      $all_settings = array_intersect_key( $all_settings, array_flip( $requested_keys ) );
    }

    if ( is_multisite() ) {
      restore_current_blog();
    }

    return $all_settings;
  }

  /**
   * Load options without transients for better performance
   */
  private function load_options_without_transients() {
    global $wpdb;
    
    $query = "SELECT option_name, option_value FROM {$wpdb->options} 
              WHERE autoload = 'yes' 
              AND option_name NOT LIKE '_transient_%' 
              AND option_name NOT LIKE '_site_transient_%'
              ORDER BY option_name";
    
    $results = $wpdb->get_results( $query );
    $options = array();
    
    foreach ( $results as $row ) {
      $options[ $row->option_name ] = maybe_unserialize( $row->option_value );
    }
    
    return $options;
  }

  public function add_acf_to_nextpress_settings( $settings ) {
    if ( ! function_exists( 'get_fields' ) ) return $settings;
    
    try {
      $cache_key = 'nextpress_acf_options_' . get_current_blog_id();
      $options = wp_cache_get( $cache_key );
      
      if ( false === $options ) {
        $options = get_fields( 'options' );
        if ( $options ) {
          wp_cache_set( $cache_key, $options, '', 1800 ); // 30 min cache
        }
      }
      
      if ( $options ) {
        $settings = array_merge( $settings, $options );
      }
    } catch ( Exception $e ) {
      error_log( 'Nextpress ACF options error: ' . $e->getMessage() );
    }
    
    return $settings;
  }

  public function add_yoast_base_to_nextpress_settings( $settings ) {
    if ( ! class_exists( 'WPSEO_Options' ) ) return $settings;
    $yoast_settings = \WPSEO_Options::get_all();
    $settings = array_merge( $settings, $yoast_settings );
    return $settings;
  }

  public function format_default_template( $settings ) {
    if (
      isset( $settings['default_after_content'] ) &&
      is_array( $settings['default_after_content'] )
    ) {
      $settings['after_content'] = $this->formatter->format_flexible_content( $settings['default_after_content'] );
    }

    if (
      isset( $settings['default_before_content'] ) &&
      is_array( $settings['default_before_content'] )
    ) {
      $settings['before_content'] = $this->formatter->format_flexible_content( $settings['default_before_content'] );
    }

    return $settings;
  }
}