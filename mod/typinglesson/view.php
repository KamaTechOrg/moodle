<?php
require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('typinglesson', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

// Set page settings
$PAGE->set_url('/mod/typinglesson/view.php', ['id' => $id]);
$PAGE->set_title("Typing Lesson");
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/mod/typinglesson/css/icon.css');

echo $OUTPUT->header();

// Output div with background image using CSS class
echo html_writer::start_tag('div', ['class' => 'typinglesson-banner']);
echo html_writer::end_tag('div');

echo $OUTPUT->heading("Typing Lesson Content");

echo $OUTPUT->footer();
