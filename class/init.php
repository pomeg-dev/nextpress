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
		new Register_Blocks( $this->helpers );

		// URL redirects and preview links
		new URL_Handlers( $this->helpers );
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
}