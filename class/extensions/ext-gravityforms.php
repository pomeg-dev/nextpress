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
    add_filter( 'nextpress_post_object', [ $this, 'include_gf_post_data' ], 10, 1 );
  }

  public function include_gf_data( $block_data ) {
    // Early exit.
    if ( ! class_exists( 'GFAPI' ) ) return $block_data;

    // Check if contains key: gravity_form
    $is_gf = isset( $block_data['gravity_form'] );
    if ( $is_gf ) {
      $block_data['gfData'] = \GFAPI::get_form( str_replace( 'form_id_', '', $block_data['gravity_form'] ) );
      return $block_data;
    } elseif ( $block_data && is_array( $block_data ) ) {
      // Check if any fields are keyed with 'gravity' and 'form'.
      $keys = array_keys( $block_data );
      foreach ( $keys as $key ) {
        if ( strpos( $key, 'gravity' ) !== false && strpos( $key, 'form' ) !== false ) {
          $data_key = "${key}_data";
          $block_data[ $data_key ] = \GFAPI::get_form( str_replace( 'form_id_', '', $block_data[ $key ] ) );
          return $block_data;
        }
      }
    }

    return $block_data;
  }

  public function include_gf_post_data( $post ) {
    // Early exit.
    if ( ! class_exists( 'GFAPI' ) ) return $post;

    // Check if contains key: gravity_form
    $is_gf = isset( $post['acf_data']['gravity_form'] );
    if ( $is_gf ) {
      $post['acf_data']['gfData'] = \GFAPI::get_form( $post['acf_data']['gravity_form'] );
      return $post;
    } elseif ( isset( $post['acf_data'] ) && is_array( $post['acf_data'] ) ) {
      // Check if any fields are keyed with 'gravity' and 'form'.
      $keys = array_keys( $post['acf_data'] );
      foreach ( $keys as $key ) {
        if ( strpos( $key, 'gravity' ) !== false && strpos( $key, 'form' ) !== false ) {
          $data_key = "${key}_data";
          $post['acf_data'][ $data_key ] = \GFAPI::get_form( $post['acf_data'][ $key ] );
          return $post;
        }
      }
    }


    return $post;
  }
}