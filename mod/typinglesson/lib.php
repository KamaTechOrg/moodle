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

function typinglesson_before_standard_html_head() {
    global $PAGE;
    $PAGE->requires->css('/mod/typinglesson/styles/styles.css');
}
