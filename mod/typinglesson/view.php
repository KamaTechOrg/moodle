<?php

// Displays the main page of the activity itself, including a design banner,
// content header, and preparations for a customized display of a typing lesson.

require('../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('typinglesson', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_url('/mod/typinglesson/view.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'mod_typinglesson'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css('/mod/typinglesson/css/icon.css');

echo $OUTPUT->header();

// A button that will lead to the Add Lesson page
$url = new moodle_url('/mod/typinglesson/addlesson.php', [
    'courseid' => $course->id,
    'cmid' => $cm->id
]);
echo $OUTPUT->single_button($url, get_string('addlesson', 'mod_typinglesson'));

// Build the correct URL for the banner icon from the pix folder
$iconurl = $OUTPUT->image_url('icon', 'mod_typinglesson');
$heading = html_writer::empty_tag('img', [
    'src' => $iconurl,
    'alt' => '',
    'class' => 'typinglesson-heading-icon',
    'style' => 'width: 30px; height: 30px; vertical-align: middle; margin-right: 8px;'
]);
$heading .= get_string('lessoncontentheading', 'mod_typinglesson');
echo $OUTPUT->heading($heading);

// Fetch and display lessons
$lessons = $DB->get_records('typing_lessons');

if ($lessons) {
    echo html_writer::start_tag('h3');
    echo get_string('availablelessons', 'mod_typinglesson');
    echo html_writer::end_tag('h3');

    echo html_writer::start_tag('table', ['class' => 'typinglesson-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', get_string('typinglessonname', 'mod_typinglesson'));
    echo html_writer::tag('th', get_string('description'));
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    echo html_writer::start_tag('tbody');
    foreach ($lessons as $lesson) {
        $name = format_string($lesson->name);
        $description = format_text($lesson->description);
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', $name);
        echo html_writer::tag('td', $description);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo $OUTPUT->notification(get_string('nolessons', 'mod_typinglesson'), 'notifyproblem');
}

echo $OUTPUT->footer();
