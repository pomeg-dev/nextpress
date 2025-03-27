<?php
/**
 * Nextpress theme router class
 * Adds the /theme rest route for fetching current wp theme set in np settings
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Theme {
  /**
   * Helpers.
   */
  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;

    // Register main theme routes.
    add_action('rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/theme(/(?P<path>[a-zA-Z0-9-\/]+))?$',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_theme_json' ],
      ]
    );

    // Another route for getting the block_theme field (wp option)
    register_rest_route(
      'nextpress',
      '/block_theme(/(?P<theme>[a-zA-Z0-9-\/]+))?$',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_block_theme' ],
      ]
    );
  }

  public function get_theme_json() {
    $file = file_get_contents( get_template_directory() . '/theme.json' );
    $json = json_decode( $file, true );
    return apply_filters( "nextpress_theme_json", $json );
  }

  public function get_block_theme( $request ) {
    $theme = get_field( 'blocks_theme', 'option' );
    if ( ! $theme ) return ['default'];
    return apply_filters( "np_block_theme", $theme );
  }
}