<?php

defined('ABSPATH') or die('You do not have access to this file');

require_once(plugin_dir_path(__FILE__) . 'NextpressTemplateLoader.php');

class NextpressPostFormatter
{
    public static function format_post($post, $include_content = false)
    {
        $formatted_post = array(
            'id' => $post->ID,
            'slug' => self::get_slug($post),
            'type' => self::get_post_type($post),
            'status' => $post->post_status,
            'date' => $post->post_date,
            'title' => $post->post_title,
            'excerpt' => $post->post_excerpt,
            'image' => self::get_post_image($post),
            'categories' => self::get_post_categories($post),
            'tags' => self::get_post_tags($post),
            'related_posts' => self::get_related_posts($post),
            'password' => $post->post_password,
        );

        if ($include_content) {
            $template = self::get_template_content($post);
            $formatted_post['template'] = $template;
            $formatted_post['content'] = self::parse_block_data($post->post_content);
        }

        $formatted_post = self::include_featured_image($formatted_post);
        $formatted_post = self::include_author_name($formatted_post);
        $formatted_post = self::include_is_homepage($formatted_post);
        $formatted_post = self::include_category_names($formatted_post);
        $formatted_post = self::include_post_path($formatted_post);
        $formatted_post = self::return_post_revision_for_preview($formatted_post);

        return apply_filters('np_post_object', $formatted_post);
    }

    private static function get_template_content($post)
    {
        $post_type = get_post_type($post);
        $post_categories = wp_get_post_categories($post->ID, ['fields' => 'ids']);

        // Try to get post type specific templates
        $templates = get_field("{$post_type}_content_templates", 'option');

        if ($templates) {
            $default_template = null;

            foreach ($templates as $template) {
                if (empty($template['category'])) {
                    $default_template = $template;
                } elseif (in_array($template['category'], $post_categories)) {
                    return array(
                        'before_content' => self::format_flexible_content($template['before_content']),
                        'after_content' => self::format_flexible_content($template['after_content'])
                    );
                }
            }

            // If no category-specific template was found, use the default for this post type
            if ($default_template) {
                return array(
                    'before_content' => self::format_flexible_content($default_template['before_content']),
                    'after_content' => self::format_flexible_content($default_template['after_content'])
                );
            }
        }

        // If no template found for the post type, use the global default
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
            $formatted_block = [
                'id' => uniqid('acf_'), // Generate a unique ID for ACF blocks
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
                // 'data' => apply_filters("np_block_data", $block['attrs']),
                'data' => apply_filters("np_block_data", $block['attrs']['data'], $block),
            ];

            $formatted_content[] = $formatted_block;
        }

