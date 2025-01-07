<?php

use StoutLogic\AcfBuilder\FieldsBuilder;

//add menus
add_action('acf/init', function () {
    if (function_exists('acf_add_options_page')) {
        acf_add_options_page(array(
            'page_title'    => __('Theme settings'),
            'menu_title'    => __('Theme settings'),
            'menu_slug'     => 'nextpress',
            'capability'    => 'edit_posts',
            'redirect'      => true
        ));
        acf_add_options_sub_page(array(
            'page_title'    => 'Styles',
            'menu_title'    => 'Styles',
            'parent_slug'   => 'nextpress',
        ));
        acf_add_options_sub_page(array(
            'page_title'    => 'Settings',
            'menu_title'    => 'Settings',
            'parent_slug'   => 'nextpress',
        ));
    }
});

//styles

$colors = new FieldsBuilder('colors');
$colors
    ->addTab("colors")
    ->addColorPicker("primary_color", array(
        'default_value' => '#F5BC51',
    ))
    ->addColorPicker("secondary_color", array(
        'default_value' => '#1D1D1B',
    ))
    ->addColorPicker("tertiary_color", array(
        'default_value' => '#F6F7F9',
    ))
    ->addColorPicker("quaternary_color", array(
        'default_value' => '#D3D6D8',
    ));

$animation = new FieldsBuilder('animation');
$animation
    ->addTab("animation")
    ->addTrueFalse("animations_enable")
    ->addMessage('animation_instructions', 'possible options are .animation-fade, .animation-fade-up, .animation-fade-down, .animation-fade-left, .animation-fade-right, .animation-fade-up-right, .animation-fade-up-left, .animation-fade-down-right, .animation-fade-down-left, .animation-flip-up, .animation-flip-down, .animation-flip-left, .animation-flip-right, .animation-slide-up, .animation-slide-down, .animation-slide-left, .animation-slide-right, .animation-zoom-in, .animation-zoom-in-up, .animation-zoom-in-down, .animation-zoom-in-left, .animation-zoom-in-right, .animation-zoom-out, .animation-zoom-out-up, .animation-zoom-out-down, .animation-zoom-out-left, .animation-zoom-out-right', array(
        'label' => 'Instructions',
        'instructions' => '',
        'required' => 0,
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'animations_enable',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
        'message' => 'possible options are .animation-fade, .animation-fade-up, .animation-fade-down, .animation-fade-left, .animation-fade-right, .animation-fade-up-right, .animation-fade-up-left, .animation-fade-down-right, .animation-fade-down-left, .animation-flip-up, .animation-flip-down, .animation-flip-left, .animation-flip-right, .animation-slide-up, .animation-slide-down, .animation-slide-left, .animation-slide-right, .animation-zoom-in, .animation-zoom-in-up, .animation-zoom-in-down, .animation-zoom-in-left, .animation-zoom-in-right, .animation-zoom-out, .animation-zoom-out-up, .animation-zoom-out-down, .animation-zoom-out-left, .animation-zoom-out-right,',
        'new_lines' => 'wpautop', // 'wpautop', 'br', '' no formatting
        'esc_html' => 0,
    ));


