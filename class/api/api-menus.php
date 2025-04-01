<?php
/**
 * Nextpress menus router class
 * Adds the /menus rest route for fetching wp menus
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Menus {
  /**
   * Helpers.
   */
  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;

    // Register main menus route.
    add_action('rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/menus',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_menus' ],
        'permission_callback' => '__return_true',
      ]
    );

    register_rest_route(
      'nextpress',
      '/menus/(?P<location>[a-zA-Z0-9_-]+)', 
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_menu_by_location' ],
        'permission_callback' => '__return_true',
        'args' => [
          'location' => [
            'required' => true,
            'type' => 'string',
            'description' => 'The menu location slug',
          ],
        ],
      ]
    );
  }

  public function get_menus() {
    $menus = wp_get_nav_menus();
    $formatted_menus = array_map( function ( $menu ) {
      return $this->format_menu( $menu );
    }, $menus );

    return new \WP_REST_Response( $formatted_menus, 200 );
  }

  public function get_menu_by_location( $request ) {
    $location = $request->get_param( 'location' );
    $locations = get_nav_menu_locations();

    if ( !isset( $locations[ $location ] ) ) {
      return new WP_Error(
        'menu_location_not_found',
        sprintf(
          'Menu location "%s" not found. Available locations: %s',
          $location, 
          implode( ', ', array_keys( $locations ) )
        ),
        [ 'status' => 404 ]
      );
    }

    $menu_id = $locations[ $location ];
    $menu = wp_get_nav_menu_object( $menu_id );

    if ( ! $menu ) {
      return new WP_Error(
        'menu_not_found',
        sprintf(
          'Menu not found for location "%s"',
          $location
        ),
        [ 'status' => 404 ]
      );
    }

    $formatted_menu = $this->format_menu( $menu );
    return new \WP_REST_Response( $formatted_menu, 200 );
  }

  private function format_menu( $menu ) {
    $menu_items = wp_get_nav_menu_items( $menu->term_id );
    $formatted_items = array_map( function ( $item ) {
      return [
        'id' => $item->ID,
        'title' => $item->title,
        'url' => $item->url,
        'menu_order' => $item->menu_order,
        'parent' => $item->menu_item_parent,
      ];
    }, $menu_items );

    return [
      'id' => $menu->term_id,
      'name' => $menu->name,
      'slug' => $menu->slug,
      'items' => $formatted_items,
    ];
  }
}