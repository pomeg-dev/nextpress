<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextpressApiMenus
{
    public function __construct()
    {
        $this->_init();
    }

    public static function _init()
    {
        add_action('rest_api_init', array('NextpressApiMenus', 'register_routes'));
    }

    public static function register_routes()
    {
        register_rest_route('nextpress', '/menus', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiMenus', 'get_menus'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('nextpress', '/menus/(?P<location>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiMenus', 'get_menu_by_location'),
            'permission_callback' => '__return_true',
            'args' => array(
                'location' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'The menu location slug',
                ),
            ),
        ));
    }

    public static function get_menus()
    {
        $menus = wp_get_nav_menus();
        $formatted_menus = array_map(function ($menu) {
            return self::format_menu($menu);
        }, $menus);

        return new WP_REST_Response($formatted_menus, 200);
    }

    public static function get_menu_by_location($request)
    {
        $location = $request->get_param('location');
        $locations = get_nav_menu_locations();

        if (!isset($locations[$location])) {
            return new WP_Error(
                'menu_location_not_found',
                sprintf('Menu location "%s" not found. Available locations: %s', $location, implode(', ', array_keys($locations))),
                array('status' => 404)
            );
        }

        $menu_id = $locations[$location];
        $menu = wp_get_nav_menu_object($menu_id);

        if (!$menu) {
            return new WP_Error(
                'menu_not_found',
                sprintf('Menu not found for location "%s"', $location),
                array('status' => 404)
            );
        }

        $formatted_menu = self::format_menu($menu);
        return new WP_REST_Response($formatted_menu, 200);
    }

    private static function format_menu($menu)
    {
        $menu_items = wp_get_nav_menu_items($menu->term_id);
        $formatted_items = array_map(function ($item) {
            return array(
                'id' => $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'menu_order' => $item->menu_order,
                'parent' => $item->menu_item_parent,
            );
        }, $menu_items);

        return array(
            'id' => $menu->term_id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'items' => $formatted_items,
        );
    }
}
