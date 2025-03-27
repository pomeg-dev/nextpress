<?php
/**
 * Extends WP posts delivered to nextjs with Gravityforms data.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Ext_GravityForms {
  public function __construct() {
    add_filter( 'nextpress_block_data', [ $this, 'include_gf_data' ], 10, 2 );
  }

  public function include_gf_data( $block_data ) {
    if ( ! isset( $block_data['gravity_form'] ) ) return $block_data;
    if ( ! class_exists( 'GFAPI' ) ) return $block_data;
    $block_data['gfData'] = \GFAPI::get_form( str_replace( 'form_id_', '', $block_data['gravity_form'] ) );
    return $block_data;
  }
}