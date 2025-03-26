<?php
/**
 * This class initialises the plugin and does the setup legwork
 *
 * @package nextpress
 */

namespace nextpress;

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
		
		// Register gutenberg block fields
		new Register_Blocks( $this->helpers );

		// Register API routes
		new Router( $this->helpers );
		new Posts( $this->helpers );
		// Add extensions acf, yoast, gforms, multilingual

		// Add revalidators

		// Add redirects/fix page links
	}

	/**
	 * Check for plugin/repo updates
	 */
	public function plugin_update_checker() {
		require NEXTPRESS_PATH . '/includes/plugin-update-checker/plugin-update-checker.php';

		$update_checker = \Puc_v4_Factory::buildUpdateChecker(
			'https://github.com/pomeg-dev/nextpress',
			__FILE__,
			'nextpress'
		);

		//Optional: If you're using a private repository, specify the access token like this:
		$update_checker->setAuthentication('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');

		//Optional: Set the branch that contains the stable release.
		// $update_checker->setBranch('stable-branch-name');
	}
}