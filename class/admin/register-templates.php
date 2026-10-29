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
    add_action( 'wp_loaded', [ $this, 'setup_templates' ] );
    add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
  }

  /**
   * Enqueue the "copy/move flexible-content layouts between sections" UI,
   * scoped to the Templates options page.
   */
  public function enqueue_assets() {
    $page = isset( $_GET['page'] ) ? $_GET['page'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    // The Templates landing page ("templates") plus every per-post-type
    // sub-page ("templates-page", "templates-post", …).
    $is_templates_page = ( $page === 'templates' || strpos( $page, 'templates-' ) === 0 );
    if ( ! $is_templates_page ) {
      return;
    }

    wp_enqueue_script(
      'nextpress-acf-flexible-copy',
      NEXTPRESS_URI . '/assets/js/acf-flexible-copy.js',
      [ 'jquery', 'acf-input' ],
      filemtime( NEXTPRESS_PATH . '/assets/js/acf-flexible-copy.js' ),
      true
    );
    wp_enqueue_style(
      'nextpress-acf-flexible-copy',
      NEXTPRESS_URI . '/assets/css/acf-flexible-copy.css',
      [],
      filemtime( NEXTPRESS_PATH . '/assets/css/acf-flexible-copy.css' )
    );
  }

  /**
   * Post types that get a Templates sub-page / field group. Static so the
   * admin-page registrar ([Register_Pages]) can register matching sub-pages
   * without duplicating the selection logic.
   *
   * @return array<string,\WP_Post_Type> keyed by post-type name
   */
  public static function template_post_types() {
    $exclude = [ 'attachment' ];
    $out = [];
    foreach ( get_post_types( [ 'public' => true ], 'objects', 'and' ) as $post_type ) {
      if ( in_array( $post_type->name, $exclude, true ) ) {
        continue;
      }
      if ( ! post_type_supports( $post_type->name, 'editor' ) ) {
        continue;
      }
      $out[ $post_type->name ] = $post_type;
    }
    return $out;
  }

  /**
   * Register every templates field group.
   *
   * Historically this was one giant group (all post types + all languages + all
   * block layouts) rendered on a single tabbed page. That group is now split
   * into one group per section — "Default" plus one per post type — each on its
   * own options sub-page, so the ACF admin UI only renders a fraction of the
   * layouts at a time (far lighter to load and to save). The field *names and
   * keys are unchanged* (every builder is still named `templates`, so keys stay
   * `field_templates_*`), so `get_field( …, 'option' )` reads — and the frontend
   * contract — are byte-identical.
   */
  public function setup_templates() {
    if ( ! function_exists( 'acf_add_local_field_group' ) ) {
      return;
    }
    foreach ( $this->get_template_groups() as $group ) {
      acf_add_local_field_group( $group );
    }
  }

  /**
   * Build (or fetch from cache) the array of ACF field-group configs.
   *
   * Building the groups means constructing thousands of StoutLogic field objects
   * and running the key-namespacing transforms on every request. The result only
   * changes when the blocks, languages or post types change, so it is memoised in
   * a (non-autoloaded) cache keyed by a signature of exactly those inputs plus
   * this file's mtime — any relevant change misses the cache and rebuilds.
   */
  private function get_template_groups() {
    $theme  = get_field( 'blocks_theme', 'option' );
    $blocks = $this->helpers->get_blocks( $theme ) ?: [];
    $langs  = ! empty( $this->helpers->languages ) ? array_keys( $this->helpers->languages ) : [];
    $pts    = array_keys( self::template_post_types() );

    $signature = md5( serialize( [ $blocks, $langs, $pts, filemtime( __FILE__ ) ] ) );
    $cache_key = 'tmpl_groups_' . $signature;

    $cached = $this->helpers->cache_get( $cache_key, 'nextpress_templates' );
    if ( is_array( $cached ) && ! empty( $cached ) ) {
      return $cached;
    }

    $groups = [];
    $groups[] = $this->build_default_group( $blocks );
    foreach ( self::template_post_types() as $name => $post_type ) {
      $groups[] = $this->build_post_type_group( $name, $blocks );
    }

    $this->helpers->cache_set( $cache_key, $groups, 'nextpress_templates', DAY_IN_SECONDS );
    return $groups;
  }

  /**
   * "Default" (global) templates group → options page `templates`.
   */
  private function build_default_group( $blocks ) {
    $group = new FieldsBuilder( 'templates' );
    $group
      ->addFields( $this->create_default_template_fields( $blocks ) )
      ->setLocation( 'options_page', '==', 'templates' )
      ->setGroupConfig( 'key', 'templates_default' )
      ->setGroupConfig( 'style', 'seamless' );
    return $group->build();
  }

  /**
   * Per-post-type templates group → options page `templates-{post_type}`.
   */
  private function build_post_type_group( $post_type, $blocks ) {
    $group = new FieldsBuilder( 'templates' );
    $group
      ->addFields( $this->create_post_type_template_fields( $post_type, $blocks ) )
      ->setLocation( 'options_page', '==', 'templates-' . $post_type )
      ->setGroupConfig( 'key', 'templates_' . $post_type )
      ->setGroupConfig( 'style', 'seamless' );
    return $group->build();
  }

  /**
   * Create default tab template fields
   */
  private function create_default_template_fields( $blocks ) {
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
            'label' => "Before Content {$language}",
            'button_label' => 'Add Block',
            'layout' => 'block'
          ])
          ->addFlexibleContent("default_after_content_{$key}", [
            'label' => "After Content {$language}",
            'button_label' => 'Add Block',
            'layout' => 'block'
          ]);
      }
    }

    // Add layouts to flexible content fields
    $block_layouts = $this->create_block_layouts( $blocks );
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
  private function create_post_type_template_fields( $post_type, $blocks ) {
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
            'label' => "Content Templates {$language}",
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
    $block_layouts = $this->create_block_layouts( $blocks );
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
   * Creates block layouts from the (already fetched) blocks list.
   */
  private function create_block_layouts( $blocks ) {
    $blocks = is_array( $blocks ) ? $blocks : [];
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