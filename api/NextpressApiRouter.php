<?php

defined('ABSPATH') or die('You do not have access to this file');

require_once(plugin_dir_path(__FILE__) . 'NextpressPostFormatter.php');
require_once(plugin_dir_path(__FILE__) . 'helpers.php');

class NextpressApiRouter
{
    public function __construct()
    {
        $this->_init();
    }

    public static function _init()
    {
        add_action('rest_api_init', array('NextpressApiRouter', 'register_routes'));
    }

    public static function register_routes()
    {
        register_rest_route('nextpress', '/router(/(?P<path>[a-zA-Z0-9-\/]+))?$', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiRouter', 'get_post_by_path'),
        ));
    }

    public static function get_post_by_path($data)
    {
        $path = apply_filters("nextpress_path", $data['path']);
        $page_for_posts_id = get_option('page_for_posts');
        $page_for_posts_url = get_permalink(get_option('page_for_posts'));
        $page_for_posts_path = trim(str_replace(site_url(), '', $page_for_posts_url), '/');
        
        if (!$path) {
            $post_id = $data->get_param('p') ?? $data->get_param('page_id');
            $post = $post_id ? get_post($post_id) : get_homepage();
        } else if ($page_for_posts_path == $path) {
            $post = get_post($page_for_posts_id);
        } else {
            $post_id = url_to_postid($path);
            $post = get_post($post_id);
        }

        if (!$post) {
            $path_parts = explode('/', $path);
            $slug = end($path_parts);

            $post_status = array('draft', 'pending', 'auto-draft', 'future', 'private', 'revision');

            $post = get_posts(array(
                'post_type' => 'any',
                'post_status' => $post_status,
                'name' => $slug,
                'posts_per_page' => 1
            ));
            $post = !empty($post) ? $post[0] : null;
            if (!$post) {
                $post = get_posts(array(
                    'post_status' => $post_status,
                    'title' => $slug,
                    'posts_per_page' => 1
                ));
                $post = !empty($post) ? $post[0] : null;
            }
        }

        if (!$post) return apply_filters('np_post_not_found', ['404' => true]);

        return NextpressPostFormatter::format_post($post, true);
    }
}
