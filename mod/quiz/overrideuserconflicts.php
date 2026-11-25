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
 * Conflict resolution UI for a user override vs existing group overrides.
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

$sessionkey = "mod_quiz_user_override_conflict_draft_{$draftkey}";
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
$editingid = !empty($SESSION->$sessionkey['editingid']) ? (int)$SESSION->$sessionkey['editingid'] : 0;

$fields = ['timelimit', 'timeclose', 'timeopen', 'attempts'];
$requesteduseroverride = [];
foreach ($fields as $f) {
    $requesteduseroverride[$f] = property_exists($draft, $f) ? $draft->{$f} : null;
}

$payload = override_conflict_db_service::build_user_conflict_choice_list(
    $quiz,
    (int)$draft->userid,
    $requesteduseroverride,
    $fields
);

$returnurl = new moodle_url('/mod/quiz/overrideedit.php', ['cmid' => $cmid]);
$PAGE->set_url(new moodle_url('/mod/quiz/overrideuserconflicts.php', ['cmid' => $cmid, 'draft' => $draftkey]));
$PAGE->set_pagelayout('admin');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title(get_string('overrides', 'quiz'));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();

$mform = new override_conflicts_form($PAGE->url, [
    'users' => $payload['users'] ?? [],
    'returnurl' => $returnurl->out(false),
    'title' => get_string('useroverrides', 'quiz'),
]);

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    require_sesskey();
    $choice = 'use_user';
    if (!empty($data->choice) && is_array($data->choice)) {
        foreach ($data->choice as $uid => $c) {
            $choice = (string)$c;
            break;
        }
    }

    // Adjust the user override submission based on the chosen resolution, then save.
    $fromform = new stdClass();
    foreach (get_object_vars($draft) as $k => $v) {
        $fromform->{$k} = $v;
    }

    if ($choice === 'use_group') {
        foreach ($fields as $f) {
            $fromform->{$f} = null;
        }
    } else if ($choice === 'use_both') {
        $effectivegroup = $payload['users'][0]['values']['group'] ?? [];
        $basevalues = [];
        $uservalues = [];
        foreach ($fields as $f) {
            $basevalues[$f] = $quiz->{$f};
            $uservalues[$f] = property_exists($fromform, $f) ? $fromform->{$f} : null;
        }
        $both = \mod_quiz\local\override_conflict_resolver::apply_both_merge_relative_to_base($basevalues, $uservalues, $effectivegroup, $fields);
        foreach ($fields as $f) {
            $fromform->{$f} = $both[$f];
        }
    }

    $editing = null;
    if ($editingid) {
        $editing = $DB->get_record('quiz_overrides', ['id' => $editingid], '*', MUST_EXIST);
        $editing->id = (int)$editing->id;
        $editing->userid = (int)$editing->userid;
        $editing->groupid = null;
    }

    quiz_save_override_submission($quiz, $cm, $context, $fromform, $editing, false);

    unset($SESSION->$sessionkey);
    redirect(new moodle_url('/mod/quiz/overrides.php', ['cmid' => $cmid, 'mode' => 'user']));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('useroverrides', 'quiz'), 3);
$mform->display();
echo $OUTPUT->footer();

