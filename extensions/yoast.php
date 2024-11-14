<?php

add_filter("np_post_object", "include_yoast_data");
function include_yoast_data($post)
{
    if (!function_exists('YoastSEO')) return $post;
    $meta_helper = YoastSEO()->classes->get(Yoast\WP\SEO\Surfaces\Meta_Surface::class);
    $meta = $meta_helper->for_post($post->ID);
    if (!$meta) return $post;
    $post['yoastHeadJSON'] = $meta->get_head()->json;
    return $post;
}
