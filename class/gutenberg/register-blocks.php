<?php
/**
 * This class registers all blocks from Next.js API as ACF gutenberg blocks
 *
 * @package nextpress
 */

namespace nextpress;

defined('ABSPATH') or die('You do not have access to this file');

use StoutLogic\AcfBuilder\FieldsBuilder;
use StoutLogic\AcfBuilder\FieldBuilder;
use StoutLogic\AcfBuilder\RepeaterBuilder;

class Register_Blocks {
  /**
   * Helpers.
   */
  public $helpers;

  /**
   * ACF field builder.
   */
  public $field_builder;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->field_builder = new Field_Builder();

    // Register blocks.
    add_action( 'acf/init', [ $this, 'regsiter_nextpress_blocks' ] );
  }

  /**
   * Get API fields and register ACF blocks callback function
   */
  public function regsiter_nextpress_blocks() {
    $themes = get_field( 'blocks_theme', 'option' );
    $blocks = $this->helpers->fetch_blocks_from_api( $themes );

    if ( ! $blocks ) {
      error_log('Failed to fetch blocks from API');
      return;
    }

    foreach ( $blocks as $index => $block ) {
      if ( ! isset( $block['id'] ) || ! isset( $block['blockName'] ) ) {
        continue;
      }

      $block_name = $block['id']; // Format {theme}--{name} (double hyphen deliberate).
      $theme = explode( '--', $block_name )[0];
      $block_title = ucwords( str_replace( '-', ' ', $block['blockName'] ) );
      $block_title .= ' (' . ucwords( str_replace( '-', ' ', $theme ) ) . ')';

      $block_builder = new FieldsBuilder($theme . '-' . $block['blockName']);
      $this->field_builder->build( $block['fields'], $block_builder );

      $global = new FieldsBuilder( $theme . '-' . $block['blockName'] . '-block' );
      $global
        ->addFields( $block_builder )
        ->setLocation( 'block', '==', 'acf/' . $block_name );

      acf_add_local_field_group( $global->build() );

      acf_register_block_type(
        [
          'name'              => $block_name,
          'title'             => $block_title,
          'description'       => 'A custom ' . $block['blockName'] . ' block.',
          'render_callback'   => [ $this, 'render_nextpress_block' ],
          'category'          => $theme,
          'icon'              => $this->get_icon( $block_name ),
          'keywords'          => [ $block_name, 'custom' ],
          'supports'          => [
            'jsx' => true, 
            'anchor' => true
          ],
        ]
      );
    }
  }

  /**
   * Render block callback
   * TODO: block preview
   */
  public function render_nextpress_block( $block, $content = '', $is_preview = false, $post_id = 0 ) {
    $block_name = str_replace( 'acf/', '', $block['name'] );
    $inner_blocks = get_field( 'inner_blocks' );

    ?>
      <div class="nextpress-block" style="border: 2px solid #007cba; padding: 20px; margin: 10px 0; background-color: #f0f0f1;">
        <h3 style="margin-top: 0; color: #007cba;">Block: <?php echo ucfirst( str_replace( '-', ' ', $block_name ) ); ?></h3>

        <?php
        $block_template = [
          [
            'core/paragraph',
            [
              'placeholder' => __( 'Type / to choose a block', 'luna' ),
            ],
          ],
        ];
        $allowed_blocks = $inner_blocks ?? [];
        if ($inner_blocks) :
          ?>
          <InnerBlocks
            template="<?php echo esc_attr( wp_json_encode( $block_template ) ); ?>"
            allowedBlocks="<?php echo esc_attr( wp_json_encode( $allowed_blocks ) ); ?>"
          />
          <?php
        endif;
        ?>
      </div>
    <?php
  }

  // Gets a wp icon using seed generator from blockname. also adds particular icons if contains certain strings like hero, list, slider, image etc. etc.
  private function get_icon( $block_name ) {
      $block_name = strtolower( $block_name );
      $icon_map = [
        'hero' => 'format-image',
        'list' => 'editor-ul',
        'slider' => 'images-alt2',
        'image' => 'format-image',
        'text' => 'editor-alignleft',
        'quote' => 'format-quote',
        'video' => 'video-alt3',
        'gallery' => 'format-gallery',
        'form' => 'feedback',
        'cta' => 'megaphone',
        'contact' => 'email-alt',
        'social' => 'share',
        'map' => 'location-alt',
        'accordion' => 'editor-kitchensink',
        'tab' => 'editor-table',
        'menu' => 'menu',
        'footer' => 'admin-generic',
        'header' => 'admin-generic',
        'sidebar' => 'admin-generic',
        'widget' => 'admin-generic',
      ];

      foreach ( $icon_map as $key => $value ) {
        if ( str_contains( $block_name, $key ) ) {
          return $value;
        }
      }

      // If no match found, use seeded random selection
      $icons = [
        'admin-comments',
        'admin-post',
        'admin-media',
        'admin-links',
        'admin-page',
        'admin-appearance',
        'admin-plugins',
        'admin-users',
        'admin-tools',
        'admin-settings',
        'admin-network',
        'admin-home',
        'admin-generic',
        'admin-collapse',
        'welcome-write-blog',
        'welcome-edit-page',
        'welcome-add-page',
        'welcome-view-site',
        'welcome-widgets-menus',
        'welcome-comments',
        'welcome-learn-more',
        'format-aside',
        'format-image',
        'format-gallery',
        'format-video',
        'format-status',
        'format-quote',
        'format-chat',
        'format-audio',
        'camera',
        'images-alt',
        'images-alt2',
        'video-alt',
        'video-alt2',
        'video-alt3',
        'vault',
        'shield',
        'shield-alt',
        'pressthis',
        'update',
        'cart',
        'feedback',
        'cloud',
        'translation',
        'tag',
        'category',
        'yes',
        'no',
        'plus',
        'minus',
        'dismiss',
        'marker',
        'star-filled',
        'star-half',
        'star-empty',
        'flag',
        'location',
        'location-alt'
      ];

      // Create a seed based on the block name
      $seed = crc32( $block_name );
      mt_srand( $seed );

      return $icons[mt_rand(0, count($icons) - 1)];
  }
}