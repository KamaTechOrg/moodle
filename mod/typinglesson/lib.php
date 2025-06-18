<?php
defined('MOODLE_INTERNAL') || die();

function typinglesson_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_BACKUP_MOODLE2:
            return true;
        default:
            return null;
    }
}

// Load a custom CSS file before the page's HTML is loaded — 
// for control over the design of pages related to the touch typing plugin.
function typinglesson_before_standard_html_head() {
    global $PAGE;
    $PAGE->requires->css('/mod/typinglesson/css/icon.css');
}

//Return information about the Activity (the instance of the module in the course)
function typinglesson_get_coursemodule_info($coursemodule) {
    $info = new cached_cm_info();
    $info->icon = new moodle_url('/mod/typinglesson/pix/icon.svg');
    return $info;
}
