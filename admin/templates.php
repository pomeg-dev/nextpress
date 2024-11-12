<?php

use StoutLogic\AcfBuilder\FieldsBuilder;

// Add menus
add_action('acf/init', function () {
    if (function_exists('acf_add_options_page')) {
        acf_add_options_sub_page(array(
            'page_title'    => 'Templates',
            'menu_title'    => 'Templates',
            'parent_slug'   => 'nextpress',
            'menu_slug'     => 'templates',
        ));
    }
});



// Function to get all public post types
function get_public_post_types()
{
    $args = array(
        'public'   => true,
    );
    $post_types = get_post_types($args, 'names', 'and');
    return $post_types;
}

// Function to get all categories
function get_all_categories()
{
    $categories = get_categories(array(
        'orderby' => 'name',
        'order'   => 'ASC',
        'hide_empty' => false,
    ));
    $category_choices = array('');  // Adding an empty option
    foreach ($categories as $category) {
        $category_choices[$category->term_id] = $category->name;
    }
    return $category_choices;
}

function create_block_layouts()
{
    $theme = get_field('blocks_theme', 'option');

    $blocks = fetch_blocks_from_api($theme);
    $layouts = [];

    foreach ($blocks as $block) {
        $layout = new FieldsBuilder($block['id']);
        $layout = build_acf_fields($block['fields'], $layout);
        $layouts[$block['id']] = $layout;
    }

    return $layouts;
}

// Function to create template fields for the default option
function create_default_template_fields()
{
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

    // Add layouts to flexible content fields
    $block_layouts = create_block_layouts();
    foreach ($block_layouts as $name => $layout) {
        $template
            ->getField("default_before_content")
            ->addLayout($layout);
        $template
            ->getField("default_after_content")
            ->addLayout($layout);
    }

    return $template;
}

// Function to create template fields for a post type (remains the same)
function create_template_fields($post_type)
{
    $template = new FieldsBuilder("{$post_type}_templates");

    $template
        ->addRepeater("{$post_type}_content_templates", ['label' => 'Content Templates', 'layout' => 'block'])
        ->addSelect('category', [
            'label' => 'Category (optional)',
            'choices' => get_all_categories(),
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
        ->endRepeater();

    // Add layouts to flexible content fields
    $block_layouts = create_block_layouts();
    foreach ($block_layouts as $name => $layout) {
        $template
            ->getField("{$post_type}_content_templates")
            ->getField('before_content')
            ->addLayout($layout);
        $template
            ->getField("{$post_type}_content_templates")
            ->getField('after_content')
            ->addLayout($layout);
    }

    return $template;
}

// Create tabs and fields for each post type
$templates = new FieldsBuilder('templates');

// Add a Default tab first
$templates
    ->addTab('Default')
    ->addFields(create_default_template_fields());

// Then add tabs for each post type
$post_types = get_public_post_types();

foreach ($post_types as $post_type) {
    $templates
        ->addTab(ucfirst($post_type))
        ->addFields(create_template_fields($post_type));
}

$templates
    ->setLocation('options_page', '==', 'templates')
    ->setGroupConfig('style', 'seamless');

add_action('acf/init', function () use ($templates) {
    acf_add_local_field_group($templates->build());
});
