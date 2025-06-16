<?php
require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('typinglesson', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

// Set the URL, title, and heading for the page
$PAGE->set_url('/mod/typinglesson/view.php', ['id' => $id]);
$PAGE->set_title("Typing Lesson");
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

// Display the icon image located in the mod's pix folder
// Create a moodle_url pointing to the image file
$iconurl = new moodle_url('/mod/typinglesson/pix/icon.jpg');

// Output the image tag with inline CSS to control size (width and height)
echo '<div style="text-align:center; margin-bottom:20px;">';
echo html_writer::empty_tag('img', ['src' => $iconurl, 'style' => 'width:80px; height:80px;']);
echo '</div>';

// Output the main heading of the content
echo $OUTPUT->heading("Typing Lesson Content");

// Output the page footer
echo $OUTPUT->footer();
