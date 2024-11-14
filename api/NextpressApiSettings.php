<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextpressApiSettings
{
    public function __construct()
    {
        $this->_init();
    }

    public static function _init()
    {
        add_action('rest_api_init', array('NextpressApiSettings', 'register_routes'));
        add_filter('ng_settings', array('NextpressApiSettings', 'add_acf_to_ng_settings'));
        add_filter('ng_settings', array('NextpressApiSettings','add_yoast_base_settings_to_ng_settings'));
    }

    public static function register_routes()
    {
        register_rest_route('nextpress', '/settings', array(
            'methods' => 'GET',
            'callback' => array('NextpressApiSettings', 'get_settings'),
        ));
    }

    public static function get_settings($data)
    {
      if (is_multisite()) {
        switch_to_blog(get_current_blog_id());
      }
      
      $all_settings = apply_filters("ng_settings", wp_load_alloptions());

      if (is_multisite()) {
        restore_current_blog();
      }
      return $all_settings;
    }

    public static function add_acf_to_ng_settings($settings)
    {
        if (!function_exists('get_fields')) return;
        $settings = array_merge($settings, get_fields('options'));
        return $settings;
    }

    public static function add_yoast_base_settings_to_ng_settings($settings)
    {
        if (!class_exists('WPSEO_Options')) return $settings;
        $yoast_settings = WPSEO_Options::get_all();
        $settings = array_merge($settings, $yoast_settings);
        return $settings;
    }
}