$cookie_notice = new FieldsBuilder('cookie_notice');
$cookie_notice
    ->addTab("cookie_notice")
    ->addTrueFalse("cookie_notice_enabled", array(
        'default_value' => 1,
    ))
    ->addImage("cookie_notice_icon", array(
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'cookie_notice_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ))
    ->addText("cookie_notice_heading", array(
        'default_value' => 'We use cookies',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'cookie_notice_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ))
    ->addWysiwyg("cookie_notice_text", array(
        'default_value' => 'This site uses services that uses cookies to deliver better experience and analyze traffic. You can learn more about the services we use at our <a href="/privacy-policy">privacy policy</a>.',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'cookie_notice_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ))
    ->addText("cookie_notice_accept_text", array(
        'default_value' => 'Accept',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'cookie_notice_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ))
    ->addText("cookie_notice_reject_text", array(
        'default_value' => 'Reject',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'cookie_notice_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ))
    ->addColorPicker("cookie_notice_bg_color", array(
        'default_value' => '#1D1D1B',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'cookie_notice_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ));

$favicon = new FieldsBuilder('favicon');
$favicon
    ->addTab("favicon")
    ->addImage("favicon", array(
        'instructions' => 'Upload a 16x16px PNG image',
        'return_format' => 'array',
        'preview_size' => 'thumbnail',
        'library' => 'all',
        'width' => '16',
        'height' => '16',
    ));


$global = new FieldsBuilder('Settings');
$global
    ->addFields($colors)
    ->addFields($animation)
    ->addFields($cookie_notice)
    ->addFields($favicon)
    ->setLocation('options_page', '==', 'acf-options-styles')
    ->setGroupConfig('style', 'seamless');

add_action('acf/init', function () use ($global) {
    acf_add_local_field_group($global->build());
});

// //add certain settings to indivudal pages as well
// $post_settings = new FieldsBuilder('Post style settings');
// $post_settings
//     ->addTrueFalse('enable_settings_overrides')
//     ->addGroup('post_style_settings')
//     ->conditional('enable_settings_overrides', '==', '1')
//     ->addFields($header)
//     ->addFields($footer)
//     ->addFields($container)
//     ->endGroup()
//     ->setLocation('post_type', '==', 'page')
//     ->setGroupConfig('style', 'seamless');

// add_action('acf/init', function () use ($post_settings) {
//     acf_add_local_field_group($post_settings->build());
// });

//general settings

$all_blocks = fetch_blocks_from_api();
//get top level array keys
if (!empty($all_blocks))
    $themes = array_keys($all_blocks);
$blocks = new FieldsBuilder('blocks');
$blocks
    ->addTab("blocks")
    ->addSelect("blocks_theme", array(
        'choices' => $themes,
        'multiple' => 1,
        'ui' => 1,
    ))
    ->addUrl("blocks_api_url", array(
        'label' => 'API URL',
        'instructions' => 'The URL to the API endpoint that returns the blocks. if connected with vercel we need to autpop this. and have it greyedout?',
        'required' => 0,
    ));


$google_tag_manager = new FieldsBuilder('google_tag_manager');
$google_tag_manager
    ->addTab("google_tag_manager")
    ->addTrueFalse("google_tag_manager_enabled", array(
        'default_value' => 1,
    ))
    ->addText("google_tag_manager_id", array(
        'default_value' => 'GTM-XXXXXXX',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'google_tag_manager_enabled',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ));


$page_404 = new FieldsBuilder('page_404');
$page_404
    ->addTab("404")
    ->addPostObject('page_404', [
        'label' => 'Show page',
        'instructions' => 'This will show content from this page, with the default header and footer',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'enable_page_404',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
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
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'enable_coming_soon',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
        'post_type' => ['page'],
        'ui' => 1,
    ]);

$user_flow = new FieldsBuilder('user_flow');
$user_flow
    ->addTab("user_flow")
    ->addTrueFalse("enable_user_flow", [
        'ui' => 1,
    ])
    ->addTrueFalse("enable_login_redirect", [
        'instructions' => 'If enabled, users will be redirected to the login page if not logged in',
        'ui' => 1,
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'enable_user_flow',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ])
    ->addTextarea("email_domain_whitelist", [
        'instructions' => 'Only below domains will be allowed to register, one domain per line',
        'default_value' => 'pomegranate.co.uk',
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'enable_user_flow',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
    ])
    ->addPostObject('login_page', [
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'enable_user_flow',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
        'post_type' => ['page'],
        'ui' => 1,
    ])
    ->addPostObject('register_page', [
        'conditional_logic' => array(
            array(
                array(
                    'field' => 'enable_user_flow',
                    'operator' => '==',
                    'value' => '1',
                ),
            ),
        ),
        'post_type' => ['page'],
        'ui' => 1,
    ]);

$settings = new FieldsBuilder('General-settings');
$settings
    ->addFields($blocks)
    ->addFields($google_tag_manager)
    ->addFields($page_404)
    ->addFields($coming_soon)
    ->addFields($user_flow)
    ->setLocation('options_page', '==', 'acf-options-settings')
    ->setGroupConfig('style', 'seamless');

add_action('acf/init', function () use ($settings) {
    acf_add_local_field_group($settings->build());
});




function mytheme_setup_theme_supported_features()
{
    add_theme_support('editor-color-palette', array(
        array(
            'name'  => 'Primary',
            'slug'  => 'primary',
            'color' => get_field("primary_color", "option"),
        ),
        array(
            'name'  => 'Secondary',
            'slug'  => 'secondary',
            'color' => get_field("secondary_color", "option"),
        ),
        array(
            'name'  => 'Tertiary',
            'slug'  => 'tertiary',
            'color' => get_field("tertiary_color", "option"),
        ),
        array(
            'name'  => 'Quaternary',
            'slug'  => 'quaternary',
            'color' => get_field("quaternary_color", "option"),
        ),
    ));
}

add_action('after_setup_theme', 'mytheme_setup_theme_supported_features');
