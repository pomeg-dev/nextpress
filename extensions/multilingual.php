<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextPressMLExtension
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        add_filter("np_post_object", array($this, "include_related_sites"), 10, 1);
    }

    public function include_related_sites($post)
    {
        // Get related sites option.
        $current_blog_id = get_current_blog_id();
        $locale = substr(get_blog_option($current_blog_id, 'WPLANG'), 0, 2);
        $post_id = is_object($post) ? $post->ID : $post['id'];
        $hreflang[] = [
            "code" => $locale,
            "href" => get_permalink($post_id),
        ];
        $related_sites = get_field('related_sites', 'option');
        if ($related_sites) {
            foreach ($related_sites as $site_id) {
                $site_id = str_replace('site_id_', '', $site_id);
                switch_to_blog($site_id);
                $ml_locale = substr(get_blog_option($site_id, 'WPLANG'), 0, 2);
                $hreflang[] = [
                    "code" => $ml_locale,
                    "href" => home_url(),
                ];
                restore_current_blog();
            }
            
            $post['hreflang'] = $hreflang;
        }
        return $post;
    }
}

// Initialize the class
new NextPressMLExtension();
