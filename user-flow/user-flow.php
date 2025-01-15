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
      header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
      header('Access-Control-Allow-Headers: Content-Type, Authorization');
      return $response;
    });

    // Register routes.
    $this->register_routes();

    // Redirect to register page.
    add_action('login_init', function() {
      $is_login_redirect = get_field('enable_login_redirect', 'option');
      $register_page = get_field('register_page', 'option');
      if (
        $is_login_redirect && 
        $register_page && 
        strpos($_SERVER['REQUEST_URI'], 'wp-login.php?action=register') !== false
      ) {
        wp_redirect(get_permalink($register_page['id']));
        exit;
      }
    });

    // Redirect to login page.
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
      register_rest_route('nextpress', '/register', array(
          'methods' => 'POST',
          'callback' => [ $this, 'register_callback' ],
          'permission_callback' => '__return_true',
      ));
    });
  }

  public function login_callback(WP_REST_Request $request) {
    $referrer = sanitize_text_field($request->get_param('referrer'));
    $user_login = sanitize_text_field($request->get_param('user_login'));
    $user_password = sanitize_text_field($request->get_param('user_password'));
    $remember = filter_var($request->get_param('remember'), FILTER_SANITIZE_NUMBER_INT);

    if (!$user_login || !$user_password) {
      return new WP_REST_Response([
        'message' => __('All fields are required', 'nextpress'),
        'success' => false,
      ]);
    }

    $credentials = [
      'user_login' => $user_login,
      'user_password' => $user_password,
      'remember' => $remember,
    ];

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

  private function generate_jwt_token($user_id) {
    $user = get_userdata($user_id);
    $issued_at = time();
    $expiration_time = $issued_at + (DAY_IN_SECONDS * 7);
    $payload = [
      'iss' => get_bloginfo('url'),
      'iat' => $issued_at,
      'exp' => $expiration_time,
      'user_id' => $user_id,
      'blog_id' => get_current_blog_id(),
      'is_admin' => $user && in_array('administrator', $user->roles),
    ];

    $jwt = JWT::encode($payload, JWT_AUTH_SECRET_KEY, 'HS256');
    return $jwt;
  }

  public function forgot_password_callback(WP_REST_Request $request) {
    $email = sanitize_email($request->get_param('email'));

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

  public function reset_password_callback(WP_REST_Request $request) {
    $reset_key = sanitize_text_field($request->get_param('key'));
    $login = sanitize_text_field($request->get_param('login'));
    $password = sanitize_text_field($request->get_param('password'));

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

  public function register_callback(WP_REST_Request $request) {
    if (!get_option('users_can_register')) {
      return new WP_REST_Response([
        'message' => __('Registration is closed.', 'nextpress'),
        'success' => false,
      ]);
    }

    $username = sanitize_text_field($request->get_param('username'));
    $email = sanitize_email($request->get_param('email'));
    $password = sanitize_text_field($request->get_param('password'));

    if (!$username || !$email || !$password) {
      return new WP_REST_Response([
        'message' => __('All fields are required.', 'nextpress'),
        'success' => false,
      ]);
    }

    // Check if already exists.
    if (email_exists($email)) {
      return new WP_REST_Response([
        'message' => __('User is already registered.', 'nextpress'),
        'success' => false,
      ]);
    }

    // Check if email is whitelisted.
    $domain_whitelist = get_field('email_domain_whitelist', 'option');
    $domains = explode("\n", $domain_whitelist);
    if (!$domain_whitelist || !in_array(explode('@', $email)[1], $domains)) {
      return new WP_REST_Response([
        'message' => __('Email domain is not allowed.', 'nextpress'),
        'success' => false,
      ]);
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
      return new WP_REST_Response([
        'message' => $user->get_error_message(),
        'success' => false,
      ]);
    }

    // Send email verification.
    $blog_name = get_bloginfo('name');
    $login_page = get_field('login_page', 'option');
    $redirect_link = $login_page ? $login_page['wordpress_path'] : home_url('/login');
    $subject = "[$blog_name] Registration Successful";
    $message = "Hello, \n\nYou can now login by clicking on the following link: $redirect_link";
    wp_mail($email, $subject, $message);

    return new WP_REST_Response([
      'message' => __('User registered successfully, check your inbox for further instructions.', 'nextpress'),
      'success' => true,
      'redirect' => $redirect_link,
    ]);
  }
}

// Initialize the class
new NextPressUserFlow();