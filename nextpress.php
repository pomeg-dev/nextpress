<?php
/**
 * Plugin Name: Nextpress
 * Plugin URI: https://pomegranate.co.uk
 * Description: Nextpress is a WordPress plugin that allows you to use Next.js with WordPress as a headless CMS.
 * Author: Pomegranate
 * Version: 2.02
 * Text Domain: nextpress
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

// Base filepath and URL constants, without a trailing slash.
define( 'NEXTPRESS_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'NEXTPRESS_URI', plugins_url( plugin_basename( __DIR__ ) ) );

/**
 * 'spl_autoload_register' callback function.
 * Autoloads all the required plugin classes, found in the /class/ directory (relative to the plugin's root).
 *
 * @param string $class The name of the class being instantiated inculding its namespaces.
 */
function autoloader( $class ) {
    // $class returns the classname including any namespaces
    $raw_class = explode( '\\', $class );
    $filename = str_replace( '_', '-', strtolower( end( $raw_class ) ) );
    $base_dir = __DIR__ . '/class/';
    $found = false;
    
    // First check if the file exists directly in the class directory
    $direct_filepath = $base_dir . $filename . '.php';
    if ( file_exists( $direct_filepath ) ) {
			include_once $direct_filepath;
			$found = true;
    } else {
			// If not found, check each subdirectory
			if ( is_dir( $base_dir ) ) {
				$subdirs = glob( $base_dir . '*', GLOB_ONLYDIR );
				foreach ( $subdirs as $dir ) {
					$filepath = $dir . '/' . $filename . '.php';
					if ( file_exists( $filepath ) ) {
						include_once $filepath;
						$found = true;
						break;
					}
				}
			}
    }
    return $found;
}
spl_autoload_register( __NAMESPACE__ . '\autoloader' );

/**
 * Initialise
 */
new Init();

// Dumper function
function np_dumper( $variable ) {
	error_log( 'NP DUMP: ' . print_r( $variable, true ) );
}