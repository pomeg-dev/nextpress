<?php
/**
 * This class registers admin settings for the plugin using Stoutlogic
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

use StoutLogic\AcfBuilder\FieldsBuilder;

class Register_Settings {
  /**
   * Helpers.
   */
  public $helpers;

  /**
   * ACF settings.
   */
  public $settings;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->settings = $this->build_settings();
    add_action( 'acf/init', [ $this, 'register_general_settings' ] );
  }

  /**
   * Register general settings
   */
  public function register_general_settings() {
    // Allow additional of extra tabs/settings via filter.
    apply_filters( 'nextpress_general_settings', $this->settings );

    // ACF register.
    acf_add_local_field_group( $this->settings->build() );
  }

  /**
   * Builds Stoutlogic settings vars
   */
  private function build_settings() {
    $all_blocks = $this->helpers->fetch_blocks_from_api( null, 'settings' );
    $blocks = new FieldsBuilder('blocks');
    $blocks
      ->addTab("blocks")
      ->addSelect("blocks_theme", [
        'choices' => ! empty( $all_blocks ) 
          ? array_keys( $all_blocks ) 
          : [],
        'multiple' => 1,
        'ui' => 1,
      ])
      ->addUrl("frontend_url", [
        'label' => 'Frontend URL',
        'instructions' => 'The URL to the Next.js deployed domain, leave blank for local development.',
        'required' => 0,
      ]);

    $google_tag_manager = new FieldsBuilder('google_tag_manager');
    $google_tag_manager
      ->addTab("google_tag_manager")
      ->addTrueFalse("google_tag_manager_enabled", [
        'default_value' => 1,
      ])
      ->addText("google_tag_manager_id", [
        'default_value' => 'GTM-XXXXXXX',
        'conditional_logic' => [
          [
            [
              'field' => 'google_tag_manager_enabled',
              'operator' => '==',
              'value' => '1',
            ]
          ]
        ],
      ]);

    $favicon = new FieldsBuilder('favicon');
    $favicon
      ->addTab("favicon")
      ->addImage("favicon");

    $page_404 = new FieldsBuilder('page_404');
    $page_404
      ->addTab("404")
      ->addPostObject('page_404', [
        'label' => 'Show page',
        'instructions' => 'This will show content from this page, with the default header and footer',
        'conditional_logic' => [
          [
            [
              'field' => 'enable_page_404',
              'operator' => '==',
              'value' => '1',
            ],
          ],
        ],
        'post_type' => ['page'],
        'ui' => 1,
      ]);

    $coming_soon = new FieldsBuilder('coming_soon');
    $coming_soon
      ->addTab("coming_soon")
      ->addTrueFalse("enable_coming_soon")
      ->addPostObject('coming_soon_page', [
        'label' => 'Show page',
        'instructions' => 'This will show content from this page, without any header and footer',
        'conditional_logic' => [
          [
            [
              'field' => 'enable_coming_soon',
              'operator' => '==',
              'value' => '1',
            ],
          ],
        ],
        'post_type' => ['page'],
        'ui' => 1,
      ]);

    $settings = new FieldsBuilder('General-settings');
    $settings
      ->addFields($blocks)
      ->addFields($google_tag_manager)
      ->addFields($favicon)
      ->addFields($page_404)
      ->addFields($coming_soon)
      ->setLocation('options_page', '==', 'acf-options-settings')
      ->setGroupConfig('style', 'seamless');
    
    return $settings;
  }
}