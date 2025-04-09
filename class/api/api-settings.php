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
      ]
    );
  }

  public function get_settings( $data ) {
    if ( is_multisite() ) {
      switch_to_blog( get_current_blog_id() );
    }
    
    $all_settings = apply_filters( "nextpress_settings", wp_load_alloptions() );

    if ( is_multisite() ) {
      restore_current_blog();
    }

    return $all_settings;
  }

  public function add_acf_to_nextpress_settings( $settings ) {
    if ( ! function_exists( 'get_fields' ) ) return;
    $options = get_fields( 'options' );
    if ( $options ) {
      $settings = array_merge( $settings, get_fields( 'options' ) );
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