        return $formatted_content;
    }


    private static function get_slug($post)
    {
        return array(
            'slug' => $post->post_name,
            'full_path' => self::get_full_path($post),
        );
    }


    private static function get_full_path($post)
    {
        $permalink = get_permalink($post);
        return str_replace(home_url(), '', $permalink);
    }

    private static function get_post_type($post)
    {
        $post_type = get_post_type_object($post->post_type);
        return array(
            'id' => $post_type->name,
            'name' => $post_type->labels->singular_name,
            'slug' => $post_type->rewrite['slug'],
        );
    }

    private static function get_post_image($post)
    {
        $image_id = get_post_thumbnail_id($post->ID);
        if (!$image_id) {
            return null;
        }

        return array(
            'full' => wp_get_attachment_image_url($image_id, 'full'),
            'thumbnail' => wp_get_attachment_image_url($image_id, 'thumbnail'),
        );
    }

    private static function get_post_categories($post)
    {
        $categories = wp_get_post_categories($post->ID, array('fields' => 'all'));
        return array_map(function ($category) {
            return array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
            );
        }, $categories);
    }

    private static function get_post_tags($post)
    {
        $tags = wp_get_post_tags($post->ID);
        return array_map(function ($tag) {
            return array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            );
        }, $tags);
    }

    private static function get_related_posts($post)
    {
        // Implement your own logic to get related posts
        // For this example, we'll just return an empty array
        return array();
    }

    private static function parse_block_data($content)
    {
        $blocks = parse_blocks($content);
        return self::format_blocks($blocks);
    }

    private static function format_blocks($blocks, $parent = 0)
    {
        $formatted_blocks = array();

        //remove all blockName = null
        $blocks = array_filter($blocks, function ($block) {
            return $block['blockName'] !== null;
        });

        foreach ($blocks as $index => $block) {

            $formatted_block = array(
                'id' => $block['id'],
                'blockName' => $block['blockName'],
                'slug' => sanitize_title($block['blockName']),
                'innerHTML' => $block['innerHTML'],
                'innerContent' => $block['innerContent'],
                'type' => array(
                    'id' => 0,
                    'name' => ucfirst(str_replace('core/', '', $block['blockName'])),
                    'slug' => str_replace('core/', '', $block['blockName']),
                ),
                'parent' => $parent,
                'innerBlocks' => array(),
                'data' => apply_filters("np_block_data", $block['attrs']['data'], $block),
            );


            //handle innerBlocks (default wp blocks like group etc)
            if (!empty($block['innerBlocks'])) {
                $formatted_block['innerBlocks'] = self::format_blocks($block['innerBlocks'], $formatted_block['id']);
            }

            //handle reusable blocks/patterns
            if ($block['blockName'] == 'core/block' && $block['attrs']['ref']) {
                $pattern_blocks = parse_blocks(get_post($block['attrs']['ref'])->post_content);
                $formatted_block['innerBlocks'] = self::format_blocks($pattern_blocks, $formatted_block['id']);
                $sdlknf = "sdfdsf";
            }

            $formatted_blocks[] = $formatted_block;
        }

        return $formatted_blocks;
    }

    private static function include_featured_image($formatted_post)
    {
        $formatted_post['featured_image'] = array(
            'url' => get_the_post_thumbnail_url($formatted_post['id']),
            'sizes' => array(
                'thumbnail' => get_the_post_thumbnail_url($formatted_post['id'], 'thumbnail'),
                'medium' => get_the_post_thumbnail_url($formatted_post['id'], 'medium'),
                'large' => get_the_post_thumbnail_url($formatted_post['id'], 'large'),
                'full' => get_the_post_thumbnail_url($formatted_post['id'], 'full'),
            )
        );
        return $formatted_post;
    }

    private static function include_author_name($formatted_post)
    {
        $formatted_post['author'] = get_the_author_meta("display_name", $formatted_post['id']);
        return $formatted_post;
    }

    private static function include_is_homepage($formatted_post)
    {
        $formatted_post['is_homepage'] = is_home() || is_front_page();
        return $formatted_post;
    }

    private static function include_category_names($formatted_post)
    {
        $formatted_post['category_names'] = wp_get_post_categories($formatted_post['id'], array('fields' => 'names'));
        return $formatted_post;
    }

    private static function include_post_path($formatted_post)
    {
        $base_url = site_url();
        $post = get_post($formatted_post['id']);

        if (in_array($post->post_status, array('draft', 'pending', 'auto-draft', 'future', 'private'))) {
            $my_post = clone $post;
            $my_post->post_status = 'publish';
            $my_post->post_name = sanitize_title(
                $my_post->post_name ? $my_post->post_name : $my_post->post_title,
                $my_post->ID
            );
            $permalink = get_permalink($my_post);
        } else {
            $permalink = get_permalink($post);
        }

        $formatted_post['path'] = str_replace($base_url, '', $permalink);
        $formatted_post['wordpress_path'] = get_permalink($post->ID);

        return $formatted_post;
    }

    private static function return_post_revision_for_preview($formatted_post)
    {
        $post = get_post($formatted_post['id']);
        if ($post->post_type == "revision") {
            $parent_post = get_post($post->post_parent);
            $revisions = wp_get_post_revisions($parent_post->ID);
            if (!empty($revisions)) {
                krsort($revisions);
                $latest_revision = reset($revisions);
                return self::format_post($latest_revision);
            }
        }
        return $formatted_post;
    }
}
