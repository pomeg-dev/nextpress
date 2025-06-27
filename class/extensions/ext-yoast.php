<?php
/**
 * Extends WP posts delivered to nextjs with Yoast meta data.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Ext_Yoast {
  public function __construct() {
    add_filter( 'nextpress_post_object_w_meta', [ $this, 'include_yoast_post_data' ], 10, 1 );
    add_filter( 'nextpress_term_object', [ $this, 'include_yoast_term_data' ], 10, 1 );
    add_filter( 'nextpress_post_not_found', [ $this, 'include_yoast_404_redirects' ], 10, 1 );
  }

  public function include_yoast_post_data( $post ) {
    if ( ! function_exists( 'YoastSEO' ) ) return $post;
    $meta_helper = YoastSEO()->classes->get( \Yoast\WP\SEO\Surfaces\Meta_Surface::class );
    $post_id = is_object( $post ) 
      ? $post->ID 
      : $post['id'];
    $meta = $meta_helper->for_post( $post_id );

    if (!$meta) return $post;
    $post['yoastHeadJSON'] = $meta->get_head()->json;

    // Check for redirects.
    $redirects_json = get_option( 'wpseo-premium-redirects-base' );
    $permalink = get_permalink( $post_id );
    $permalink = rtrim( 
      str_replace( 
        site_url( '/' ), 
        '',
        $permalink
      ), '/'
    );

    if ( $redirects_json && $permalink ) {
      foreach ( $redirects_json as $redirect ) {
        if ( $permalink === $redirect['origin'] ) {
          $post['yoastHeadJSON']['redirect'] = $redirect['url'];
        }
      }
    }

    return $post;
  }

  public function include_yoast_term_data( $term ) {
    if ( ! function_exists( 'YoastSEO' ) ) return $term;
    $meta_helper = YoastSEO()->classes->get( \Yoast\WP\SEO\Surfaces\Meta_Surface::class );
    $meta = $meta_helper->for_term( $term->term_id, $term->taxonomy );

    if (!$meta) return $term;
    $term->yoastHeadJSON = $meta->get_head()->json;
    
    return $term;
  }

  public function include_yoast_404_redirects( $post ) {
    $redirects_json = get_option( 'wpseo-premium-redirects-base' );
    $permalink = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    $permalink = rtrim( 
      str_replace( 
        site_url( '/' ), 
        '',
        $permalink
      ), '/'
    );

    if ( $redirects_json && $permalink ) {
      foreach ( $redirects_json as $redirect ) {
        if ( $permalink === $redirect['origin'] ) {
          $post['yoastHeadJSON']['redirect'] = $redirect['url'];
        }
      }
    }

    return $post;
  }
}