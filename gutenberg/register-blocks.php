<?php

use StoutLogic\AcfBuilder\FieldsBuilder;
use StoutLogic\AcfBuilder\FieldBuilder;
use StoutLogic\AcfBuilder\RepeaterBuilder;

// Function to fetch blocks from the API
function fetch_blocks_from_api($theme = null)
{
    $api_url = get_blocks_api_url();
    if ($theme) {
        //$theme might be a string or an array of strings
        if (is_array($theme)) {
            $theme = implode(',', $theme);
        }
        $api_url .= '?theme=' . $theme;
    }

    // Resolve 'host.docker.internal' to the host IP if necessary
    // $api_url = str_replace('host.docker.internal', gethostbyname('host.docker.internal'), $api_url);

    $response = wp_remote_get($api_url, array(
        'timeout' => 15, // Increase timeout to 15 seconds
        'sslverify' => false // Only use this for local development!
    ));

    if (is_wp_error($response)) {
        error_log('API request failed: ' . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('API request failed with response code: ' . $response_code);
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Failed to parse API response: ' . json_last_error_msg());
        return false;
    }

    return $data;
}

function get_blocks_api_url()
{
    // Get the blocks API URL from the theme options
    // if null get localhost docker url
    $api_url = get_field('blocks_api_url', 'option');
    if (!$api_url) {
        $api_url = 'http://host.docker.internal:3000/api/blocks';
    }
    return $api_url;
}

function get_base_api_url()
{
    $parsed_url = parse_url(get_blocks_api_url());
    $base_url = $parsed_url['scheme'] . "://" . $parsed_url['host'];
    $base_url = isset($parsed_url['port']) ? 
        $base_url . ':' . $parsed_url['port'] : 
        $base_url;
    return $base_url;
}

// Function to map API field types to ACF field types
function build_acf_fields($fields, $builder)
{
    foreach ($fields as $field) {
        $field_type = $field['type'];
        $field_args = [
            'label' => $field['label'] ?? ucfirst(str_replace('_', ' ', $field['id'])),
            'name' => $field['id'],
            'instructions' => $field['instructions'] ?? '',
            'allowed_types' => $field['allowed_types'] ?? '',
            'layout' => $field['layout'] ?? 'table',
        ];

        // Merge any additional configuration from the API
        $field_args = array_merge($field_args, array_diff_key($field, array_flip(['id', 'type', 'label', 'instructions'])));

        switch ($field_type) {
            case 'text':
                $builder->addText($field['id'], $field_args);
                break;
            case 'textarea':
                $builder->addTextarea($field['id'], $field_args);
                break;
            case 'number':
                $builder->addNumber($field['id'], $field_args);
                break;
            case 'email':
                $builder->addEmail($field['id'], $field_args);
                break;
            case 'url':
                $builder->addUrl($field['id'], $field_args);
                break;
            case 'password':
                $builder->addPassword($field['id'], $field_args);
                break;
            case 'wysiwyg':
                $builder->addWysiwyg($field['id'], $field_args);
                break;
            case 'image':
                $builder->addImage($field['id'], $field_args);
                break;
            case 'file':
                $builder->addFile($field['id'], $field_args);
                break;
            case 'gallery':
                $builder->addGallery($field['id'], $field_args);
                break;
            case 'select':
                $builder->addSelect($field['id'], $field_args);
                break;
            case 'checkbox':
                $builder->addCheckbox($field['id'], $field_args);
                break;
            case 'radio':
                $builder->addRadio($field['id'], $field_args);
                break;
            case 'true_false':
                $builder->addTrueFalse($field['id'], $field_args);
                break;
            case 'link':
                $builder->addLink($field['id'], $field_args);
                break;
            case 'post_object':
                $builder->addPostObject($field['id'], $field_args);
                break;
            case 'page_link':
                $builder->addPageLink($field['id'], $field_args);
                break;
            case 'relationship':
                $builder->addRelationship($field['id'], $field_args);
                break;
            case 'taxonomy':
                $builder->addTaxonomy($field['id'], $field_args);
                break;
            case 'user':
                $builder->addUser($field['id'], $field_args);
                break;
            case 'date_picker':
                $builder->addDatePicker($field['id'], $field_args);
                break;
            case 'time_picker':
                $builder->addTimePicker($field['id'], $field_args);
                break;
            case 'color_picker':
                $builder->addColorPicker($field['id'], $field_args);
                break;
            case 'message':
                $builder->addMessage($field['id'], $field_args);
                break;
            case 'repeater':
                $repeater = $builder->addRepeater($field['id'], $field_args);
                if (isset($field['fields'])) {
                    build_acf_fields($field['fields'], $repeater);
                }
                break;
            case 'group':
                $group = $builder->addGroup($field['id'], $field_args);
                if (isset($field['fields'])) {
                    build_acf_fields($field['fields'], $group);
                }
                $group->endGroup();
                break;
            case 'flexible_content':
                $flex = $builder->addFlexibleContent($field['id'], $field_args);
                if (isset($field['layouts'])) {
                    foreach ($field['layouts'] as $layout) {
                        $flex_layout = $flex->addLayout($layout['name'], ['label' => $layout['label']]);
                        if (isset($layout['fields'])) {
                            build_acf_fields($layout['fields'], $flex_layout);
                        }
                    }
                }
                break;
            case 'nav':
                $field_args['choices'] = get_menus();
                $builder->addSelect($field['id'], $field_args);
                break;
            case 'theme':
                $field_args['choices'] = get_np_themes();
                $field_args['default_value'] = 'default-blocks';
                $builder->addSelect($field['id'], $field_args);
                break;
            case 'gravity_form':
                $field_args['choices'] = get_gravity_forms();
                $builder->addSelect($field['id'], $field_args);
                break;
            case 'tab':
                $builder->addTab($field['id'], $field_args);
                break;
            case 'accordion':
                $builder->addAccordion($field['id'], $field_args);
                break;
            case 'inner_blocks':
                if (is_array($field['choices'])) {
                    $field_args['default_value'] = array_values($field['choices']);
                }
                $builder->addCheckbox($field['id'], $field_args);
                break;
            default:
                // For any custom or unhandled field types
                $builder->addField($field_type, $field['id'], $field_args);
                break;
        }
    }
    return $builder;
}

function map_field_type($api_type)
{
    $type_map = [
        'text' => 'text',
        'textarea' => 'textarea',
        'number' => 'number',
        'range' => 'range',
        'email' => 'email',
        'url' => 'url',
        'password' => 'password',
        'wysiwyg' => 'wysiwyg',
        'oembed' => 'oembed',
        'image' => 'image',
        'file' => 'file',
        'gallery' => 'gallery',
        'select' => 'select',
        'checkbox' => 'checkbox',
        'radio' => 'radio',
        'button_group' => 'buttonGroup',
        'true_false' => 'trueFalse',
        'link' => 'link',
        'post_object' => 'postObject',
        'page_link' => 'pageLink',
        'relationship' => 'relationship',
        'taxonomy' => 'taxonomy',
        'user' => 'user',
        'google_map' => 'googleMap',
        'date_picker' => 'datePicker',
        'date_time_picker' => 'dateTimePicker',
        'time_picker' => 'timePicker',
        'color_picker' => 'colorPicker',
        'message' => 'message',
        'accordion' => 'accordion',
        'tab' => 'tab',
        'group' => 'group',
        'repeater' => 'repeater',
        'flexible_content' => 'flexibleContent',
        // Add any custom field types here
        'nav' => 'select',
        'gravity_form' => 'select',
        'theme' => 'select',
        'inner_blocks' => 'checkbox',
    ];


    return $type_map[$api_type] ?? $api_type;
}

function get_menus()
{
    $menu_choices = array(null => __('Please select menu', 'nextpress'));
    $menus = wp_get_nav_menus();
    if (!is_wp_error($menus)) {
        foreach ($menus as $menu) {
            $id = $menu->term_id;
            $menu_choices["{{nav_id-$id}}"] = $menu->name;
        }
    } else {
        $menus = get_nav_menu_locations();
        foreach ($menus as $location => $id) {
            $menu_choices["{{nav_id-$id}}"] = ucfirst(str_replace('_', ' ', $location));
        }
    }
    return $menu_choices;
}

function get_np_themes()
{
    $themes = get_field('blocks_theme', 'option');
    if ($themes && is_array($themes)) {
        foreach ( $themes as $theme ) {
          $choices[ $theme ] = $theme;
        }
    }
    return $choices;
}

function get_gravity_forms()
{
    if ( ! class_exists( 'GFAPI' ) ) {
        // No Gravity Form API class available. The plugin probably isn't active.
        return $field;
    }

    $forms = GFAPI::get_forms(true);
    $choices[null] = __('Please select form', 'nextpress');
    foreach ( $forms as $form ) {
        $choices['form_id_' . $form['id']] = $form['title'];
    }

    return $choices;
}

// Main function to register blocks and fields
function register_nextpress_blocks()
{
    add_action('acf/init', function () {
        $themes = get_field('blocks_theme', 'option');
        // If get_field() still doesn't work, you can use get_option() as a fallback:
        // $themes = get_option('options_blocks_theme');

        $blocks = fetch_blocks_from_api($themes);

        if (!$blocks) {
            error_log('Failed to fetch blocks from API');
            return;
        }

        foreach ($blocks as $index => $block) {
            if (!isset($block['id'])) continue;
            if (!isset($block['blockName'])) continue;
            $block_name = $block['id']; //in format {theme}--{name} (double hyphen deliberate)
            $theme = explode('--', $block_name)[0];
            $block_title = ucwords(str_replace('-', ' ', $block['blockName']));
            $block_title .= ' (' . ucwords(str_replace('-', ' ', $theme)) . ')';

            $block_builder = new FieldsBuilder($theme . '-' . $block['blockName']);
            build_acf_fields($block['fields'], $block_builder);

            $global = new FieldsBuilder($theme . '-' . $block['blockName'] . '-block');
            $global
                ->addFields($block_builder)
                ->setLocation('block', '==', 'acf/' . $block_name);

            acf_add_local_field_group($global->build());

            acf_register_block_type([
                'name'              => $block_name,
                'title'             => $block_title,
                'description'       => 'A custom ' . $block['blockName'] . ' block.',
                'render_callback'   => 'render_nextpress_block',
                'category'          => $theme,
                'icon'              => get_icon($block_name),
                'keywords'          => [$block_name, 'custom'],
                'supports'          => ['jsx' => true, 'anchor' => true],
            ]);
        }
    });
}

// gets a wp icon using seed generator from blockname. also adds particular icons if contains certain strings like hero, list, slider, image etc. etc.
function get_icon($block_name)
{
    $block_name = strtolower($block_name);

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

    foreach ($icon_map as $key => $value) {
        if (str_contains($block_name, $key)) {
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
    $seed = crc32($block_name);
    mt_srand($seed);

    return $icons[mt_rand(0, count($icons) - 1)];
}

function render_nextpress_block($block, $content = '', $is_preview = false, $post_id = 0)
{
    $block_name = str_replace('acf/', '', $block['name']);
    $inner_blocks = get_field('inner_blocks');

    // Block Preview.
    $is_preview = render_block_preview($post_id, $block, $inner_blocks);
    if (!$is_preview) :
        ?>
        <div class="nextpress-block" style="border: 2px solid #007cba; padding: 20px; margin: 10px 0; background-color: #f0f0f1;">
            <h3 style="margin-top: 0; color: #007cba;">Block: <?php echo esc_html(ucfirst(str_replace('-', ' ', $block_name))); ?></h3>
            <?php
            // You can add more content here if needed, for example:
            // $fields = get_fields();
            // foreach ($fields as $key => $value) {
            //     echo '<p><strong>' . esc_html($key) . ':</strong> ' . esc_html($value) . '</p>';
            // }
            ?>
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
                    template="<?php echo esc_attr(wp_json_encode($block_template)); ?>"
                    allowedBlocks="<?php echo esc_attr(wp_json_encode($allowed_blocks)); ?>"
                />
                <?php
            endif;
            ?>
        </div>
        <?php
    endif;
}

function render_block_preview($post_id, $block, $inner_blocks) {
    if (get_post_status($post_id) !== 'publish') return false;
    $block_id = isset($block['anchor']) ? $block['anchor'] : $block['np_custom_id'];
    if (!$block_id) return false;

    $temp_file = fetch_tmp_file($post_id);
    if ($temp_file) {
        $html = file_get_contents($temp_file);
    } else {
        $wp_post_url = rtrim(get_permalink($post_id), '/');
        $base_url = get_base_api_url();
        $fe_url = str_replace(home_url(), $base_url, $wp_post_url);
        if (!$fe_url) return false;
        
        // Fetch frontend HTML.
        $html = file_get_contents($fe_url);
        if (!$html) return false;
    }

    // Load DOM.
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $block_div = $xpath->query("//div[@id='" . $block_id . "']")->item(0);
    if ($block_div) {
        $block_html = '<div data-theme="' . $block['category'] . '">' . $dom->saveHTML($block_div) . '</div>';

        // Inner Blocks.
        if ($inner_blocks) {
            $inner_dom = new DOMDocument();
            @$inner_dom->loadHTML($block_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $inner_xpath = new DOMXPath($inner_dom);
    
            $inner_blocks_div = $inner_xpath->query("//div[contains(@class, 'inner-blocks')]")->item(0);
            if ($inner_blocks_div) {
                $block_template = [
                    [
                        'core/paragraph',
                        [
                            'placeholder' => __( 'Type / to choose a block', 'luna' ),
                        ],
                    ],
                ];
                $allowed_blocks = $inner_blocks ?? [];
                while ($inner_blocks_div->firstChild) {
                    $inner_blocks_div->removeChild($inner_blocks_div->firstChild);
                }
    
                $inner_blocks_element = $inner_dom->createElement('InnerBlocks');
                $inner_blocks_element->setAttribute(
                    'template',
                    json_encode($block_template)
                );
                $inner_blocks_element->setAttribute(
                    'allowedBlocks',
                    json_encode($allowed_blocks)
                );
                $inner_blocks_div->appendChild($inner_blocks_element);
                $updated_block_html = $inner_dom->saveHTML();
                echo $updated_block_html;
            }
        } else {
            echo $block_html;
        }

        return true;
    }
}

function fetch_tmp_file($post_id) {
    $prefix = '__html_' . $post_id;
    $tmp_dir = sys_get_temp_dir();
    if ($handle = opendir($tmp_dir)) {
        while (false !== ($file = readdir($handle))) {
            if (strpos($file, $prefix) === 0) {
                closedir($handle);
                return $tmp_dir . DIRECTORY_SEPARATOR . $file;
            }
        }
        closedir($handle);
    }

    return null;
}

function delete_tmp_file($post_id) {
    $prefix = '__html_' . $post_id;
    $temp_dir = sys_get_temp_dir();
    if ($handle = opendir($temp_dir)) {
        while (false !== ($file = readdir($handle))) {
            $file_path = $temp_dir . DIRECTORY_SEPARATOR . $file;
            if (strpos($file, $prefix) === 0 && is_file($file_path)) {
                unlink($file_path);
            }
        }
        closedir($handle);
    }
}

function handle_dom_preload($post_id, $load_styles = false)
{
    if (get_post_status($post_id) !== 'publish') return;
    $wp_post_url = rtrim(get_permalink($post_id), '/');
    $base_url = get_base_api_url();
    $fe_url = str_replace(home_url(), $base_url, $wp_post_url);
    if (!$fe_url) return;
    
    // Fetch frontend HTML.
    $html = file_get_contents($fe_url);
    if (!$html) return;

    // Load DOM.
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    
    // Replace image srcs.
    $images = $dom->getElementsByTagName('img');
    foreach ($images as $img) {
        $src = $img->getAttribute('src');
        if (strpos($src, '/_next/image?url=') !== false) {
            $decoded_url = urldecode(parse_url($src, PHP_URL_QUERY));
            $decoded_url = str_replace('url=', '', $decoded_url);
            $position = strpos($decoded_url, '&');
            if ($position !== false) {
                $decoded_url = substr($decoded_url, 0, $position);
            }
            $img->setAttribute('src', $decoded_url);
            $img->removeAttribute('srcset');
        }
    }

    // Inject styles.
    if ($load_styles) {
        $base_url = get_base_api_url();
        $css_rules = '';
        $style_tags = $xpath->query("//style");
        foreach ($style_tags as $style) {
            $css_rules .= $style->textContent;
        }
        $external_styles = [];
        $link_tags = $xpath->query("//link[@rel='stylesheet']");
        foreach ($link_tags as $link) {
            $external_styles[] = $link->getAttribute('href');
        }
        $external_styles_content = '';
        foreach ($external_styles as $url) {
            $styles = file_get_contents($base_url . $url);
            $external_styles_content .= $styles;
        }
        $combined_styles = $css_rules . "\n" . $external_styles_content;
        $combined_styles = preg_replace('/\.\__variable_[a-zA-Z0-9]+/', 'body', $combined_styles);
        $fe_url = get_nextpress_frontend_url();
        $combined_styles = preg_replace('/src:url\((\/[^\)]+\.[a-zA-Z0-9]+)\)/', 'src:url(' . $fe_url . '$1)', $combined_styles);
        ?>
        <style id="fe-style">
            .inner-blocks .block-editor-block-list__block {
                margin-top: 0 !important;
                margin-bottom: 0 !important;
            }
            .break-out {
                width: auto !important;
                left: unset !important;
                margin-left: unset !important;
            }
            .swiper-wrapper {
                gap: 1rem;
            }
            .swiper-wrapper .swiper-slide {
                width: 50%;
                opacity: 1 !important;
            }
            .swiper-wrapper .swiper-slide.testimonials-slider-item {
                width: 80%;
            }
            .opacity-0 {
                opacity: 1 !important;
            }
            <?php echo $combined_styles; ?>
        </style>
        <?php
    }

    // Save dom to tmp file.
    $tmp_file = tempnam(sys_get_temp_dir(), '__html_' . $post_id);
    file_put_contents($tmp_file, $dom->saveHTML());
}

function revalidate_fetch_route($tag)
{
    $fe_url = get_base_api_url();
    $request_url = $fe_url . "/api/revalidate?tag=" . $tag;
    if ($fe_url) {
        return wp_remote_get($request_url);
    }
}

function preload_frontend_page()
{
    $screen = get_current_screen();
    if ($screen->base === 'post' && isset($_GET['post'])) {
        $post_id = intval($_GET['post']);
        handle_dom_preload($post_id, true);
    }
}

function reload_frontend_page($post_id)
{
    if (!$post_id) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    revalidate_fetch_route('post');
    revalidate_fetch_route('posts');
    delete_tmp_file($post_id);
    handle_dom_preload($post_id);
}

// Initialize the block registration
add_action('after_setup_theme', 'register_nextpress_blocks');

// Setup DOMDocument and inject frontend styles.
add_action('admin_head', 'preload_frontend_page');

// Refetch DOMDocument on post save.
add_action( 'save_post', 'reload_frontend_page');

// Populate post_type_rest field.
add_filter('acf/load_field/name=post_type_rest', function ($field) {
    $post_types = get_post_types( array( 'public'   => true ), 'objects' );
    foreach ($post_types as $post_type) {
        if ($post_type->name === 'page' || $post_type->name === 'attachment') {
            continue;
        }
        $field['choices'][$post_type->rest_base] = $post_type->label;
    }

    // return the field
    return $field;
});

// Populate taxonomy field.
add_filter('acf/load_field/name=taxonomy_rest', function ($field) {
    $taxonomies = get_taxonomies( array( 'public'   => true ), 'objects' );
    foreach ($taxonomies as $tax) {
        $field['choices'][$tax->rest_base] = $tax->label;
    }

    // return the field
    return $field;
});

// Populate current_post field with current post object.
add_filter('acf/load_field/name=current_post', function ($field) {
    $current_post_id = get_the_ID();
    if ($current_post_id) {
        $field['value'] = $current_post_id;
    }

    // return the field
    return $field;
});

// Ensure correct post object is returned for current_post field.
add_filter('acf/format_value/type=post_object', function ($value, $post_id, $field) {
    if (is_object($value)) {
        $value = NextpressPostFormatter::format_post($value);
    }
    return $value;
}, 10, 3);
