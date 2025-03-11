<?php

defined('ABSPATH') or die('You do not have access to this file');

/**
 * Class NextPressOptionsAPI
 * 
 * Handles WordPress options through REST API endpoints
 */
class NextPressOptionsAPI
{
  public function __construct()
  {
    $is_enabled = get_field('enable_options_api', 'option') ?? true;
    if ($is_enabled) {
      $this->init();
    }
  }

  public function init()
  {
    add_action('rest_pre_serve_request', function ($response) {
      header('Access-Control-Allow-Origin: ' . get_nextpress_frontend_url());
      header('Access-Control-Allow-Credentials: true');
      header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
      header('Access-Control-Allow-Headers: Content-Type, Authorization');
      return $response;
    });

    // Register routes
    $this->register_routes();
  }

  private function register_routes()
  {
    add_action('rest_api_init', function () {
      // Get a specific option
      register_rest_route('nextpress', '/get-option/(?P<option_name>[a-zA-Z0-9_-]+)', array(
        'methods' => 'GET',
        'callback' => [$this, 'get_option_callback'],
        'permission_callback' => '__return_true'
      ));

      // Get all options or options with a specific prefix
      register_rest_route('nextpress', '/get-options', array(
        'methods' => 'GET',
        'callback' => [$this, 'get_options_callback'],
        'permission_callback' => '__return_true'
      ));

      // Update or create an option
      register_rest_route('nextpress', '/update-option', array(
        'methods' => 'POST',
        'callback' => [$this, 'update_option_callback'],
        'permission_callback' => '__return_true'
      ));

      // Delete an option
      register_rest_route('nextpress', '/delete-option', array(
        'methods' => 'POST',
        'callback' => [$this, 'delete_option_callback'],
        'permission_callback' => '__return_true'
      ));
    });
  }

  /**
   * Check if user has permission to manage options
   */
  public function check_admin_permissions()
  {
    // By default, only administrators can manage options
    return current_user_can('manage_options');
  }

  /**
   * Get a specific WordPress option
   */
  public function get_option_callback(WP_REST_Request $request)
  {
    $option_name = sanitize_text_field($request->get_param('option_name'));

    if (!$option_name) {
      return new WP_REST_Response([
        'message' => __('Option name is required', 'nextpress'),
        'success' => false,
      ], 400);
    }

    $option_value = get_option($option_name);

    // If option doesn't exist
    if ($option_value === false) {
      return new WP_REST_Response([
        'message' => __('Option not found', 'nextpress'),
        'success' => false,
      ], 404);
    }

    return new WP_REST_Response([
      'optionValue' => $option_value,
      'success' => true,
    ]);
  }

  /**
   * Get all WordPress options or options with a specific prefix
   */
  public function get_options_callback(WP_REST_Request $request)
  {
    global $wpdb;

    $prefix = sanitize_text_field($request->get_param('prefix'));

    if ($prefix) {
      // Get options with specific prefix
      $query = $wpdb->prepare(
        "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
        $prefix . '%'
      );
    } else {
      // Get all options
      // Note: This could be a large dataset, so consider pagination for production use
      $query = "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} LIMIT 1000";
    }

    $results = $wpdb->get_results($query);

    if (!$results) {
      return new WP_REST_Response([
        'message' => __('No options found', 'nextpress'),
        'success' => false,
      ], 404);
    }

    $options = [];
    foreach ($results as $result) {
      $options[] = [
        'id' => (int)$result->option_id,
        'optionName' => $result->option_name,
        'optionValue' => maybe_unserialize($result->option_value),
        'autoload' => $result->autoload
      ];
    }

    return new WP_REST_Response($options);
  }

  /**
   * Update or create a WordPress option
   */
  public function update_option_callback(WP_REST_Request $request)
  {
    $option_name = sanitize_text_field($request->get_param('optionName'));
    $option_value = $request->get_param('optionValue');
    $autoload = sanitize_text_field($request->get_param('autoload') ?? 'yes');

    if (!$option_name || !isset($option_value)) {
      return new WP_REST_Response([
        'message' => __('Option name and value are required', 'nextpress'),
        'success' => false,
      ], 400);
    }

    // Validate autoload value
    if ($autoload !== 'yes' && $autoload !== 'no') {
      $autoload = 'yes';
    }

    $result = false;

    // Check if option exists
    $exists = get_option($option_name, null) !== null;

    if ($exists) {
      // Update existing option
      update_option($option_name, $option_value, $autoload);
      $result = true;
    } else {
      // Add new option
      $result = add_option($option_name, $option_value, '', $autoload);
    }

    if ($result) {
      return new WP_REST_Response([
        'message' => __('Option updated successfully', 'nextpress'),
        'success' => true,
      ]);
    }

    return new WP_REST_Response([
      'message' => __('Error updating option', 'nextpress'),
      'success' => false,
    ], 500);
  }

  /**
   * Delete a WordPress option
   */
  public function delete_option_callback(WP_REST_Request $request)
  {
    $option_name = sanitize_text_field($request->get_param('optionName'));

    if (!$option_name) {
      return new WP_REST_Response([
        'message' => __('Option name is required', 'nextpress'),
        'success' => false,
      ], 400);
    }

    $result = delete_option($option_name);

    if ($result) {
      return new WP_REST_Response([
        'message' => __('Option deleted successfully', 'nextpress'),
        'success' => true,
      ]);
    }

    return new WP_REST_Response([
      'message' => __('Error deleting option or option does not exist', 'nextpress'),
      'success' => false,
    ], 404);
  }
}

// Initialize the class
new NextPressOptionsAPI();
