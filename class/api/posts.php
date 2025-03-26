<?php
/**
 * Nextpress posts router class
 * Adds the /posts rest route for fetching all wp posts
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class Posts {
  /**
   * Helpers.
   */
  public $helpers;

  /**
   * Post formatter.
   */
  public $formatter;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->formatter = new Post_Formatter();
    add_action('rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/posts',
      [
        'methods' => 'GET',
        'callback' => array('NextpressApiPosts', 'get_posts'),
        'permission_callback' => '__return_true',
        // 'args' => self::get_collection_params(),
      ]
    );
  }
}