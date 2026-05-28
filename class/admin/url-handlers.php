<?php
/**
 * Handles WordPress URL redirects and preview links for the Next.js frontend.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class URL_Handlers {
	/**
	 * Helpers.
	 */
	public $helpers;

	public function __construct( $helpers ) {
		$this->helpers = $helpers;

		add_action( 'template_redirect', [ $this, 'redirect_frontend' ] );
		add_filter( 'preview_post_link', [ $this, 'preview_post_link' ], 10, 2 );
	}

	/**
	 * Redirect all frontend requests to the Next.js frontend.
	 */
	public function redirect_frontend() {
		// Check for yoast redirects.
		$redirects_json = get_option('wpseo-premium-redirects-base');
		$permalink = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		$permalink = rtrim(
			str_replace(
				site_url( '/' ),
				'',
				$permalink
			), '/'
		);

		if ( $redirects_json ) {
			foreach ( $redirects_json as $redirect ) {
				if ( $permalink === $redirect['origin'] ) {
					wp_redirect( $this->helpers->get_frontend_url_public() . '/' . ltrim( $redirect['url'] ), 301 );
					exit;
				}
			}
		}

		// If multisite request, remove the blog url
		if ( is_multisite() ) {
			$path = get_blog_details()->path;
			$req = str_replace( $path, '/', $_SERVER['REQUEST_URI'] );
		} else {
			$req = $_SERVER['REQUEST_URI'];
		}

		parse_str( parse_url( $req, PHP_URL_QUERY ) ?? '', $query_params );
		if ( isset( $query_params['page_id'] ) ) {
			$page_id = $query_params['page_id'];
			$req = '/api/draft?secret=<token>&id=' . $page_id;
		} elseif ( isset( $query_params['p'] ) ) {
			$page_id = $query_params['p'];
			$req = '/api/draft?secret=<token>&id=' . $page_id;
		}

		if (
			strpos($req, 'wp-admin') !== false ||
			strpos($req, 'wp-login') !== false ||
			strpos($req, 'index.php') !== false
		) {
			return;
		}

		wp_redirect( $this->helpers->get_frontend_url_public() . $req, 301 );
		exit;
	}

	/**
	 * Rewrite preview post links to point to the Next.js draft endpoint.
	 */
	public function preview_post_link( $link, $post ) {
		return $this->helpers->get_frontend_url_public() . '/api/draft?secret=<token>&id=' . $post->ID;
	}
}