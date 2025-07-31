<?php

/**
 * @package Nextpress
 */

/*
Plugin Name: Nextpress
Plugin URI: 
Description: Nextpress is a WordPress plugin that allows you to use Next.js with WordPress as a headless CMS.
Version: 0.7
*/

defined('ABSPATH') or die('You do not have access to this file');


/*----------------------------------------------------------------------------*
 * Includes
 *----------------------------------------------------------------------------*/
require_once plugin_dir_path(__FILE__) . 'includes/acf-builder/autoload.php';
if ( ! class_exists( 'Firebase\JWT\JWT' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/php-jwt/src/JWT.php';
}
if ( ! class_exists( 'Firebase\JWT\Key' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/php-jwt/src/Key.php';
}


/*----------------------------------------------------------------------------*
 * Gutenberg
 *----------------------------------------------------------------------------*/
require_once plugin_dir_path(__FILE__) . 'gutenberg/register-blocks.php';
require_once plugin_dir_path(__FILE__) . 'gutenberg/core-blocks.php';

/*----------------------------------------------------------------------------*
 * Plugin WP-Admin Settings
 *----------------------------------------------------------------------------*/
require_once plugin_dir_path(__FILE__) . 'admin/admin-menu.php';
require_once plugin_dir_path(__FILE__) . 'admin/settings.php';
require_once plugin_dir_path(__FILE__) . 'admin/templates.php';
require_once plugin_dir_path(__FILE__) . 'admin/deploy.php';




/*----------------------------------------------------------------------------*
 * API
 *----------------------------------------------------------------------------*/
require_once plugin_dir_path(__FILE__) . 'api/helpers.php';
require_once plugin_dir_path(__FILE__) . 'api/NextpressApiRouter.php';
require_once plugin_dir_path(__FILE__) . 'api/NextpressApiSettings.php';
require_once plugin_dir_path(__FILE__) . 'api/NextpressApiPosts.php';
require_once plugin_dir_path(__FILE__) . 'api/NextpressApiTheme.php';
require_once plugin_dir_path(__FILE__) . 'api/NextpressApiMenus.php';
require_once plugin_dir_path(__FILE__) . 'api/NextpressApiTemplates.php';


/*----------------------------------------------------------------------------*
 * Extensions
 *----------------------------------------------------------------------------*/
require_once plugin_dir_path(__FILE__) . 'extensions/acf.php';
require_once plugin_dir_path(__FILE__) . 'extensions/yoast.php';
require_once plugin_dir_path(__FILE__) . 'extensions/gravity-forms.php';
require_once plugin_dir_path(__FILE__) . 'extensions/multilingual.php';


/*----------------------------------------------------------------------------*
* User Flow
*----------------------------------------------------------------------------*/
require_once plugin_dir_path(__FILE__) . 'user-flow/user-flow.php';


class Nextpress
{

    public function __construct()
    {

        // Stop crons on uninstall
        register_deactivation_hook(__FILE__, array($this, 'deactivate_scheduled_cron'));

        $this->_init();
    }

    private function _init()
    {
        NextpressApiRouter::_init();
        NextpressApiSettings::_init();
        NextpressApiPosts::_init();
        NextpressApiMenus::_init();
        NextpressApiTheme::_init();
        NextpressApiTemplates::_init();

        $this->plugin_update_checker();
    }

    public function plugin_update_checker()
    {
        // PLUGIN UPDATE CHECKER
        require 'plugin-update-checker/plugin-update-checker.php';
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/pomeg-dev/nextpress',
            __FILE__,
            'nextpress'
        );
        //Optional: If you're using a private repository, specify the access token like this:
        $myUpdateChecker->setAuthentication('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');

        //Optional: Set the branch that contains the stable release.
        // $myUpdateChecker->setBranch('stable-branch-name');
    }
}


new Nextpress;


//if fetch_blocks_from_api function is not returning anything, DISABEL all editign ont he site and show message
add_action('init', 'disable_editing_if_no_blocks');
function disable_editing_if_no_blocks()
{
    $blocks = fetch_blocks_from_api();
    if (empty($blocks)) {
        add_filter('use_block_editor_for_post', '__return_false');
        add_action('admin_notices', 'no_blocks_notice');

        //and hide the classic editor
        add_action('admin_head', 'hide_classic_editor');

        //dont allow updating the post
        // add_action('admin_head', 'disable_publishing');
    }
}

function get_nextpress_frontend_url()
{
    $fe_url = "http://localhost:3000";
    $api_url = get_field('blocks_api_url', 'option');
    if ($api_url) {
        $parsed_url = parse_url($api_url);
        $fe_url = $parsed_url['scheme'] . "://" . $parsed_url['host'];
    }

    return $fe_url;
}

function disable_publishing()
{
?>
    <style>
        #publishing-action {
            display: none;
        }
    </style>
<?php
}

function hide_classic_editor()
{
?>
    <style>
        #post-body-content {
            display: none;
        }
    </style>
<?php
}


function no_blocks_notice()
{
?>
    <div class="notice notice-error is-dismissible">
        <p><?php _e('No blocks found. Please make sure the blocks api endpoint is configured', 'nextpress'); ?></p>
    </div>
<?php
}


add_filter(
    'acf/pre_save_block',
    function ($attributes) {

        //error_log('attributes');
        // error_log(print_r($attributes, true));

        // if ( empty( $attributes['np_custom_id'] ) ) {
        //     $attributes['np_custom_id'] = 'np_custom_id-' . uniqid();
        // }

        if (!$attributes['np_custom_id']) {
            $attributes['np_custom_id'] = uniqid();
        }

        if (empty($attributes['anchor'])) {
            $attributes['anchor'] = 'block-' . uniqid();
        }

        // if ( empty( $attributes['data']['np_custom_id'] ) ) {
        //     $attributes['data']['np_custom_id'] = 'np_custom_id-' . uniqid();
        // }

        return $attributes;
    }
);

function nextpress_redirect_frontend()
{
    $fe_url = get_nextpress_frontend_url();

    // Check for yoast redirects.
    $redirects_json = get_option('wpseo-premium-redirects-base');
    if ($redirects_json) {
        foreach ($redirects_json as $redirect) {
            if (strpos($_SERVER['REQUEST_URI'], $redirect['origin']) !== false) {
                wp_redirect($fe_url . '/' . ltrim($redirect['url']), 301);
                exit;
            }
        }
    }

    //if multisite req, remove the blog url
    if (is_multisite()) {
        $path = get_blog_details()->path;
        $req = str_replace($path, "/", $_SERVER['REQUEST_URI']);
    } else {
        $req = $_SERVER['REQUEST_URI'];
    }
    if ($fe_url) {
        parse_str(parse_url($req, PHP_URL_QUERY), $queryParams);
        if (
            isset($queryParams['page_id'])
        ) {
            $page_id = $queryParams['page_id'];
            $req = '/api/draft?secret=<token>&id=' . $page_id;
        }
        wp_redirect($fe_url . $req, 301);
        exit;
    }
}
add_action('template_redirect', 'nextpress_redirect_frontend', 10, 1);

function nextpress_edit_post_preview_link($link, WP_Post $post)
{
    $fe_url = get_nextpress_frontend_url();
    $draft_link =  $fe_url . "/api/draft?secret=<token>&id=" . $post->ID;
    return $draft_link;
}
add_filter('preview_post_link', 'nextpress_edit_post_preview_link', 10, 2);


// DUMPER FUNCTION
function np_dumper($variable) {
    error_log('NP DUMP: ' . print_r($variable, true));
}