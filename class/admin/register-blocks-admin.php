<?php
/**
 * Nextpress > Blocks admin page: block manifest status, rollback, a manual
 * pull from the frontend, and the sync secret used by `npm run blocks:push`.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Register_Blocks_Admin {
  /**
   * Helpers.
   */
  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;

    // After ACF registers the Nextpress parent menu (priority 99).
    add_action( 'admin_menu', [ $this, 'register_page' ], 100 );
    add_action( 'admin_post_nextpress_blocks', [ $this, 'handle_action' ] );
  }

  public function register_page() {
    add_submenu_page(
      $this->parent_slug(),
      __( 'Blocks', 'nextpress' ),
      __( 'Blocks', 'nextpress' ),
      'manage_options',
      'nextpress-blocks',
      [ $this, 'render_page' ]
    );
  }

  /**
   * The Nextpress options page uses ACF's `redirect`, so its menu slug is its
   * first sub-page's (`acf-options-settings`), not `nextpress`. Find the
   * top-level menu that holds the Templates page rather than hardcoding it.
   */
  private function parent_slug() {
    global $submenu;
    foreach ( (array) $submenu as $parent => $items ) {
      foreach ( $items as $item ) {
        if ( ( $item[2] ?? '' ) === 'templates' ) {
          return $parent;
        }
      }
    }
    return 'acf-options-settings';
  }

  public function handle_action() {
    if ( ! current_user_can( 'manage_options' ) ) {
      wp_die( 'Not allowed' );
    }
    check_admin_referer( 'nextpress_blocks' );

    $manifest = $this->helpers->blocks_manifest();
    $action   = sanitize_key( $_POST['np_action'] ?? '' );
    $notice   = 'error';

    switch ( $action ) {
      case 'pull':
        $notice = $manifest->pull( true ) ? 'pulled' : 'pull_failed';
        break;
      case 'rollback':
        $notice = is_wp_error( $manifest->rollback() ) ? 'rollback_failed' : 'rolled_back';
        break;
      case 'generate_secret':
        update_option( 'nextpress_sync_secret', bin2hex( random_bytes( 32 ) ), false );
        $notice = 'secret';
        break;
    }

    wp_safe_redirect( add_query_arg( [ 'page' => 'nextpress-blocks', 'np_notice' => $notice ], admin_url( 'admin.php' ) ) );
    exit;
  }

  public function render_page() {
    $manifest = $this->helpers->blocks_manifest();
    $meta     = $manifest->get_meta();
    $history  = $manifest->get_history();
    $secret   = API_Auth::sync_secret();
    $notices  = [
      'pulled'          => [ 'success', 'Pulled block definitions from the frontend.' ],
      'pull_failed'     => [ 'error', 'Could not pull from the frontend. Check the Frontend URL setting and that /api/blocks is reachable.' ],
      'rolled_back'     => [ 'success', 'Rolled back to the previous manifest.' ],
      'rollback_failed' => [ 'error', 'No previous manifest to roll back to.' ],
      'secret'          => [ 'success', 'Generated a new sync secret. Update NEXTPRESS_SYNC_SECRET wherever blocks are pushed from.' ],
    ];
    $notice = $notices[ sanitize_key( $_GET['np_notice'] ?? '' ) ] ?? null;
    ?>
    <div class="wrap">
      <h1><?php esc_html_e( 'Blocks', 'nextpress' ); ?></h1>

      <?php if ( $notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
      <?php endif; ?>

      <p>
        Block fields are defined in the frontend (<code>themes/&lt;theme&gt;/blocks/&lt;block&gt;/fields.json</code>)
        and pushed here with <code>npm run blocks:push</code>. They're stored in the database, so changes don't need a
        frontend or WordPress deploy, and blocks keep working if the frontend is unreachable.
      </p>

      <h2><?php esc_html_e( 'Current manifest', 'nextpress' ); ?></h2>
      <?php if ( $meta ) : ?>
        <table class="widefat striped" style="max-width:720px">
          <tbody>
            <tr><th>Source</th><td><?php echo esc_html( $meta['source'] === 'push' ? 'Pushed from frontend' : 'Pulled from /api/blocks' ); ?></td></tr>
            <tr><th>Hash</th><td><code><?php echo esc_html( substr( $meta['hash'], 0, 12 ) ); ?></code></td></tr>
            <tr><th>Blocks</th><td><?php echo esc_html( $meta['blocks'] . ' (' . implode( ', ', $meta['themes'] ) . ')' ); ?></td></tr>
            <tr><th>Generated</th><td><?php echo esc_html( $meta['generated_at'] ?: '—' ); ?></td></tr>
            <tr><th>Stored</th><td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $meta['stored_at'] ) ); ?></td></tr>
          </tbody>
        </table>
      <?php else : ?>
        <p><em>No manifest stored yet. Push one from the frontend, or pull it below.</em></p>
      <?php endif; ?>

      <p style="display:flex;gap:8px">
        <?php $this->action_button( 'pull', 'Pull from frontend now' ); ?>
        <?php
        if ( $history ) {
          $previous = $history[0];
          $this->action_button(
            'rollback',
            sprintf( 'Roll back to %s (%s)', substr( $previous['hash'], 0, 12 ), wp_date( 'Y-m-d H:i', $previous['stored_at'] ) ),
            'Restore the previous block manifest?'
          );
        }
        ?>
      </p>

      <h2><?php esc_html_e( 'Sync secret', 'nextpress' ); ?></h2>
      <?php if ( defined( 'NEXTPRESS_SYNC_SECRET' ) && NEXTPRESS_SYNC_SECRET ) : ?>
        <p>Set by <code>NEXTPRESS_SYNC_SECRET</code> in wp-config.php.</p>
      <?php else : ?>
        <?php if ( $secret ) : ?>
          <p>
            <input type="password" readonly value="<?php echo esc_attr( $secret ); ?>" class="regular-text code" onfocus="this.type='text';this.select()" onblur="this.type='password'">
          </p>
        <?php else : ?>
          <p><em>No secret yet, so pushes are rejected.</em></p>
        <?php endif; ?>
        <p class="description">Set this as <code>NEXTPRESS_SYNC_SECRET</code> (or <code>NEXTPRESS_SYNC_SECRET_&lt;TARGET&gt;</code>) where you run <code>npm run blocks:push</code>.</p>
        <p><?php $this->action_button( 'generate_secret', $secret ? 'Regenerate secret' : 'Generate secret', $secret ? 'Existing pushes will stop working until updated. Continue?' : '' ); ?></p>
      <?php endif; ?>
    </div>
    <?php
  }

  private function action_button( $action, $label, $confirm = '' ) {
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : ''; ?>>
      <?php wp_nonce_field( 'nextpress_blocks' ); ?>
      <input type="hidden" name="action" value="nextpress_blocks">
      <input type="hidden" name="np_action" value="<?php echo esc_attr( $action ); ?>">
      <?php submit_button( $label, 'secondary', 'submit', false ); ?>
    </form>
    <?php
  }
}
