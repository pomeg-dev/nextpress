<?php
/**
 * Nextpress settings router class
 * Adds the /settings rest route for fetching all wp settings
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

class API_Settings {
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

    // Register main settings route.
    add_action('rest_api_init', [ $this, 'register_routes' ] );

    // Add ACF/Yoast filters.
    add_filter( 'nextpress_settings', [ $this, 'add_acf_to_nextpress_settings' ] );
    add_filter( 'nextpress_settings', [ $this, 'add_yoast_base_to_nextpress_settings'] );

    // Format default template content.
    add_filter( 'nextpress_settings', [ $this, 'format_default_template'] );

    // Add revalidators
		add_action( 'acf/options_page/save', [ $this, 'revalidate_settings' ], 10, 2 );
		add_action( 'save_post', [ $this, 'revalidate_menu_settings' ] );

		// Revalidate when safe-list WP options or Yoast settings change.
		add_action( 'update_option', [ $this, 'revalidate_on_option_update' ], 10, 3 );
  }

  public function register_routes() {
    register_rest_route(
      'nextpress',
      '/settings',
      [
        'methods' => 'GET',
        'callback' => [ $this, 'get_settings' ],
        'permission_callback' => '__return_true',
        'args' => [
          'keys' => [
            'description' => 'Comma-separated list of specific setting keys to return',
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
          ],
        ],
      ]
    );
  }

  public function get_settings( $data ) {
    if ( is_multisite() ) {
      switch_to_blog( get_current_blog_id() );
    }

    // Cache settings for 1 day
    $cache_key = 'nextpress_settings_' . get_current_blog_id();
    $all_settings = $this->helpers->cache_get( $cache_key, 'nextpress_settings' );

    if ( false === $all_settings ) {
      try {
        $all_settings = apply_filters( "nextpress_settings", $this->load_options_without_transients() );
        $this->helpers->cache_set( $cache_key, $all_settings, 'nextpress_settings', DAY_IN_SECONDS );
      } catch ( Exception $e ) {
        error_log( 'Nextpress settings error: ' . $e->getMessage() );
        return new \WP_Error( 'settings_error', 'Failed to load settings', array( 'status' => 500 ) );
      }
    }

    // Add blog page slug.
    $page_for_posts = get_option( 'page_for_posts' );
    $blog_page = get_post( $page_for_posts );
    if ( $blog_page ) {
      $all_settings['page_for_posts_slug'] = $blog_page->post_name;
    }

    // Add polylang.
    if ( ! empty( $this->helpers->languages ) ) {
      $all_settings['languages'] = $this->helpers->languages;
      $all_settings['default_language'] = $this->helpers->default_language;
    }

    // Filter specific keys if requested
    if ( isset( $data['keys'] ) && ! empty( $data['keys'] ) ) {
      $requested_keys = array_map( 'trim', explode( ',', $data['keys'] ) );
      $all_settings = array_intersect_key( $all_settings, array_flip( $requested_keys ) );
    }

    if ( is_multisite() ) {
      restore_current_blog();
    }

    return $all_settings;
  }

  /**
   * Filterable allowlist of WP core option keys safe for public exposure.
   *
   * SECURITY: Never replace this with a raw SELECT on wp_options — that leaks
   * API keys, SMTP credentials, and secret keys to unauthenticated visitors.
   * Add keys here only if they are safe to expose publicly.
   * Use the 'nextpress_safe_option_keys' filter to extend from other plugins.
   */
  private function get_safe_option_keys() {
    return apply_filters( 'nextpress_safe_option_keys', [
      'blogname',
      'blogdescription',
      'blog_public',
      'siteurl',
      'home',
      'page_on_front',
      'page_for_posts',
      'posts_per_page',
      'date_format',
      'time_format',
      'timezone_string',
      'start_of_week',
      'WPLANG',
      'show_on_front',
      'google_tag_manager_enabled',
      'google_tag_manager_id',
      'page_for_posts_slug',
      'frontend_url',
      'before_content',
      'after_content',
      'page_404',
      'favicon',
    ] );
  }

  /**
   * Load only the safe allowlist of WP core options.
   */
  private function load_options_without_transients() {
    $options = [];
    foreach ( $this->get_safe_option_keys() as $key ) {
      $options[ $key ] = get_option( $key );
    }
    return $options;
  }

  public function add_acf_to_nextpress_settings( $settings ) {
    if ( ! function_exists( 'get_fields' ) ) return $settings;
    
    try {
      $cache_key = 'nextpress_acf_options_' . get_current_blog_id();
      $options = $this->helpers->cache_get( $cache_key, 'nextpress_settings' );

      if ( false === $options ) {
        $options = get_fields( 'options' );
        if ( $options ) {
          $this->helpers->cache_set( $cache_key, $options, 'nextpress_settings', DAY_IN_SECONDS );
        }
      }
      
      if ( $options ) {
        $settings = array_merge( $settings, $options );
      }
    } catch ( Exception $e ) {
      error_log( 'Nextpress ACF options error: ' . $e->getMessage() );
    }
    
    return $settings;
  }

  public function add_yoast_base_to_nextpress_settings( $settings ) {
    if ( ! class_exists( 'WPSEO_Options' ) ) return $settings;
    $yoast_settings = \WPSEO_Options::get_all();
    $settings = array_merge( $settings, $yoast_settings );
    return $settings;
  }

  public function format_default_template( $settings ) {
    if (
      isset( $settings['default_after_content'] ) &&
      is_array( $settings['default_after_content'] )
    ) {
      $settings['after_content'] = $this->formatter->format_flexible_content( $settings['default_after_content'] );
      unset( $settings['default_after_content'] );
    }

    if (
      isset( $settings['default_before_content'] ) &&
      is_array( $settings['default_before_content'] )
    ) {
      $settings['before_content'] = $this->formatter->format_flexible_content( $settings['default_before_content'] );
      unset( $settings['default_before_content'] );
    }

    // Add languages for Polylang support.
    if ( ! empty( $this->helpers->languages ) ) {
      foreach ( $this->helpers->languages as $lang => $details ) {
        $default_before_key = "default_before_content_{$lang}";
        $np_before_key = "before_content_{$lang}";
        $default_after_key = "default_after_content_{$lang}";
        $np_after_key = "after_content_{$lang}";

        if (
          isset( $settings[ $default_before_key ] ) &&
          is_array( $settings[ $default_before_key ] )
        ) {
          $settings[ $np_before_key ] = $this->formatter->format_flexible_content( $settings[ $default_before_key ] );
          unset( $settings[ $default_before_key ] );
        }

        if (
          isset( $settings[ $default_after_key ] ) &&
          is_array( $settings[ $default_after_key ] )
        ) {
          $settings[ $np_after_key ] = $this->formatter->format_flexible_content( $settings[ $default_after_key ] );
          unset( $settings[ $default_after_key ] );
        }
      }
    }

    return $settings;
  }

  /**
	 * Revalidate settings
	 */
	public function revalidate_settings( $post_id, $menu_slug ) {
		if ( ! $post_id && ! $menu_slug ) return;
		if ( $post_id !== 'options' ) return;

		// Flush wp cache.
		$cache_key = 'nextpress_settings_' . get_current_blog_id();
    $acf_cache_key = 'nextpress_acf_options_' . get_current_blog_id();
		$this->helpers->cache_delete( $cache_key, 'nextpress_settings' );
		$this->helpers->cache_delete( $acf_cache_key, 'nextpress_settings' );

		// Revalidate nextjs.
		if ( $menu_slug === 'templates' ) {
			$this->helpers->revalidate_fetch_route( 'before_content' );
			$this->helpers->revalidate_fetch_route( 'after_content' );

      // Revalidate language templates.
      if ( function_exists( 'pll_languages_list' ) ) {
        $languages = pll_languages_list();
        foreach ( $languages as $lang ) {
          $this->helpers->revalidate_fetch_route( "before_content_$lang" );
			    $this->helpers->revalidate_fetch_route( "after_content_$lang" );
        }
      }
		} else {
			$this->helpers->revalidate_fetch_route( 'settings' );
		}
	}

  /**
	 * Revalidate menu settings
	 */
	public function revalidate_menu_settings( $post_id ) {
    if ( $this->helpers->should_skip_save( $post_id ) ) return;

    $post = get_post( $post_id );
    if ( $post->post_type !== "nav_menu_item" ) return;

    $this->helpers->revalidate_fetch_route( 'before_content' );
    $this->helpers->revalidate_fetch_route( 'after_content' );
    
    // Revalidate language templates.
    if ( function_exists( 'pll_languages_list' ) ) {
      $languages = pll_languages_list();
      foreach ( $languages as $lang ) {
        $this->helpers->revalidate_fetch_route( "before_content_$lang" );
        $this->helpers->revalidate_fetch_route( "after_content_$lang" );
      }
    }
	}

	/**
	 * Revalidate when a WP option in our safe list or a Yoast setting is updated.
	 * Debounced via static flag — settings pages save many options per request.
	 */
	public function revalidate_on_option_update( $option, $old_value, $value ) {
		if ( $old_value === $value ) return;

		static $already_revalidated = false;
		if ( $already_revalidated ) return;

		$is_safe_option = in_array( $option, $this->get_safe_option_keys(), true );
		$is_yoast_option = strpos( $option, 'wpseo' ) === 0;

		if ( ! $is_safe_option && ! $is_yoast_option ) return;

		$already_revalidated = true;

		$cache_key = 'nextpress_settings_' . get_current_blog_id();
		$this->helpers->cache_delete( $cache_key, 'nextpress_settings' );
		$this->helpers->revalidate_fetch_route( 'settings' );
	}
}