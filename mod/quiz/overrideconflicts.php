<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Conflict resolution UI for quiz overrides (group override vs existing user overrides).
 *
 * This page is opened from overrideedit.php when a group override submission would
 * affect users who already have user overrides. It lets the teacher choose, per user:
 * - use user override
 * - use group override
 * - use both (user + group)
 *
 * @package mod_quiz
 */

use mod_quiz\form\override_conflicts_form;
use mod_quiz\local\override_conflict_db_service;
use mod_quiz\quiz_settings;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

global $SESSION;

$cmid = required_param('cmid', PARAM_INT);
$draftkey = required_param('draft', PARAM_ALPHANUMEXT);

$sessionkey = "mod_quiz_override_conflict_draft_{$draftkey}";
if (empty($SESSION->$sessionkey) || empty($SESSION->$sessionkey['cmid']) || (int)$SESSION->$sessionkey['cmid'] !== $cmid) {
    redirect(new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cmid]));
}

$quizobj = quiz_settings::create_for_cmid($cmid);
$quiz = $quizobj->get_quiz();
$cm = $quizobj->get_cm();
$course = $quizobj->get_course();
$context = $quizobj->get_context();

require_login($course, false, $cm);
require_capability('mod/quiz:manageoverrides', $context);

$draft = (object)$SESSION->$sessionkey['fromform'];

// Build the shared conflict list using the shared DB service + pure resolver.
$requested = [
    'groupid' => (int)$draft->groupid,
    'timelimit' => property_exists($draft, 'timelimit') ? $draft->timelimit : null,
    'timeclose' => property_exists($draft, 'timeclose') ? $draft->timeclose : null,
];
$conflicts = override_conflict_db_service::build_group_conflict_choice_list(
    (int)$quiz->id,
    (int)$draft->groupid,
    $requested,
    ['timelimit', 'timeclose']
);
if (!empty($conflicts['users'])) {
    $conflicts['users'] = override_conflict_db_service::decorate_users_with_identity_labels($conflicts['users']);
}

$returnurl = new moodle_url('/mod/quiz/overrideedit.php', ['cmid' => $cmid]);
$PAGE->set_url(new moodle_url('/mod/quiz/overrideconflicts.php', ['cmid' => $cmid, 'draft' => $draftkey]));
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title(get_string('overrides', 'quiz'));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

$mform = new override_conflicts_form($PAGE->url, [
    'users' => $conflicts['users'] ?? [],
    'returnurl' => $returnurl->out(false),
    'title' => get_string('groupoverrides', 'quiz'),
]);

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    require_sesskey();
    $selections = [];
    if (!empty($data->choice) && is_array($data->choice)) {
        foreach ($data->choice as $userid => $choice) {
            $selections[(int)$userid] = (string)$choice;
        }
    }

    // Apply: save the group override, then apply teacher-selected updates to user overrides.
    $groupfromform = new stdClass();
    $groupfromform->userid = null;
    $groupfromform->groupid = (int)$draft->groupid;
    $groupfromform->timelimit = $draft->timelimit;
    $groupfromform->timeclose = $draft->timeclose;

    override_conflict_db_service::apply_group_override_with_conflict_resolutions(
        $quiz,
        $cm,
        $context,
        $groupfromform,
        $selections,
        ['timelimit', 'timeclose']
    );

    unset($SESSION->$sessionkey);

    redirect(new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cmid, 'mode' => 'group']));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('groupoverrides', 'quiz'), 3);
$mform->display();
echo $OUTPUT->footer();

