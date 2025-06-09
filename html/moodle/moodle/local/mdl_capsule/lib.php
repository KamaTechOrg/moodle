<?php

defined('MOODLE_INTERNAL') || die();


function mdl_capsule_insert_or_update($data) {
    global $DB;

    // נניח שהמזהה הייחודי הוא 'capsuleid'
    $existing = $DB->get_record('capsules', ['capsuleid' => $data->capsuleid]);

    if ($existing) {
        $data->id = $existing->id;
        return $DB->update_record('capsules', $data);
    } else {
        return $DB->insert_record('capsules', $data);
    }
}

function mdl_capsule_courses_log_upsert($data) {
    global $DB;

    // בדיקה אם יש כבר רשומה עם אותו capsuleid ו-courseid
    $existing = $DB->get_record('capsule_courses_log', [
        'capsuleid' => $data->capsuleid,
        'courseid'  => $data->courseid
    ]);

    if ($existing) {
        $data->id = $existing->id;
        return $DB->update_record('capsule_courses_log', $data);
    } else {
        return $DB->insert_record('capsule_courses_log', $data);
    }
}

