<?php
/**
 * This is an extension of Nextpress allowing a custom Next.js/WordPress login/register user flow.
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

use StoutLogic\AcfBuilder\FieldsBuilder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class User_Flow {
  /**
   * Helpers.
   */
  public $helpers;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;

    // Includes.
    if ( ! class_exists( 'Firebase\JWT\JWT' ) ) {
      require_once NEXTPRESS_PATH . '/includes/php-jwt/src/JWT.php';
    }
    if ( ! class_exists( 'Firebase\JWT\Key' ) ) {
      require_once NEXTPRESS_PATH . '/includes/php-jwt/src/Key.php';
    }

    // Settings.
    add_filter( 'nextpress_general_settings', [ $this, 'add_user_flow_fields' ], 10, 1 );

    // Init.
    $is_enabled = get_field( 'enable_user_flow', 'option' );
    if ( $is_enabled ) {
      $this->init();
    }
  }

  /**
   * Add general settings
   */
  public function add_user_flow_fields( $settings ) {
    $user_flow = new FieldsBuilder('user_flow');
    $user_flow
      ->addTab("user_flow")
      ->addTrueFalse("enable_user_flow")
      ->addTrueFalse("enable_login_redirect", [
        'instructions' => 'If enabled, users will be redirected to the login page if not logged in',
        'ui' => 1,
        'conditional_logic' => [
          [
            [
              'field' => 'enable_user_flow',
              'operator' => '==',
              'value' => '1',
            ],
          ],
        ],
      ])
      ->addTextarea("email_domain_whitelist", [
        'instructions' => 'Only below domains will be allowed to register, one domain per line',
        'default_value' => 'pomegranate.co.uk',
        'conditional_logic' => [
          [
            [
              'field' => 'enable_user_flow',
              'operator' => '==',
              'value' => '1',
            ],
          ],
        ],
      ])
      ->addPostObject('login_page', [
        'post_type' => ['page'],
        'ui' => 1,
        'conditional_logic' => [
          [
            [
              'field' => 'enable_user_flow',
              'operator' => '==',
              'value' => '1',
            ],
          ],
        ],
      ])
      ->addPostObject('register_page', [
        'post_type' => ['page'],
        'ui' => 1,
        'conditional_logic' => [
          [
            [
              'field' => 'enable_user_flow',
              'operator' => '==',
              'value' => '1',
            ],
          ],
        ],
      ]);

    $settings->addFields( $user_flow );
    return $settings;
  }

  /**
   * Initialise user flow.
   */
  public function init() {
    add_filter('rest_pre_serve_request', function( $response ) {
      header( 'Access-Control-Allow-Origin: ' . $this->helpers->frontend_url );
      header( 'Access-Control-Allow-Credentials: true' );
      header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
      header( 'Access-Control-Allow-Headers: Content-Type, Authorization' );
      return $response;
    });

    // Register main posts route.
    add_action('rest_api_init', [ $this, 'register_routes' ] );

    // Login redirect hook.
    add_action('login_init', [$this, 'redirect_login']);
  }

  /**
   * Register API routes
   */
  public function register_routes() {
    register_rest_route(
      'nextpress', 
      '/logout',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'logout_callback' ],
        'permission_callback' => '__return_true'
      ]
    );

    register_rest_route(
      'nextpress', 
      '/login',
      [
        'methods' => 'POST',
        'callback' => [ $this, 'login_callback' ],
        'permission_callback' => '__return_true'
      ]
    );

    register_rest_route(
      'nextpress', 
      '/request-reset',
      [
        'methods' => 'POST',
        'callback' => [ $this, 'forgot_password_callback' ],
        'permission_callback' => '__return_true'
      ]
    );

    register_rest_route(
      'nextpress', 
      '/reset-password',
      [
        'methods' => 'POST',
        'callback' => [ $this, 'reset_password_callback' ],
        'permission_callback' => '__return_true'
      ]
    );

    register_rest_route(
      'nextpress', 
      '/register',
      [
        'methods' => 'POST',
        'callback' => [ $this, 'register_callback' ],
        'permission_callback' => '__return_true',
      ]
    );
  }

  public function logout_callback() {
    wp_logout();
    wp_destroy_current_session();
    wp_clear_auth_cookie();
    wp_set_current_user(0);
    $response = [
      'message' => __('User logged out successfully', 'nextpress'),
      'success' => true,
    ];
    return new \WP_REST_Response( $response );
  }

  public function login_callback( \WP_REST_Request $request ) {
    $user_login = sanitize_text_field($request->get_param('user_login'));
    $user_password = sanitize_text_field($request->get_param('user_password'));
    $remember = filter_var($request->get_param('remember'), FILTER_SANITIZE_NUMBER_INT);

    if (!$user_login || !$user_password) {
      return new \WP_REST_Response([
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
      return new \WP_REST_Response([
        'message' => $user->get_error_message(),
        'success' => false,
      ]);
    }

    // Generate a new JWT token.
    $jwt_token = $this->generate_jwt_token($user->ID);

    $response = [
      'message' => __('User logged in successfully', 'nextpress'),
      'jwt_token' => $jwt_token,
      'success' => true,
      'user_id' => $user->ID,
      'user_display_name' => $user->display_name,
      'user_email' => $user->user_email,
      'blog_id' => get_current_blog_id(),
      'blog_url' => get_bloginfo('url'),
      'is_admin' => $user && in_array('administrator', $user->roles),
    ];
    return new \WP_REST_Response($response);
  }

  private function generate_jwt_token($user_id) {
    $issued_at = time();
    $expiration_time = $issued_at + (DAY_IN_SECONDS * 7);
    $payload = [
      'iss' => get_bloginfo('url'),
      'iat' => $issued_at,
      'exp' => $expiration_time,
      'user_id' => $user_id,
    ];

    $jwt = JWT::encode($payload, JWT_AUTH_SECRET_KEY, 'HS256');
    return $jwt;
  }

  public function forgot_password_callback( \WP_REST_Request $request ) {
    $email = sanitize_email($request->get_param('email'));

    if (!$email) {
      return new \WP_REST_Response([
        'message' => __('Email is required', 'nextpress'),
        'success' => false,
      ]);
    }

    $user = get_user_by('email', $email);
    if (!$user) {
      $user = get_user_by('login', $email);
    }

    if (!$user) {
      return new \WP_REST_Response([
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

    return new \WP_REST_Response([
      'message' => __('Reset link sent, please check your email', 'nextpress'),
      'success' => true,
    ]);
  }

  public function reset_password_callback( \WP_REST_Request $request ) {
    $reset_key = sanitize_text_field($request->get_param('key'));
    $login = sanitize_text_field($request->get_param('login'));
    $password = sanitize_text_field($request->get_param('password'));

    if (!$reset_key || !$login || !$password) {
      return new \WP_REST_Response([
        'message' => __('Reset link invalid, please try again', 'nextpress'),
        'success' => false,
      ]);
    }

    $user = check_password_reset_key($reset_key, $login);
    if (is_wp_error($user)) {
      return new \WP_REST_Response([
        'message' => $user->get_error_message(),
        'success' => false,
      ]);
    }

    reset_password($user, $password);
    return new \WP_REST_Response([
      'message' => __('Password reset successfully', 'nextpress'),
      'success' => true,
    ]);
  }

  public function register_callback( \WP_REST_Request $request ) {
    if (!get_option('users_can_register')) {
      return new \WP_REST_Response([
        'message' => __('Registration is closed.', 'nextpress'),
        'success' => false,
      ]);
    }

    $username = sanitize_text_field($request->get_param('username'));
    $email = sanitize_email($request->get_param('email'));
    $password = sanitize_text_field($request->get_param('password'));

    if (!$username || !$email || !$password) {
      return new \WP_REST_Response([
        'message' => __('All fields are required.', 'nextpress'),
        'success' => false,
      ]);
    }

    // Check if already exists.
    if (email_exists($email)) {
      return new \WP_REST_Response([
        'message' => __('User is already registered.', 'nextpress'),
        'success' => false,
      ]);
    }

    // Check if email is whitelisted.
    $domain_whitelist = get_field('email_domain_whitelist', 'option');
    $domains = explode("\n", $domain_whitelist);
    if (!$domain_whitelist || !in_array(explode('@', $email)[1], $domains)) {
      return new \WP_REST_Response([
        'message' => __('Email domain is not allowed.', 'nextpress'),
        'success' => false,
      ]);
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
      return new \WP_REST_Response([
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

    return new \WP_REST_Response([
      'message' => __('User registered successfully, check your inbox for further instructions.', 'nextpress'),
      'success' => true,
      'redirect' => $redirect_link,
    ]);
  }

  /**
   * Redirect login page.
   */
  public function redirect_login() {
    // Function to auth JWT and redirect to admin from nextjs.
    $redirect_to = isset( $_GET['redirect_to'] ) ? $_GET['redirect_to'] : '';
    if ( strpos( $redirect_to, 'token=' ) !== false) {
      $url_parts = parse_url( $redirect_to );
      parse_str( $url_parts['query'], $query_params );
      $redirect_to = remove_query_arg( 'token', $redirect_to );
      $token = $query_params['token'];

      if ( ! $token ) {
        return;
      }

      try {
        $decoded_token = JWT::decode( $token, new Key( JWT_AUTH_SECRET_KEY, 'HS256' ) );
        if ( isset( $decoded_token->user_id ) ) {
          wp_set_auth_cookie( $decoded_token->user_id );
          wp_redirect( $redirect_to );
          exit;
        }
      } catch ( Exception $e ) {
        error_log( 'Token verification failed: ' . $e->getMessage() );
        wp_redirect( home_url( '/login' ) );
        exit;
      }
    }
  }
}