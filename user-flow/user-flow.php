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
      header('Access-Control-Allow-Origin: ' . get_nextpress_frontend_url());
      header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Headers: Content-Type, Authorization');
      return $response;
    });

    // Register routes.
    $this->register_routes();

    // Redierct to login page.
    add_action('login_init', function() {
      $is_login_redirect = get_field('enable_login_redirect', 'option');
      $login_page = get_field('login_page', 'option');
      if (
        $is_login_redirect && 
        $login_page && 
        strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false
      ) {
        wp_redirect(get_permalink($login_page['id']));
        exit;
      }
    });
  }

  private function register_routes() {
    add_action('rest_api_init', function () {
      register_rest_route('nextpress', '/login', array(
          'methods' => 'POST',
          'callback' => [ $this, 'login_callback' ],
      ));
      register_rest_route('nextpress', '/request-reset', array(
          'methods' => 'POST',
          'callback' => [ $this, 'forgot_password_callback' ],
      ));
      register_rest_route('nextpress', '/reset-password', array(
          'methods' => 'POST',
          'callback' => [ $this, 'reset_password_callback' ],
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

  public function forgot_password_callback() {
    $email = file_get_contents('php://input');
    $email = json_decode($email, true);
    $email = $email && isset($email['email']) ? $email['email'] : '';

    if (!$email) {
      return new WP_REST_Response([
        'message' => __('Email is required', 'nextpress'),
        'success' => false,
      ]);
    }

    $user = get_user_by('email', $email);
    if (!$user) {
      $user = get_user_by('login', $email);
    }

    if (!$user) {
      return new WP_REST_Response([
        'message' => __('User not found', 'nextpress'),
        'success' => false,
      ]);
    }

    $blog_name = get_bloginfo('name');
    $reset_key = get_password_reset_key($user);
    $login_page = get_field('login_page', 'option');
    $reset_url = $login_page ? 
      $login_page['wordpress_path'] . '?action=rp&key=' . $reset_key . '&login=' . rawurlencode($user->user_login) : 
      home_url("/wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($user->user_login));

    $subject = "[$blog_name] Password Reset Request";
    $message = "Hello, \n\nYou can reset your password by clicking on the following link: $reset_url";
    wp_mail($user->user_email, $subject, $message);

    return new WP_REST_Response([
      'message' => __('Reset link sent, please check your email', 'nextpress'),
      'success' => true,
    ]);
  }

  public function reset_password_callback() {
    $data = file_get_contents('php://input');
    $data = json_decode($data, true);
    $reset_key = $data['key'];
    $login = $data['login'];
    $password = $data['password'];

    if (!$reset_key || !$login || !$password) {
      return new WP_REST_Response([
        'message' => __('Reset link invalid, please try again', 'nextpress'),
        'success' => false,
      ]);
    }

    $user = check_password_reset_key($reset_key, $login);
    if (is_wp_error($user)) {
      return new WP_REST_Response([
        'message' => $user->get_error_message(),
        'success' => false,
      ]);
    }

    reset_password($user, $password);
    return new WP_REST_Response([
      'message' => __('Password reset successfully', 'nextpress'),
      'success' => true,
    ]);
  }
}

// Initialize the class
new NextPressUserFlow();