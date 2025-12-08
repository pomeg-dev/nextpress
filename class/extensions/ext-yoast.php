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
    $permalink = get_permalink( $post_id );
    $permalink = rtrim(
      str_replace(
        site_url( '/' ),
        '',
        $permalink
      ), '/'
    );

    $redirect_url = $this->check_yoast_redirects( $permalink );
    if ( $redirect_url ) {
      $post['yoastHeadJSON']['redirect'] = $redirect_url;
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
    $permalink = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
    $permalink = rtrim(
      str_replace(
        site_url( '/' ),
        '',
        $permalink
      ), '/'
    );
    if ( strpos( $permalink, '/router/' ) !== false ) {
      $permalink = substr( $permalink, strpos( $permalink, '/router/' ) + strlen( '/router/' ) );
    }

    $redirect_url = $this->check_yoast_redirects( $permalink );
    if ( $redirect_url ) {
      $post['yoastHeadJSON']['redirect'] = $redirect_url;
    }

    return $post;
  }

  /**
   * Check if a permalink matches any Yoast redirects and return the redirect URL if found.
   *
   * @param string $permalink The permalink to check (without leading slash)
   * @return string|false The redirect URL if found, false otherwise
   */
  private function check_yoast_redirects( $permalink ) {
    $redirects_json = get_option( 'wpseo-premium-redirects-base' );

    if ( ! $redirects_json || ! $permalink ) {
      return false;
    }

    foreach ( $redirects_json as $redirect ) {
      $format = isset( $redirect['format'] ) ? $redirect['format'] : 'plain';

      if ( $format === 'regex' ) {
        // Handle regex redirects
        $pattern = $redirect['origin'];
        $pattern = preg_replace( '#^\^/#', '^', $pattern );
        $test_permalink = $permalink;
        $test_permalink_with_slash = $permalink . '/';
        $pattern = '#' . $pattern . '#';
        $matched = false;
        $matches = array();

        if ( preg_match( $pattern, $test_permalink, $matches ) ) {
          $matched = true;
        } elseif ( preg_match( $pattern, $test_permalink_with_slash, $matches ) ) {
          $matched = true;
        }

        if ( $matched ) {
          $redirect_url = $redirect['url'];
          // Replace $1, $2, etc. with captured groups
          for ( $i = 1; $i < count( $matches ); $i++ ) {
            $redirect_url = str_replace( '$' . $i, $matches[$i], $redirect_url );
          }
          return rtrim( $redirect_url, '/') . '/';
        }
      } else {
        // Handle plain redirects
        if ( $permalink === $redirect['origin'] ) {
          return rtrim( $redirect['url'], '/') . '/';
        }
      }
    }

    return false;
  }
}