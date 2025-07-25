<?php
/**
 * This class initialises the plugin and does the setup legwork
 *
 * @package nextpress
 */

namespace nextpress;

use YahnisElsts\PluginUpdateChecker\v5p5\PucFactory;

defined('ABSPATH') or die('You do not have access to this file');

class Init {
	/**
	 * Public class helpers class dependency.
	 */
	public $helpers;

	public function __construct() {
		// Plugin checker
		$this->plugin_update_checker();
		
		// Add Stoutlogic
		require_once NEXTPRESS_PATH . '/includes/acf-builder/autoload.php';

		// Add helpers
		$this->helpers = new Helpers();

		// Add admin settings
		new Register_Pages();
		new Register_Settings( $this->helpers );
		new Register_Templates( $this->helpers );

		// Register API routes
		new API_Router( $this->helpers );
		new API_Settings( $this->helpers );
		new API_Posts( $this->helpers );
		new API_Menus( $this->helpers );
		new API_Theme( $this->helpers );

		// Add user flows.
		new User_Flow( $this->helpers );

		// Add extensions
		new Ext_ACF();
		new Ext_Yoast();
		new Ext_GravityForms();
		// TODO: multilingual

		// Register gutenberg block fields
		new Register_Blocks( $this->helpers );

		// Add redirects
		add_action( 'template_redirect', [ $this, 'nextpress_redirect_frontend' ] );
		
		// Fix page links
		add_filter( 'preview_post_link', [ $this, 'nextpress_edit_post_preview_link' ], 10, 2 );

		// Disable editing if no blocks founds
		add_action( 'init', [ $this, 'disable_editing_if_no_blocks' ] );

		// Clear caches if GET param set
		add_action( 'init', [ $this, 'clear_wp_cache' ] );
	}

	/**
	 * Check for plugin/repo updates
	 */
	public function plugin_update_checker() {
		require NEXTPRESS_PATH . '/includes/plugin-update-checker/plugin-update-checker.php';

		$update_checker = PucFactory::buildUpdateChecker(
			'https://github.com/pomeg-dev/nextpress',
			NEXTPRESS_PATH . '/nextpress.php',
			'nextpress'
		);

		//Optional: If you're using a private repository, specify the access token like this:
		// $update_checker->setAuthentication('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');

		//Optional: Set the branch that contains the stable release.
		// $update_checker->setBranch('main');
	}

	/**
	 * Redirect frontend
	 */
	public function nextpress_redirect_frontend() {
		// Check for yoast redirects.
    $redirects_json = get_option('wpseo-premium-redirects-base');
		$permalink = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    $permalink = rtrim( 
      str_replace( 
        site_url( '/' ), 
        '',
        $permalink
      ), '/'
    );

    if ( $redirects_json ) {
			foreach ( $redirects_json as $redirect ) {
				if ( $permalink === $redirect['origin'] ) {
					wp_redirect( $this->helpers->frontend_url . '/' . ltrim( $redirect['url'] ), 301 );
					exit;
				}
			}
    }

    // If multisite request, remove the blog url
    if ( is_multisite() ) {
			$path = get_blog_details()->path;
			$req = str_replace( $path, '/', $_SERVER['REQUEST_URI'] );
    } else {
			$req = $_SERVER['REQUEST_URI'];
    }

    parse_str( parse_url( $req, PHP_URL_QUERY ), $query_params );
		if ( isset( $query_params['page_id'] ) ) {
			$page_id = $query_params['page_id'];
			$req = '/api/draft?secret=<token>&id=' . $page_id;
		} elseif ( isset( $query_params['p'] ) ) {
			$page_id = $query_params['p'];
			$req = '/api/draft?secret=<token>&id=' . $page_id;
		}

		if (
			strpos($req, 'wp-admin') !== false ||
			strpos($req, 'wp-login') !== false ||
			strpos($req, 'index.php') !== false
		) {
			return;
		}

		wp_redirect( $this->helpers->frontend_url . $req, 301 );
		exit;
	}

	/**
	 * Fix preview post links
	 */
	public function nextpress_edit_post_preview_link( $link, $post ) {
		$draft_link =  $this->helpers->frontend_url . '/api/draft?secret=<token>&id=' . $post->ID;
    return $draft_link;
	}

	/**
	 * Disable editing if no blocks founf in fetch_blocks_from_api function
	 */
	public function disable_editing_if_no_blocks() {
		$blocks = $this->helpers->fetch_blocks_from_api( null, 'init' );
		if (empty($blocks)) {
			add_filter( 'use_block_editor_for_post', '__return_false' );
			add_action( 'admin_notices', [ $this, 'no_blocks_notice' ] );
			add_action( 'admin_head', [ $this, 'hide_classic_editor' ] );
		}
	}
	public function no_blocks_notice() {
		?>
			<div class="notice notice-error is-dismissible">
				<p><?php _e('No blocks found. Please make sure the blocks api endpoint is configured', 'nextpress'); ?></p>
			</div>
		<?php
	}
	public function hide_classic_editor() {
		?>
			<style>
				#post-body-content {
					display: none;
				}
			</style>
		<?php
	}

	/**
	 * Clear caches
	 */
	public function clear_wp_cache() {
		if ( isset( $_GET['clear'] ) ) {
			wp_cache_flush();
			global $wpdb;
			$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'");
			if (defined('WP_CLI') && WP_CLI) {
				\WP_CLI::runcommand('transient delete --all --network');
				$sites = \WP_CLI::runcommand('site list --field=url', ['return' => true]);
				$site_urls = explode("\n", trim($sites));
				foreach ($site_urls as $url) {
					\WP_CLI::runcommand("--url={$url} transient delete --all");
				}
			}
		}
	}
}