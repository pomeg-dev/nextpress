<?php
/**
 * One-time admin page to fix autoloaded transients
 *
 * This page identifies and fixes transients that are incorrectly set to autoload='yes'
 * in the wp_options table, which causes severe performance issues.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Fix_Autoload_Transients {
  public function __construct() {
    add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
  }

  /**
   * Register admin page under Tools menu
   */
  public function register_admin_page() {
    add_management_page(
      'Fix Autoload Transients',
      'Fix Autoload Transients',
      'manage_options',
      'fix-autoload-transients',
      [ $this, 'render_admin_page' ]
    );
  }

  /**
   * Render the admin page
   */
  public function render_admin_page() {
    global $wpdb;

    // Handle form submission
    if ( isset( $_POST['fix_transients_nonce'] ) && wp_verify_nonce( $_POST['fix_transients_nonce'], 'fix_transients_action' ) ) {
      $result = $this->fix_autoloaded_transients();

      echo '<div class="notice notice-success"><p>';
      echo sprintf(
        'Fixed %d transient options. Database query performance should improve significantly.',
        $result['fixed_count']
      );
      echo '</p></div>';
    }

    // Get current autoloaded transients
    $autoloaded_transients = $wpdb->get_results( "
      SELECT option_name, LENGTH(option_value) as size
      FROM {$wpdb->options}
      WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )
      AND (option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%')
      ORDER BY LENGTH(option_value) DESC
    " );

    $total_transients = count( $autoloaded_transients );
    $total_size = 0;
    foreach ( $autoloaded_transients as $transient ) {
      $total_size += $transient->size;
    }

    // Get total autoloaded options for context
    $total_autoloaded = $wpdb->get_var( "
      SELECT COUNT(*)
      FROM {$wpdb->options}
      WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )
    " );

    $total_autoloaded_size = $wpdb->get_var( "
      SELECT SUM(LENGTH(option_value))
      FROM {$wpdb->options}
      WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )
    " );

    ?>
    <div class="wrap">
      <h1>Fix Autoloaded Transients</h1>

      <div class="card">
        <h2>Current Status</h2>
        <table class="widefat">
          <tr>
            <th>Total Autoloaded Options:</th>
            <td><?php echo number_format( $total_autoloaded ); ?></td>
          </tr>
          <tr>
            <th>Total Autoloaded Size:</th>
            <td><?php echo size_format( $total_autoloaded_size ); ?></td>
          </tr>
          <tr>
            <th style="color: red;">Autoloaded Transients (PROBLEM):</th>
            <td style="color: red;"><?php echo number_format( $total_transients ); ?></td>
          </tr>
          <tr>
            <th style="color: red;">Autoloaded Transients Size:</th>
            <td style="color: red;"><?php echo size_format( $total_size ); ?></td>
          </tr>
        </table>

        <p style="margin-top: 20px;">
          <strong>Issue:</strong> Transients are temporary cache data and should NEVER be autoloaded.
          When autoloaded, they are loaded on every single WordPress request, causing severe performance issues.
        </p>

        <?php if ( $total_transients > 0 ): ?>
          <p style="margin-top: 20px;">
            <strong style="color: red;">
              You have <?php echo number_format( $total_transients ); ?> transients incorrectly set to autoload,
              wasting <?php echo size_format( $total_size ); ?> of memory on EVERY request!
            </strong>
          </p>
        <?php endif; ?>
      </div>

      <?php if ( $total_transients > 0 ): ?>
        <div class="card" style="margin-top: 20px;">
          <h2>Top 20 Largest Autoloaded Transients</h2>
          <table class="wp-list-table widefat fixed striped">
            <thead>
              <tr>
                <th>Transient Name</th>
                <th>Size</th>
                <th>Type</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $shown = 0;
              foreach ( $autoloaded_transients as $transient ):
                if ( $shown >= 20 ) break;
                $shown++;

                // Identify transient type
                $type = 'Unknown';
                if ( strpos( $transient->option_name, '_transient_next_blocks_' ) === 0 ) {
                  $type = 'Nextpress Blocks Cache';
                } elseif ( strpos( $transient->option_name, '_transient_nextpress_router_' ) === 0 ) {
                  $type = 'Nextpress Router Cache';
                } elseif ( strpos( $transient->option_name, '_transient_np_cache_tags' ) === 0 ) {
                  $type = 'Nextpress Cache Tags';
                } elseif ( strpos( $transient->option_name, '_transient_blocks_api_' ) === 0 ) {
                  $type = 'Nextpress API Rate Limit';
                } elseif ( strpos( $transient->option_name, '_transient_wpseo' ) === 0 ) {
                  $type = 'Yoast SEO';
                } elseif ( strpos( $transient->option_name, '_transient_gf_' ) === 0 ) {
                  $type = 'Gravity Forms';
                }
              ?>
                <tr>
                  <td><code><?php echo esc_html( $transient->option_name ); ?></code></td>
                  <td><?php echo size_format( $transient->size ); ?></td>
                  <td><?php echo esc_html( $type ); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card" style="margin-top: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
          <h2>Fix This Issue</h2>
          <p>
            This will set <code>autoload='no'</code> for all transient options in the database.
            This is <strong>safe and recommended</strong>. Transients are temporary cache data and should never be autoloaded.
          </p>
          <p>
            <strong>What this does:</strong>
          </p>
          <ul>
            <li>Updates all <code>_transient_*</code> options to <code>autoload='no'</code></li>
            <li>Updates all <code>_site_transient_*</code> options to <code>autoload='no'</code></li>
            <li>Does NOT delete any data - only changes the autoload flag</li>
            <li>Takes effect immediately - no cache clearing needed</li>
          </ul>
          <p>
            <strong>Expected impact:</strong> Database query time will drop from 3-18 seconds to under 100ms.
          </p>

          <form method="post" onsubmit="return confirm('This will fix <?php echo $total_transients; ?> autoloaded transients. Continue?');">
            <?php wp_nonce_field( 'fix_transients_action', 'fix_transients_nonce' ); ?>
            <button type="submit" class="button button-primary button-hero">
              Fix <?php echo number_format( $total_transients ); ?> Autoloaded Transients
            </button>
          </form>
        </div>
      <?php else: ?>
        <div class="notice notice-success" style="margin-top: 20px;">
          <p><strong>Great!</strong> No autoloaded transients found. Your database is optimized.</p>
        </div>
      <?php endif; ?>
    </div>
    <?php
  }

  /**
   * Fix all autoloaded transients
   */
  private function fix_autoloaded_transients() {
    global $wpdb;

    // Update all transient options to autoload='no'
    $updated = $wpdb->query( "
      UPDATE {$wpdb->options}
      SET autoload = 'no'
      WHERE autoload IN ( 'yes', 'on', 'auto-on', 'auto' )
      AND (option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%')
    " );

    return [
      'fixed_count' => $updated
    ];
  }
}
