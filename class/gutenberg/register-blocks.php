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
   * Post formatter.
   */
  public $formatter;

  /**
   * ACF field builder.
   */
  public $field_builder;

  public function __construct( $helpers ) {
    $this->helpers = $helpers;
    $this->formatter = new Post_Formatter();
    $this->field_builder = new Field_Builder();

    // Register blocks.
    add_action( 'wp_loaded', [ $this, 'register_nextpress_blocks' ] );

    // Disable editing if no blocks found.
    add_action( 'init', [ $this, 'disable_editing_if_no_blocks' ] );
  }

  /**
   * Disable editing if no blocks found in fetch_blocks_from_api function.
   * Only checks on post editor screens.
   */
  public function disable_editing_if_no_blocks() {
    if ( ! is_admin() ) {
      return;
    }

    global $pagenow;
    if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) ) {
      return;
    }

    $blocks = $this->helpers->fetch_blocks_from_api( null, 'init' );
    if ( empty( $blocks ) ) {
      add_filter( 'use_block_editor_for_post', '__return_false' );
      add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error is-dismissible"><p>';
        _e( 'No blocks found. Please make sure the blocks api endpoint is configured', 'nextpress' );
        echo '</p></div>';
      });
      add_action( 'admin_head', function() {
        echo '<style>#post-body-content { display: none; }</style>';
      });
    }
  }

  /**
   * Get API fields and register ACF blocks callback function
   */
  public function register_nextpress_blocks() {
    if ( ! function_exists('acf_register_block_type') ) {
      return;
    }

    // CRITICAL FIX: Only fetch blocks when actually needed (post editor, templates page, REST API)
    // This prevents unnecessary HTTP requests on posts list, pages list, settings, etc.
    $should_fetch_blocks = false;

    global $pagenow;

    // Check if this is a REST API request (multiple detection methods)
    $is_rest_request = (
      ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
      ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/wp-json/' ) !== false )
    );

    // Check if we're in admin and on specific pages that need blocks
    if ( is_admin() ) {
      $allowed_pages = [
        'post.php',           // Editing existing post
        'post-new.php',       // Creating new post
        'admin-ajax.php',     // Admin ajax requests
      ];

      // Check for templates admin page
      $is_np_page = (
        $pagenow === 'admin.php' &&
        isset( $_GET['page'] ) && ( $_GET['page'] === 'templates' || $_GET['page'] === 'acf-options-settings' )
      );

      if ( in_array( $pagenow, $allowed_pages ) || $is_np_page ) {
        $should_fetch_blocks = true;
      }
    }

    // Always fetch for REST API requests (needed for block editor and frontend API calls)
    if ( $is_rest_request ) {
      $should_fetch_blocks = true;
    }

    // Allow manual override via query param for testing
    if ( isset( $_GET['nextpress_register_blocks'] ) ) {
      $should_fetch_blocks = true;
    }

    if ( ! $should_fetch_blocks ) {
      return;
    }

    $themes = get_field( 'blocks_theme', 'option' );
    $blocks = $this->helpers->fetch_blocks_from_api( $themes, 'blocks' );

    if ( ! $blocks ) {
      error_log('Nextpress: Failed to fetch blocks from API');
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

      // Remove DS_Store.
      if ( str_contains( $block_title, 'DS_Store' ) ) {
        continue;
      }

      // Add theme to categories.
      if ( $theme ) {
        add_filter( 'block_categories_all', function( $categories ) use ( $theme ) {
          return array_merge(
            $categories,
            [
              [
                'slug' => $theme,
                'title' => ucwords( str_replace( '-', ' ', $theme ) ),
                'icon' => 'star-filled',
              ],
            ]
          );
        }, 10, 1 );
      }

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
          'api_version'       => 3,
          'acf_block_version' => 3,
        ]
      );
    }
  }

  /**
   * Render block callback optimized for ACF Blocks V3
   * Requests nextjs /block-preview route in an iframe with minimal re-rendering
   */
  public function render_nextpress_block( $block, $content = '', $is_preview = false, $post_id = 0 ) {
    $block_name = str_replace( 'acf/', '', $block['name'] );

    // Find inner blocks.
    $ib_field_name = str_replace( '--', '-', $block_name );
    $inner_blocks = [];
    if ( isset( $block['data']['inner_blocks'] ) ) {
      $inner_blocks = $block['data']['inner_blocks'];
    } else if ( isset( $block['data']["field_{$ib_field_name}-block_inner_blocks"] ) ) {
      $inner_blocks = $block['data']["field_{$ib_field_name}-block_inner_blocks"];
    }

    $block_html = $this->convert_acf_block_to_string( $block );
    $block_html = $this->formatter->parse_block_data( $block_html );
    $block_html = $this->set_inner_blocks( $block, $post_id, $block_html, $content );

    $block_prefix = isset( $block_html[0]['slug'] )
        ? 'field_' . str_replace( 'acf-', '', $block_html[0]['slug'] ) . '-block_'
        : '';
    $block_html = json_encode( $block_html, JSON_UNESCAPED_SLASHES );
    if ( $block_prefix ) {
      $block_html = str_replace( $block_prefix, '', $block_html );
    }

    // Remove modal_content items
    $pattern = '/"modal_content":\s*(\{(?:[^{}]|(?1))*\})/';
    $replacement = '"modal_content": null';
    $block_html = preg_replace( $pattern, $replacement, $block_html );

    $encoded_content = urlencode( $this->compress_data( $block_html ) );
    $frontend_url = $this->helpers->get_frontend_url_public();
    $iframe_id = 'block_preview_' . $block['id'];

    // Create a hash of the content for change detection
    $content_hash = md5( $block_html );

    // Initial iframe with loading state
    echo "<div id='block_wrapper_{$iframe_id}' class='nextpress-block-wrapper' data-block-id='{$block['id']}'>";
    echo "<h4 style=\"margin: 0; color: #007cba; padding: 4px; border-bottom: 1px dashed #007cba;\">Block: " . ucfirst( str_replace( '-', ' ', $block_name ) ) . "</h4>";
    echo "<div id='loading_{$iframe_id}' class='nextpress-loading' style='display: flex; align-items: center; justify-content: center; height: 100px; background: #f0f0f1; border: 1px dashed #ccc;'>";
    echo "<span>Loading preview...</span>";
    echo "</div>";
    // echo "<div>{$block_html}</div>";
    echo "<iframe id='{$iframe_id}' style='display: none; pointer-events: none; min-height: 80px; width: 100%; border: none;' data-content-hash='{$content_hash}' data-frontend-url='{$frontend_url}' data-post-id='{$post_id}' data-encoded-content='{$encoded_content}' data-initialized='false'></iframe>";
    echo "</div>";

    // Enqueue block preview assets (WordPress deduplicates automatically).
    wp_enqueue_script(
      'nextpress-block-preview',
      NEXTPRESS_URI . '/assets/js/block-preview.js',
      [],
      filemtime( NEXTPRESS_PATH . '/assets/js/block-preview.js' ),
      true
    );
    wp_enqueue_style(
      'nextpress-block-preview',
      NEXTPRESS_URI . '/assets/css/block-preview.css',
      [],
      filemtime( NEXTPRESS_PATH . '/assets/css/block-preview.css' )
    );

    // Register this specific block instance.
    wp_add_inline_script(
      'nextpress-block-preview',
      '(function() {' .
        'var iframeId = ' . wp_json_encode( $iframe_id ) . ';' .
        'if (window.NextPressBlockManager) { window.NextPressBlockManager.register(iframeId); }' .
      '})();'
    );

    $block_template = [
      [
        'core/paragraph',
        [
          'placeholder' => __( 'Type / to choose a block', 'luna' ),
        ],
      ],
    ];
    $allowed_blocks = $inner_blocks ?? [];
    if ( ! empty( $inner_blocks ) ) :
      ?>
      <div class="nextpress-block" style="border: 2px solid #007cba; padding: 0 10px; margin: 0; background-color: #f0f0f1;">
        <h5 style="margin: 10px 0 0; color: #007cba; padding: 0 0 10px; border-bottom: 1px dotted #007cba;">Inner blocks:</h5>
        <InnerBlocks
            template="<?php echo esc_attr( wp_json_encode( $block_template ) ); ?>"
            <?php
            if ( $allowed_blocks && $allowed_blocks[0] !== 'all' ) :
                ?>
                allowedBlocks="<?php echo esc_attr( wp_json_encode( $allowed_blocks ) ); ?>"
                <?php
            endif;
            ?>
        />
        </div>
        <?php
    endif;
  }

  /**
   * Parse post content and try to set inner blocks into block_html, including Patterns
   */
  private function set_inner_blocks( $block, $post_id, $block_html, $content = '' ) {
    // First, try to get innerBlocks directly from the $block parameter (ACF V3)
    // This handles the case where the block is being rendered before saving
    $inner_blocks = isset( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

    // If no innerBlocks found in $block, try parsing from $content parameter (ACF passes rendered innerBlocks here)
    if ( empty( $inner_blocks ) && ! empty( $content ) ) {
      // Parse the content to extract inner blocks
      $parsed_content = parse_blocks( $content );
      if ( ! empty( $parsed_content ) ) {
        $inner_blocks = array_filter( $parsed_content, function( $block ) {
          return isset( $block['blockName'] ) && ! empty( $block['blockName'] );
        });
      }
    }

    // If still no innerBlocks found, try parsing from saved post content
    if ( empty( $inner_blocks ) ) {
      $post_content = get_post_field( 'post_content', $post_id );
      if ( ! $post_content ) {
        return $block_html;
      }

      $all_blocks = parse_blocks( $post_content );

      $all_blocks = array_filter($all_blocks, function( $content_block ) use ( $block ) {
        // Match by block name and anchor (required)
        $name_match = isset( $content_block['blockName'] )
          && is_string( $content_block['blockName'] )
          && $content_block['blockName'] !== ''
          && isset( $block['name'] )
          && $content_block['blockName'] === $block['name'];

        $anchor_match = isset( $content_block['attrs']['anchor'] )
          && isset( $block['anchor'] )
          && $content_block['attrs']['anchor'] === $block['anchor'];

        // nextpress_id match is optional (only check if both exist)
        $nextpress_match = true;
        if ( isset( $block['nextpress_id'] ) && isset( $content_block['attrs']['nextpress_id'] ) ) {
          $nextpress_match = $content_block['attrs']['nextpress_id'] === $block['nextpress_id'];
        }

        return $name_match && $anchor_match && $nextpress_match;
      });

      if ( !empty( $all_blocks ) ) {
        $all_blocks = array_values( $all_blocks );

        // If we found multiple matches, try to narrow down
        if ( count($all_blocks) > 1 ) {
          // Try to narrow down using nextpress_id if available
          if ( isset($block['nextpress_id']) ) {
            $filtered = array_filter($all_blocks, function($b) use ($block) {
              return isset($b['attrs']['nextpress_id']) && $b['attrs']['nextpress_id'] === $block['nextpress_id'];
            });
            if ( !empty($filtered) ) {
              $all_blocks = array_values($filtered);
            }
          }

          // If still multiple matches, skip innerBlocks to avoid wrong data
          if ( count($all_blocks) > 1 ) {
            return $block_html; // Return early without innerBlocks
          }
        }

        // At this point we have exactly 1 match - safe to use it
        $block_content = $all_blocks[0];
        $inner_blocks = isset( $block_content['innerBlocks'] ) ? $block_content['innerBlocks'] : [];
      }
    }

    // If still no innerBlocks found, return original block_html
    if ( empty( $inner_blocks ) ) {
      return $block_html;
    }

    foreach ( $inner_blocks as $key => $nested_block ) {
      // Handle reusable blocks (patterns)
      if ( isset( $nested_block['blockName'] ) && $nested_block['blockName'] === 'core/block' ) {
        $pattern_id = isset( $nested_block['attrs']['ref'] ) ? $nested_block['attrs']['ref'] : null;

        if ( $pattern_id ) {
          $pattern_post = get_post( $pattern_id );

          if ( $pattern_post && $pattern_post->post_type === 'wp_block' ) {
            $pattern_blocks = parse_blocks( $pattern_post->post_content );
            foreach ( $pattern_blocks as &$inner_pattern_block ) {
              if ( isset( $inner_pattern_block['attrs']['data'] ) ) {
                $inner_pattern_block['data'] = $inner_pattern_block['attrs']['data'];
              } else {
                $inner_pattern_block['data'] = isset( $inner_pattern_block['attrs'] ) ? $inner_pattern_block['attrs'] : [];
              }
            }
            $inner_blocks[ $key ]['innerBlocks'] = $pattern_blocks;
          }
        }
      } else {
        // Parse ACF repeater fields for regular blocks
        $nested_block['data'] = $this->parse_acf_repeater_fields( $nested_block, $post_id );
        $inner_blocks[ $key ] = $nested_block;
        unset( $inner_blocks[ $key ]['attrs'] );
      }
    }

    $block_html[0]['innerBlocks'] = $inner_blocks;

    return $block_html;
  }

  private function parse_acf_repeater_fields( $block, $post_id ) {
    // Check if attrs and data exist
    if ( ! isset( $block['attrs']['data'] ) ) {
      return [];
    }

    $data = $block['attrs']['data'];
    $result = [];
    $repeaters = [];

    foreach ( $data as $key => $value ) {
      if (strpos($key, '_') === 0) continue;
      if ( strpos( $key, '_' ) === false ) {
        $result[ $key ] = $value;

        if (
          is_numeric( $value ) &&
          $value > 0 &&
          isset( $data["_{$key}"] ) &&
          strpos( $data["_{$key}"], 'field_' ) === 0
        ) {
          $repeaters[ $key ] = (int) $value;
        }
      }
    }

    // Process each repeater field
    foreach ( $repeaters as $repeater_name => $count ) {
      $items = [];

      for ( $i = 0; $i < $count; $i++ ) {
        $item = [];
        $prefix = "{$repeater_name}_{$i}_";
        $prefix_length = strlen( $prefix );

        foreach ( $data as $key => $value ) {
          if ( strpos( $key, '_' ) === 0 ) continue;

          // Check for correct return values.
          if ( strpos( $key, $prefix ) === 0 ) {
            // $acf_key = $data["_$key"];
            $field_name = substr( $key, $prefix_length );
            $item[ $field_name ] = $value;
          }
        }

        if ( ! empty( $item ) ) {
          $items[] = $item;
        }
      }

      $result[ $repeater_name ] = $items;
    }

    return $result;
  }

  public function convert_acf_block_to_string( $block ) {
    $name = $block['name'];
    $data = isset( $block['data'] ) ? $block['data'] : [];
    $mode = isset( $block['mode'] ) ? $block['mode'] : '';
    $align = isset( $block['align'] ) ? $block['align'] : '';
    $anchor = isset( $block['anchor'] ) ? $block['anchor'] : '';

    $attributes = [
      'name' => $name,
      'data' => $data
    ];

    if ( ! empty( $mode ) ) {
      $attributes['mode'] = $mode;
    }

    if ( ! empty( $align ) ) {
      $attributes['align'] = $align;
    }

    if ( ! empty( $anchor ) ) {
      $attributes['anchor'] = $anchor;
    }

    $json_attributes = json_encode( $attributes, JSON_UNESCAPED_SLASHES );
    $json_attributes = str_replace( '--', '\u002d\u002d', $json_attributes );
    $block_string = "<!-- wp:{$name} {$json_attributes} /-->";

    return $block_string;
  }

  // Compress data.
  private function compress_data( $data ) {
    $compressed = gzcompress( $data, 9) ;
    return rtrim( strtr( base64_encode( $compressed ), '+/', '-_' ), '=' );
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
