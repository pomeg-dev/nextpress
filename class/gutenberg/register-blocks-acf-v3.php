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

class Register_Blocks_ACF_V3 {
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
    add_action( 'wp_loaded', [ $this, 'regsiter_nextpress_blocks' ] );
  }

  /**
   * Get API fields and register ACF blocks callback function
   */
  public function regsiter_nextpress_blocks() {
    if ( ! function_exists('acf_register_block_type') ) {
      return;
    }

    $themes = get_field( 'blocks_theme', 'option' );
    $blocks = $this->helpers->fetch_blocks_from_api( $themes, 'blocks' );

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

    // Enqueue global script only once
    static $global_script_added = false;
    if ( ! $global_script_added ) {
      $global_script_added = true;
      ?>
      <script>
      // Global NextPress block manager for ACF Blocks V3
      window.NextPressBlockManager = window.NextPressBlockManager || (function() {
          var instances = {};
          var debounceTimers = {};
          var globalDebounceTimer = null;
          var isGlobalListenerSetup = false;

          function setupGlobalListeners() {
              if (isGlobalListenerSetup) return;
              isGlobalListenerSetup = true;

              // Global message listener for height adjustments
              window.addEventListener('message', function(event) {
                  if (event.data && event.data.type === 'blockPreviewHeight' && event.data.iframeId) {
                      var iframe = document.getElementById(event.data.iframeId);
                      if (iframe) {
                          var newHeight = (event.data.height + 20) + 'px';
                          if (typeof event.data.height === 'string' && event.data.height.includes('vh')) {
                              newHeight = event.data.height;
                          }
                          iframe.style.height = newHeight;
                      }
                  }
              });

              // ACF V3 specific: Block re-renders happen automatically on change
              // We don't need manual field listeners because ACF V3 triggers PHP re-render
              // Our register() function will detect the new DOM and reload automatically
              if (window.acf) {
                  acf.addAction('ready', function() {
                      // Only listen to structural changes that need immediate updates
                      acf.addAction('append', function() {
                          reloadAllVisible(800);
                      });
                      acf.addAction('remove', function() {
                          reloadAllVisible(800);
                      });
                      acf.addAction('sortstop', function() {
                          reloadAllVisible(800);
                      });
                  });
              }
          }

          function reloadAllVisible(delay) {
              clearTimeout(globalDebounceTimer);
              globalDebounceTimer = setTimeout(function() {
                  Object.keys(instances).forEach(function(id) {
                      if (instances[id].isVisible()) {
                          instances[id].reload();
                      }
                  });
              }, delay || 800);
          }

          function BlockInstance(iframeId) {
              this.iframeId = iframeId;
              this.iframe = null;
              this.loading = null;
              this.wrapper = null;
              this.lastLoadedHash = '';
              this.isLoading = false;
              this.initialized = false;

              this.init = function() {
                  this.iframe = document.getElementById(this.iframeId);
                  this.loading = document.getElementById('loading_' + this.iframeId);
                  this.wrapper = document.getElementById('block_wrapper_' + this.iframeId);

                  if (!this.iframe) return;

                  setupGlobalListeners();

                  // Check if content hash has changed (ACF V3 triggers re-render on field changes)
                  var currentHash = this.iframe.getAttribute('data-content-hash');
                  var isInitialized = this.iframe.getAttribute('data-initialized') === 'true';

                  // Only skip if hash is same AND already initialized
                  if (isInitialized && currentHash === this.lastLoadedHash) {
                      // Content hasn't changed, skip reload
                      return;
                  }

                  // Content changed or first load - reload iframe
                  var self = this;
                  setTimeout(function() {
                      self.loadIframe();
                  }, 150);
              };

              this.isVisible = function() {
                  if (!this.wrapper) return false;
                  var rect = this.wrapper.getBoundingClientRect();
                  return rect.top < window.innerHeight && rect.bottom > 0;
              };

              this.loadIframe = function() {
                  if (!this.iframe || this.isLoading) return;

                  var currentHash = this.iframe.getAttribute('data-content-hash');

                  // Skip if content hasn't changed and iframe is already loaded
                  if (currentHash === this.lastLoadedHash && this.iframe.src) {
                      if (this.iframe.style.display === 'none') {
                          this.iframe.style.display = 'block';
                          if (this.loading) this.loading.style.display = 'none';
                      }
                      return;
                  }

                  this.isLoading = true;
                  this.lastLoadedHash = currentHash;

                  var frontendUrl = this.iframe.getAttribute('data-frontend-url');
                  var postId = this.iframe.getAttribute('data-post-id');
                  var encodedContent = this.iframe.getAttribute('data-encoded-content');

                  if (this.loading) {
                      this.loading.style.display = 'flex';
                      this.loading.innerHTML = '<span>Updating preview...</span>';
                  }
                  this.iframe.style.display = 'none';

                  var self = this;
                  var newSrc = frontendUrl + '/block-preview?post_id=' + postId + '&content=' + encodedContent + '&iframe_id=' + this.iframeId + '&t=' + Date.now();

                  this.iframe.onload = function() {
                      self.isLoading = false;
                      self.iframe.style.display = 'block';
                      self.iframe.setAttribute('data-initialized', 'true');
                      if (self.loading) self.loading.style.display = 'none';
                  };

                  this.iframe.onerror = function() {
                      self.isLoading = false;
                      if (self.loading) {
                          self.loading.innerHTML = '<span style="color: #d63638;">Preview unavailable</span>';
                      }
                  };

                  this.iframe.src = newSrc;

                  // Timeout fallback
                  setTimeout(function() {
                      if (self.isLoading) {
                          self.isLoading = false;
                          self.iframe.style.display = 'block';
                          if (self.loading) self.loading.style.display = 'none';
                      }
                  }, 5000);
              };

              this.reload = function() {
                  var self = this;
                  clearTimeout(debounceTimers[this.iframeId]);
                  debounceTimers[this.iframeId] = setTimeout(function() {
                      // Force reload by updating hash
                      self.lastLoadedHash = '';
                      self.loadIframe();
                  }, 300);
              };
          }

          return {
              register: function(iframeId) {
                  // ACF V3 re-renders blocks, so we need to re-initialize even if instance exists
                  if (instances[iframeId]) {
                      // Re-initialize existing instance with new DOM elements
                      setTimeout(function() {
                          instances[iframeId].init();
                      }, 100);
                  } else {
                      // Create new instance
                      instances[iframeId] = new BlockInstance(iframeId);
                      setTimeout(function() {
                          instances[iframeId].init();
                      }, 100);
                  }
                  return instances[iframeId];
              },
              getInstance: function(iframeId) {
                  return instances[iframeId];
              }
          };
      })();
      </script>
      <?php
    }

    // Register this specific block instance
    ?>
    <script>
    (function() {
        var iframeId = '<?php echo $iframe_id; ?>';
        if (window.NextPressBlockManager) {
            window.NextPressBlockManager.register(iframeId);
        }
    })();
    </script>

    <style>
    .nextpress-block-wrapper {
        position: relative;
        border: 2px solid #007cba;
        background-color: #f0f0f1;
        margin: 0;
    }

    .nextpress-loading {
        font-size: 14px;
        color: #666;
        animation: pulse 1.5s ease-in-out infinite alternate;
    }

    @keyframes pulse {
        from { opacity: 0.6; }
        to { opacity: 1; }
    }
    </style>


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
