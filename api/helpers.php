<?php

function get_homepage()
{
    if (get_option('show_on_front') ===  'page') {
        return get_post(get_option('page_on_front'));
    } else {
        return get_page_by_path('home');
    }
}

function is_homepage($post)
{
    $homepage = get_homepage();
    return $post->ID === $homepage->ID;
}

function get_categories_name_array($object)
{
    if (is_array($object)) $categories = $object['categories'];
    else $categories = get_the_category($object);
    foreach ($categories as $cat) {
        $cat = get_term($cat);
        $cats[] = array("id" => $cat->term_id, "name" => $cat->name);
    }
    return $cats;
}
