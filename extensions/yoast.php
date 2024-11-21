<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextPressYoastExtension
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        add_filter("np_post_object", array($this, "include_yoast_data"), 10, 1);
    }

    public function include_yoast_data($post)
    {
        if (!function_exists('YoastSEO')) return $post;
        $meta_helper = YoastSEO()->classes->get(Yoast\WP\SEO\Surfaces\Meta_Surface::class);
        $meta = $meta_helper->for_post(is_object($post) ? $post->ID : $post['id']);
        if (!$meta) return $post;
        $post['yoastHeadJSON'] = $meta->get_head()->json;
        return $post;
    }
}

// Initialize the class
new NextPressYoastExtension();
