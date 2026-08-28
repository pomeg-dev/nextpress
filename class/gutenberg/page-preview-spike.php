<?php
/**
 * SPIKE (page-preview 2b): bridge between the native Gutenberg editor and a
 * Next.js full-page live canvas.
 *
 * Piggybacks on the real post.php editor (so ACF forms, the block-editor store
 * and native save are all untouched). Only loads when ?np_spike=1 is present on
 * the editor URL, so normal editing is unaffected.
 *
 * What it proves:
 *   - read blocks reactively from core/block-editor (wp.data)
 *   - push them through /wp-json/nextpress/format and postMessage to the canvas
 *   - receive a click from the canvas and selectBlock() -> native ACF form shows
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Page_Preview_Spike {
  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
    // Block-view styling must load INSIDE the editor-canvas iframe, which only
    // enqueue_block_assets reaches (admin CSS in the top frame can't).
    add_action( 'enqueue_block_assets', [ $this, 'enqueue_canvas_block_styles' ] );
  }

  public function enqueue_canvas_block_styles() {
    if ( ! is_admin() ) {
      return;
    }
    wp_enqueue_style(
      'nextpress-page-editor-blocks',
      NEXTPRESS_URI . '/assets/css/page-editor-blocks.css',
      [],
      filemtime( NEXTPRESS_PATH . '/assets/css/page-editor-blocks.css' )
    );
  }

  private function is_spike() {
    return is_admin() && isset( $_GET['np_spike'] );
  }

  public function enqueue() {
    if ( ! $this->is_spike() ) {
      // On the normal editor, add the "Edit visually" entry button.
      wp_enqueue_script(
        'nextpress-page-editor-entry',
        NEXTPRESS_URI . '/assets/js/page-editor-entry.js',
        [ 'wp-dom-ready' ],
        filemtime( NEXTPRESS_PATH . '/assets/js/page-editor-entry.js' ),
        true
      );
      return;
    }

    $post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

    wp_enqueue_script(
      'nextpress-page-preview-spike',
      NEXTPRESS_URI . '/assets/js/page-preview-spike.js',
      [ 'wp-data', 'wp-blocks', 'wp-block-editor', 'wp-editor', 'wp-dom-ready' ],
      filemtime( NEXTPRESS_PATH . '/assets/js/page-preview-spike.js' ),
      true
    );

    wp_enqueue_style(
      'nextpress-page-editor',
      NEXTPRESS_URI . '/assets/css/page-editor.css',
      [],
      filemtime( NEXTPRESS_PATH . '/assets/css/page-editor.css' )
    );

    $frontend_url = untrailingslashit( $this->helpers->get_frontend_url_public() );

    wp_localize_script(
      'nextpress-page-preview-spike',
      'NP_PREVIEW',
      [
        'postId'         => $post_id,
        'frontendUrl'    => $frontend_url,
        'frontendOrigin' => $this->origin_of( $frontend_url ),
        'restUrl'        => esc_url_raw( rest_url( 'nextpress/format' ) ),
        'nonce'          => wp_create_nonce( 'wp_rest' ),
      ]
    );
  }

  private function origin_of( $url ) {
    $parts = wp_parse_url( $url );
    if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
      return $url;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if ( ! empty( $parts['port'] ) ) {
      $origin .= ':' . $parts['port'];
    }
    return $origin;
  }
}
