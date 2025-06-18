<?php

// Displays the main page of the activity itself, including a design banner,
// content header, and preparations for a customized display of a typing lesson.

require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('typinglesson', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

$PAGE->set_url('/mod/typinglesson/view.php', ['id' => $id]);
$PAGE->set_title("Typing Lesson");
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/mod/typinglesson/css/icon.css');

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['class' => 'typinglesson-banner']);
echo html_writer::end_tag('div');

echo $OUTPUT->heading("Typing Lesson Content");

echo $OUTPUT->footer();
