<?php
/**
 * This class registers admin pages for the plugin
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Register_Pages {
	public function __construct() {
    add_action( 'acf/init', [ $this, 'register_admin_page' ] );
  }

  /**
   * Register page
   */
  public function register_admin_page() {
    if ( function_exists( 'acf_add_options_page' ) ) {
      $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="39" height="39" viewBox="0 0 39 39" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M19.5 39C30.2696 39 39 30.2696 39 19.5C39 8.73045 30.2696 0 19.5 0C8.73045 0 0 8.73045 0 19.5C0 30.2696 8.73045 39 19.5 39ZM17 11H12V28H17V19.3398L22 28L22 28L22 28H27V11H22V19.6602L17 11L17 11V11Z" fill="#151B1E"/></svg>';
      $encoded_svg = 'data:image/svg+xml;base64,' . base64_encode( $svg );

      acf_add_options_page(
        [
          'page_title'    => __( 'Nextpress', 'nextpress' ),
          'menu_title'    => __( 'Nextpress', 'nextpress' ),
          'menu_slug'     => 'nextpress',
          'capability'    => 'edit_posts',
          'icon_url'      => $encoded_svg,
          'redirect'      => true
        ]
      );

      acf_add_options_sub_page(
        [
          'page_title'    => __( 'Settings', 'nextpress' ),
          'menu_title'    => __( 'Settings', 'nextpress' ),
          'parent_slug'   => 'nextpress',
        ]
      );

      acf_add_options_sub_page(
        [
          'page_title'    => __( 'Templates', 'nextpress' ),
          'menu_title'    => __( 'Templates', 'nextpress' ),
          'parent_slug'   => 'nextpress',
          'menu_slug'     => 'templates',
        ]
      );
    }
  }
}