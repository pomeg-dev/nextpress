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

        foreach ($blocks as $block) {
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
                'supports'          => ['jsx' => true],
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
    $block_template = [
        [
            'core/paragraph',
            [
                'placeholder' => __( 'Type / to choose a block', 'luna' ),
            ],
        ],
    ];
    $allowed_blocks = $inner_blocks ?? [];
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

// Initialize the block registration
add_action('after_setup_theme', 'register_nextpress_blocks');

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
