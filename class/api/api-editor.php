<?php
/**
 * SPIKE (page-preview 2b): live editor endpoints.
 *
 * /wp-json/nextpress/format
 *   POST { content: "<serialized block markup>" }
 *   The editor bridge sends wp.blocks.serialize(getBlocks()) — i.e. the exact
 *   post_content a real save would produce, including nested innerBlocks and
 *   reusable-block refs. We run it through the SAME pipeline the /router
 *   endpoint uses for live pages (Post_Formatter::parse_block_data ->
 *   nextpress_block_data -> reformat_block_data), so the preview matches
 *   production — inner blocks included — with zero hand-rolled serialization.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Editor {
  public $helpers;
  public $formatter;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->formatter = new Post_Formatter();
    add_action( 'rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/format',
      [
        'methods' => 'POST',
        'callback' => [ $this, 'format_blocks' ],
        // Editors only. The bridge sends the standard wp_rest nonce.
        'permission_callback' => function () {
          return current_user_can( 'edit_posts' );
        },
      ]
    );
  }

  /**
   * Format serialized block markup into the frontend block shape.
   * Same path /router uses for live pages, so innerBlocks come for free.
   */
  public function format_blocks( $request ) {
    $content = $request->get_param( 'content' );
    if ( ! is_string( $content ) || $content === '' ) {
      return new \WP_REST_Response( [], 200 );
    }

    $formatted = $this->formatter->parse_block_data( $content );

    return new \WP_REST_Response( $formatted, 200 );
  }
}
