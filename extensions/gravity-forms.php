<?php

add_filter("ng_block_data", "include_gravity_form_data");
function include_gravity_form_data($blocks)
{
    if (!class_exists('GFAPI')) return $blocks;
    foreach ($blocks as &$block) {
        if ($block['blockName'] === 'gravityforms/form') {
            $block['gfData'] = GFAPI::get_form($block['attrs']['formId']);
            $block['gfDataInnerHtml'] = do_shortcode("[gravityform id='" . $block['attrs']['formId'] . "' title='false' description='false' ajax='true']");
        }
    }
    return $blocks;
}
