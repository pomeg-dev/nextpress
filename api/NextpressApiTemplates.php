<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextpressApiTemplates
{
    public function __construct()
    {
        $this->_init();
    }

    public static function _init()
    {
        // Add any initialization logic here
        // For example, you might want to add actions or filters
        add_action('init', array('NextpressApiTemplates', 'register_template_routes'));
    }

    public static function register_template_routes()
    {
        // Register REST API route for default templates
        add_action('rest_api_init', function () {
            register_rest_route('nextpress', '/default-template', array(
                'methods' => 'GET',
                'callback' => array('NextpressApiTemplates', 'get_default_template_content'),
                'permission_callback' => '__return_true'
            ));
        });
    }

    public static function get_default_template_content()
    {
        $default_before_content = get_field("default_before_content", 'option');
        $default_after_content = get_field("default_after_content", 'option');

        return array(
            'before_content' => self::format_flexible_content($default_before_content),
            'after_content' => self::format_flexible_content($default_after_content)
        );
    }

    private static function format_flexible_content($flexible_content)
    {
        if (!is_array($flexible_content)) {
            return [];
        }

        $formatted_content = [];

        foreach ($flexible_content as $block) {
            $block_data = isset($block['attrs']['data']) ? $block['attrs']['data'] : [];
            $formatted_block = [
                'id' => uniqid('acf_'),
                'blockName' => 'acf/' . $block['acf_fc_layout'],
                'slug' => 'acf-' . str_replace('_', '-', $block['acf_fc_layout']),
                'innerHTML' => '',
                'innerContent' => [],
                'type' => [
                    'id' => 0,
                    'name' => ucfirst(str_replace('_', ' ', $block['acf_fc_layout'])),
                    'slug' => 'acf/' . str_replace('_', '-', $block['acf_fc_layout'])
                ],
                'parent' => 0,
                'innerBlocks' => [],
                'data' => apply_filters("np_block_data", $block_data, $block)
            ];

            $formatted_content[] = $formatted_block;
        }

        return $formatted_content;
    }
}
