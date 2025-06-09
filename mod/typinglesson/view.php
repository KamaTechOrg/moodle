<?php
require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('typinglesson', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

$PAGE->set_url('/mod/typinglesson/view.php', ['id' => $id]);
$PAGE->set_title("Typing Lesson");
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading("Typing Lesson Content");
echo $OUTPUT->footer();
