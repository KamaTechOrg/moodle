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

namespace mod_quiz\local;

use cm_info;
use context_module;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/lib.php');

/**
 * DB-backed helpers for override conflict resolution flows.
 *
 * This class is intentionally a thin integration layer:
 * - It loads the existing overrides + group memberships from Moodle APIs/DB
 * - It delegates conflict/merge-choice logic to {@see override_conflict_resolver} (pure)
 * - It applies teacher selections using the existing core save function {@see quiz_save_override_submission()}
 *
 * Keeping this in mod_quiz allows both:
 * - core override UI (mod/quiz/overrideedit.php)
 * - local plugin flows (local/quizoverriderequests)
 * to reuse identical behaviour without duplicating code.
 *
 * @package   mod_quiz
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class override_conflict_db_service {

    /**
     * Add a human-readable label (fullname + username) to each user entry.
     *
     * Input: list items containing at least ['userid' => int]
     * Output: same list items with ['label' => string]
     *
     * @param array<int,array<string,mixed>> $users
     * @return array<int,array<string,mixed>>
     */
    public static function decorate_users_with_identity_labels(array $users): array {
        global $DB;

        $userids = [];
        foreach ($users as $u) {
            if (!empty($u['userid'])) {
                $userids[] = (int)$u['userid'];
            }
        }
        $userids = array_values(array_unique(array_filter($userids)));
        if (!$userids) {
            return $users;
        }

        $records = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname, username');
        foreach ($users as &$u) {
            $uid = !empty($u['userid']) ? (int)$u['userid'] : 0;
            if ($uid && isset($records[$uid])) {
                $user = $records[$uid];
                $u['label'] = fullname($user) . ' (' . s($user->username) . ')';
            } else {
                $u['label'] = get_string('user') . ' #' . $uid;
            }
        }
        unset($u);

        return $users;
    }

    /**
     * Load override rows for a quiz, returned in the array-shapes expected by {@see override_conflict_resolver}.
     *
     * @param int $quizid
     * @param string[] $fields Supported subset of quiz_overrides fields.
     * @return array{useroverrides: array<int,array<string,mixed>>, groupoverrides: array<int,array<string,mixed>>}
     */
    public static function load_overrides_for_quiz(int $quizid, array $fields = ['timelimit', 'timeclose']): array {
        global $DB;

        $allowed = ['timeopen', 'timeclose', 'timelimit', 'attempts', 'password'];
        $fields = array_values(array_intersect($fields, $allowed));
        $select = array_merge(['id', 'quiz', 'userid', 'groupid'], $fields);
        $selectsql = implode(', ', $select);

        $rows = $DB->get_records_sql(
            "SELECT $selectsql
               FROM {quiz_overrides}
              WHERE quiz = :quizid",
            ['quizid' => $quizid]
        );

        $useroverrides = [];
        $groupoverrides = [];
        foreach ($rows as $row) {
            $a = (array)$row;
            if (!empty($row->userid)) {
                $useroverrides[] = $a;
            } else if (!empty($row->groupid)) {
                $groupoverrides[] = $a;
            }
        }

        return [
            'useroverrides' => $useroverrides,
            'groupoverrides' => $groupoverrides,
        ];
    }

    /**
     * Build a groupid => userids map for the provided group ids.
     *
     * @param int[] $groupids
     * @return array<int, array<int,int>>
     */
    public static function load_group_memberships(array $groupids): array {
        $out = [];
        foreach (array_values(array_unique(array_map('intval', $groupids))) as $gid) {
            if ($gid <= 0) {
                continue;
            }
            $members = groups_get_members($gid, 'u.id');
            $out[$gid] = $members ? array_values(array_map(static fn($u) => (int)$u->id, $members)) : [];
        }
        return $out;
    }

    /**
     * Convenience for the teacher UI: list group members who already have user overrides, with 3 merge choices.
     *
     * @param int $quizid
     * @param int $groupid
     * @param array<string,mixed> $requestedgroupoverride must include groupid + fields.
     * @param string[] $fields
     * @param int[]|null $scopeuserids optional filter
     * @return array<string,mixed>
     */
    public static function build_group_conflict_choice_list(
        int $quizid,
        int $groupid,
        array $requestedgroupoverride,
        array $fields = ['timelimit', 'timeclose'],
        ?array $scopeuserids = null
    ): array {
        global $DB;
        $overrides = self::load_overrides_for_quiz($quizid, $fields);
        $memberships = self::load_group_memberships([$groupid]);

        // Base quiz values are required for "both" = user + (group - base).
        $baseselect = array_merge(['id'], $fields);
        $basequiz = $DB->get_record('quiz', ['id' => $quizid], implode(',', $baseselect), MUST_EXIST);
        $basevalues = [];
        foreach ($fields as $f) {
            $basevalues[$f] = $basequiz->{$f};
        }

        $opts = ['fields' => $fields];
        $opts['basevalues'] = $basevalues;
        if ($scopeuserids !== null) {
            $opts['affecteduserids'] = $scopeuserids;
        }

        return override_conflict_resolver::list_group_members_with_user_override_merge_choices(
            $groupid,
            $overrides['useroverrides'],
            $requestedgroupoverride,
            $memberships,
            $opts
        );
    }

    /**
     * Build a single-user conflict choice payload when saving a user override for a user who is already
     * affected by group override(s).
     *
     * The "group" values shown here are the effective group override values for this user
     * (after lenient combining across all applicable group overrides).
     *
     * @param stdClass $quiz quiz row (must include id, course, and base field values)
     * @param int $userid
     * @param array<string,mixed> $requesteduseroverride field => value (as requested in the form)
     * @param string[] $fields fields to consider
     * @return array<string,mixed> compatible with override_conflicts_form custom data:
     *   { hasconflict: bool, users: [ {userid,label,choices,values} ] }
     */
    public static function build_user_conflict_choice_list(
        stdClass $quiz,
        int $userid,
        array $requesteduseroverride,
        array $fields = ['timelimit', 'timeclose', 'timeopen', 'attempts']
    ): array {
        global $DB;

        $userid = (int)$userid;
        if ($userid <= 0) {
            return ['hasconflict' => false, 'users' => []];
        }

        // Fetch all group overrides for groups the user belongs to in this course.
        $groupings = groups_get_user_groups((int)$quiz->course, $userid);
        $groupids = !empty($groupings[0]) ? array_values(array_unique(array_map('intval', $groupings[0]))) : [];
        if (!$groupids) {
            return ['hasconflict' => false, 'users' => []];
        }

        list($insql, $params) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
        $params['quizid'] = (int)$quiz->id;
        $grows = $DB->get_records_sql(
            "SELECT id, groupid, timeopen, timeclose, timelimit, attempts
               FROM {quiz_overrides}
              WHERE quiz = :quizid AND groupid $insql",
            $params
        );
        if (!$grows) {
            return ['hasconflict' => false, 'users' => []];
        }

        $groupoverrides = array_values(array_map(static fn($r) => (array)$r, $grows));
        $effectivegroup = self::calculate_effective_group_fields($groupoverrides, $fields);

        // Only ask if there is at least one overlapping field where both user request and group effective value exist.
        $overlap = false;
        foreach ($fields as $f) {
            if (($requesteduseroverride[$f] ?? null) !== null && ($effectivegroup[$f] ?? null) !== null) {
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            return ['hasconflict' => false, 'users' => []];
        }

        $basevalues = [];
        foreach ($fields as $f) {
            $basevalues[$f] = $quiz->{$f};
        }
        $both = override_conflict_resolver::apply_both_merge_relative_to_base($basevalues, $requesteduseroverride, $effectivegroup, $fields);

        $user = [
            'userid' => $userid,
            'fields' => $fields,
            'values' => [
                'user' => $requesteduseroverride,
                'group' => $effectivegroup,
                'both' => $both,
            ],
            'choices' => [
                ['id' => 'use_user', 'label' => 'Use user override', 'value' => $requesteduseroverride],
                ['id' => 'use_group', 'label' => 'Use group override', 'value' => $effectivegroup],
                ['id' => 'use_both', 'label' => 'Use both (user + group delta)', 'value' => $both],
            ],
        ];

        $user = self::decorate_users_with_identity_labels([$user])[0];

        return [
            'hasconflict' => true,
            'users' => [$user],
        ];
    }

    /**
     * Apply teacher-selected conflict resolutions when creating/updating a group override.
     *
     * This method does NOT change Moodle behaviour by itself; it only applies additional user-override updates
     * based on explicit selections (use user / use group / use both).
     *
     * Supported fields: timelimit, timeclose (others can be added later).
     *
     * @param stdClass $quiz quiz row
     * @param cm_info $cm
     * @param context_module $context
     * @param stdClass $groupfromform form-like data for the group override submission (userid/groupid + fields)
     * @param array<int,string> $selections map userid => choice id (use_user|use_group|use_both)
     * @param string[] $fields fields to apply (default timelimit,timeclose)
     * @return int group override id
     */
    public static function apply_group_override_with_conflict_resolutions(
        stdClass $quiz,
        cm_info $cm,
        context_module $context,
        stdClass $groupfromform,
        array $selections,
        array $fields = ['timelimit', 'timeclose']
    ): int {
        $groupmode = true;
        $groupoverrideid = quiz_save_override_submission($quiz, $cm, $context, $groupfromform, null, $groupmode);

        self::apply_user_override_conflict_resolutions_for_group_override(
            $quiz,
            $cm,
            $context,
            $groupfromform,
            $selections,
            $fields
        );

        return $groupoverrideid;
    }

    /**
     * Apply only the user-override updates implied by conflict-resolution selections.
     *
     * Use this when the group override already exists (or is being handled elsewhere), and you only
     * need to adjust user overrides based on explicit selections.
     *
     * @param stdClass $quiz
     * @param cm_info $cm
     * @param context_module $context
     * @param stdClass $groupfromform form-like group override data (groupid + fields)
     * @param array<int,string> $selections map userid => use_user|use_group|use_both
     * @param string[] $fields
     * @return void
     */
    public static function apply_user_override_conflict_resolutions_for_group_override(
        stdClass $quiz,
        cm_info $cm,
        context_module $context,
        stdClass $groupfromform,
        array $selections,
        array $fields = ['timelimit', 'timeclose']
    ): void {
        global $DB;

        if (empty($selections)) {
            return;
        }

        $groupid = (int)$groupfromform->groupid;
        $requestedgroupoverride = [
            'groupid' => $groupid,
        ];
        foreach ($fields as $f) {
            $requestedgroupoverride[$f] = property_exists($groupfromform, $f) ? $groupfromform->{$f} : null;
        }

        // Load the current user overrides for the selected users (those are the only ones we may update).
        list($insql, $params) = $DB->get_in_or_equal(array_keys($selections), SQL_PARAMS_NAMED);
        $params['quizid'] = $quiz->id;
        $userrows = $DB->get_records_sql(
            "SELECT *
               FROM {quiz_overrides}
              WHERE quiz = :quizid AND userid $insql",
            $params
        );

        $existinguseroverrides = [];
        foreach ($userrows as $row) {
            $existinguseroverrides[(int)$row->userid] = (array)$row;
        }

        foreach ($selections as $userid => $choice) {
            $userid = (int)$userid;
            if (!isset($existinguseroverrides[$userid])) {
                continue;
            }
            $existing = $existinguseroverrides[$userid];
            $uservalues = [];
            $groupvalues = [];
            foreach ($fields as $f) {
                $uservalues[$f] = $existing[$f] ?? null;
                $groupvalues[$f] = $requestedgroupoverride[$f] ?? null;
            }
            $basevalues = [];
            foreach ($fields as $f) {
                $basevalues[$f] = $quiz->{$f};
            }
            $both = override_conflict_resolver::apply_both_merge_relative_to_base($basevalues, $uservalues, $groupvalues, $fields);

            if ($choice === 'use_user') {
                continue;
            }

            // IMPORTANT:
            // quiz_save_override_submission() checks whether the "target" changed using strict comparisons (!==).
            // DB layer may return numeric fields as strings, so normalise types to avoid Moodle thinking the userid/groupid changed,
            // which would trigger deletion of the existing override before saving.
            $editing = (object)$existing;
            if (property_exists($editing, 'id') && $editing->id !== null) {
                $editing->id = (int)$editing->id;
            }
            if (property_exists($editing, 'userid') && $editing->userid !== null) {
                $editing->userid = (int)$editing->userid;
            }
            // Ensure groupid is null for user overrides (not 0 / "0").
            if (!property_exists($editing, 'groupid') || !$editing->groupid) {
                $editing->groupid = null;
            } else {
                $editing->groupid = (int)$editing->groupid;
            }
            $fromform = new stdClass();
            $fromform->userid = $userid;
            $fromform->groupid = null;

            if ($choice === 'use_group') {
                foreach ($fields as $f) {
                    $fromform->{$f} = null;
                }
            } else if ($choice === 'use_both') {
                foreach ($fields as $f) {
                    $fromform->{$f} = $both[$f];
                }
            } else {
                continue;
            }

            quiz_save_override_submission($quiz, $cm, $context, $fromform, $editing, false);
        }
    }

    /**
     * Apply a selected resolution when saving a user override and group overrides already apply.
     *
     * This does the minimal work needed to enforce the teacher's choice:
     * - use_user: leave requested values unchanged
     * - use_group: null out the specified fields in the user override (so group values apply)
     * - use_both: replace the specified fields with delta-based merged values
     *
     * @param stdClass $quiz quiz row
     * @param cm_info $cm
     * @param context_module $context
     * @param stdClass $userfromform requested user override form-like data (must include userid)
     * @param string $choice use_user|use_group|use_both
     * @param string[] $fields
     * @return void
     */
    public static function apply_user_override_resolution_against_groups(
        stdClass $quiz,
        cm_info $cm,
        context_module $context,
        stdClass $userfromform,
        string $choice,
        array $fields = ['timelimit', 'timeclose', 'timeopen', 'attempts']
    ): void {
        global $DB;

        $userid = !empty($userfromform->userid) ? (int)$userfromform->userid : 0;
        if ($userid <= 0) {
            return;
        }
        if ($choice === 'use_user') {
            return;
        }

        // Compute effective group fields for this user.
        $payload = self::build_user_conflict_choice_list(
            $quiz,
            $userid,
            array_combine($fields, array_map(static fn($f) => property_exists($userfromform, $f) ? $userfromform->{$f} : null, $fields)),
            $fields
        );
        if (empty($payload['users'][0]['values']['group'])) {
            return;
        }
        $effectivegroup = $payload['users'][0]['values']['group'];
        $basevalues = [];
        $uservalues = [];
        foreach ($fields as $f) {
            $basevalues[$f] = $quiz->{$f};
            $uservalues[$f] = property_exists($userfromform, $f) ? $userfromform->{$f} : null;
        }
        $both = override_conflict_resolver::apply_both_merge_relative_to_base($basevalues, $uservalues, $effectivegroup, $fields);

        // Load the just-saved (or existing) user override row so we can update it safely.
        $editing = $DB->get_record('quiz_overrides', ['quiz' => (int)$quiz->id, 'userid' => $userid], '*', MUST_EXIST);
        // Normalise types to avoid strict comparison issues in quiz_save_override_submission().
        $editing->id = (int)$editing->id;
        $editing->userid = (int)$editing->userid;
        $editing->groupid = null;

        $fromform = new stdClass();
        $fromform->userid = $userid;
        $fromform->groupid = null;

        if ($choice === 'use_group') {
            foreach ($fields as $f) {
                $fromform->{$f} = null;
            }
        } else if ($choice === 'use_both') {
            foreach ($fields as $f) {
                $fromform->{$f} = $both[$f];
            }
        } else {
            return;
        }

        quiz_save_override_submission($quiz, $cm, $context, $fromform, $editing, false);
    }

    /**
     * Effective group fields (lenient combine) for the supported fields.
     *
     * @param array<int, array<string,mixed>> $groupoverrides
     * @param string[] $fields
     * @return array<string, int|null>
     */
    private static function calculate_effective_group_fields(array $groupoverrides, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $values = [];
            foreach ($groupoverrides as $go) {
                if (array_key_exists($field, $go) && $go[$field] !== null) {
                    $values[] = (int)$go[$field];
                }
            }
            if (!$values) {
                $out[$field] = null;
                continue;
            }
            if (in_array($field, ['timelimit', 'timeclose', 'attempts'], true) && in_array(0, $values, true)) {
                $out[$field] = 0;
                continue;
            }
            if ($field === 'timeopen') {
                $out[$field] = min($values);
                continue;
            }
            $out[$field] = max($values);
        }
        return $out;
    }
}

