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
  }

  public function admin_body_class( $classes ) {
    if ( $this->is_spike() ) {
      $classes .= ' np-editor-booting';
    }
    return $classes;
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
        // Surfaced in the compat banner when a WP-internal locator is missing.
        'wpVersion'      => get_bloginfo( 'version' ),
        // Stateless HMAC token: the auth boundary for the Next render (verified
        // in the renderBlocks Server Action + page.tsx). `sid` isolates this
        // editor session so two editors on the same post can't collide.
        'previewToken'   => $this->mint_token( $post_id ),
      ]
    );
  }

  /**
   * Shared secret for the preview token. Defined in wp-config
   * (NEXTPRESS_PREVIEW_SECRET); the same value lives in the Next .env. The
   * fallback keeps a fresh install working out of the box — override in prod.
   */
  private function preview_secret() {
    if ( defined( 'NEXTPRESS_PREVIEW_SECRET' ) && NEXTPRESS_PREVIEW_SECRET ) {
      return NEXTPRESS_PREVIEW_SECRET;
    }
    return 'c1492212c32302f323046d9bfb9496980b175da70b0f9004154272e8cbc273be';
  }

  private function base64url( $bin ) {
    return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
  }

  /**
   * Mint `base64url(payload).base64url(HMAC-SHA256(payload))`.
   * payload = { uid, post, sid, iat, exp } — signed over the base64url payload
   * string so the Next side can recompute it from the wire value verbatim.
   */
  private function mint_token( $post_id ) {
    $payload = [
      'uid'  => get_current_user_id(),
      'post' => (int) $post_id,
      'sid'  => wp_generate_uuid4(),
      'iat'  => time(),
      'exp'  => time() + 2 * HOUR_IN_SECONDS,
    ];
    $payload_b64 = $this->base64url( wp_json_encode( $payload ) );
    $sig         = hash_hmac( 'sha256', $payload_b64, $this->preview_secret(), true );
    return $payload_b64 . '.' . $this->base64url( $sig );
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
