<?php

// Displays the form and edits the data.

require('../../config.php');
require_once($CFG->dirroot.'/mod/typinglesson/forms/addlesson_form.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
$PAGE->set_url('/mod/typinglesson/addlesson.php', ['courseid' => $courseid]);
$PAGE->set_context($context);
$PAGE->set_title('Add Typing Lesson');
$PAGE->set_heading($course->fullname);

$form = new mod_typinglesson_addlesson_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/typinglesson/view.php', ['id' => $courseid]));
} else if ($data = $form->get_data()) {
    global $DB;

    $record = new stdClass();
    $record->name = $data->name;
    $record->description = $data->description;
    $record->lesson_type_id = $data->lesson_type_id;
    $record->required_letters = $data->required_letters;
    $record->text_to_type = $data->text_to_type ?? null;
    $record->timecreated = time();

    $DB->insert_record('typing_lessons', $record);

    redirect(new moodle_url('/mod/typinglesson/view.php', ['id' => $courseid]), 'Lesson saved successfully', 2);
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Add Typing Lesson');
$form->display();
echo $OUTPUT->footer();
