<?php

function np_core_block_styles()
{
    wp_enqueue_script(
        'np-core-styles',
        plugin_dir_url(dirname(__FILE__)) . 'js/core-blocks.js',
        array( 'wp-blocks' ),
        filemtime(plugin_dir_url(dirname(__FILE__)) . 'js/core-blocks.js'),
        true
    );
}
add_action('enqueue_block_editor_assets', 'np_core_block_styles');
