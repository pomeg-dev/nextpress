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
 * Resolves classes via a static classmap — no filesystem scanning on every load.
 *
 * @param string $class Fully-qualified class name.
 */
function autoloader( $class ) {
    static $class_map = [
        'nextpress\\init'                    => '/class/init.php',
        'nextpress\\helpers'                 => '/class/helpers.php',
        'nextpress\\cache'                   => '/class/cache.php',
        'nextpress\\api_router'              => '/class/api/api-router.php',
        'nextpress\\api_posts'               => '/class/api/api-posts.php',
        'nextpress\\api_settings'            => '/class/api/api-settings.php',
        'nextpress\\api_menus'               => '/class/api/api-menus.php',
        'nextpress\\api_theme'               => '/class/api/api-theme.php',
        'nextpress\\post_formatter'          => '/class/api/post-formatter.php',
        'nextpress\\register_settings'       => '/class/admin/register-settings.php',
        'nextpress\\register_pages'          => '/class/admin/register-pages.php',
        'nextpress\\register_templates'      => '/class/admin/register-templates.php',
        'nextpress\\fix_autoload_transients' => '/class/admin/fix-autoload-transients.php',
        'nextpress\\url_handlers'            => '/class/admin/url-handlers.php',
        'nextpress\\register_blocks'         => '/class/gutenberg/register-blocks.php',
        'nextpress\\field_builder'           => '/class/gutenberg/field-builder.php',
        'nextpress\\ext_acf'                 => '/class/extensions/ext-acf.php',
        'nextpress\\ext_gravityforms'        => '/class/extensions/ext-gravityforms.php',
        'nextpress\\ext_yoast'               => '/class/extensions/ext-yoast.php',
        'nextpress\\user_flow'               => '/class/user-flow/user-flow.php',
    ];

    $key = strtolower( $class );
    if ( isset( $class_map[ $key ] ) ) {
        include_once __DIR__ . $class_map[ $key ];
        return true;
    }
    return false;
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