<?php

// Displays the form and handles lesson addition.

require('../../config.php');
require_once($CFG->dirroot . '/mod/typinglesson/forms/addlesson_form.php');

$courseid = required_param('courseid', PARAM_INT);
// Activity ID
$cmid = required_param('cmid', PARAM_INT); 

$course = get_course($courseid);
$cm = get_coursemodule_from_id('typinglesson', $cmid, 0, false, MUST_EXIST);
$context = context_module::instance($cmid);

require_login($course, true, $cm);

$PAGE->set_url('/mod/typinglesson/addlesson.php', ['courseid' => $courseid, 'cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('addlesson', 'mod_typinglesson'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/mod/typinglesson/css/icon.css');

//You also passed the cmid so that the form knows how to make a correct referral.
$form = new mod_typinglesson_addlesson_form(null, ['courseid' => $courseid, 'cmid' => $cmid]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/typinglesson/view.php', ['id' => $cmid]));

} else if ($data = $form->get_data()) {
    global $DB;

    $record = new stdClass();
    $record->name = $data->name;
    $record->description = $data->description;
    $record->lesson_type_id = $data->lesson_type_id;
    $record->required_letters = $data->required_letters;
    $record->text_to_type = $data->text_to_type ?? '';
    $record->timecreated = time();

    $DB->insert_record('typing_lessons', $record);

    // Translated message, configurable in language file
    redirect(new moodle_url('/mod/typinglesson/view.php', ['id' => $cmid]),
        get_string('lessonsaved', 'mod_typinglesson'), 2);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('addlesson', 'mod_typinglesson'));
$form->display();
echo $OUTPUT->footer();
