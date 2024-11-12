<?php

use function SafeSvg\Blocks\SafeSvgBlock\register;

defined('ABSPATH') or die('You do not have access to this file');


//routes we need:
// /wp-json/nextpress/router/<route>
// /wp-json/nextpress/post/<slug>  ,   /wp-json/nextpress/post/<id>

class NextpressApiTheme
{
    public function __construct()
    {
        $this->_init();
    }

    public static function _init()
    {
        add_action('rest_api_init', array('NextpressApiTheme', 'register_routes'));

        add_filter("nextpress_post_object", array('NextpressApiTheme', 'parse_block_data'));
        // add_filter("nextpress_post_object", array('NextpressApiTheme', 'include_featured_image'));
        // add_filter("nextpress_post_object", array('NextpressApiTheme', 'include_author_name'));
    }

    public static function register_routes()
    {
        register_rest_route('nextpress', '/theme(/(?P<path>[a-zA-Z0-9-\/]+))?$', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiTheme', 'get_theme_json'),
            // 'args' => array(),
        ));

        //another route for getting the block_theme field (wp option)
        register_rest_route('nextpress', '/block_theme(/(?P<theme>[a-zA-Z0-9-\/]+))?$', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiTheme', 'get_block_theme'),
            // 'args' => array(),
        ));
    }

    public static function get_theme_json($data)
    {
        // $path = apply_filters("nextpress_path", $data['path']);

        // get theme json
        $file = file_get_contents(get_template_directory() . '/theme.json');
        $json = json_decode($file, true);


        return apply_filters("nextpress_theme_json", $json);
    }

    public static function get_block_theme($data)
    {
        $theme = get_field('blocks_theme', 'option');
        return apply_filters("np_block_theme", $theme);
    }
}
