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
            "href" => str_replace(home_url(), get_nextpress_frontend_url(), get_permalink($post_id)),
        ];
        $related_sites = get_field('related_sites', 'option');
        if ($related_sites) {
            foreach ($related_sites as $site_id) {
                $site_id = str_replace('site_id_', '', $site_id);

                // Get href for related post.
                $ml_post_id = false;
                if (class_exists('cty_cloner\Cloner_Relationship')) {
                    $cloner    = new cty_cloner\Cloner_Relationship();
                    $rel_array = $cloner->get_relationship( $post_id, get_current_blog_id() );
                    if ($rel_array) {
                        $relationship = $rel_array['relationship'];
                        $key          = array_key_first( $relationship );
                        if ( isset( $relationship[ $key ][ $site_id ] ) ) {
                            $ml_post_id = $relationship[ $key ][ $site_id ];
                        }
                    }
                }

                switch_to_blog($site_id);
                $ml_locale = substr(get_blog_option($site_id, 'WPLANG'), 0, 2);
                $ml_href = $ml_post_id ? get_permalink($ml_post_id) : home_url();
                $ml_href = str_replace(home_url(), get_nextpress_frontend_url(), $ml_href);
                
                $hreflang[] = [
                    "code" => $ml_locale,
                    "href" => $ml_href,
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
