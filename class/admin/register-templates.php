<?php
/**
 * This class registers flexible content templates using Stoutlogic
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

use StoutLogic\AcfBuilder\FieldsBuilder;

class Register_Templates {
  /**
   * Helpers.
   */
  public $helpers;

  /**
   * ACF field builder.
   */
  public $field_builder;

  /**
   * ACF templates.
   */
  public $templates;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->field_builder = new Field_Builder();
    add_action( 'wp_loaded', [ $this, 'set_templates' ] );
    add_action( 'wp_loaded', [ $this, 'register_templates' ] );
  }

  /**
   * Set templates in class property.
   */
  public function set_templates() {
    $this->templates = $this->build_templates();
  }

  /**
   * Register general settings
   */
  public function register_templates() {
    // ACF register.
    if ( function_exists( 'acf_add_local_field_group' ) ) {
      acf_add_local_field_group( $this->templates->build() );
    }
  }

  /**
   * Builds Stoutlogic templates
   */
  private function build_templates() {
    // Create tabs and fields for each post type
    $templates = new FieldsBuilder('templates');

    // Add a Default tab first
    $templates
      ->addTab('Default')
      ->addFields( $this->create_default_template_fields() );

    // Add tabs for each post type.
    $exclude = [ 'attachment' ];
    $post_types = get_post_types(
      [ 'public' => true ],
      'objects', 
      'and'
    );
    foreach ( $post_types as $post_type ) {
      if ( in_array( $post_type->name, $exclude ) ) {
        continue;
      }
      $supports_editor = post_type_supports( $post_type->name, 'editor' );
      if ( ! $supports_editor ) {
        continue;
      }
      $templates
        ->addTab( ucfirst( $post_type->name ) )
        ->addFields( $this->create_post_type_template_fields( $post_type->name ) );
    }

    $templates
      ->setLocation('options_page', '==', 'templates')
      ->setGroupConfig('style', 'seamless');

    return $templates;
  }

  /**
   * Create default tab template fields
   */
  private function create_default_template_fields() {
    $template = new FieldsBuilder("default_templates");

    $template
      ->addFlexibleContent("default_before_content", [
        'label' => 'Before Content',
        'button_label' => 'Add Block',
        'layout' => 'block'
      ])
      ->addFlexibleContent("default_after_content", [
        'label' => 'After Content',
        'button_label' => 'Add Block',
        'layout' => 'block'
      ]);

    if ( ! empty( $this->helpers->languages ) ) {
      foreach ( $this->helpers->languages as $key => $lang ) {
        if ( $lang['default_language'] ) continue;
        $language = $lang['name'];
        $template
          ->addFlexibleContent("default_before_content_{$key}", [
            'label' => "Before Content ${language}",
            'button_label' => 'Add Block',
            'layout' => 'block'
          ])
          ->addFlexibleContent("default_after_content_{$key}", [
            'label' => "After Content ${language}",
            'button_label' => 'Add Block',
            'layout' => 'block'
          ]);
      }
    }

    // Add layouts to flexible content fields
    $block_layouts = $this->create_block_layouts();
    foreach ( $block_layouts as $name => $layout ) {
      $template
        ->getField( "default_before_content" )
        ->addLayout( $layout) ;
      $template
        ->getField( "default_after_content" )
        ->addLayout( $layout );

      if ( ! empty( $this->helpers->languages ) ) {
        foreach ( $this->helpers->languages as $key => $lang ) {
          if ( $lang['default_language'] ) continue;
          $language = $lang['name'];
          $template
            ->getField( "default_before_content_{$key}" )
            ->addLayout( $layout );
          $template
            ->getField( "default_after_content_{$key}" )
            ->addLayout( $layout );
        }
      }
    }

    return $template;
  }

  /**
   * Create post type template fields
   */
  private function create_post_type_template_fields( $post_type ) {
    $categories = get_categories(
      [
        'orderby' => 'name',
        'order'   => 'ASC',
        'hide_empty' => false,
      ]
    );
    $category_choices = [ '' => __( 'Select category', 'nextpress' ) ];
    foreach ( $categories as $category ) {
      $category_choices[ $category->term_id ] = $category->name;
    }

    $template = new FieldsBuilder("{$post_type}_templates");
    $template
      ->addRepeater("{$post_type}_content_templates", ['label' => 'Content Templates', 'layout' => 'block'])
      ->addSelect('category', [
        'label' => 'Category (optional)',
        'choices' => $category_choices,
        'required' => 0,
      ])
      ->addFlexibleContent('before_content', [
        'label' => 'Before Content',
        'button_label' => 'Add Block',
        'layout' => 'block'
      ])
      ->addFlexibleContent('after_content', [
        'label' => 'After Content',
        'button_label' => 'Add Block',
        'layout' => 'block'
      ])
      ->addFlexibleContent('sidebar_content', [
        'label' => 'Sidebar Content',
        'button_label' => 'Add Block',
        'layout' => 'block'
      ])
      ->endRepeater();

    if ( ! empty( $this->helpers->languages ) ) {
      foreach ( $this->helpers->languages as $key => $lang ) {
        if ( $lang['default_language'] ) continue;
        $language = $lang['name'];
        $template
          ->addRepeater("{$post_type}_content_templates_{$key}", [
            'label' => "Content Templates ${language}",
            'layout' => 'block'
          ])
          ->addSelect('category', [
            'label' => 'Category (optional)',
            'choices' => $category_choices,
            'required' => 0,
          ])
          ->addFlexibleContent('before_content', [
            'label' => 'Before Content',
            'button_label' => 'Add Block',
            'layout' => 'block'
          ])
          ->addFlexibleContent('after_content', [
            'label' => 'After Content',
            'button_label' => 'Add Block',
            'layout' => 'block'
          ])
          ->addFlexibleContent('sidebar_content', [
            'label' => 'Sidebar Content',
            'button_label' => 'Add Block',
            'layout' => 'block'
          ])
          ->endRepeater();
      }
    }

    // Add layouts to flexible content fields
    $block_layouts = $this->create_block_layouts();
    foreach ( $block_layouts as $name => $layout ) {
      $template
        ->getField("{$post_type}_content_templates")
        ->getField('before_content')
        ->addLayout($layout);
      $template
        ->getField("{$post_type}_content_templates")
        ->getField('after_content')
        ->addLayout($layout);
      $template
        ->getField("{$post_type}_content_templates")
        ->getField('sidebar_content')
        ->addLayout($layout);

      if ( ! empty( $this->helpers->languages ) ) {
        foreach ( $this->helpers->languages as $key => $lang ) {
          if ( $lang['default_language'] ) continue;
          $template
            ->getField("{$post_type}_content_templates_{$key}")
            ->getField('before_content')
            ->addLayout($layout);
          $template
            ->getField("{$post_type}_content_templates_{$key}")
            ->getField('after_content')
            ->addLayout($layout);
          $template
            ->getField("{$post_type}_content_templates_{$key}")
            ->getField('sidebar_content')
            ->addLayout($layout);
        }
      }
    }

    return $template;
  }

  /**
   * Creates block layouts
   */
  private function create_block_layouts() {
    $theme = get_field( 'blocks_theme', 'option' );
    $blocks = $this->helpers->fetch_blocks_from_api( $theme, 'templates' ) ?: [];
    $layouts = [];

    foreach ( $blocks as $block ) {
      if ( ! is_array( $block ) || ! isset( $block['id'] ) ) {
        continue;
      }
      
      $layout = new FieldsBuilder( $block['id'] );
      $fields = isset( $block['fields'] ) ? $block['fields'] : [];
      $layout = $this->field_builder->build( $fields, $layout );
      $layouts[ $block['id'] ] = apply_filters( 'nextpress_block_layouts', $layout );
    }

    return $layouts;
  }
}