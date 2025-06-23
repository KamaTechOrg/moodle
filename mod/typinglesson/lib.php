<?php

// Defines system functions that allow Moodle to understand how to deal with the typinglesson module,
// including feature support, loading designs, and basic lifecycle functions.
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
// for control over the design of pages related to the typinglesson module.
function typinglesson_before_standard_html_head() {
    global $PAGE;
    $PAGE->requires->css('/mod/typinglesson/css/icon.css');
}

// Return information about the activity (the instance of the module in the course).
// No need to manually define $info->icon – Moodle will handle it automatically if pix/icon.svg or icon.png exist.
function typinglesson_get_coursemodule_info($coursemodule) {
    return new cached_cm_info();
}

// Handles creation of a new activity instance.
function typinglesson_add_instance($data, $mform = null) {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = time();
    return $DB->insert_record('typinglesson', $data);
}

// Handles updates to an existing activity instance.
function typinglesson_update_instance($data, $mform = null) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;
    return $DB->update_record('typinglesson', $data);
}

// Handles deletion of an activity instance.
function typinglesson_delete_instance($id) {
    global $DB;

    if (!$record = $DB->get_record('typinglesson', ['id' => $id])) {
        return false;
    }

    return $DB->delete_records('typinglesson', ['id' => $id]);
}
