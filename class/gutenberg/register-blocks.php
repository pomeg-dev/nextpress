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
        ]
      );
    }
  }

  /**
   * Render block callback with debouncing and intelligent polling
   * Requests nextjs /block-preview route in an iframe with optimized loading
   */
  public function render_nextpress_block( $block, $content = '', $is_preview = false, $post_id = 0 ) {
    $block_name = str_replace('acf/', '', $block['name']);
    $inner_blocks = get_field('inner_blocks');

    $block_html = $this->convert_acf_block_to_string( $block );
    $block_html = $this->formatter->parse_block_data( $block_html );

    // Attempt to parse inner blocks and patterns.
    $block_id = isset( $block_html[0]['id'] ) ? $block_html[0]['id'] : false;
    $block_html = $this->set_inner_blocks( $block_id, $post_id, $block_html );

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
    $frontend_url = $this->helpers->frontend_url;
    $iframe_id = 'block_preview_' . $block['id'];
    
    // Create a hash of the content for change detection
    $content_hash = md5($block_html);

    // Initial iframe with loading state
    echo "<div id='block_wrapper_{$iframe_id}' class='nextpress-block-wrapper'>";
    echo "<div id='loading_{$iframe_id}' class='nextpress-loading' style='display: flex; align-items: center; justify-content: center; height: 100px; background: #f0f0f1; border: 1px dashed #ccc;'>";
    echo "<span>Loading preview...</span>";
    echo "</div>";
    echo "<iframe id='{$iframe_id}' style='display: none; pointer-events: none; min-height: 80px; width: 100%; border: none;' data-content-hash='{$content_hash}' data-frontend-url='{$frontend_url}' data-post-id='{$post_id}' data-encoded-content='{$encoded_content}'></iframe>";
    echo "</div>";

    // Add the optimized loading and resize script
    ?>
    <script>
    (function() {
        var iframeId = '<?php echo $iframe_id; ?>';
        var contentHash = '<?php echo $content_hash; ?>';
        var debounceTimer;
        var lastLoadedHash = '';
        var isLoading = false;
        
        function loadIframe() {
            var iframe = document.getElementById(iframeId);
            var loading = document.getElementById('loading_' + iframeId);
            
            if (!iframe || isLoading) return;
            
            // Check if content has actually changed
            var currentHash = iframe.getAttribute('data-content-hash');
            if (currentHash === lastLoadedHash) {
                // Content hasn't changed, just show the iframe if it's hidden
                if (iframe.style.display === 'none' && iframe.src) {
                    iframe.style.display = 'block';
                    if (loading) loading.style.display = 'none';
                }
                return;
            }
            
            isLoading = true;
            lastLoadedHash = currentHash;
            
            var frontendUrl = iframe.getAttribute('data-frontend-url');
            var postId = iframe.getAttribute('data-post-id');
            var encodedContent = iframe.getAttribute('data-encoded-content');
            
            // Show loading state
            if (loading) {
                loading.style.display = 'flex';
                loading.innerHTML = '<span>Updating preview...</span>';
            }
            iframe.style.display = 'none';
            
            // Set up the iframe source
            iframe.src = frontendUrl + '/block-preview?post_id=' + postId + '&content=' + encodedContent + '&iframe_id=' + iframeId + '&t=' + Date.now();
            
            // Handle iframe load
            iframe.onload = function() {
                isLoading = false;
                iframe.style.display = 'block';
                if (loading) loading.style.display = 'none';
            };
            
            // Handle iframe error
            iframe.onerror = function() {
                isLoading = false;
                if (loading) {
                    loading.innerHTML = '<span style="color: #d63638;">Preview unavailable</span>';
                }
            };
            
            // Timeout fallback
            setTimeout(function() {
                if (isLoading) {
                    isLoading = false;
                    iframe.style.display = 'block';
                    if (loading) loading.style.display = 'none';
                }
            }, 5000);
        }
        
        function debouncedLoad() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(loadIframe, 800); // 800ms debounce
        }
        
        function setupMessageListener() {
            var iframe = document.getElementById(iframeId);
            if (!iframe) {
                setTimeout(setupMessageListener, 100);
                return;
            }
            
            function handleMessage(event) {
                if (
                    event.data && 
                    event.data.type === 'blockPreviewHeight' &&
                    event.data.iframeId === iframeId
                ) {
                    var iframe = document.getElementById(iframeId);
                    if (iframe) {
                        var newHeight = (event.data.height + 20) + 'px';
                        if (typeof event.data.height === 'string' && event.data.height.includes('vh')) {
                            newHeight = event.data.height;
                        }
                        iframe.style.height = newHeight;
                    }
                }
            }
            
            window.removeEventListener('message', handleMessage);
            window.addEventListener('message', handleMessage);
        }
        
        // Initialize
        setupMessageListener();
        
        // Load immediately for first render
        setTimeout(debouncedLoad, 100);
        
        // Set up ACF field change listeners
        if (window.acf) {
            acf.addAction('ready', function() {
                // Listen for ACF field changes
                acf.addAction('load', debouncedLoad);
                acf.addAction('append', debouncedLoad);
                acf.addAction('remove', debouncedLoad);
                acf.addAction('sortstop', debouncedLoad);
                
                // Listen for specific field types that commonly trigger updates
                jQuery(document).on('change input', '.acf-field input, .acf-field textarea, .acf-field select', function() {
                    // Update the content hash when fields change
                    var iframe = document.getElementById(iframeId);
                    if (iframe) {
                        // Generate new hash based on current form data
                        var formData = new FormData(jQuery(this).closest('form')[0] || document.body);
                        var newHash = Array.from(formData.entries()).join('');
                        iframe.setAttribute('data-content-hash', btoa(newHash).substring(0, 16));
                    }
                    debouncedLoad();
                });
            });
        }
        
        // WordPress block editor integration
        if (window.wp && window.wp.data) {
            var lastBlockCount = 0;
            wp.data.subscribe(function() {
                var blocks = wp.data.select('core/block-editor').getBlocks();
                var currentBlockCount = blocks.length;
                
                // Only trigger if block count changed (avoid constant updates)
                if (currentBlockCount !== lastBlockCount) {
                    lastBlockCount = currentBlockCount;
                    debouncedLoad();
                }
            });
        }
        
        // Handle document ready state
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                setupMessageListener();
                debouncedLoad();
            });
        }
        
        // Intersection Observer for lazy loading (only load when visible)
        if (window.IntersectionObserver) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting && entry.target.id === 'block_wrapper_' + iframeId) {
                        debouncedLoad();
                    }
                });
            }, { threshold: 0.1 });
            
            var wrapper = document.getElementById('block_wrapper_' + iframeId);
            if (wrapper) {
                observer.observe(wrapper);
            }
        }
    })();
    </script>
    
    <style>
    .nextpress-block-wrapper {
        position: relative;
        border: 2px solid #007cba;
        background-color: #f0f0f1;
        margin: 0 0 10px;
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

    <div class="nextpress-block" style="border: 2px solid #007cba; padding: 10px; margin: 0 0 10px; background-color: #f0f0f1;">
        <h4 style="margin: 0; color: #007cba;">Block: <?php echo ucfirst( str_replace( '-', ' ', $block_name ) ); ?></h4>

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
            <?php
        endif;
        ?>
    </div>
    <?php
  }

  /**
   * Parse post content and try to set inner blocks into block_html, including Patterns
   */
  private function set_inner_blocks( $block_id, $post_id, $block_html ) {
    $post_content = get_post_field( 'post_content', $post_id );
    $all_blocks = parse_blocks( $post_content );
    foreach ( $all_blocks as $block_content ) {
      if ( $block_id === $block_content['attrs']['anchor'] ) {
        $inner_blocks = $block_content['innerBlocks'];
        foreach ( $inner_blocks as $key => $nested_block ) {
          if ( $nested_block['blockName'] === 'core/block' ) {
            $pattern_id = $nested_block['attrs']['ref'];
            $pattern_post = get_post( $pattern_id );
            
            if ( $pattern_post && $pattern_post->post_type === 'wp_block' ) {
              $pattern_blocks = parse_blocks( $pattern_post->post_content );
              foreach ( $pattern_blocks as &$inner_pattern_block ) {
                if ( isset( $inner_pattern_block['attrs']['data'] ) ) {
                  $inner_pattern_block['data'] = $inner_pattern_block['attrs']['data'];
                } else {
                  $inner_pattern_block['data'] = $inner_pattern_block['attrs'];
                }
              }
              $inner_blocks[ $key ]['innerBlocks'] = $pattern_blocks;
            }
          } else {
            $nested_block['data'] = $this->parse_acf_repeater_fields( $nested_block, $post_id );
            $inner_blocks[ $key ] = $nested_block;
          }
        }


        $block_html[0]['innerBlocks'] = $inner_blocks;
        break;
      }
    }

    return $block_html;
  }

  private function parse_acf_repeater_fields( $block, $post_id ) {
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