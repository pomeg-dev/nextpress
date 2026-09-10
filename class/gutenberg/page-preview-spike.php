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
    // Paint the boot loader from first paint (server-side class) so the native
    // editor never flashes before the JS builds the shell over it. The JS fades
    // it once the canvas has rendered; chrome-hiding stays JS-side so a JS
    // failure degrades to the intact standard editor.
    add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );
    // "Edit visually" entry point in the edit.php list-table row actions
    // (post_row_actions covers posts + custom types, page_row_actions covers pages).
    add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
    add_filter( 'page_row_actions', [ $this, 'row_action' ], 10, 2 );
  }

  /**
   * Add "Edit visually" to a row's hover actions, linking to the live editor.
   * Gated on page-preview mode + edit capability + a block-editor post type.
   */
  public function row_action( $actions, $post ) {
    if ( ! $this->helpers->is_page_preview_mode() ) {
      return $actions;
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) || 'trash' === $post->post_status ) {
      return $actions;
    }
    if ( function_exists( 'use_block_editor_for_post_type' )
      && ! use_block_editor_for_post_type( $post->post_type ) ) {
      return $actions;
    }

    $url = add_query_arg(
      [ 'post' => $post->ID, 'action' => 'edit', 'np_spike' => '1' ],
      admin_url( 'post.php' )
    );
    $actions['np_edit_visually'] = sprintf(
      '<a href="%s">%s</a>',
      esc_url( $url ),
      esc_html__( 'Edit visually', 'nextpress' )
    );
    return $actions;
  }

  public function admin_body_class( $classes ) {
    if ( $this->is_spike() && $this->helpers->is_page_preview_mode() ) {
      $classes .= ' np-editor-booting';
    }
    return $classes;
  }

  public function enqueue_canvas_block_styles() {
    // Block-label styling only applies in page-preview mode (legacy uses the
    // classic iframe previews, which have their own CSS).
    if ( ! is_admin() || ! $this->helpers->is_page_preview_mode() ) {
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
    // Whole feature is off unless the admin selected page-preview mode.
    if ( ! $this->helpers->is_page_preview_mode() ) {
      return;
    }

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
        // Surfaced in the compat banner when a WP-internal locator is missing.
        'wpVersion'      => get_bloginfo( 'version' ),
        // Stateless HMAC token — the auth boundary for the Next render (verified
        // in the renderBlocks Server Action + page.tsx). `sid` isolates this
        // editor session so two editors on the same post can't collide. Minted by
        // Helpers so the secret has a single source (shared with the compat check).
        'previewToken'   => $this->helpers->mint_preview_token( $post_id ),
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
