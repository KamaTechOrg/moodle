<?php

// Defines system functions that allow Moodle to understand how to deal with the typinglesson module,
// including feature support, loading designs, and an icon in the activity.
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