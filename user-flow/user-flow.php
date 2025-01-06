<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextPressUserFlow {
  public function __construct() {
    $is_enabled = get_field('enable_user_flow', 'option');
    if ($is_enabled) {
      $this->init();
    }
  }

  public function init() {
    add_action('rest_pre_serve_request', function($response) {
      header('Access-Control-Allow-Origin: *');
      header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Headers: Content-Type, Authorization');
      return $response;
    });

    // Register routes.
    $this->register_routes();

    // redierct to login page
    // enable_login_redirect
    // login_page
  }

  private function register_routes() {
    add_action('rest_api_init', function () {
      register_rest_route('nextpress', '/login', array(
          'methods' => 'POST',
          'callback' => [ $this, 'login_callback' ],
      ));
    });
  }

  public function login_callback() {
    $credentials = file_get_contents('php://input');
    $credentials = json_decode($credentials, true);

    if (isset($credentials['user_login']) && isset($credentials['user_password'])) {
      $user = wp_signon($credentials, is_ssl());
      if (is_wp_error($user)) {
        return new WP_REST_Response([
          'message' => $user->get_error_message(),
        ]);
      } else {
        wp_clear_auth_cookie();
        do_action('wp_login', $user->user_login, $user->ID);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        return new WP_REST_Response([
          'message' => __('User logged in successfully', 'nextpress'),
          'user_id' => $user->ID,
          'success' => true,
        ]);
      }
    }
  }
}

// Initialize the class
new NextPressUserFlow();