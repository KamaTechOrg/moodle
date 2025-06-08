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
 * Quiz external API
 *
 * @package    mod_quiz
 * @category   external
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */

use core_course\external\helper_for_get_mods_by_courses;
use core_external\external_api;
use core_external\external_files;
use core_external\external_format_value;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use core_external\util;
use mod_quiz\access_manager;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/question/engine/bank.php');


/**
 * Quiz external functions
 *
 * @package    mod_quiz
 * @category   external
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */
class mod_quiz_external extends external_api {

    /**
     * Describes the parameters for get_quizzes_by_courses.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_quizzes_by_courses_parameters() {
        return new external_function_parameters (
            [
                'courseids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'course id'), 'Array of course ids', VALUE_DEFAULT, []
                ),
            ]
        );
    }

    /**
     * Returns a list of quizzes in a provided list of courses,
     * if no list is provided all quizzes that the user can view will be returned.
     *
     * @param array $courseids Array of course ids
     * @return array of quizzes details
     * @since Moodle 3.1
     */
    public static function get_quizzes_by_courses($courseids = []) {
        global $USER;

        $warnings = [];
        $returnedquizzes = [];

        $params = [
            'courseids' => $courseids,
        ];
        $params = self::validate_parameters(self::get_quizzes_by_courses_parameters(), $params);

        $mycourses = [];
        if (empty($params['courseids'])) {
            $mycourses = enrol_get_my_courses();
            $params['courseids'] = array_keys($mycourses);
        }

        // Ensure there are courseids to loop through.
        if (!empty($params['courseids'])) {

            list($courses, $warnings) = util::validate_courses($params['courseids'], $mycourses);

            // Get the quizzes in this course, this function checks users visibility permissions.
            // We can avoid then additional validate_context calls.
            $quizzes = get_all_instances_in_courses("quiz", $courses);
            foreach ($quizzes as $quiz) {
                $context = context_module::instance($quiz->coursemodule);

                // Update quiz with override information.
                $quiz = quiz_update_effective_access($quiz, $USER->id);

                // Entry to return.
                $quizdetails = helper_for_get_mods_by_courses::standard_coursemodule_element_values(
                        $quiz, 'mod_quiz', 'mod/quiz:view', 'mod/quiz:view');

                if (has_capability('mod/quiz:view', $context)) {
                    $quizdetails['introfiles'] = util::get_area_files($context->id, 'mod_quiz', 'intro', false, false);
                    $viewablefields = ['timeopen', 'timeclose', 'attempts', 'timelimit', 'grademethod', 'decimalpoints',
                                            'questiondecimalpoints', 'sumgrades', 'grade', 'preferredbehaviour'];

                    // Sometimes this function returns just empty.
                    $hasfeedback = quiz_has_feedback($quiz);
                    $quizdetails['hasfeedback'] = (!empty($hasfeedback)) ? 1 : 0;

                    $timenow = time();
                    $quizobj = quiz_settings::create($quiz->id, $USER->id);
                    $accessmanager = new access_manager($quizobj, $timenow, has_capability('mod/quiz:ignoretimelimits',
                                                                $context, null, false));

                    // Fields the user could see if have access to the quiz.
                    if (!$accessmanager->prevent_access()) {
                        $quizdetails['hasquestions'] = (int) $quizobj->has_questions();
                        $quizdetails['autosaveperiod'] = get_config('quiz', 'autosaveperiod');

                        $additionalfields = ['attemptonlast', 'reviewattempt', 'reviewcorrectness', 'reviewmaxmarks', 'reviewmarks',
                                                    'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer',
                                                    'reviewoverallfeedback', 'questionsperpage', 'navmethod',
                                                    'browsersecurity', 'delay1', 'delay2', 'showuserpicture', 'showblocks',
                                                    'completionattemptsexhausted', 'overduehandling',
                                                    'graceperiod', 'canredoquestions', 'allowofflineattempts'];
                        $viewablefields = array_merge($viewablefields, $additionalfields);

                        // Any course module fields that previously existed in quiz.
                        $quizdetails['completionpass'] = $quizobj->get_cm()->completionpassgrade;
                    }

                    // Fields only for managers.
                    if (has_capability('moodle/course:manageactivities', $context)) {
                        $additionalfields = ['shuffleanswers', 'timecreated', 'timemodified', 'password', 'subnet'];
                        $viewablefields = array_merge($viewablefields, $additionalfields);
                    }

                    foreach ($viewablefields as $field) {
                        $quizdetails[$field] = $quiz->{$field};
                    }
                }
                $returnedquizzes[] = $quizdetails;
            }
        }
        $result = [];
        $result['quizzes'] = $returnedquizzes;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_quizzes_by_courses return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_quizzes_by_courses_returns() {
        return new external_single_structure(
            [
                'quizzes' => new external_multiple_structure(
                    new external_single_structure(array_merge(
                        helper_for_get_mods_by_courses::standard_coursemodule_elements_returns(true),
                        [
                            'timeopen' => new external_value(PARAM_INT, 'The time when this quiz opens. (0 = no restriction.)',
                                                                VALUE_OPTIONAL),
                            'timeclose' => new external_value(PARAM_INT, 'The time when this quiz closes. (0 = no restriction.)',
                                                                VALUE_OPTIONAL),
                            'timelimit' => new external_value(PARAM_INT, 'The time limit for quiz attempts, in seconds.',
                                                                VALUE_OPTIONAL),
                            'overduehandling' => new external_value(PARAM_ALPHA, 'The method used to handle overdue attempts.
                                                                    \'autosubmit\', \'graceperiod\' or \'autoabandon\'.',
                                                                    VALUE_OPTIONAL),
                            'graceperiod' => new external_value(PARAM_INT, 'The amount of time (in seconds) after the time limit
                                                                runs out during which attempts can still be submitted,
                                                                if overduehandling is set to allow it.', VALUE_OPTIONAL),
                            'preferredbehaviour' => new external_value(PARAM_ALPHANUMEXT, 'The behaviour to ask questions to use.',
                                                                        VALUE_OPTIONAL),
                            'canredoquestions' => new external_value(PARAM_INT, 'Allows students to redo any completed question
                                                                        within a quiz attempt.', VALUE_OPTIONAL),
                            'attempts' => new external_value(PARAM_INT, 'The maximum number of attempts a student is allowed.',
                                                                VALUE_OPTIONAL),
                            'attemptonlast' => new external_value(PARAM_INT, 'Whether subsequent attempts start from the answer
                                                                    to the previous attempt (1) or start blank (0).',
                                                                    VALUE_OPTIONAL),
                            'grademethod' => new external_value(PARAM_INT, 'One of the values QUIZ_GRADEHIGHEST, QUIZ_GRADEAVERAGE,
                                                                    QUIZ_ATTEMPTFIRST or QUIZ_ATTEMPTLAST.', VALUE_OPTIONAL),
                            'decimalpoints' => new external_value(PARAM_INT, 'Number of decimal points to use when displaying
                                                                    grades.', VALUE_OPTIONAL),
                            'questiondecimalpoints' => new external_value(PARAM_INT, 'Number of decimal points to use when
                                                                            displaying question grades.
                                                                            (-1 means use decimalpoints.)', VALUE_OPTIONAL),
                            'reviewattempt' => new external_value(PARAM_INT, 'Whether users are allowed to review their quiz
                                                                    attempts at various times. This is a bit field, decoded by the
                                                                    \mod_quiz\question\display_options class. It is formed by ORing
                                                                    together the constants defined there.', VALUE_OPTIONAL),
                            'reviewcorrectness' => new external_value(PARAM_INT, 'Whether users are allowed to review their quiz
                                                       attempts at various times.A bit field, like reviewattempt.', VALUE_OPTIONAL),
                            'reviewmaxmarks' => new external_value(PARAM_INT, 'Whether users are allowed to review their quiz
                                                  attempts at various times. A bit field, like reviewattempt.', VALUE_OPTIONAL),
                            'reviewmarks' => new external_value(PARAM_INT, 'Whether users are allowed to review their quiz attempts
                                                                at various times. A bit field, like reviewattempt.',
                                                                VALUE_OPTIONAL),
                            'reviewspecificfeedback' => new external_value(PARAM_INT, 'Whether users are allowed to review their
                                                                            quiz attempts at various times. A bit field, like
                                                                            reviewattempt.', VALUE_OPTIONAL),
                            'reviewgeneralfeedback' => new external_value(PARAM_INT, 'Whether users are allowed to review their
                                                                            quiz attempts at various times. A bit field, like
                                                                            reviewattempt.', VALUE_OPTIONAL),
                            'reviewrightanswer' => new external_value(PARAM_INT, 'Whether users are allowed to review their quiz
                                                                        attempts at various times. A bit field, like
                                                                        reviewattempt.', VALUE_OPTIONAL),
                            'reviewoverallfeedback' => new external_value(PARAM_INT, 'Whether users are allowed to review their quiz
                                                                            attempts at various times. A bit field, like
                                                                            reviewattempt.', VALUE_OPTIONAL),
                            'questionsperpage' => new external_value(PARAM_INT, 'How often to insert a page break when editing
                                                                        the quiz, or when shuffling the question order.',
                                                                        VALUE_OPTIONAL),
                            'navmethod' => new external_value(PARAM_ALPHA, 'Any constraints on how the user is allowed to navigate
                                                                around the quiz. Currently recognised values are
                                                                \'free\' and \'seq\'.', VALUE_OPTIONAL),
                            'shuffleanswers' => new external_value(PARAM_INT, 'Whether the parts of the question should be shuffled,
                                                                    in those question types that support it.', VALUE_OPTIONAL),
                            'sumgrades' => new external_value(PARAM_FLOAT, 'The total of all the question instance maxmarks.',
                                                                VALUE_OPTIONAL),
                            'grade' => new external_value(PARAM_FLOAT, 'The total that the quiz overall grade is scaled to be
                                                            out of.', VALUE_OPTIONAL),
                            'timecreated' => new external_value(PARAM_INT, 'The time when the quiz was added to the course.',
                                                                VALUE_OPTIONAL),
                            'timemodified' => new external_value(PARAM_INT, 'Last modified time.',
                                                                    VALUE_OPTIONAL),
                            'password' => new external_value(PARAM_RAW, 'A password that the student must enter before starting or
                                                                continuing a quiz attempt.', VALUE_OPTIONAL),
                            'subnet' => new external_value(PARAM_RAW, 'Used to restrict the IP addresses from which this quiz can
                                                            be attempted. The format is as requried by the address_in_subnet
                                                            function.', VALUE_OPTIONAL),
                            'browsersecurity' => new external_value(PARAM_ALPHANUMEXT, 'Restriciton on the browser the student must
                                                                    use. E.g. \'securewindow\'.', VALUE_OPTIONAL),
                            'delay1' => new external_value(PARAM_INT, 'Delay that must be left between the first and second attempt,
                                                            in seconds.', VALUE_OPTIONAL),
                            'delay2' => new external_value(PARAM_INT, 'Delay that must be left between the second and subsequent
                                                            attempt, in seconds.', VALUE_OPTIONAL),
                            'showuserpicture' => new external_value(PARAM_INT, 'Option to show the user\'s picture during the
                                                                    attempt and on the review page.', VALUE_OPTIONAL),
                            'showblocks' => new external_value(PARAM_INT, 'Whether blocks should be shown on the attempt.php and
                                                                review.php pages.', VALUE_OPTIONAL),
                            'completionattemptsexhausted' => new external_value(PARAM_INT, 'Mark quiz complete when the student has
                                                                                exhausted the maximum number of attempts',
                                                                                VALUE_OPTIONAL),
                            'completionpass' => new external_value(PARAM_INT, 'Whether to require passing grade', VALUE_OPTIONAL),
                            'allowofflineattempts' => new external_value(PARAM_INT, 'Whether to allow the quiz to be attempted
                                                                            offline in the mobile app', VALUE_OPTIONAL),
                            'autosaveperiod' => new external_value(PARAM_INT, 'Auto-save delay', VALUE_OPTIONAL),
                            'hasfeedback' => new external_value(PARAM_INT, 'Whether the quiz has any non-blank feedback text',
                                                                VALUE_OPTIONAL),
                            'hasquestions' => new external_value(PARAM_INT, 'Whether the quiz has questions', VALUE_OPTIONAL),
                        ]
                    ))
                ),
                'warnings' => new external_warnings(),
            ]
        );
    }


    /**
     * Utility function for validating a quiz.
     *
     * @param int $quizid quiz instance id
     * @return array array containing the quiz, course, context and course module objects
     * @since  Moodle 3.1
     */
    protected static function validate_quiz($quizid) {
        global $DB;

        // Request and permission validation.
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        list($course, $cm) = get_course_and_cm_from_instance($quiz, 'quiz');

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        return [$quiz, $course, $cm, $context];
    }

    /**
     * Describes the parameters for view_quiz.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function view_quiz_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
            ]
        );
    }

    /**
     * Trigger the course module viewed event and update the module completion status.
     *
     * @param int $quizid quiz instance id
     * @return array of warnings and status result
     * @since Moodle 3.1
     */
    public static function view_quiz($quizid) {
        global $DB;

        $params = self::validate_parameters(self::view_quiz_parameters(), ['quizid' => $quizid]);
        $warnings = [];

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        // Trigger course_module_viewed event and completion.
        quiz_view($quiz, $course, $cm, $context);

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the view_quiz return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function view_quiz_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_user_attempts.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_user_attempts_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
                'userid' => new external_value(PARAM_INT, 'user id, empty for current user', VALUE_DEFAULT, 0),
                'status' => new external_value(PARAM_ALPHA, 'quiz status: all, finished or unfinished', VALUE_DEFAULT, 'finished'),
                'includepreviews' => new external_value(PARAM_BOOL, 'whether to include previews or not', VALUE_DEFAULT, false),

            ]
        );
    }

    /**
     * Return a list of attempts for the given quiz and user.
     *
     * @param int $quizid quiz instance id
     * @param int $userid user id
     * @param string $status quiz status: all, finished or unfinished
     * @param bool $includepreviews whether to include previews or not
     * @return array of warnings and the list of attempts
     * @since Moodle 3.1
     */
    public static function get_user_attempts($quizid, $userid = 0, $status = 'finished', $includepreviews = false) {
        global $USER;

        $warnings = [];

        $params = [
            'quizid' => $quizid,
            'userid' => $userid,
            'status' => $status,
            'includepreviews' => $includepreviews,
        ];
        $params = self::validate_parameters(self::get_user_attempts_parameters(), $params);

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        if (!in_array($params['status'], ['all', 'finished', 'unfinished'])) {
            throw new invalid_parameter_exception('Invalid status value');
        }

        // Default value for userid.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
        core_user::require_active_user($user);

        // Extra checks so only users with permissions can view other users attempts.
        if ($USER->id != $user->id) {
            require_capability('mod/quiz:viewreports', $context);
        }

        // Update quiz with override information.
        $quiz = quiz_update_effective_access($quiz, $params['userid']);
        $attempts = quiz_get_user_attempts($quiz->id, $user->id, $params['status'], $params['includepreviews']);
        $attemptresponse = [];
        foreach ($attempts as $attempt) {
            $reviewoptions = quiz_get_review_options($quiz, $attempt, $context);
            if (!has_capability('mod/quiz:viewreports', $context) &&
                    ($reviewoptions->marks < question_display_options::MARK_AND_MAX || $attempt->state != quiz_attempt::FINISHED)) {
                // Blank the mark if the teacher does not allow it.
                $attempt->sumgrades = null;
            }
            $attemptresponse[] = $attempt;
        }
        $result = [];
        $result['attempts'] = $attemptresponse;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes a single attempt structure.
     *
     * @return external_single_structure the attempt structure
     */
    private static function attempt_structure() {
        return new external_single_structure(
            [
                'id' => new external_value(PARAM_INT, 'Attempt id.', VALUE_OPTIONAL),
                'quiz' => new external_value(PARAM_INT, 'Foreign key reference to the quiz that was attempted.',
                                                VALUE_OPTIONAL),
                'userid' => new external_value(PARAM_INT, 'Foreign key reference to the user whose attempt this is.',
                                                VALUE_OPTIONAL),
                'attempt' => new external_value(PARAM_INT, 'Sequentially numbers this students attempts at this quiz.',
                                                VALUE_OPTIONAL),
                'uniqueid' => new external_value(PARAM_INT, 'Foreign key reference to the question_usage that holds the
                                                    details of the the question_attempts that make up this quiz
                                                    attempt.', VALUE_OPTIONAL),
                'layout' => new external_value(PARAM_RAW, 'Attempt layout.', VALUE_OPTIONAL),
                'currentpage' => new external_value(PARAM_INT, 'Attempt current page.', VALUE_OPTIONAL),
                'preview' => new external_value(PARAM_INT, 'Whether is a preview attempt or not.', VALUE_OPTIONAL),
                'state' => new external_value(PARAM_ALPHA, 'The current state of the attempts. \'inprogress\',
                                                \'overdue\', \'finished\' or \'abandoned\'.', VALUE_OPTIONAL),
                'timestart' => new external_value(PARAM_INT, 'Time when the attempt was started.', VALUE_OPTIONAL),
                'timefinish' => new external_value(PARAM_INT, 'Time when the attempt was submitted.
                                                    0 if the attempt has not been submitted yet.', VALUE_OPTIONAL),
                'timemodified' => new external_value(PARAM_INT, 'Last modified time.', VALUE_OPTIONAL),
                'timemodifiedoffline' => new external_value(PARAM_INT, 'Last modified time via webservices.', VALUE_OPTIONAL),
                'timecheckstate' => new external_value(PARAM_INT, 'Next time quiz cron should check attempt for
                                                        state changes.  NULL means never check.', VALUE_OPTIONAL),
                'sumgrades' => new external_value(PARAM_FLOAT, 'Total marks for this attempt.', VALUE_OPTIONAL),
                'gradednotificationsenttime' => new external_value(PARAM_INT,
                    'Time when the student was notified that manual grading of their attempt was complete.', VALUE_OPTIONAL),
            ]
        );
    }

    /**
     * Describes the get_user_attempts return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_user_attempts_returns() {
        return new external_single_structure(
            [
                'attempts' => new external_multiple_structure(self::attempt_structure()),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_user_best_grade.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_user_best_grade_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
                'userid' => new external_value(PARAM_INT, 'user id', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Get the best current grade for the given user on a quiz.
     *
     * @param int $quizid quiz instance id
     * @param int $userid user id
     * @return array of warnings and the grade information
     * @since Moodle 3.1
     */
    public static function get_user_best_grade($quizid, $userid = 0) {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $warnings = [];

        $params = [
            'quizid' => $quizid,
            'userid' => $userid,
        ];
        $params = self::validate_parameters(self::get_user_best_grade_parameters(), $params);

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        // Default value for userid.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
        core_user::require_active_user($user);

        // Extra checks so only users with permissions can view other users attempts.
        if ($USER->id != $user->id) {
            require_capability('mod/quiz:viewreports', $context);
        }

        $result = [];

        // This code was mostly copied from mod/quiz/view.php. We need to make the web service logic consistent.
        // Get this user's attempts.
        $attempts = quiz_get_user_attempts($quiz->id, $user->id, 'all');
        $canviewgrade = false;
        if ($attempts) {
            if ($USER->id != $user->id) {
                // No need to check the permission here. We did it at by require_capability('mod/quiz:viewreports', $context).
                $canviewgrade = true;
            } else {
                // Work out which columns we need, taking account what data is available in each attempt.
                [$notused, $alloptions] = quiz_get_combined_reviewoptions($quiz, $attempts);
                $canviewgrade = $alloptions->marks >= question_display_options::MARK_AND_MAX;
            }
        }

        $grade = $canviewgrade ? quiz_get_best_grade($quiz, $user->id) : null;

        if ($grade === null) {
            $result['hasgrade'] = false;
        } else {
            $result['hasgrade'] = true;
            $result['grade'] = $grade;
        }

        // Inform user of the grade to pass if non-zero.
        $gradinginfo = grade_get_grades($course->id, 'mod', 'quiz', $quiz->id, $user->id);
        if (!empty($gradinginfo->items)) {
            $item = $gradinginfo->items[0];

            if ($item && grade_floats_different($item->gradepass, 0)) {
                $result['gradetopass'] = $item->gradepass;
            }
        }

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_user_best_grade return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_user_best_grade_returns() {
        return new external_single_structure(
            [
                'hasgrade' => new external_value(PARAM_BOOL, 'Whether the user has a grade on the given quiz.'),
                'grade' => new external_value(PARAM_FLOAT, 'The grade (only if the user has a grade).', VALUE_OPTIONAL),
                'gradetopass' => new external_value(PARAM_FLOAT, 'The grade to pass the quiz (only if set).', VALUE_OPTIONAL),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_combined_review_options.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_combined_review_options_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
                'userid' => new external_value(PARAM_INT, 'user id (empty for current user)', VALUE_DEFAULT, 0),

            ]
        );
    }

    /**
     * Combines the review options from a number of different quiz attempts.
     *
     * @param int $quizid quiz instance id
     * @param int $userid user id (empty for current user)
     * @return array of warnings and the review options
     * @since Moodle 3.1
     */
    public static function get_combined_review_options($quizid, $userid = 0) {
        global $DB, $USER;

        $warnings = [];

        $params = [
            'quizid' => $quizid,
            'userid' => $userid,
        ];
        $params = self::validate_parameters(self::get_combined_review_options_parameters(), $params);

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        // Default value for userid.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
        core_user::require_active_user($user);

        // Extra checks so only users with permissions can view other users attempts.
        if ($USER->id != $user->id) {
            require_capability('mod/quiz:viewreports', $context);
        }

        // Update quiz with override information.
        $quiz = quiz_update_effective_access($quiz, $params['userid']);
        $attempts = quiz_get_user_attempts($quiz->id, $user->id, 'all', true);

        $result = [];
        $result['someoptions'] = [];
        $result['alloptions'] = [];

        list($someoptions, $alloptions) = quiz_get_combined_reviewoptions($quiz, $attempts);

        foreach (['someoptions', 'alloptions'] as $typeofoption) {
            foreach ($$typeofoption as $key => $value) {
                $result[$typeofoption][] = [
                    "name" => $key,
                    "value" => (!empty($value)) ? $value : 0
                ];
            }
        }

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_combined_review_options return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_combined_review_options_returns() {
        return new external_single_structure(
            [
                'someoptions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'option name'),
                            'value' => new external_value(PARAM_INT, 'option value'),
                        ]
                    )
                ),
                'alloptions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'option name'),
                            'value' => new external_value(PARAM_INT, 'option value'),
                        ]
                    )
                ),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for start_attempt.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function start_attempt_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                ),
                'forcenew' => new external_value(PARAM_BOOL, 'Whether to force a new attempt or not.', VALUE_DEFAULT, false),

            ]
        );
    }

    /**
     * Starts a new attempt at a quiz.
     *
     * @param int $quizid quiz instance id
     * @param array $preflightdata preflight required data (like passwords)
     * @param bool $forcenew Whether to force a new attempt or not.
     * @return array of warnings and the attempt basic data
     * @since Moodle 3.1
     */
    public static function start_attempt($quizid, $preflightdata = [], $forcenew = false) {
        global $DB, $USER;

        $warnings = [];
        $attempt = [];

        $params = [
            'quizid' => $quizid,
            'preflightdata' => $preflightdata,
            'forcenew' => $forcenew,
        ];
        $params = self::validate_parameters(self::start_attempt_parameters(), $params);
        $forcenew = $params['forcenew'];

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        $quizobj = quiz_settings::create($cm->instance, $USER->id);

        // Check questions.
        if (!$quizobj->has_questions()) {
            throw new moodle_exception('noquestionsfound', 'quiz', $quizobj->view_url());
        }

        // Create an object to manage all the other (non-roles) access rules.
        $timenow = time();
        $accessmanager = $quizobj->get_access_manager($timenow);

        // Validate permissions for creating a new attempt and start a new preview attempt if required.
        list($currentattemptid, $attemptnumber, $lastattempt, $messages, $page) =
            quiz_validate_new_attempt($quizobj, $accessmanager, $forcenew, -1, false);

        // Check access.
        if (!$quizobj->is_preview_user() && $messages) {
            // Create warnings with the exact messages.
            foreach ($messages as $message) {
                $warnings[] = [
                    'item' => 'quiz',
                    'itemid' => $quiz->id,
                    'warningcode' => '1',
                    'message' => clean_text($message, PARAM_TEXT)
                ];
            }
        } else {
            if ($accessmanager->is_preflight_check_required($currentattemptid)) {
                // Need to do some checks before allowing the user to continue.

                $provideddata = [];
                foreach ($params['preflightdata'] as $data) {
                    $provideddata[$data['name']] = $data['value'];
                }

                $errors = $accessmanager->validate_preflight_check($provideddata, [], $currentattemptid);

                if (!empty($errors)) {
                    throw new moodle_exception(array_shift($errors), 'quiz', $quizobj->view_url());
                }

                // Pre-flight check passed.
                $accessmanager->notify_preflight_check_passed($currentattemptid);
            }

            if ($currentattemptid) {
                if ($lastattempt->state == quiz_attempt::OVERDUE) {
                    throw new moodle_exception('stateoverdue', 'quiz', $quizobj->view_url());
                } else {
                    throw new moodle_exception('attemptstillinprogress', 'quiz', $quizobj->view_url());
                }
            }
            $offlineattempt = WS_SERVER ? true : false;
            $attempt = quiz_prepare_and_start_new_attempt($quizobj, $attemptnumber, $lastattempt, $offlineattempt);
        }

        $result = [];
        $result['attempt'] = $attempt;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the start_attempt return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function start_attempt_returns() {
        return new external_single_structure(
            [
                'attempt' => self::attempt_structure(),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Utility function for validating a given attempt
     *
     * @param  array $params array of parameters including the attemptid and preflight data
     * @param  bool $checkaccessrules whether to check the quiz access rules or not
     * @param  bool $failifoverdue whether to return error if the attempt is overdue
     * @return  array containing the attempt object and access messages
     * @since  Moodle 3.1
     */
    protected static function validate_attempt($params, $checkaccessrules = true, $failifoverdue = true) {
        global $USER;

        $attemptobj = quiz_attempt::create($params['attemptid']);

        $context = context_module::instance($attemptobj->get_cm()->id);
        self::validate_context($context);

        // Check that this attempt belongs to this user.
        if ($attemptobj->get_userid() != $USER->id) {
            throw new moodle_exception('notyourattempt', 'quiz', $attemptobj->view_url());
        }

        // General capabilities check.
        $ispreviewuser = $attemptobj->is_preview_user();
        if (!$ispreviewuser) {
            $attemptobj->require_capability('mod/quiz:attempt');
        }

        // Check the access rules.
        $accessmanager = $attemptobj->get_access_manager(time());
        $messages = [];
        if ($checkaccessrules) {
            // If the attempt is now overdue, or abandoned, deal with that.
            $attemptobj->handle_if_time_expired(time(), true);

            $messages = $accessmanager->prevent_access();
            if (!$ispreviewuser && $messages) {
                throw new moodle_exception('attempterror', 'quiz', $attemptobj->view_url());
            }
        }

        // Attempt closed?.
        if ($attemptobj->is_finished()) {
            throw new moodle_exception('attemptalreadyclosed', 'quiz', $attemptobj->view_url());
        } else if ($failifoverdue && $attemptobj->get_state() == quiz_attempt::OVERDUE) {
            throw new moodle_exception('stateoverdue', 'quiz', $attemptobj->view_url());
        }

        // User submitted data (like the quiz password).
        if ($accessmanager->is_preflight_check_required($attemptobj->get_attemptid())) {
            $provideddata = [];
            foreach ($params['preflightdata'] as $data) {
                $provideddata[$data['name']] = $data['value'];
            }

            $errors = $accessmanager->validate_preflight_check($provideddata, [], $params['attemptid']);
            if (!empty($errors)) {
                throw new moodle_exception(array_shift($errors), 'quiz', $attemptobj->view_url());
            }
            // Pre-flight check passed.
            $accessmanager->notify_preflight_check_passed($params['attemptid']);
        }

        if (isset($params['page'])) {
            // Check if the page is out of range.
            if ($params['page'] != $attemptobj->force_page_number_into_range($params['page'])) {
                throw new moodle_exception('Invalid page number', 'quiz', $attemptobj->view_url());
            }

            // Prevent out of sequence access.
            if (!$attemptobj->check_page_access($params['page'])) {
                throw new moodle_exception('Out of sequence access', 'quiz', $attemptobj->view_url());
            }

            // Check slots.
            $slots = $attemptobj->get_slots($params['page']);

            if (empty($slots)) {
                throw new moodle_exception('noquestionsfound', 'quiz', $attemptobj->view_url());
            }
        }

        return [$attemptobj, $messages];
    }

    /**
     * Describes a single question structure.
     *
     * @return external_single_structure the question data. Some fields may not be returned depending on the quiz display settings.
     * @since  Moodle 3.1
     * @since Moodle 3.2 blockedbyprevious parameter added.
     */
    private static function question_structure() {
        return new external_single_structure(
            [
                'slot' => new external_value(PARAM_INT, 'slot number'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'question type, i.e: multichoice'),
                'page' => new external_value(PARAM_INT, 'page of the quiz this question appears on'),
                'questionnumber' => new external_value(PARAM_RAW,
                        'The question number to display for this question, e.g. "7", "i" or "Custom-B)".'),
                'number' => new external_value(PARAM_INT,
                        'DO NOT USE. Use questionnumber. Only retained for backwards compatibility.', VALUE_OPTIONAL),
                'html' => new external_value(PARAM_RAW, 'the question rendered'),
                'responsefileareas' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'area' => new external_value(PARAM_NOTAGS, 'File area name'),
                            'files' => new external_files('Response files for the question', VALUE_OPTIONAL),
                        ]
                    ), 'Response file areas including files', VALUE_OPTIONAL
                ),
                'sequencecheck' => new external_value(PARAM_INT, 'the number of real steps in this attempt', VALUE_OPTIONAL),
                'lastactiontime' => new external_value(PARAM_INT, 'the timestamp of the most recent step in this question attempt',
                                                        VALUE_OPTIONAL),
                'hasautosavedstep' => new external_value(PARAM_BOOL, 'whether this question attempt has autosaved data',
                                                            VALUE_OPTIONAL),
                'flagged' => new external_value(PARAM_BOOL, 'whether the question is flagged or not'),
                'state' => new external_value(PARAM_ALPHA, 'the state where the question is in.
                    It will not be returned if the user cannot see it due to the quiz display correctness settings.',
                    VALUE_OPTIONAL),
                'status' => new external_value(PARAM_RAW, 'current formatted state of the question', VALUE_OPTIONAL),
                'blockedbyprevious' => new external_value(PARAM_BOOL, 'whether the question is blocked by the previous question',
                    VALUE_OPTIONAL),
                'mark' => new external_value(PARAM_RAW, 'the mark awarded.
                    It will be returned only if the user is allowed to see it.', VALUE_OPTIONAL),
                'maxmark' => new external_value(PARAM_FLOAT, 'the maximum mark possible for this question attempt.
                    It will be returned only if the user is allowed to see it.', VALUE_OPTIONAL),
                'settings' => new external_value(PARAM_RAW, 'Question settings (JSON encoded).', VALUE_OPTIONAL),
            ],
            'The question data. Some fields may not be returned depending on the quiz display settings.'
        );
    }

    /**
     * Return questions information for a given attempt.
     *
     * @param  quiz_attempt  $attemptobj  the quiz attempt object
     * @param  bool  $review  whether if we are in review mode or not
     * @param  mixed  $page  string 'all' or integer page number
     * @return array array of questions including data
     */
    private static function get_attempt_questions_data(quiz_attempt $attemptobj, $review, $page = 'all') {
        global $PAGE;

        $questions = [];
        $displayoptions = $attemptobj->get_display_options($review);
        $renderer = $PAGE->get_renderer('mod_quiz');
        $contextid = $attemptobj->get_quizobj()->get_context()->id;

        foreach ($attemptobj->get_slots($page) as $slot) {
            $qtype = $attemptobj->get_question_type_name($slot);
            $qattempt = $attemptobj->get_question_attempt($slot);
            $questiondef = $qattempt->get_question(true);

            // Get response files (for questions like essay that allows attachments).
            $responsefileareas = [];
            foreach (question_bank::get_qtype($qtype)->response_file_areas() as $area) {
                if ($files = $attemptobj->get_question_attempt($slot)->get_last_qt_files($area, $contextid)) {
                    $responsefileareas[$area]['area'] = $area;
                    $responsefileareas[$area]['files'] = [];

                    foreach ($files as $file) {
                        $responsefileareas[$area]['files'][] = [
                            'filename' => $file->get_filename(),
                            'fileurl' => $qattempt->get_response_file_url($file),
                            'filesize' => $file->get_filesize(),
                            'filepath' => $file->get_filepath(),
                            'mimetype' => $file->get_mimetype(),
                            'timemodified' => $file->get_timemodified(),
                        ];
                    }
                }
            }

            // Check display settings for question.
            $settings = $questiondef->get_question_definition_for_external_rendering($qattempt, $displayoptions);

            $question = [
                'slot' => $slot,
                'type' => $qtype,
                'page' => $attemptobj->get_question_page($slot),
                'questionnumber' => $attemptobj->get_question_number($slot),
                'flagged' => $attemptobj->is_question_flagged($slot),
                'html' => $attemptobj->render_question($slot, $review, $renderer) . $PAGE->requires->get_end_code(),
                'responsefileareas' => $responsefileareas,
                'sequencecheck' => $qattempt->get_sequence_check_count(),
                'lastactiontime' => $qattempt->get_last_step()->get_timecreated(),
                'hasautosavedstep' => $qattempt->has_autosaved_step(),
                'settings' => !empty($settings) ? json_encode($settings) : null,
            ];

            if ($question['questionnumber'] === (string) (int) $question['questionnumber']) {
                $question['number'] = $question['questionnumber'];
            }

            if ($attemptobj->is_real_question($slot)) {
                $showcorrectness = $displayoptions->correctness && $qattempt->has_marks();
                if ($showcorrectness) {
                    $question['state'] = (string) $attemptobj->get_question_state($slot);
                }
                $question['status'] = $attemptobj->get_question_status($slot, $displayoptions->correctness);
                $question['blockedbyprevious'] = $attemptobj->is_blocked_by_previous_question($slot);
            }
            if ($displayoptions->marks >= question_display_options::MAX_ONLY) {
                $question['maxmark'] = $qattempt->get_max_mark();
            }
            if ($displayoptions->marks >= question_display_options::MARK_AND_MAX) {
                $question['mark'] = $attemptobj->get_question_mark($slot);
            }
            if ($attemptobj->check_page_access($attemptobj->get_question_page($slot), false)) {
                $questions[] = $question;
            }
        }
        return $questions;
    }

    /**
     * Describes the parameters for get_attempt_data.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_attempt_data_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'page' => new external_value(PARAM_INT, 'page number'),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                )
            ]
        );
    }

    /**
     * Returns information for the given attempt page for a quiz attempt in progress.
     *
     * @param int $attemptid attempt id
     * @param int $page page number
     * @param array $preflightdata preflight required data (like passwords)
     * @return array of warnings and the attempt data, next page, message and questions
     * @since Moodle 3.1
     */
    public static function get_attempt_data($attemptid, $page, $preflightdata = []) {
        global $PAGE;

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'page' => $page,
            'preflightdata' => $preflightdata,
        ];
        $params = self::validate_parameters(self::get_attempt_data_parameters(), $params);

        [$attemptobj, $messages] = self::validate_attempt($params);

        if ($attemptobj->is_last_page($params['page'])) {
            $nextpage = -1;
        } else {
            $nextpage = $params['page'] + 1;
        }

        // TODO: Remove the code once the long-term solution (MDL-76728) has been applied.
        // Set a default URL to stop the debugging output.
        $PAGE->set_url('/fake/url');

        $result = [];
        $result['attempt'] = $attemptobj->get_attempt();
        $result['messages'] = $messages;
        $result['nextpage'] = $nextpage;
        $result['warnings'] = $warnings;
        $result['questions'] = self::get_attempt_questions_data($attemptobj, false, $params['page']);

        return $result;
    }

    /**
     * Describes the get_attempt_data return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_attempt_data_returns() {
        return new external_single_structure(
            [
                'attempt' => self::attempt_structure(),
                'messages' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'access message'),
                    'access messages, will only be returned for users with mod/quiz:preview capability,
                    for other users this method will throw an exception if there are messages'),
                'nextpage' => new external_value(PARAM_INT, 'next page number'),
                'questions' => new external_multiple_structure(self::question_structure()),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_attempt_summary.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_attempt_summary_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                )
            ]
        );
    }

    /**
     * Returns a summary of a quiz attempt before it is submitted.
     *
     * @param int $attemptid attempt id
     * @param int $preflightdata preflight required data (like passwords)
     * @return array of warnings and the attempt summary data for each question
     * @since Moodle 3.1
     */
    public static function get_attempt_summary($attemptid, $preflightdata = []) {

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'preflightdata' => $preflightdata,
        ];
        $params = self::validate_parameters(self::get_attempt_summary_parameters(), $params);

        list($attemptobj, $messages) = self::validate_attempt($params, true, false);

        $result = [];
        $result['warnings'] = $warnings;
        $result['questions'] = self::get_attempt_questions_data($attemptobj, false, 'all');

        return $result;
    }

    /**
     * Describes the get_attempt_summary return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_attempt_summary_returns() {
        return new external_single_structure(
            [
                'questions' => new external_multiple_structure(self::question_structure()),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for save_attempt.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function save_attempt_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'data' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_RAW, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'the data to be saved'
                ),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                )
            ]
        );
    }

    /**
     * Processes save requests during the quiz. This function is intended for the quiz auto-save feature.
     *
     * @param int $attemptid attempt id
     * @param array $data the data to be saved
     * @param  array $preflightdata preflight required data (like passwords)
     * @return array of warnings and execution result
     * @since Moodle 3.1
     */
    public static function save_attempt($attemptid, $data, $preflightdata = []) {
        global $DB, $USER;

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'data' => $data,
            'preflightdata' => $preflightdata,
        ];
        $params = self::validate_parameters(self::save_attempt_parameters(), $params);

        // Add a page, required by validate_attempt.
        list($attemptobj, $messages) = self::validate_attempt($params);

        // Prevent functions like file_get_submitted_draft_itemid() or form library requiring a sesskey for WS requests.
        if (WS_SERVER || PHPUNIT_TEST) {
            $USER->ignoresesskey = true;
        }
        $transaction = $DB->start_delegated_transaction();
        // Create the $_POST object required by the question engine.
        $_POST = [];
        foreach ($data as $element) {
            $_POST[$element['name']] = $element['value'];
            // Some deep core functions like file_get_submitted_draft_itemid() also requires $_REQUEST to be filled.
            $_REQUEST[$element['name']] = $element['value'];
        }
        $timenow = time();
        // Update the timemodifiedoffline field.
        $attemptobj->set_offline_modified_time($timenow);
        $attemptobj->process_auto_save($timenow);
        $transaction->allow_commit();

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the save_attempt return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function save_attempt_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for process_attempt.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function process_attempt_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'data' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_RAW, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ),
                    'the data to be saved', VALUE_DEFAULT, []
                ),
                'finishattempt' => new external_value(PARAM_BOOL, 'whether to finish or not the attempt', VALUE_DEFAULT, false),
                'timeup' => new external_value(PARAM_BOOL, 'whether the WS was called by a timer when the time is up',
                                                VALUE_DEFAULT, false),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                )
            ]
        );
    }

    /**
     * Process responses during an attempt at a quiz and also deals with attempts finishing.
     *
     * @param int $attemptid attempt id
     * @param array $data the data to be saved
     * @param bool $finishattempt whether to finish or not the attempt
     * @param bool $timeup whether the WS was called by a timer when the time is up
     * @param array $preflightdata preflight required data (like passwords)
     * @return array of warnings and the attempt state after the processing
     * @since Moodle 3.1
     */
    public static function process_attempt($attemptid, $data, $finishattempt = false, $timeup = false, $preflightdata = []) {
        global $USER;

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'data' => $data,
            'finishattempt' => $finishattempt,
            'timeup' => $timeup,
            'preflightdata' => $preflightdata,
        ];
        $params = self::validate_parameters(self::process_attempt_parameters(), $params);

        // Do not check access manager rules and evaluate fail if overdue.
        $attemptobj = quiz_attempt::create($params['attemptid']);
        $failifoverdue = !($attemptobj->get_quizobj()->get_quiz()->overduehandling == 'graceperiod');

        list($attemptobj, $messages) = self::validate_attempt($params, false, $failifoverdue);

        // Prevent functions like file_get_submitted_draft_itemid() or form library requiring a sesskey for WS requests.
        if (WS_SERVER || PHPUNIT_TEST) {
            $USER->ignoresesskey = true;
        }
        // Create the $_POST object required by the question engine.
        $_POST = [];
        foreach ($params['data'] as $element) {
            $_POST[$element['name']] = $element['value'];
            $_REQUEST[$element['name']] = $element['value'];
        }
        $timenow = time();
        $finishattempt = $params['finishattempt'];
        $timeup = $params['timeup'];

        $result = [];
        // Update the timemodifiedoffline field.
        $attemptobj->set_offline_modified_time($timenow);
        $result['state'] = $attemptobj->process_attempt($timenow, $finishattempt, $timeup, 0);

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the process_attempt return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function process_attempt_returns() {
        return new external_single_structure(
            [
                'state' => new external_value(PARAM_ALPHANUMEXT, 'state: the new attempt state:
                                                                    inprogress, finished, overdue, abandoned'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Validate an attempt finished for review. The attempt would be reviewed by a user or a teacher.
     *
     * @param  array $params Array of parameters including the attemptid
     * @return  array containing the attempt object and display options
     * @since  Moodle 3.1
     */
    protected static function validate_attempt_review($params) {

        $attemptobj = quiz_attempt::create($params['attemptid']);
        $attemptobj->check_review_capability();

        $displayoptions = $attemptobj->get_display_options(true);
        if ($attemptobj->is_own_attempt()) {
            if (!$attemptobj->is_finished()) {
                throw new moodle_exception('attemptclosed', 'quiz', $attemptobj->view_url());
            } else if (!$displayoptions->attempt) {
                throw new moodle_exception('noreview', 'quiz', $attemptobj->view_url(), null,
                    $attemptobj->cannot_review_message());
            }
        } else if (!$attemptobj->is_review_allowed()) {
            throw new moodle_exception('noreviewattempt', 'quiz', $attemptobj->view_url());
        }
        return [$attemptobj, $displayoptions];
    }

    /**
     * Describes the parameters for get_attempt_review.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_attempt_review_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'page' => new external_value(PARAM_INT, 'page number, empty for all the questions in all the pages',
                                                VALUE_DEFAULT, -1),
            ]
        );
    }

    /**
     * Returns review information for the given finished attempt, can be used by users or teachers.
     *
     * @param int $attemptid attempt id
     * @param int $page page number, empty for all the questions in all the pages
     * @return array of warnings and the attempt data, feedback and questions
     * @since Moodle 3.1
     */
    public static function get_attempt_review($attemptid, $page = -1) {
        global $PAGE;

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'page' => $page,
        ];
        $params = self::validate_parameters(self::get_attempt_review_parameters(), $params);

        list($attemptobj, $displayoptions) = self::validate_attempt_review($params);

        if ($params['page'] !== -1) {
            $page = $attemptobj->force_page_number_into_range($params['page']);
        } else {
            $page = 'all';
        }

        // Make sure all users associated to the attempt steps are loaded. Otherwise, this will
        // trigger a debugging message.
        $attemptobj->preload_all_attempt_step_users();

        // Prepare the output.
        $result = [];
        $result['attempt'] = $attemptobj->get_attempt();
        $result['questions'] = self::get_attempt_questions_data($attemptobj, true, $page, true);

        $result['additionaldata'] = [];
        // Summary data (from behaviours).
        $summarydata = $attemptobj->get_additional_summary_data($displayoptions);
        foreach ($summarydata as $key => $data) {
            // This text does not need formatting (no need for external_format_[string|text]).
            $result['additionaldata'][] = [
                'id' => $key,
                'title' => $data['title'], $attemptobj->get_quizobj()->get_context()->id,
                'content' => $data['content'],
            ];
        }

        // Feedback if there is any, and the user is allowed to see it now.
        $grade = quiz_rescale_grade($attemptobj->get_attempt()->sumgrades, $attemptobj->get_quiz(), false);

        $feedback = $attemptobj->get_overall_feedback($grade);
        if ($displayoptions->overallfeedback && $feedback) {
            $result['additionaldata'][] = [
                'id' => 'feedback',
                'title' => get_string('feedback', 'quiz'),
                'content' => $feedback,
            ];
        }

        $result['grade'] = $grade;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_attempt_review return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_attempt_review_returns() {
        return new external_single_structure(
            [
                'grade' => new external_value(PARAM_RAW, 'grade for the quiz (or empty or "notyetgraded")'),
                'attempt' => self::attempt_structure(),
                'additionaldata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'id' => new external_value(PARAM_ALPHANUMEXT, 'id of the data'),
                            'title' => new external_value(PARAM_TEXT, 'data title'),
                            'content' => new external_value(PARAM_RAW, 'data content'),
                        ]
                    )
                ),
                'questions' => new external_multiple_structure(self::question_structure()),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for view_attempt.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function view_attempt_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'page' => new external_value(PARAM_INT, 'page number'),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                )
            ]
        );
    }

    /**
     * Trigger the attempt viewed event.
     *
     * @param int $attemptid attempt id
     * @param int $page page number
     * @param array $preflightdata preflight required data (like passwords)
     * @return array of warnings and status result
     * @since Moodle 3.1
     */
    public static function view_attempt($attemptid, $page, $preflightdata = []) {

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'page' => $page,
            'preflightdata' => $preflightdata,
        ];
        $params = self::validate_parameters(self::view_attempt_parameters(), $params);
        list($attemptobj, $messages) = self::validate_attempt($params);

        // Log action.
        $attemptobj->fire_attempt_viewed_event();

        // Update attempt page, throwing an exception if $page is not valid.
        if (!$attemptobj->set_currentpage($params['page'])) {
            throw new moodle_exception('Out of sequence access', 'quiz', $attemptobj->view_url());
        }

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the view_attempt return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function view_attempt_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for view_attempt_summary.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function view_attempt_summary_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
                'preflightdata' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'name' => new external_value(PARAM_ALPHANUMEXT, 'data name'),
                            'value' => new external_value(PARAM_RAW, 'data value'),
                        ]
                    ), 'Preflight required data (like passwords)', VALUE_DEFAULT, []
                )
            ]
        );
    }

    /**
     * Trigger the attempt summary viewed event.
     *
     * @param int $attemptid attempt id
     * @param array $preflightdata preflight required data (like passwords)
     * @return array of warnings and status result
     * @since Moodle 3.1
     */
    public static function view_attempt_summary($attemptid, $preflightdata = []) {

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
            'preflightdata' => $preflightdata,
        ];
        $params = self::validate_parameters(self::view_attempt_summary_parameters(), $params);
        list($attemptobj, $messages) = self::validate_attempt($params);

        // Log action.
        $attemptobj->fire_attempt_summary_viewed_event();

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the view_attempt_summary return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function view_attempt_summary_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for view_attempt_review.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function view_attempt_review_parameters() {
        return new external_function_parameters (
            [
                'attemptid' => new external_value(PARAM_INT, 'attempt id'),
            ]
        );
    }

    /**
     * Trigger the attempt reviewed event.
     *
     * @param int $attemptid attempt id
     * @return array of warnings and status result
     * @since Moodle 3.1
     */
    public static function view_attempt_review($attemptid) {

        $warnings = [];

        $params = [
            'attemptid' => $attemptid,
        ];
        $params = self::validate_parameters(self::view_attempt_review_parameters(), $params);
        list($attemptobj, $displayoptions) = self::validate_attempt_review($params);

        // Log action.
        $attemptobj->fire_attempt_reviewed_event();

        $result = [];
        $result['status'] = true;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the view_attempt_review return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function view_attempt_review_returns() {
        return new external_single_structure(
            [
                'status' => new external_value(PARAM_BOOL, 'status: true if success'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for view_quiz.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_quiz_feedback_for_grade_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
                'grade' => new external_value(PARAM_FLOAT, 'the grade to check'),
            ]
        );
    }

    /**
     * Get the feedback text that should be show to a student who got the given grade in the given quiz.
     *
     * @param int $quizid quiz instance id
     * @param float $grade the grade to check
     * @return array of warnings and status result
     * @since Moodle 3.1
     */
    public static function get_quiz_feedback_for_grade($quizid, $grade) {
        global $DB;

        $params = [
            'quizid' => $quizid,
            'grade' => $grade,
        ];
        $params = self::validate_parameters(self::get_quiz_feedback_for_grade_parameters(), $params);
        $warnings = [];

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        $result = [];
        $result['feedbacktext'] = '';
        $result['feedbacktextformat'] = FORMAT_MOODLE;

        $feedback = quiz_feedback_record_for_grade($params['grade'], $quiz);
        if (!empty($feedback->feedbacktext)) {
            list($text, $format) = \core_external\util::format_text(
                $feedback->feedbacktext,
                $feedback->feedbacktextformat,
                $context,
                'mod_quiz',
                'feedback',
                $feedback->id
            );
            $result['feedbacktext'] = $text;
            $result['feedbacktextformat'] = $format;
            $feedbackinlinefiles = util::get_area_files($context->id, 'mod_quiz', 'feedback', $feedback->id);
            if (!empty($feedbackinlinefiles)) {
                $result['feedbackinlinefiles'] = $feedbackinlinefiles;
            }
        }

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_quiz_feedback_for_grade return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_quiz_feedback_for_grade_returns() {
        return new external_single_structure(
            [
                'feedbacktext' => new external_value(PARAM_RAW, 'the comment that corresponds to this grade (empty for none)'),
                'feedbacktextformat' => new external_format_value('feedbacktext', VALUE_OPTIONAL),
                'feedbackinlinefiles' => new external_files('feedback inline files', VALUE_OPTIONAL),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_quiz_access_information.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_quiz_access_information_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id')
            ]
        );
    }

    /**
     * Return access information for a given quiz.
     *
     * @param int $quizid quiz instance id
     * @return array of warnings and the access information
     * @since Moodle 3.1
     */
    public static function get_quiz_access_information($quizid) {
        global $DB, $USER;

        $warnings = [];

        $params = [
            'quizid' => $quizid
        ];
        $params = self::validate_parameters(self::get_quiz_access_information_parameters(), $params);

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        $result = [];
        // Capabilities first.
        $result['canattempt'] = has_capability('mod/quiz:attempt', $context);;
        $result['canmanage'] = has_capability('mod/quiz:manage', $context);;
        $result['canpreview'] = has_capability('mod/quiz:preview', $context);;
        $result['canreviewmyattempts'] = has_capability('mod/quiz:reviewmyattempts', $context);;
        $result['canviewreports'] = has_capability('mod/quiz:viewreports', $context);;

        // Access manager now.
        $quizobj = quiz_settings::create($cm->instance, $USER->id);
        $ignoretimelimits = has_capability('mod/quiz:ignoretimelimits', $context, null, false);
        $timenow = time();
        $accessmanager = new access_manager($quizobj, $timenow, $ignoretimelimits);

        $result['accessrules'] = $accessmanager->describe_rules();
        $result['activerulenames'] = $accessmanager->get_active_rule_names();
        $result['preventaccessreasons'] = $accessmanager->prevent_access();

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_quiz_access_information return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_quiz_access_information_returns() {
        return new external_single_structure(
            [
                'canattempt' => new external_value(PARAM_BOOL, 'Whether the user can do the quiz or not.'),
                'canmanage' => new external_value(PARAM_BOOL, 'Whether the user can edit the quiz settings or not.'),
                'canpreview' => new external_value(PARAM_BOOL, 'Whether the user can preview the quiz or not.'),
                'canreviewmyattempts' => new external_value(PARAM_BOOL, 'Whether the users can review their previous attempts
                                                                or not.'),
                'canviewreports' => new external_value(PARAM_BOOL, 'Whether the user can view the quiz reports or not.'),
                'accessrules' => new external_multiple_structure(
                                    new external_value(PARAM_TEXT, 'rule description'), 'list of rules'),
                'activerulenames' => new external_multiple_structure(
                                    new external_value(PARAM_PLUGIN, 'rule plugin names'), 'list of active rules'),
                'preventaccessreasons' => new external_multiple_structure(
                                            new external_value(PARAM_TEXT, 'access restriction description'), 'list of reasons'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_attempt_access_information.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_attempt_access_information_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id'),
                'attemptid' => new external_value(PARAM_INT, 'attempt id, 0 for the user last attempt if exists', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Return access information for a given attempt in a quiz.
     *
     * @param int $quizid quiz instance id
     * @param int $attemptid attempt id, 0 for the user last attempt if exists
     * @return array of warnings and the access information
     * @since Moodle 3.1
     */
    public static function get_attempt_access_information($quizid, $attemptid = 0) {
        global $DB, $USER;

        $warnings = [];

        $params = [
            'quizid' => $quizid,
            'attemptid' => $attemptid,
        ];
        $params = self::validate_parameters(self::get_attempt_access_information_parameters(), $params);

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        $attempttocheck = null;
        if (!empty($params['attemptid'])) {
            $attemptobj = quiz_attempt::create($params['attemptid']);
            if ($attemptobj->get_userid() != $USER->id) {
                throw new moodle_exception('notyourattempt', 'quiz', $attemptobj->view_url());
            }
            $attempttocheck = $attemptobj->get_attempt();
        }

        // Access manager now.
        $quizobj = quiz_settings::create($cm->instance, $USER->id);
        $ignoretimelimits = has_capability('mod/quiz:ignoretimelimits', $context, null, false);
        $timenow = time();
        $accessmanager = new access_manager($quizobj, $timenow, $ignoretimelimits);

        $attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'finished', true);
        $lastfinishedattempt = end($attempts);
        if ($unfinishedattempt = quiz_get_user_attempt_unfinished($quiz->id, $USER->id)) {
            $attempts[] = $unfinishedattempt;

            // Check if the attempt is now overdue. In that case the state will change.
            $quizobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

            if ($unfinishedattempt->state != quiz_attempt::IN_PROGRESS and $unfinishedattempt->state != quiz_attempt::OVERDUE) {
                $lastfinishedattempt = $unfinishedattempt;
            }
        }
        $numattempts = count($attempts);

        if (!$attempttocheck) {
            $attempttocheck = $unfinishedattempt ?: $lastfinishedattempt;
        }

        $result = [];
        $result['isfinished'] = $accessmanager->is_finished($numattempts, $lastfinishedattempt);
        $result['preventnewattemptreasons'] = $accessmanager->prevent_new_attempt($numattempts, $lastfinishedattempt);

        if ($attempttocheck) {
            $endtime = $accessmanager->get_end_time($attempttocheck);
            $result['endtime'] = ($endtime === false) ? 0 : $endtime;
            $attemptid = $unfinishedattempt ? $unfinishedattempt->id : null;
            $result['ispreflightcheckrequired'] = $accessmanager->is_preflight_check_required($attemptid);
        }

        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_attempt_access_information return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_attempt_access_information_returns() {
        return new external_single_structure(
            [
                'endtime' => new external_value(PARAM_INT, 'When the attempt must be submitted (determined by rules).',
                                                VALUE_OPTIONAL),
                'isfinished' => new external_value(PARAM_BOOL, 'Whether there is no way the user will ever be allowed to attempt.'),
                'ispreflightcheckrequired' => new external_value(PARAM_BOOL, 'whether a check is required before the user
                                                                    starts/continues his attempt.', VALUE_OPTIONAL),
                'preventnewattemptreasons' => new external_multiple_structure(
                                                new external_value(PARAM_TEXT, 'access restriction description'),
                                                                    'list of reasons'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Describes the parameters for get_quiz_required_qtypes.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function get_quiz_required_qtypes_parameters() {
        return new external_function_parameters (
            [
                'quizid' => new external_value(PARAM_INT, 'quiz instance id')
            ]
        );
    }

    /**
     * Return the potential question types that would be required for a given quiz.
     * Please note that for random question types we return the potential question types in the category choosen.
     *
     * @param int $quizid quiz instance id
     * @return array of warnings and the access information
     * @since Moodle 3.1
     */
    public static function get_quiz_required_qtypes($quizid) {
        global $DB, $USER;

        $warnings = [];

        $params = [
            'quizid' => $quizid
        ];
        $params = self::validate_parameters(self::get_quiz_required_qtypes_parameters(), $params);

        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);

        $quizobj = quiz_settings::create($cm->instance, $USER->id);
        $quizobj->preload_questions();

        // Question types used.
        $result = [];
        $result['questiontypes'] = $quizobj->get_all_question_types_used(true);
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Describes the get_quiz_required_qtypes return value.
     *
     * @return external_single_structure
     * @since Moodle 3.1
     */
    public static function get_quiz_required_qtypes_returns() {
        return new external_single_structure(
            [
                'questiontypes' => new external_multiple_structure(
                                    new external_value(PARAM_PLUGIN, 'question type'), 'list of question types used in the quiz'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    //Added by Tamar
    public static function get_quiz_questions_parameters() {
        return new external_function_parameters(
            [
                'quizid' => new external_value(PARAM_INT, 'The ID of the quiz')
            ]
        );
    }
    

    /**
     * Returns a list of questions for a given quiz by leveraging the question_references table.
     *
     * @param int $quizid The ID of the quiz
     * @return array An array containing 'questions' and 'warnings'
     * @throws moodle_exception if quiz structure is invalid or quiz not found
     */
    public static function get_quiz_questions($quizid) {
        global $DB, $USER;

        // Validate the parameters.
        $params = self::validate_parameters(
            self::get_quiz_questions_parameters(),
            array('quizid' => $quizid)
        );

        // Validate the quiz instance and retrieve related data.
        list($quiz, $course, $cm, $context) = self::validate_quiz($quizid);

        // Check if the user has the capability to view the quiz.
        require_capability('mod/quiz:view', $context);

        // Initialize the result arrays.
        $questions = [];
        $warnings = [];

        // Fetch all quiz_slots for the given quiz.
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot');

        if (!$slots) {
            return [
                'questions' => $questions,
                'warnings'  => $warnings
            ];
        }

        foreach ($slots as $slot) {
            // Get the questionbankentryid from mdl_question_references.
            $questionreference = $DB->get_record('question_references', [
                'itemid' => $slot->id,
                'component' => 'mod_quiz',
                'questionarea' => 'slot'
            ]);

            if (!$questionreference) {
                $warnings[] = [
                    'item'        => 'slot',
                    'itemid'      => $slot->slot,
                    'warningcode' => 'missingreference',
                    'message'     => 'No question reference found for slot ' . $slot->slot
                ];
                continue;
            }

            // Get the questionid from mdl_question_versions using the questionbankentryid.
            $questionversion = $DB->get_record('question_versions', [
                'questionbankentryid' => $questionreference->questionbankentryid
            ]);

            if (!$questionversion) {
                $warnings[] = [
                    'item'        => 'slot',
                    'itemid'      => $slot->slot,
                    'warningcode' => 'missingversion',
                    'message'     => 'No question version found for slot ' . $slot->slot
                ];
                continue;
            }

            $questionid = $questionversion->questionid;

            // Fetch the question details.
            $question = $DB->get_record('question', ['id' => $questionid], '*');

            if ($question) {
                $questions[] = [
                    'slot'         => $slot->slot,
                    'questionid'   => $question->id,
                    'name'         => $question->name,
                    'questiontext' => $question->questiontext,
                    'qtype'        => $question->qtype,
                    // Add other necessary fields here.
                ];
            } else {
                $warnings[] = [
                    'item'        => 'slot',
                    'itemid'      => $slot->slot,
                    'warningcode' => 'invalidquestion',
                    'message'     => 'Invalid question associated with slot ' . $slot->slot
                ];
            }
        }

        return [
            'questions' => $questions,
            'warnings'  => $warnings
        ];
    }
        
    public static function get_quiz_questions_returns() {
        return new external_single_structure(
            [
                'questions' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'slot'         => new external_value(PARAM_INT, 'The slot number'),
                            'questionid'   => new external_value(PARAM_INT, 'The ID of the question'),
                            'name'         => new external_value(PARAM_RAW, 'The name of the question'),
                            'questiontext' => new external_value(PARAM_RAW, 'The text of the question'),
                            'qtype'        => new external_value(PARAM_ALPHA, 'The type of the question')
                        ]
                    )
                ),
                'warnings' => new external_warnings(),
            ]
        );
    }


    public static function create_quiz_parameters() {
        return new external_function_parameters([
            'courseid'    => new external_value(PARAM_INT, 'ID of the course'),
            'section'     => new external_value(PARAM_INT, 'Section number to add the quiz'),
            'name'        => new external_value(PARAM_TEXT, 'Name of the new quiz'),
            'intro'       => new external_value(PARAM_RAW, 'Quiz introduction', VALUE_DEFAULT, ''),
            // Add more quiz-specific parameters as needed.
        ]);
    }

    public static function create_quiz($courseid, $section, $name, $intro = '') {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::create_quiz_parameters(), [
            'courseid' => $courseid,
            'section'  => $section,
            'name'     => $name,
            'intro'    => $intro,
        ]);

        // Validate context and capability.
        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        require_capability('moodle/course:manageactivities', $coursecontext);

        $module = $DB->get_record('modules', array('name' => 'quiz'), '*', MUST_EXIST);
        // Get module info.
        $course = get_course($params['courseid']);
        $sectioninfo = $DB->get_record('course_sections', array('course' => $params['courseid'], 'section' => $section));
        if (!$sectioninfo) {
            // Create section if needed.
            $sectioninfo = course_create_section($course, $section);
        }
        
        // Create course module entry.
        $cm = new stdClass();
        $cm->course = $course->id;
        $cm->module = $module->id;
        $cm->instance = 0;
        $cm->section = $sectioninfo->id;
        $cm->visible = 1;
        // ... set other course module defaults.
        $cm->id = add_course_module($cm);

        // Prepare new quiz record.
        $quiz = new stdClass();
        $quiz->course = $params['courseid'];
        $quiz->name = $params['name'];
        $quiz->intro = $params['intro'];
        $quiz->quizpassword = '';
        $quiz->introformat = FORMAT_HTML;
        $quiz->timeopen = 0;
        $quiz->timeclose = 0;
        $quiz->timecreated = time();
        $quiz->timemodified = time();
        $quiz->coursemodule = $cm->id;
        $quiz->preferredbehaviour = 'deferredfeedback';
        $quiz->grade = 100.00000;
        // ... set other default quiz settings as needed.

        // // Insert quiz into database.
        $quiz->id = quiz_add_instance($quiz);
        if (empty($quiz->id)) {
            throw new moodle_exception('Quiz creation failed: no ID returned.');
        }

        // Add module to section.
        course_add_cm_to_section($course, $cm->id, $sectioninfo->id);
        rebuild_course_cache($course->id);

        return ['status' => 'success', 'quizid' => $quiz->id, 'cmid' => $cm->id];
    }

    public static function create_quiz_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Operation status'),
            'quizid' => new external_value(PARAM_INT, 'ID of the newly created quiz'),
            'cmid'   => new external_value(PARAM_INT, 'Course module id of the new quiz'),
        ]);
    }

    public static function create_question_in_quiz_parameters() {
        return new external_function_parameters([
            'quizid' => new external_value(PARAM_INT, 'The ID of the existing quiz'),
            //'categoryid' => new external_value(PARAM_INT, 'The question category ID to place the question in'),
            'qtype' => new external_value(PARAM_ALPHANUMEXT, 'Question type, e.g. shortanswer, multichoice, etc.'),
            'name' => new external_value(PARAM_TEXT, 'Question name'),
            'questiontext' => new external_value(PARAM_RAW, 'The text of the question'),
            'defaultmark' => new external_value(PARAM_FLOAT, 'Default mark for the question', VALUE_DEFAULT, 1.0),
            // Add question-type–specific parameters if needed, e.g. answers for multiple-choice, etc.
        ]);
    }

    // public static function create_question_in_quiz($quizid, $categoryid, $qtype, $name, $questiontext, $defaultmark = 1.0) {
    //     global $DB, $CFG, $USER;

    //     // Validate parameters.
    //     $params = self::validate_parameters(
    //         self::create_question_in_quiz_parameters(),
    //         [
    //             'quizid'       => $quizid,
    //             'categoryid'   => $categoryid,
    //             'qtype'        => $qtype,
    //             'name'         => $name,
    //             'questiontext' => $questiontext,
    //             'defaultmark'  => $defaultmark
    //         ]
    //     );

    //     // Ensure the quiz exists.
    //     $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);

    //     // Validate context and capability.
    //     $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
    //     $coursecontext = context_course::instance($course->id);
    //     self::validate_context($coursecontext);
    //     require_capability('moodle/question:add', $coursecontext);

    //     // Confirm the category exists.
    //     $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);

    //     // Prepare question data for the 'question' table.
    //     $questiondata = new stdClass();
    //     $questiondata->category               = $category->id;
    //     $questiondata->name                   = $params['name'];
    //     $questiondata->questiontext           = $params['questiontext'];
    //     $questiondata->questiontextformat     = FORMAT_HTML;
    //     $questiondata->generalfeedback        = '';
    //     $questiondata->generalfeedbackformat  = FORMAT_HTML;
    //     $questiondata->defaultmark            = $params['defaultmark'];
    //     $questiondata->qtype                  = $qtype;
    //     $questiondata->parent                 = 0;
    //     $questiondata->stamp                  = '';
    //     $questiondata->version                = 1;
    //     $questiondata->timecreated            = time();
    //     $questiondata->timemodified           = time();
    //     $questiondata->createdby              = $USER->id;
    //     $questiondata->modifiedby             = $USER->id;

    //     // Insert into question table.
    //     $questionid = $DB->insert_record('question', $questiondata);
    //     if (!$questionid) {
    //         throw new moodle_exception('Failed to create question record in {question} table.');
    //     }

    //     // Insert into question_bank_entries based on your version's columns.
    //     $qbentry = new stdClass();
    //     $qbentry->questioncategoryid = $category->id;  
    //     $qbentry->idnumber           = '';
    //     $qbentry->ownerid            = $USER->id;      

    //     $qbentryid = $DB->insert_record('question_bank_entries', $qbentry);
    //     if (!$qbentryid) {
    //         throw new moodle_exception('Failed to create record in question_bank_entries.');
    //     }

    //     // Insert into question_versions to link question + bank entry.
    //     $qversion = new stdClass();
    //     $qversion->questionid          = $questionid;
    //     $qversion->questionbankentryid = $qbentryid;
    //     $qversion->version             = 1;

    //     $DB->insert_record('question_versions', $qversion);

    //     // [*] If this is a shortanswer question, insert a record in qtype_shortanswer_options.
    //     if ($qtype === 'shortanswer') {
    //         // For shortanswer, Moodle expects a record in qtype_shortanswer_options.
    //         //   - questionid  (bigint) = your new question ID
    //         //   - usecase     (int)    = 0 for case-insensitive, 1 for case-sensitive
    //         // You can add more columns if your version has them.
    //         $shortansweropts = new stdClass();
    //         $shortansweropts->questionid = $questionid;
    //         $shortansweropts->usecase    = 0; // Or 1 if you want case-sensitive
    //         // Insert other fields if your table includes them (like answers, etc).
    //         $DB->insert_record('qtype_shortanswer_options', $shortansweropts);
    //     }

    //     // If other question types require their own sub-tables, insert them similarly here.

    //     // Retrieve the quiz cmid and add the question to the quiz
    //     $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
    //     $quiz->cmid = $cm->id;

    //     $pagenumber = 1;
    //     quiz_add_quiz_question($questionid, $quiz, $pagenumber);


    //     $slotrecord = $DB->get_record_sql(
    //         "SELECT * FROM {quiz_slots} WHERE quizid = :quizid
    //         ORDER BY id DESC
    //         LIMIT 1",
    //         ['quizid' => $quiz->id]
    //     );
        
    //     $newslot = null;
    //     if ($slotrecord) {
    //         $newslot = $slotrecord->slot;
    //     } else {
    //         debugging("No quiz slot record found for quizid {$quiz->id}", DEBUG_DEVELOPER);
    //     }
        

    //     quiz_update_sumgrades($quiz);
    //     quiz_save_best_grade($quiz, $USER->id);

    //     // Return success.
    //     return [
    //         'status'        => 'success',
    //         'questionid'    => $questionid,
    //         'quizid'        => $quiz->id,
    //         'slot'          => $newslot,
    //         'questionadded' => true
    //     ];
    // }


    /**
     * Create a question in a quiz with an optional category ID.
     *
     * @param int      $quizid       The ID of the quiz.
     * @param string   $qtype        The question type (e.g., 'shortanswer').
     * @param string   $name         The name of the question.
     * @param string   $questiontext The text of the question.
     * @param float    $defaultmark  The default mark for the question.
     * @param int|null $categoryid   (Optional) The ID of the question category.
     * @return array Result of the operation.
     * @throws moodle_exception If any database operation fails or capabilities are missing.
     */
    public static function create_question_in_quiz(
        int $quizid,
        string $qtype,
        string $name,
        string $questiontext,
        float $defaultmark = 1.0
    ) {
        global $DB, $CFG, $USER;
        $categoryid = 0;
        // Validate parameters.
        $params = self::validate_parameters(
            self::create_question_in_quiz_parameters(),
            [
                'quizid'       => $quizid,
                'qtype'        => $qtype,
                'name'         => $name,
                'questiontext' => $questiontext,
                'defaultmark'  => $defaultmark,
            ]
        );

        // Ensure the quiz exists.
        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);

        // Validate context and capability.
        $course = $DB->get_record('course', ['id' => $quiz->course], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('moodle/question:add', $coursecontext);

        // Determine category: use provided one or fetch/create default.
        $categoryid = self::get_or_create_default_category($course->id, $coursecontext);

        // Confirm the category exists.
        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);

        // Prepare question data for the 'question' table.
        $questiondata = new stdClass();
        $questiondata->category               = $category->id;
        $questiondata->name                   = $params['name'];
        $questiondata->questiontext           = $params['questiontext'];
        $questiondata->questiontextformat     = FORMAT_HTML;
        $questiondata->generalfeedback        = '';
        $questiondata->generalfeedbackformat  = FORMAT_HTML;
        $questiondata->defaultmark            = $params['defaultmark'];
        $questiondata->qtype                  = $qtype;
        $questiondata->parent                 = 0;
        $questiondata->stamp                  = '';
        $questiondata->version                = 1;
        $questiondata->timecreated            = time();
        $questiondata->timemodified           = time();
        $questiondata->createdby              = $USER->id;
        $questiondata->modifiedby             = $USER->id;

        // Insert into question table.
        $questionid = $DB->insert_record('question', $questiondata);
        if (!$questionid) {
            throw new moodle_exception('Failed to create question record in {question} table.');
        }

        // Insert into question_bank_entries.
        $qbentry = new stdClass();
        $qbentry->questioncategoryid = $category->id;  
        $qbentry->idnumber = (string)$questionid;
        $qbentry->ownerid            = $USER->id;      

        $qbentryid = $DB->insert_record('question_bank_entries', $qbentry);
        if (!$qbentryid) {
            throw new moodle_exception('Failed to create record in question_bank_entries.');
        }

        // Link question and bank entry.
        $qversion = new stdClass();
        $qversion->questionid          = $questionid;
        $qversion->questionbankentryid = $qbentryid;
        $qversion->version             = 1;
        $DB->insert_record('question_versions', $qversion);

        // Specific handling for shortanswer type.
        if ($qtype === 'shortanswer') {
            $shortansweropts = new stdClass();
            $shortansweropts->questionid = $questionid;
            $shortansweropts->usecase    = 0;
            $DB->insert_record('qtype_shortanswer_options', $shortansweropts);
        }

        // Retrieve the quiz cmid and add the question to the quiz.
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $quiz->cmid = $cm->id;
        $pagenumber = 1;
        quiz_add_quiz_question($questionid, $quiz, $pagenumber);

        $slotrecord = $DB->get_record_sql(
            "SELECT * FROM {quiz_slots} WHERE quizid = :quizid ORDER BY id DESC LIMIT 1",
            ['quizid' => $quiz->id]
        );

        $newslot = null;
        if ($slotrecord) {
            $newslot = $slotrecord->slot;
        } else {
            debugging("No quiz slot record found for quizid {$quiz->id}", DEBUG_DEVELOPER);
        }

        quiz_update_sumgrades($quiz);
        quiz_save_best_grade($quiz, $USER->id);

        return [
            'status'        => 'success',
            'questionid'    => $questionid,
            'quizid'        => $quiz->id,
            'slot'          => $newslot,
            'questionadded' => true
        ];
    }

    /**
     * Retrieve or create the default question category for a course.
     *
     * @param int            $courseid       The ID of the course.
     * @param context_course $coursecontext  The context of the course.
     * @return int The ID of the default question category.
     * @throws moodle_exception If category creation fails.
     */
    protected static function get_or_create_default_category($courseid, $coursecontext) {
        global $DB;

        $category = $DB->get_record('question_categories', [
            'contextid' => $coursecontext->id,
            'parent'    => 0,
            'name'      => 'Default'
        ]);

        if ($category) {
            return $category->id;
        }

        $newcategory = new stdClass();
        $newcategory->contextid  = $coursecontext->id;
        $newcategory->name       = 'Default';
        $newcategory->info       = '';
        $newcategory->infoformat = FORMAT_HTML;
        $newcategory->parent     = 0;
        $newcategory->sortorder  = 999;

        $newcategoryid = $DB->insert_record('question_categories', $newcategory);
        if (!$newcategoryid) {
            throw new moodle_exception('Failed to create default question category.');
        }

        return $newcategoryid;
    }


    public static function create_question_in_quiz_returns() {
        return new external_single_structure([
            'status'        => new external_value(PARAM_TEXT, 'Status of the operation'),
            'questionid'    => new external_value(PARAM_INT, 'ID of the newly created question'),
            'quizid'        => new external_value(PARAM_INT, 'ID of the quiz to which the question was added'),
            'slot'          => new external_value(PARAM_INT, 'Slot'),
            'questionadded' => new external_value(PARAM_BOOL, 'True if the question was successfully added'),
        ]);
    }



    public static function update_question_grade_parameters() {
        return new external_function_parameters([
            'quizid'     => new external_value(PARAM_INT, 'Quiz instance ID'),
            'userid'     => new external_value(PARAM_INT, 'User ID whose attempt will be updated'),
            'questionid' => new external_value(PARAM_INT, 'Question ID to update'),
            'grade'      => new external_value(PARAM_FLOAT, 'Grade as a fraction (0.0 to 1.0)'),
            'maxmark'    => new external_value(PARAM_FLOAT, 'Max Grade'),
            'comment'    => new external_value(PARAM_RAW, 'Optional comment', VALUE_DEFAULT, '')
        ]);
    }
    
    public static function update_question_grade_returns() {
        return new external_single_structure([
            'status'   => new external_value(PARAM_TEXT, 'Status of the operation'),
            'warnings' => new external_warnings()
        ]);
    }

    public static function update_question_grade($quizid, $userid = 0, $questionid, $grade, $maxmark, $comment = '') {
        global $DB, $USER, $CFG;
    
        // Validate incoming parameters.
        $params = self::validate_parameters(self::update_question_grade_parameters(), [
            'quizid'     => $quizid,
            'userid'     => $userid,
            'questionid' => $questionid,
            'grade'      => $grade,
            'maxmark'    => $maxmark,
            'comment'    => $comment
        ]);
    
        // Validate context for the quiz.
        list($quiz, $course, $cm, $context) = self::validate_quiz($params['quizid']);
    
        // Ensure the caller has grading capability.
        require_capability('mod/quiz:grade', $context);

        // Default value for userid.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }
    
        // 1. Retrieve the question attempt ID for given quiz, user, and question.
        $sql_qa = "SELECT qa.id AS questionattemptid
                   FROM {question_attempts} qa
                   JOIN {quiz_attempts} quiza ON quiza.uniqueid = qa.questionusageid
                   WHERE quiza.quiz = :quizid AND quiza.userid = :userid AND qa.questionid = :questionid";
        $qa_record = $DB->get_record_sql($sql_qa, [
            'quizid'     => $params['quizid'],
            'userid'     => $params['userid'],
            'questionid' => $params['questionid']
        ]);
        if (!$qa_record) {
            throw new moodle_exception('questionattemptnotfound', 'error', '', null, 'No matching question attempt found.');
        }
        $questionattemptid = $qa_record->questionattemptid;
    
        // 2. Determine the next sequence number.
        $maxseq = $DB->get_field_sql(
            "SELECT COALESCE(MAX(sequencenumber),0) FROM {question_attempt_steps} WHERE questionattemptid = ?",
            [$questionattemptid]
        );
        $nextseq = $maxseq + 1;
    
        // 3. Decide on the state based on the grade fraction.
        $state = ($grade === 1.0) ? 'gradedright'
               : (($grade === 0.0) ? 'gradedwrong' : 'mangrpartial');
        $timestamp = time();
    
        // 4. Insert a new attempt step and get the new step ID.
        $step = new stdClass();
        $step->questionattemptid = $questionattemptid;
        $step->sequencenumber    = $nextseq;
        $step->state             = $state;
        $step->fraction          = $grade/$maxmark;
        $step->timecreated       = $timestamp;
        $step->userid            = $USER->id;
        $newstepid = $DB->insert_record('question_attempt_steps', $step, true);
    
        // 5. Insert related step data.
        $datapairs = [
            '-comment'       => $params['comment'],
            '-mark'          => $grade,
            '-commentformat' => 1,
            '-maxmark'       => $maxmark
        ];
    
        foreach ($datapairs as $name => $value) {
            $data = new stdClass();
            $data->attemptstepid = $newstepid;
            $data->name          = $name;
            $data->value         = $value;
            $DB->insert_record('question_attempt_step_data', $data);
        }
    
        // // 6. Recalculate overall quiz grades.
        // require_once($CFG->dirroot.'/mod/quiz/locallib.php');
        // if ($quiz = $DB->get_record('quiz', ['id' => $params['quizid']])) {
        //     quiz_update_sumgrades($quiz);
        //     quiz_save_best_grade($quiz, $params['userid']);
        // }
    
        return [
            'status'   => 'success',
            'warnings' => []
        ];
    }
    
        /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function regrade_attempt_parameters() {
        return new external_function_parameters(array(
            'quizid'    => new external_value(PARAM_INT, 'ID of the quiz'),
            'attemptid' => new external_value(PARAM_INT, 'ID of the quiz attempt'),
            'userid'    => new external_value(PARAM_INT, 'ID of the user'),
        ));
    }

    /**
     * Regrade a specific quiz attempt for a specific user.
     *
     * @param int $quizid
     * @param int $attemptid
     * @param int $userid
     * @return array Result of the regrade operation.
     */
    public static function regrade_attempt($quizid, $attemptid, $userid) {
        global $DB;
    
        // Validate parameters and context.
        $params = self::validate_parameters(self::regrade_attempt_parameters(), [
            'quizid'    => $quizid,
            'attemptid' => $attemptid,
            'userid'    => $userid,
        ]);
    
        // Check if the attempt exists and is linked to the correct quiz/user.
        $attempt = $DB->get_record('quiz_attempts', [
            'id'      => $params['attemptid'],
            'quiz'    => $params['quizid'],
            'userid'  => $params['userid'],
            'preview' => 0
        ], '*', MUST_EXIST);
    
        // Load course module, quiz, and context.
        $quiz = $DB->get_record('quiz', ['id' => $params['quizid']], '*', MUST_EXIST);
        $cm   = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
    
        // Capability check: typically 'mod/quiz:regrade' or 'mod/quiz:manage'.
        require_capability('mod/quiz:regrade', $context);
    
        // Increase time if needed for large quizzes.
        \core_php_time_limit::raise(300);
    
        // Load the question usage for this attempt.
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
    
        // We'll do a real regrade (not dry-run).
        $dryrun = false;
    
        // Regrade all question slots.
        $slots = $quba->get_slots();
    
        // Start DB transaction to avoid partial updates if an error occurs.
        $transaction = $DB->start_delegated_transaction();
    
        $finished = ($attempt->state == \mod_quiz\quiz_attempt::FINISHED);
        $changes = [];
    
        foreach ($slots as $slot) {
            $oldfraction = $quba->get_question_fraction($slot);
            $quba->regrade_question($slot, $finished);
            $newfraction = $quba->get_question_fraction($slot);
    
            if (abs($oldfraction - $newfraction) > 1e-7) {
                $changes[$slot] = [
                    'oldfraction' => $oldfraction,
                    'newfraction' => $newfraction
                ];
            }
        }
    
        // Save updated question usage if not a dry run.
        if (!$dryrun) {
            \question_engine::save_questions_usage_by_activity($quba);
    
            // Trigger an event for regrading.
            $event = \mod_quiz\event\attempt_regraded::create([
                'objectid'      => $attempt->id,
                'relateduserid' => $attempt->userid,
                'context'       => $context,
                'other'         => ['quizid' => $quiz->id],
            ]);
            $event->trigger();
        }
    
        // Update final grades in quiz_grades table using the *new* grade calculator API.
        if (!$dryrun) {
            // 1. Recompute final grade for this user:
            $calculator = \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator();
            $calculator->recompute_all_attempt_sumgrades($attempt->userid);
            // Then recalc final grade for this user.
            $calculator->recompute_final_grade($attempt->userid);                
            // 2. Then push that new grade into the Moodle gradebook:
            quiz_update_grades($quiz, $attempt->userid);
        }
    
        // Commit transaction.
        $transaction->allow_commit();
    
        // Return a basic status or details about changed fractions.
        return [
            'status'   => 'Regrade completed',
            'messages' => 'Any messages'
        ];
    }
    


    /**
     * Returns description of method result value.
     *
     * @return external_description
     */
    public static function regrade_attempt_returns() {
        return new external_single_structure([
            'status'   => new external_value(PARAM_TEXT, 'Status info'),
            'messages' => new external_value(PARAM_TEXT, 'Any messages'),
        ]);
    }
    
}
