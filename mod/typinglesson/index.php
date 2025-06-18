<?php

// Displays the plugin's main activity page and verifies that the user is logged in and has access 
// — currently populating sample content only

require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('typinglesson', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

$PAGE->set_url('/mod/typinglesson/index.php', ['id' => $id]);
$PAGE->set_title("Typing Lesson Index");
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading("Typing Lessons will be listed here");
echo $OUTPUT->footer();
