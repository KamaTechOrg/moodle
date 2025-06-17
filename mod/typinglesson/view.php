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

echo $OUTPUT->header();

// URL for the background image
$backgroundurl = new moodle_url('/mod/typinglesson/pix/icon.jpg');

// Output div with background image
echo html_writer::start_tag('div', [
    'style' => 'background-image: url(' . $backgroundurl . '); 
                background-size: cover; 
                background-position: center; 
                width: 24px; 
                height: 24px; 
                border-radius: 10px; 
                margin-bottom: 20px;'
]);
echo html_writer::end_tag('div');

// Main heading
echo $OUTPUT->heading("Typing Lesson Content");

// Footer
echo $OUTPUT->footer();
