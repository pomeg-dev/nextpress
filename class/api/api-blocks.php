<?php
/**
 * Nextpress blocks sync routes
 * Receives the compiled block manifest pushed by `npm run blocks:push`, and
 * returns the stored one so the CLI can diff before pushing.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Blocks {
  /**
   * Helpers.
   */
  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;

    add_action( 'rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/blocks/sync',
      [
        'methods'             => 'POST',
        'callback'            => [ $this, 'sync_manifest' ],
        'permission_callback' => [ API_Auth::class, 'verify_sync_signature' ],
      ]
    );

    register_rest_route(
      'nextpress',
      '/blocks/manifest',
      [
        'methods'             => 'GET',
        'callback'            => [ $this, 'get_manifest' ],
        'permission_callback' => [ API_Auth::class, 'verify_sync_signature' ],
      ]
    );
  }

  public function sync_manifest( $request ) {
    $stored = $this->helpers->blocks_manifest()->store( $request->get_body(), 'push' );
    if ( is_wp_error( $stored ) ) {
      return $stored;
    }
    return rest_ensure_response( $stored );
  }

  public function get_manifest() {
    $manifest = $this->helpers->blocks_manifest()->get_raw();
    if ( ! $manifest ) {
      return new \WP_Error( 'nextpress_no_manifest', 'No block manifest has been stored yet', [ 'status' => 404 ] );
    }
    return rest_ensure_response( $manifest );
  }
}
