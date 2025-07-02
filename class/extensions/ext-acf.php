<?php
/**
 * Extends WP posts delivered to nextjs with ACF meta/block data.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Ext_ACF {
  public function __construct() {
    // Add custom atts early on to ACF data.
    add_filter( 'acf/pre_save_block', [ $this, 'add_acf_attributes' ], 10, 1 );

    // Modify data.
    add_filter( 'nextpress_post_object', [ $this, 'include_acf_data' ], 10, 1 );
    add_filter( 'nextpress_block_data', [ $this, 'reformat_block_data' ], 10, 2 );
    add_filter( 'nextpress_block_data', [ $this, 'replace_nav_id_in_data' ], 10, 1 );
    add_action( 'rest_api_init', [ $this, 'featured_media_posts_api' ] );
  }

  public function add_acf_attributes( $attributes ) {
    if ( ! $attributes['nextpress_id']) {
      $attributes['nextpress_id'] = uniqid();
    }

    if ( ! $attributes['anchor'] ) {
      $attributes['anchor'] = 'block-' . uniqid();
    }

    return $attributes;
  }

  public function include_acf_data( $post ) {
    if ( ! function_exists( 'get_fields' ) ) return $post;

    $post['acf_data'] = get_fields(
      is_object( $post ) ? $post->ID : $post['id']
    );

    if ( ! is_array( $post['acf_data'] ) ) return $post;

    foreach ( $post['acf_data'] as $key => $value ) {
      if ( is_string( $value ) && strpos( $value, 'nav_id' ) !== false ) {
        $post['acf_data'][ $key ] = $this->replace_nav_id_in_data( $value, false );
      }
    }

    return $post;
  }

  public function reformat_block_data( $block_data, $block ) {
    if (
      ! isset( $block['attrs']['data'] ) ||
      ! isset( $block['attrs']['nextpress_id'] )
    ) {
      return $this->reduce_media_data( $block_data );
    }

    acf_setup_meta(
      $block['attrs']['data'],
      $block['attrs']['nextpress_id'],
      true
    );

    $fields = get_fields();
    acf_reset_meta( $block['attrs']['name'] );

    // Remove unused image attrs.
    $fields = $this->reduce_media_data( $fields );

    $block_data = $fields;
    return $block_data;
  }

  private function reduce_media_data( $data ) {
    if ( ! is_array( $data ) ) return $data;
    
    // Check if this is an image array
    if ( isset( $data['mime_type'] ) && strpos( $data['mime_type'], 'image' ) !== false ) {
      if ( isset( $data['sizes'] ) ) {
        unset( $data['sizes']['medium_large'] );
        unset( $data['sizes']['medium_large-width'] );
        unset( $data['sizes']['medium_large-height'] );
        unset( $data['sizes']['1536x1536'] );
        unset( $data['sizes']['1536x1536-width'] );
        unset( $data['sizes']['1536x1536-height'] );
        unset( $data['sizes']['2048x2048'] );
        unset( $data['sizes']['2048x2048-width'] );
        unset( $data['sizes']['2048x2048-height'] );
      }

      return [
        'id' => $data['id'] ?? null,
        'title' => $data['title'] ?? null,
        'url' => $data['url'] ?? null,
        'alt' => $data['alt'] ?? null,
        'description' => $data['description'] ?? null,
        'caption' => $data['caption'] ?? null,
        'width' => $data['width'] ?? null,
        'height' => $data['height'] ?? null,
        'sizes' => $data['sizes'] ?? [],
      ];
    }

    // Check if this is a video array
    if ( isset( $data['mime_type'] ) && strpos( $data['mime_type'], 'video' ) !== false ) {
      return [
        'id' => $data['id'] ?? null,
        'title' => $data['title'] ?? null,
        'url' => $data['url'] ?? null,
        'alt' => $data['alt'] ?? null,
        'description' => $data['description'] ?? null,
        'caption' => $data['caption'] ?? null,
        'width' => $data['width'] ?? null,
        'height' => $data['height'] ?? null,
        'mime_type' => $data['mime_type'] ?? [],
      ];
    }
    
    // Recursively process array elements
    return array_map( [$this, 'reduce_media_data' ], $data );
  }

  // If you spot a value of {{nav_id-[id]}} in the block data, replace it with the actual menu object
  public function replace_nav_id_in_data( $block_data, $is_block = true ) {
    // Stringify block data and check if nav-id exists.
    $block_string = wp_json_encode( $block_data );
    $re = '/{{nav_id-(\d*)}}/m';
    preg_match_all( $re, $block_string, $matches, PREG_SET_ORDER, 0 );
    if ( $matches ) {
      foreach ( $matches as $match ) {
        $nav_id = $match[1];
        if ( ! $nav_id ) continue;
        if ( $is_block ) {
          $menu_items = wp_get_nav_menu_items(
            $nav_id,
            [
              'update_post_term_cache' => false,
              'post_status' => 'publish',
            ]
          );

          if ( $menu_items ) {
            foreach ( $menu_items as &$item ) {
              $acf_fields = get_fields( $item );
              $acf_fields = $acf_fields ? array_filter( $acf_fields ) : null;
              if ( $acf_fields ) {
                $item->acf_data = $acf_fields;
              }
            }

            $formatted_items = array_map( function ( $item ) {
              return [
                'ID' => $item->ID,
                'title' => $item->title,
                'url' => $item->url,
                'target' => $item->target,
                'classes' => $item->classes,
                'menu_item_parent' => $item->menu_item_parent,
                'acf_data' => $item->acf_data,
              ];
            }, $menu_items );

            $block_data['menus'][ $nav_id ] = (array) $formatted_items;
          }
        } else {
          $menu_items = wp_get_nav_menu_items(
            $nav_id,
            [
              'update_post_term_cache' => false,
              'post_status' => 'publish',
            ]
          );

          $formatted_items = array_map( function ( $item ) {
            return [
              'ID' => $item->ID,
              'title' => $item->title,
              'url' => $item->url,
              'target' => $item->target,
              'classes' => $item->classes,
              'menu_item_parent' => $item->menu_item_parent,
              'acf_data' => $item->acf_data,
            ];
          }, $menu_items );
          
          return $formatted_items;
        }
      }
    }
    
    return $block_data;
  }

  public function featured_media_posts_api() {
    register_rest_field(
      [ 'attachment' ],
      'dimensions',
      [
        'get_callback'    => [ $this, 'edit_attachment_response' ],
        'update_callback' => null,
        'schema'          => null,
      ]
    );
  }

  public function edit_attachment_response( $object, $field_name, $request ) {
    if ( $object['type'] === 'attachment' ) {
      if ( ( $xml = simplexml_load_file($object['guid']['raw'] ) ) !== false ) {
        $attrs = $xml->attributes();
        $viewbox = explode( ' ', $attrs->viewBox );
        $image[1] = isset( $attrs->width ) && preg_match( '/\d+/', $attrs->width, $value ) 
          ? (int) $value[0] 
          : (count($viewbox) == 4 ? (int) $viewbox[2] : null);
        $image[2] = isset( $attrs->height ) && preg_match( '/\d+/', $attrs->height, $value ) 
          ? (int) $value[0] 
          : (count($viewbox) == 4 ? (int) $viewbox[3] : null);
        return array($viewbox[2], $viewbox[3]);
      }
    }
    return false;
  }
}