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
		new Fix_Autoload_Transients();

		// Register API routes
		new API_Router( $this->helpers );
		new API_Settings( $this->helpers );
		new API_Posts( $this->helpers );
		new API_Menus( $this->helpers );
		new API_Theme( $this->helpers );

		// Add user flows.
		// Removing for now as next-auth is too large for most projects.
		// new User_Flow( $this->helpers );

		// Add extensions
		new Ext_ACF();
		new Ext_Yoast();
		new Ext_GravityForms();

		// Register gutenberg block fields
		// new Register_Blocks( $this->helpers );
		new Register_Blocks_ACF_V3( $this->helpers );

		// Add redirects
		add_action( 'template_redirect', [ $this, 'nextpress_redirect_frontend' ] );
		
		// Fix page links
		add_filter( 'preview_post_link', [ $this, 'nextpress_edit_post_preview_link' ], 10, 2 );

		// Disable editing if no blocks founds
		add_action( 'init', [ $this, 'disable_editing_if_no_blocks' ] );

		// Clear caches if GET param set
		add_action( 'init', [ $this, 'clear_wp_cache' ] );

		// CRITICAL FIX: Add database query monitoring for performance debugging
		add_action( 'init', [ $this, 'maybe_enable_query_monitoring' ] );
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
					wp_redirect( $this->helpers->get_frontend_url_public() . '/' . ltrim( $redirect['url'] ), 301 );
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

    parse_str( parse_url( $req, PHP_URL_QUERY ) ?? '', $query_params );
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

		wp_redirect( $this->helpers->get_frontend_url_public() . $req, 301 );
		exit;
	}

	/**
	 * Fix preview post links
	 */
	public function nextpress_edit_post_preview_link( $link, $post ) {
		$draft_link =  $this->helpers->get_frontend_url_public() . '/api/draft?secret=<token>&id=' . $post->ID;
    return $draft_link;
	}

	/**
	 * Disable editing if no blocks found in fetch_blocks_from_api function
	 * OPTIMIZED: Only check when actually needed (post editor screens)
	 */
	public function disable_editing_if_no_blocks() {
		// Only run this check on admin screens where we actually need blocks
		if ( ! is_admin() ) {
			return;
		}

		global $pagenow;
		$relevant_pages = [ 'post.php', 'post-new.php' ];

		// Don't check on irrelevant admin pages (dashboard, users, settings, etc.)
		if ( ! in_array( $pagenow, $relevant_pages ) ) {
			return;
		}

		// Only fetch blocks when we're actually on a page that needs them
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

	/**
	 * CRITICAL FIX: Enable query monitoring for REST API requests
	 * This helps identify slow database queries causing performance issues
	 *
	 * Enable via: add_filter('nextpress_enable_query_monitoring', '__return_true');
	 * Or via query param: ?nextpress_debug_queries=1
	 */
	public function maybe_enable_query_monitoring() {
		$enabled = apply_filters( 'nextpress_enable_query_monitoring', false );

		// Allow override via query parameter (for authenticated users only)
		if ( isset( $_GET['nextpress_debug_queries'] ) && current_user_can( 'manage_options' ) ) {
			$enabled = true;
		}

		if ( ! $enabled ) {
			return;
		}

		// Enable query tracking
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		// Log slow queries at the end of REST API requests
		add_action( 'rest_api_init', function() {
			add_filter( 'rest_post_dispatch', [ $this, 'log_slow_queries_for_rest_request' ], 10, 3 );
		});
	}

	/**
	 * Log slow queries for REST API requests
	 */
	public function log_slow_queries_for_rest_request( $result, $server, $request ) {
		global $wpdb;

		if ( empty( $wpdb->queries ) ) {
			return $result;
		}

		$slow_query_threshold = apply_filters( 'nextpress_slow_query_threshold', 1.0 ); // 1 second default
		$slow_queries = [];
		$total_time = 0;

		foreach ( $wpdb->queries as $query ) {
			$time = $query[1];
			$total_time += $time;

			if ( $time > $slow_query_threshold ) {
				$slow_queries[] = [
					'time' => $time,
					'sql' => $query[0],
					'trace' => $query[2]
				];
			}
		}

		if ( ! empty( $slow_queries ) ) {
			error_log( sprintf(
				'Nextpress Slow Query Report for %s:',
				$request->get_route()
			));
			error_log( sprintf(
				'Total queries: %d | Total time: %.4fs | Slow queries: %d',
				count( $wpdb->queries ),
				$total_time,
				count( $slow_queries )
			));

			foreach ( $slow_queries as $i => $query ) {
				error_log( sprintf(
					'[Slow Query #%d] Time: %.4fs | SQL: %s',
					$i + 1,
					$query['time'],
					substr( $query['sql'], 0, 200 ) // Truncate for readability
				));
			}
		}

		// Add header to response for debugging
		if ( function_exists( 'rest_get_server' ) ) {
			header( sprintf(
				'X-DB-Queries: %d',
				count( $wpdb->queries )
			));
			header( sprintf(
				'X-DB-Time: %.4fs',
				$total_time
			));
		}

		return $result;
	}
}