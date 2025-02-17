<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextPressAcfExtension
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        add_filter("np_post_object", array($this, "include_acf_data"));
        add_filter("np_block_data", array($this, "reformat_block_data"), 10, 2);
        add_filter("np_block_data", array($this, "replace_nav_id_in_block"), 10, 2);
        add_action('rest_api_init', array($this, 'featured_media_posts_api'));
    }

    public function include_acf_data($post)
    {
        if (!function_exists('get_fields')) return $post;
        $post['acf_data'] = get_fields(is_object($post) ? $post->ID : $post['id']);
        if (!is_array($post['acf_data'])) return $post;
        foreach ($post['acf_data'] as $key => $value) {
            if (is_string($value) && strpos($value, 'nav_id') !== false) {
                $post['acf_data'][$key] = $this->replace_nav_id_in_acf($value);
            }
        }
        return $post;
    }

    public function reformat_block_data($block_data, $block)
    {
        if (!isset($block['attrs']['data'])) return $block;
        acf_setup_meta($block['attrs']['data'], $block["attrs"]["np_custom_id"], true);
        $fields = get_fields();
        acf_reset_meta($block['attrs']['name']);
        $block_data = $fields;
        return $block_data;
    }

    // //if you spot a value of {{nav_id-[id]}} in the block data, replace it with the actual menu object
    public function replace_nav_id_in_block($block_data, $block)
    {
       // Stringiy block data and check if nav-id exists.
       $block_string = wp_json_encode($block_data);
       $re = '/{{nav_id-(\d*)}}/m';
       preg_match_all($re, $block_string, $matches, PREG_SET_ORDER, 0);
       if ($matches) {
           foreach ($matches as $match) {
               $nav_id = $match[1];
               if (!$nav_id) continue;
               $block_data['menus'][$nav_id] = wp_get_nav_menu_items($nav_id);
           }
       }
       
       return $block_data;
    }

    // //if you spot a value of {{nav_id-[id]}} in the block data, replace it with the actual menu object
    public function replace_nav_id_in_acf($block_data)
    {
       // Stringiy block data and check if nav-id exists.
       $block_string = wp_json_encode($block_data);
       $re = '/{{nav_id-(\d*)}}/m';
       preg_match_all($re, $block_string, $matches, PREG_SET_ORDER, 0);
       if ($matches) {
           foreach ($matches as $match) {
               $nav_id = $match[1];
               if (!$nav_id) continue;
               return wp_get_nav_menu_items($nav_id);
           }
       }
    }

    public function edit_attachment_response($object, $field_name, $request)
    {
        if ($object['type'] == "attachment") {
            if (($xml = simplexml_load_file($object['guid']['raw'])) !== false) {
                $attrs = $xml->attributes();
                $viewbox = explode(' ', $attrs->viewBox);
                $image[1] = isset($attrs->width) && preg_match('/\d+/', $attrs->width, $value) ? (int) $value[0] : (count($viewbox) == 4 ? (int) $viewbox[2] : null);
                $image[2] = isset($attrs->height) && preg_match('/\d+/', $attrs->height, $value) ? (int) $value[0] : (count($viewbox) == 4 ? (int) $viewbox[3] : null);
                return array($viewbox[2], $viewbox[3]);
            }
        }
        return false;
    }

    public function featured_media_posts_api()
    {
        register_rest_field(
            array('attachment'), //name of post type 'post', 'page'
            'dimensions', //name of field to display
            array(
                'get_callback'    => array($this, 'edit_attachment_response'),
                'update_callback' => null,
                'schema'          => null,
            )
        );
    }
}

// Initialize the class
new NextPressAcfExtension();
