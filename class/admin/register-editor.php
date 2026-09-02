<?php
/**
 * Registers the "Editor" admin page: choose the page editor mode (legacy vs the
 * experimental live page preview) and show a live compatibility check against
 * the Next frontend.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

use StoutLogic\AcfBuilder\FieldsBuilder;

class Register_Editor {
  const COMPAT_TRANSIENT = 'nextpress_live_editor_compat';
  const DEFAULT_SECRET   = 'c1492212c32302f323046d9bfb9496980b175da70b0f9004154272e8cbc273be';

  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    add_action( 'acf/init', [ $this, 'register_fields' ] );
  }

  /** True only when rendering our Editor options page (admin.php?page=editor). */
  private function is_editor_page() {
    return is_admin() && isset( $_GET['page'] ) && $_GET['page'] === 'editor';
  }

  public function register_fields() {
    // Bake the compat status into the message field, but only compute it (a
    // remote GET) when actually on the Editor page — not on every admin load.
    $compat_html = $this->is_editor_page() ? $this->compat_html() : '';

    $editor = new FieldsBuilder( 'nextpress_editor' );
    $editor
      ->addSelect( 'page_editor_mode', [
        'label'         => 'Page editor',
        'instructions'  => 'Legacy renders the classic per-block previews (default, unchanged on every site). '
          . 'New page preview is experimental: it enables the live canvas + "Edit visually" button and turns '
          . 'off the per-block previews.',
        'choices'       => [
          'legacy'       => 'Legacy block render (default)',
          'page_preview' => 'New page preview (experimental)',
        ],
        'default_value' => 'legacy',
        'return_format' => 'value',
        'ui'            => 1,
      ] )
      ->addMessage( 'page_editor_compat', $compat_html, [
        'label'     => 'Frontend compatibility',
        'esc_html'  => 0,  // our status HTML is trusted/built here
        'new_lines' => '', // don't wpautop the markup
      ] );

    // NB: with an explicit menu_slug ('editor'), ACF's options-page location is
    // that slug verbatim — not 'acf-options-editor' (cf. register-templates).
    $editor
      ->setLocation( 'options_page', '==', 'editor' )
      ->setGroupConfig( 'style', 'seamless' );

    acf_add_local_field_group( $editor->build() );
  }

  /**
   * Cached compatibility result. Add ?np_recheck=1 to the Editor page URL to
   * bust the cache.
   */
  public function compat_status() {
    if ( isset( $_GET['np_recheck'] ) ) {
      delete_transient( self::COMPAT_TRANSIENT );
    }
    $cached = get_transient( self::COMPAT_TRANSIENT );
    if ( is_array( $cached ) ) {
      return $cached;
    }
    $result = $this->run_compat_check();
    set_transient( self::COMPAT_TRANSIENT, $result, 5 * MINUTE_IN_SECONDS );
    return $result;
  }

  /**
   * Hit the frontend health endpoint with a freshly-minted token. Distinguishes
   * the failure modes that actually happen in production: frontend unreachable,
   * route missing (old build), and secret mismatch.
   */
  private function run_compat_check() {
    // Server-side call → needs the internal URL (host.docker.internal in Docker),
    // not the browser-facing localhost the iframe uses.
    $base  = untrailingslashit( $this->helpers->get_frontend_url_internal() );
    $token = $this->helpers->mint_preview_token( 0 );
    $url   = $base . '/page-preview/health?np_token=' . rawurlencode( $token );

    $res = wp_remote_get( $url, [ 'timeout' => 5 ] );

    if ( is_wp_error( $res ) ) {
      return [
        'state'   => 'unreachable',
        'message' => 'Frontend unreachable',
        'detail'  => 'Could not reach ' . esc_html( $base ) . ' — check the Frontend URL in Settings. (' . esc_html( $res->get_error_message() ) . ')',
      ];
    }

    $code = (int) wp_remote_retrieve_response_code( $res );

    if ( $code === 404 ) {
      return [
        'state'   => 'missing',
        'message' => 'Live preview route not found',
        'detail'  => 'The frontend responded but has no /page-preview/health route — this build does not include the live preview feature yet. Deploy an updated frontend.',
      ];
    }

    if ( $code !== 200 ) {
      return [
        'state'   => 'unknown',
        'message' => 'Unexpected response (' . $code . ')',
        'detail'  => 'The frontend returned HTTP ' . $code . '.',
      ];
    }

    $body = json_decode( wp_remote_retrieve_body( $res ), true );
    if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
      return [
        'state'   => 'unknown',
        'message' => 'Unexpected response',
        'detail'  => 'Reached the frontend but the health response was not in the expected format.',
      ];
    }

    if ( empty( $body['tokenValid'] ) ) {
      return [
        'state'   => 'secret_mismatch',
        'message' => 'Preview secret mismatch',
        'detail'  => 'The frontend is reachable but rejected the signed token. Make sure NEXTPRESS_PREVIEW_SECRET is identical in wp-config.php and the Next .env.',
      ];
    }

    return [
      'state'   => 'ready',
      'message' => 'Ready',
      'detail'  => 'Frontend preview reachable and the shared secret matches.',
      'version' => isset( $body['version'] ) ? (string) $body['version'] : '',
    ];
  }

  /**
   * Build the compat status (and the default-secret nudge) as an HTML string for
   * the ACF message field.
   */
  public function compat_html() {
    $s = $this->compat_status();

    $palette = [
      'ready'           => [ '#0a7d33', '#e7f6ec', '#b7e2c4', '&#10003;' ],
      'unreachable'     => [ '#8a1f1f', '#fdecec', '#f0a3a3', '&#9888;' ],
      'missing'         => [ '#8a5b00', '#fff8e1', '#f0d488', '&#9888;' ],
      'secret_mismatch' => [ '#8a1f1f', '#fdecec', '#f0a3a3', '&#9888;' ],
      'unknown'         => [ '#8a5b00', '#fff8e1', '#f0d488', '&#9888;' ],
    ];
    $c = isset( $palette[ $s['state'] ] ) ? $palette[ $s['state'] ] : $palette['unknown'];

    $version = ! empty( $s['version'] ) ? ' <span style="opacity:.7">(frontend v' . esc_html( $s['version'] ) . ')</span>' : '';

    $recheck = add_query_arg( 'np_recheck', '1' );

    $html  = '<div style="border:1px solid ' . $c[2] . ';background:' . $c[1] . ';color:' . $c[0]
      . ';border-radius:8px;padding:12px 14px;font-size:13px;line-height:1.5;max-width:640px;">';
    $html .= '<strong style="font-size:14px;">' . $c[3] . ' ' . esc_html( $s['message'] ) . '</strong>' . $version;
    if ( ! empty( $s['detail'] ) ) {
      $html .= '<div style="margin-top:4px;">' . wp_kses_post( $s['detail'] ) . '</div>';
    }
    $html .= '<div style="margin-top:8px;"><a href="' . esc_url( $recheck ) . '">Re-check</a></div>';
    $html .= '</div>';

    // Prod security nudge: still using the shipped default secret.
    if ( $this->helpers->preview_secret_is_default() ) {
      $html .= '<div style="border:1px solid #f0d488;background:#fff8e1;color:#8a5b00;'
        . 'border-radius:8px;padding:10px 14px;margin-top:10px;font-size:13px;line-height:1.5;max-width:640px;">'
        . '&#9888; Using the default preview secret. Set a unique <code>NEXTPRESS_PREVIEW_SECRET</code> '
        . '(wp-config.php + Next .env) before using this in production.'
        . '</div>';
    }

    return $html;
  }
}
