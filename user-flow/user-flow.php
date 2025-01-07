<?php

defined('ABSPATH') or die('You do not have access to this file');

use Firebase\JWT\JWT;

class NextPressUserFlow {
  public function __construct() {
    $is_enabled = get_field('enable_user_flow', 'option');
    if ($is_enabled) {
      $this->init();
    }
  }

  public function init() {
    add_action('rest_pre_serve_request', function($response) {
      // header('Access-Control-Allow-Origin: ' . get_nextpress_frontend_url());
      header('Access-Control-Allow-Origin: *');
      header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Headers: Content-Type, Authorization');
      return $response;
    });

    // Register routes.
    $this->register_routes();

    // redierct to login page
    // enable_login_redirect
    // login_page - register_page
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
    $referrer = '';
    if (isset($credentials['referrer'])) {
      $referrer = $credentials['referrer'];
      unset($credentials['referrer']);
    }

    if (isset($credentials['user_login']) && isset($credentials['user_password'])) {
      $user = wp_signon($credentials, is_ssl());

      if (is_wp_error($user)) {
        return new WP_REST_Response([
          'message' => $user->get_error_message(),
          'success' => false,
        ]);
      }
      
      // Set WP cookies.
      wp_clear_auth_cookie();
      wp_set_current_user($user->ID);
      wp_set_auth_cookie($user->ID, true);

      // Generate a new JWT token.
      $jwt_token = $this->generate_jwt_token($user->ID);

      $response = [
        'message' => __('User logged in successfully', 'nextpress'),
        'jwt_token' => $jwt_token,
        'success' => true,
      ];
      if ($referrer) {
        $response['referrer'] = $referrer;
      }
      return new WP_REST_Response($response);
    }
  }

  private function generate_jwt_token($user_id) {
    $issued_at = time();
    $expiration_time = $issued_at + (DAY_IN_SECONDS * 7);
    $payload = [
      'iss' => get_bloginfo('url'),
      'iat' => $issued_at,
      'exp' => $expiration_time,
      'user_id' => $user_id,
      'blog_id' => get_current_blog_id(),
    ];

    $jwt = JWT::encode($payload, JWT_AUTH_SECRET_KEY, 'HS256');
    return $jwt;
  }
}

// Initialize the class
new NextPressUserFlow();