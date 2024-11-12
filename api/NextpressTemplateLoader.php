<?php

class TemplateLoader
{
    public static function get_template_blocks($post_id)
    {
        $post = get_post($post_id);

        // if (!$post || !in_array($post->post_type, array('wp_template', 'wp_template_part'))) {
        //     return new WP_Error('invalid_template', __('The specified post is not a valid template.'));
        // }

        $template = _build_block_template_result_from_post($post);

        if (is_wp_error($template)) {
            // If the template is not found in the database, try to get it from the file system
            $template_type = $post->post_type;
            $template_slug = $post->post_name;
            $template = get_block_file_template(get_stylesheet() . '//' . $template_slug, $template_type);

            if (is_null($template)) {
                return new WP_Error('template_not_found', __('Template not found in database or file system.'));
            }
        }

        // Parse the template content into blocks
        $blocks = parse_blocks($template->content);

        // Apply hooks to the blocks
        $hooked_blocks = get_hooked_blocks();
        if (!empty($hooked_blocks) || has_filter('hooked_block_types')) {
            $before_block_visitor = make_before_block_visitor($hooked_blocks, $template, 'insert_hooked_blocks');
            $after_block_visitor = make_after_block_visitor($hooked_blocks, $template, 'insert_hooked_blocks');
            $blocks = traverse_and_serialize_blocks($blocks, $before_block_visitor, $after_block_visitor);
            $blocks = parse_blocks($blocks); // Parse again to convert the serialized content back into a blocks array
        }

        return $blocks;
    }
}
