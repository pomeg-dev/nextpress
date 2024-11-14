<?php

defined('ABSPATH') or die('You do not have access to this file');

class NextPressGformsExtension
{
    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        add_filter("np_block_data", array($this, "include_gfdata_data"), 10, 2);
    }

    public function include_gfdata_data($block_data)
    {
        if (!isset($block_data['gravity_form'])) return $block_data;
        if (!class_exists('GFAPI')) return $block_data;
        $block_data['gfData'] = GFAPI::get_form(str_replace('form_id_', '', $block_data['gravity_form']));
        return $block_data;
    }
}

// Initialize the class
new NextPressGformsExtension();
