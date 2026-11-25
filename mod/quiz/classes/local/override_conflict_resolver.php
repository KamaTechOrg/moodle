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

defined('MOODLE_INTERNAL') || die();

/**
 * Helper for detecting and describing user/group override conflicts.
 *
 * Moodle's effective access algorithm currently applies overrides in this order:
 * - User override fields (if present) take precedence.
 * - Otherwise, group overrides are combined (most lenient values per field).
 *
 * This class centralises conflict detection and "preview" logic without changing that behaviour.
 * Callers can use the returned structured data to present a UI (or to decide what to do next).
 *
 * This helper is intentionally UI-agnostic:
 * - No DB calls
 * - No use of $PAGE / form classes
 * - Data in / data out
 *
 * @package   mod_quiz
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class override_conflict_resolver {

    /** Conflict type: a group override applies to a user with an existing user override (user override will shadow). */
    public const CONFLICT_EXISTING_USER_SHADOWS_REQUESTED_GROUP = 'existing_user_shadows_requested_group';
    /** Conflict type: a user override is requested for a user with applicable group overrides (requested user override will shadow). */
    public const CONFLICT_REQUESTED_USER_SHADOWS_EXISTING_GROUPS = 'requested_user_shadows_existing_groups';
    /** Conflict type: requested group override overlaps existing group overrides (may combine, but preview is needed). */
    public const CONFLICT_REQUESTED_GROUP_OVERLAPS_EXISTING_GROUPS = 'requested_group_overlaps_existing_groups';

    /**
     * Lightweight check for the two core scenarios (no UI specifics):
     * - A requested group override affects users who already have user overrides.
     * - A requested user override targets a user who is in groups with group overrides.
     *
     * This is designed for quick validation when submitting/approving overrides.
     *
     * @param array<int, array<string, mixed>> $existinguseroverrides
     * @param array<int, array<string, mixed>> $existinggroupoverrides
     * @param array<string, mixed> $requestedoverride Must have exactly one of userid/groupid.
     * @param array<int, array<int, int>> $groupmemberships groupid => [userid...]
     * @return array<string, mixed> { hasconflict: bool, affecteduserids: int[], kind: string }
     */
    public static function has_user_group_override_conflict(
        array $existinguseroverrides,
        array $existinggroupoverrides,
        array $requestedoverride,
        array $groupmemberships
    ): array {
        $result = self::resolve_time_extension_group_conflicts(
            $existinguseroverrides,
            $existinggroupoverrides,
            $requestedoverride,
            $groupmemberships,
            ['includepreview' => false]
        );

        // Normalise to a simple "check" payload.
        $kind = 'none';
        foreach ($result['conflicts'] as $c) {
            if (($c['type'] ?? '') === self::CONFLICT_EXISTING_USER_SHADOWS_REQUESTED_GROUP) {
                $kind = 'requested_group_has_users_with_user_overrides';
                break;
            }
            if (($c['type'] ?? '') === self::CONFLICT_REQUESTED_USER_SHADOWS_EXISTING_GROUPS) {
                $kind = 'requested_user_in_groups_with_group_overrides';
                // Keep scanning in case we find the other kind too.
            }
        }

        return [
            'hasconflict' => (bool)$result['hasconflict'],
            'affecteduserids' => $result['affecteduserids'] ?? [],
            'kind' => $kind,
        ];
    }

    /**
     * Build a per-user list for a teacher UI when a group override is being created/edited.
     *
     * This targets the exact UX you described:
     * - show group members who already have a user override (for the relevant fields)
     * - for each user, present 3 choices:
     *   1) keep/select user override
     *   2) select group override
     *   3) both (user + group) (additive for timelimit; lenient for timeclose)
     *
     * This method is still no-DB: it only returns preview/choice data.
     *
     * @param int $groupid
     * @param array<int, array<string, mixed>> $existinguseroverrides Must include userid, and fields to compare.
     * @param array<string, mixed> $requestedgroupoverride Must include groupid and at least one supported field.
     * @param array<int, array<int, int>> $groupmemberships groupid => [userid...]
     * @param array<string, mixed> $options Supported keys:
     *     - fields (string[]): which fields to consider (default ['timelimit','timeclose'])
     *     - affecteduserids (int[]): optional scope filter
     * @return array<string, mixed> {
     *   hasconflict: bool,
     *   groupid: int,
     *   fields: string[],
     *   users: array<int, array<string,mixed>> list of per-user choice payloads
     * }
     */
    public static function list_group_members_with_user_override_merge_choices(
        int $groupid,
        array $existinguseroverrides,
        array $requestedgroupoverride,
        array $groupmemberships,
        array $options = []
    ): array {
        $fields = isset($options['fields']) ? array_values($options['fields']) : ['timelimit', 'timeclose'];
        $scopeuserids = isset($options['affecteduserids']) ? array_values(array_unique(array_map('intval', $options['affecteduserids']))) : null;
        $basevalues = isset($options['basevalues']) && is_array($options['basevalues']) ? $options['basevalues'] : [];

        if (!isset($requestedgroupoverride['groupid'])) {
            $requestedgroupoverride['groupid'] = $groupid;
        }
        if ((int)$requestedgroupoverride['groupid'] !== $groupid) {
            throw new \coding_exception('Requested group override groupid mismatch.');
        }

        $existinguserbyuserid = self::index_by_int_key($existinguseroverrides, 'userid');
        $groupuserids = $groupmemberships[$groupid] ?? [];
        $groupuserids = array_values(array_unique(array_map('intval', $groupuserids)));
        if ($scopeuserids !== null) {
            $groupuserids = array_values(array_intersect($groupuserids, $scopeuserids));
        }

        $users = [];
        foreach ($groupuserids as $userid) {
            $useroverride = $existinguserbyuserid[$userid] ?? null;
            if (!$useroverride) {
                continue;
            }

            // Only include users where the user override actually sets at least one of the relevant fields.
            $considered = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $useroverride) && $useroverride[$f] !== null) {
                    $considered[] = $f;
                }
            }
            if (empty($considered)) {
                continue;
            }

            $uservalues = self::extract_fields($useroverride, $fields);
            $groupvalues = self::extract_fields($requestedgroupoverride, $fields);
            $fieldmerges = self::build_field_merge_choices($uservalues, $groupvalues, $fields);

            // The teacher UI wants 3 top-level options per user. We compute "both" from the per-field rules.
            $bothvalues = self::apply_both_merge_relative_to_base($basevalues, $uservalues, $groupvalues, $fields);

            $users[] = [
                'userid' => $userid,
                'useroverrideid' => isset($useroverride['id']) ? (int)$useroverride['id'] : null,
                'fields' => $fields,
                'values' => [
                    'user' => $uservalues,
                    'group' => $groupvalues,
                    'both' => $bothvalues,
                ],
                'choices' => [
                    ['id' => 'use_user', 'label' => 'Use user override', 'value' => $uservalues],
                    ['id' => 'use_group', 'label' => 'Use group override', 'value' => $groupvalues],
                    ['id' => 'use_both', 'label' => 'Use both (user + group)', 'value' => $bothvalues],
                ],
                // Optional: if you want to drive a per-field UI, this is already included.
                'fieldmerges' => $fieldmerges,
            ];
        }

        return [
            'hasconflict' => !empty($users),
            'groupid' => $groupid,
            'fields' => $fields,
            'users' => $users,
        ];
    }

    /**
     * Resolve and describe potential conflicts related to time extensions (quiz `timelimit`).
     *
     * The primary behaviour issue this targets is the existing Moodle precedence:
     * if a user has a user override, and later a group override is added for a group containing that user,
     * that user remains affected only by the user override for fields it sets (e.g. `timelimit`).
     *
     * Input shapes (arrays are deliberate to keep this DB-agnostic and easy to call from different flows):
     *
     * - $existinguseroverrides: list of user override rows/records
     *   Each item must have: userid (int), timelimit (int|null), id (int, optional)
     *
     * - $existinggroupoverrides: list of group override rows/records
     *   Each item must have: groupid (int), timelimit (int|null), id (int, optional)
     *
     * - $requestedoverride: an override candidate (new request or pending submission)
     *   Must have exactly one of: userid (int) OR groupid (int)
     *   And a timelimit (int|null). If timelimit is null, this resolver treats it as "does not change timelimit".
     *
     * - $groupmemberships: map of groupid => list of userids, used to expand group targets to affected users.
     *
     * @param array<int, array<string, mixed>> $existinguseroverrides
     * @param array<int, array<string, mixed>> $existinggroupoverrides
     * @param array<string, mixed> $requestedoverride
     * @param array<int, array<int, int>> $groupmemberships
     * @param array<string, mixed> $options Supported keys:
     *     - affecteduserids (int[]): optional filter for "users in scope" when the requested override targets a group
     *     - includepreview (bool): include per-user preview (default true)
     * @return array<string, mixed> Structured result:
     *     - hasconflict (bool)
     *     - conflicts (array<int, array<string, mixed>>): list of conflict records
     *     - affecteduserids (int[]): unique list of userids impacted by any conflict
     *     - preview (array<int, array<string, mixed>>): keyed by userid; only present when includepreview=true
     */
    public static function resolve_time_extension_group_conflicts(
        array $existinguseroverrides,
        array $existinggroupoverrides,
        array $requestedoverride,
        array $groupmemberships,
        array $options = []
    ): array {
        $includepreview = array_key_exists('includepreview', $options) ? (bool)$options['includepreview'] : true;
        $scopeuserids = isset($options['affecteduserids']) ? array_values(array_unique(array_map('intval', $options['affecteduserids']))) : null;

        $existinguserbyuserid = self::index_by_int_key($existinguseroverrides, 'userid');
        $existinggroupbygroupid = self::index_by_int_key($existinggroupoverrides, 'groupid');

        $conflicts = [];
        $affecteduserids = [];
        $preview = [];

        $requesteduserid = isset($requestedoverride['userid']) ? (int)$requestedoverride['userid'] : 0;
        $requestedgroupid = isset($requestedoverride['groupid']) ? (int)$requestedoverride['groupid'] : 0;

        if (($requesteduserid > 0) === ($requestedgroupid > 0)) {
            throw new \coding_exception('Requested override must have exactly one of userid or groupid.');
        }

        // This resolver is currently focused on "time extension" conflicts but UIs often want to
        // show a field-by-field merge choice for related time fields too (e.g. timeclose).
        // If the requested override does not touch either timelimit or timeclose, there is no relevant conflict to resolve.
        // Keep returning the same structured shape, but with no conflicts.
        $requestedtimelimitset = array_key_exists('timelimit', $requestedoverride) && $requestedoverride['timelimit'] !== null;
        $requestedtimecloseset = array_key_exists('timeclose', $requestedoverride) && $requestedoverride['timeclose'] !== null;
        if (!$requestedtimelimitset && !$requestedtimecloseset) {
            return [
                'hasconflict' => false,
                'conflicts' => [],
                'affecteduserids' => [],
                'preview' => $includepreview ? [] : null,
            ];
        }

        if ($requestedgroupid > 0) {
            $groupuserids = $groupmemberships[$requestedgroupid] ?? [];
            $groupuserids = array_values(array_unique(array_map('intval', $groupuserids)));
            if ($scopeuserids !== null) {
                $groupuserids = array_values(array_intersect($groupuserids, $scopeuserids));
            }

            foreach ($groupuserids as $userid) {
                $useroverride = $existinguserbyuserid[$userid] ?? null;
                $shadowedfields = self::shadowed_time_fields_for_user_override($useroverride, $requestedoverride);
                if ($useroverride && !empty($shadowedfields)) {
                    $groupvalues = self::extract_fields($requestedoverride, $shadowedfields);
                    $uservalues = self::extract_fields($useroverride, $shadowedfields);
                    $conflicts[] = [
                        'type' => self::CONFLICT_EXISTING_USER_SHADOWS_REQUESTED_GROUP,
                        'userid' => $userid,
                        'requested' => [
                            'groupid' => $requestedgroupid,
                            'fields' => $groupvalues,
                        ],
                        'existing' => [
                            'useroverrideid' => isset($useroverride['id']) ? (int)$useroverride['id'] : null,
                            'fields' => $uservalues,
                        ],
                        // Field-level merge preview for later UI use.
                        // "group" here represents the requested group override values for the same fields.
                        'fieldmerges' => self::build_field_merge_choices($uservalues, $groupvalues, $shadowedfields),
                        'resolutionoptions' => self::resolution_options_for_shadowed_group(),
                    ];
                    $affecteduserids[] = $userid;
                }
            }

            // Group override overlaps existing group overrides for users who are in both groups.
            // This isn't necessarily "wrong", but it is important preview data for later UI.
            $overlappedgroupids = self::find_group_overlap_groupids($requestedgroupid, array_keys($existinggroupbygroupid), $groupmemberships);
            if ($overlappedgroupids) {
                $conflicts[] = [
                    'type' => self::CONFLICT_REQUESTED_GROUP_OVERLAPS_EXISTING_GROUPS,
                    'requested' => [
                        'groupid' => $requestedgroupid,
                        'timelimit' => (int)$requestedoverride['timelimit'],
                    ],
                    'existing' => [
                        'overlappinggroupids' => $overlappedgroupids,
                    ],
                    'resolutionoptions' => self::resolution_options_for_overlapping_groups(),
                ];
            }

            if ($includepreview) {
                foreach ($groupuserids as $userid) {
                    $preview[$userid] = self::build_time_extension_preview_for_user(
                        $userid,
                        $requestedoverride,
                        $existinguserbyuserid,
                        $existinggroupbygroupid,
                        $groupmemberships
                    );
                }
            }
        }

        if ($requesteduserid > 0) {
            // Determine existing group overrides which apply to the user, based on provided memberships.
            $applicablegroupids = self::groupids_for_userid($requesteduserid, $groupmemberships);
            $applicablegroupoverrides = [];
            foreach ($applicablegroupids as $gid) {
                if (isset($existinggroupbygroupid[$gid])) {
                    $applicablegroupoverrides[] = $existinggroupbygroupid[$gid];
                }
            }

            $groupfields = self::effective_group_time_fields_for_user($applicablegroupoverrides, $requestedoverride);

            if (!empty($groupfields)) {
                $groupvalues = self::calculate_effective_group_fields($applicablegroupoverrides, array_keys($groupfields));
                $requestedfields = self::extract_fields($requestedoverride, array_keys($groupfields));
                $conflicts[] = [
                    'type' => self::CONFLICT_REQUESTED_USER_SHADOWS_EXISTING_GROUPS,
                    'userid' => $requesteduserid,
                    'requested' => [
                        'userid' => $requesteduserid,
                        'fields' => $requestedfields,
                    ],
                    'existing' => [
                        'groupids' => $applicablegroupids,
                        'fields' => $groupvalues,
                    ],
                    // Field-level merge preview: user (requested) vs effective group values.
                    'fieldmerges' => self::build_field_merge_choices($requestedfields, $groupvalues, array_keys($groupfields)),
                    'resolutionoptions' => self::resolution_options_for_shadowing_user(),
                ];
                $affecteduserids[] = $requesteduserid;
            }

            if ($includepreview) {
                $preview[$requesteduserid] = self::build_time_extension_preview_for_user(
                    $requesteduserid,
                    $requestedoverride,
                    $existinguserbyuserid,
                    $existinggroupbygroupid,
                    $groupmemberships
                );
            }
        }

        $affecteduserids = array_values(array_unique(array_map('intval', $affecteduserids)));

        return [
            'hasconflict' => !empty($conflicts),
            'conflicts' => $conflicts,
            'affecteduserids' => $affecteduserids,
            'preview' => $includepreview ? $preview : null,
        ];
    }

    /**
     * Calculate the effective `timelimit` outcome for a user, following Moodle's current precedence rules.
     *
     * This is a small, testable helper that can be reused by UIs to present a preview:
     * - If the user override provides timelimit, it wins.
     * - Otherwise, group overrides combine leniently:
     *   - if any group override timelimit is 0, effective is 0 (unlimited)
     *   - else effective is max(timelimit) across applicable groups
     *
     * @param array<string, mixed>|null $useroverride A single user override row (or null).
     * @param array<int, array<string, mixed>> $groupoverrides Applicable group override rows (can be empty).
     * @param int|null $basetimelimit Optional base timelimit (quiz default) for UI display. Not used in calculation.
     * @return array<string, mixed> { source: 'user'|'group'|'none', timelimit: int|null, groupids: int[] }
     */
    public static function calculate_effective_timelimit_for_user(?array $useroverride, array $groupoverrides, ?int $basetimelimit = null): array {
        if ($useroverride && array_key_exists('timelimit', $useroverride) && $useroverride['timelimit'] !== null) {
            return [
                'source' => 'user',
                'timelimit' => (int)$useroverride['timelimit'],
                'groupids' => [],
                'base' => $basetimelimit,
            ];
        }

        $limits = [];
        $groupids = [];
        foreach ($groupoverrides as $go) {
            if (!isset($go['groupid'])) {
                continue;
            }
            $groupids[] = (int)$go['groupid'];
            if (array_key_exists('timelimit', $go) && $go['timelimit'] !== null) {
                $limits[] = (int)$go['timelimit'];
            }
        }
        $groupids = array_values(array_unique($groupids));

        if (empty($limits)) {
            return [
                'source' => 'none',
                'timelimit' => null,
                'groupids' => $groupids,
                'base' => $basetimelimit,
            ];
        }

        if (in_array(0, $limits, true)) {
            return [
                'source' => 'group',
                'timelimit' => 0,
                'groupids' => $groupids,
                'base' => $basetimelimit,
            ];
        }

        return [
            'source' => 'group',
            'timelimit' => max($limits),
            'groupids' => $groupids,
            'base' => $basetimelimit,
        ];
    }

    /**
     * Build a field-by-field merge choice structure for a UI.
     *
     * For each requested field, includes:
     * - the user value
     * - the group (effective group or requested group) value
     * - selectable "merge choices" with the resulting value
     *
     * This does not apply any change; it just provides preview data so a UI can ask:
     * "For each differing field, do you want the user value, the group value, or a combined value?"
     *
     * "Combined" is field-dependent:
     * - timelimit: provides two combined options:
     *   - combine_lenient: current Moodle lenient behaviour (0 if either 0 else max)
     *   - combine_additive: user + group (except 0/unlimited wins if either is 0)
     * - timeclose: combine_lenient (0 if either 0 else max)
     * - timeopen: combine_lenient (min)
     * - attempts: combine_lenient (0 if either 0 else max)
     *
     * @param array<string, mixed> $uservalues field => value (int|null)
     * @param array<string, mixed> $groupvalues field => value (int|null)
     * @param string[] $fields
     * @return array<string, array<string, mixed>> field => { user, group, choices[] }
     */
    public static function build_field_merge_choices(array $uservalues, array $groupvalues, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $user = array_key_exists($field, $uservalues) ? $uservalues[$field] : null;
            $group = array_key_exists($field, $groupvalues) ? $groupvalues[$field] : null;

            // Only show a choice if the values differ (including one being null).
            if ($user === $group) {
                continue;
            }

            $choices = [
                [
                    'id' => 'use_user',
                    'label' => 'Use user value',
                    'value' => $user,
                ],
                [
                    'id' => 'use_group',
                    'label' => 'Use group value',
                    'value' => $group,
                ],
            ];

            if (in_array($field, ['timelimit', 'timeclose', 'timeopen', 'attempts'], true)) {
                $lenient = self::combine_numeric_field_lenient($field, $user, $group);
                $choices[] = [
                    'id' => 'combine_lenient',
                    'label' => 'Use combined (lenient)',
                    'value' => $lenient,
                ];
            }

            if ($field === 'timelimit') {
                $add = self::combine_timelimit_additive($user, $group);
                $choices[] = [
                    'id' => 'combine_additive',
                    'label' => 'Use combined (user + group)',
                    'value' => $add,
                ];
            }

            $out[$field] = [
                'user' => $user,
                'group' => $group,
                'choices' => $choices,
            ];
        }
        return $out;
    }

    /**
     * Apply the "both" (user + group) merge for the supported fields.
     *
     * This is intended for the teacher UI option "make them both".
     * It produces a per-field merged value:
     * - timelimit: additive, with 0 (unlimited) winning if either is 0
     * - timeclose: lenient (0 if either 0 else max)
     * - timeopen: lenient (min)
     * - attempts: lenient (0 if either 0 else max)
     *
     * @param array<string, mixed> $uservalues
     * @param array<string, mixed> $groupvalues
     * @param string[] $fields
     * @return array<string, mixed>
     */
    public static function apply_both_merge(array $uservalues, array $groupvalues, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $user = array_key_exists($field, $uservalues) ? $uservalues[$field] : null;
            $group = array_key_exists($field, $groupvalues) ? $groupvalues[$field] : null;

            if ($field === 'timelimit') {
                $out[$field] = self::combine_timelimit_additive($user, $group);
                continue;
            }
            if (in_array($field, ['timeclose', 'timeopen', 'attempts'], true)) {
                $out[$field] = self::combine_numeric_field_lenient($field, $user, $group);
                continue;
            }
            // For unsupported fields, default to "user wins if set else group".
            $out[$field] = ($user !== null) ? $user : $group;
        }
        return $out;
    }

    /**
     * Apply "both" as "user override plus the delta introduced by group override".
     *
     * This matches the intended UX:
     * - If the base quiz had timelimit 3h, group override sets 3.5h, and user override is 4h,
     *   then "both" should yield 4.5h (user + 0.5h), not 7.5h (user + group).
     *
     * Rules:
     * - For numeric/time fields, delta is (group - base) and the resulting value is (user + delta).
     * - If user value is null (no user override for that field), fall back to group value.
     * - If group value is null (group does not override the field), keep user value.
     * - Special unlimited semantics:
     *   - timelimit 0 means unlimited. If either user or group is 0, result is 0.
     *   - attempts 0 means unlimited. If either user or group is 0, result is 0.
     *
     * @param array<string,mixed> $basevalues field => base quiz value (int|mixed)
     * @param array<string,mixed> $uservalues
     * @param array<string,mixed> $groupvalues
     * @param string[] $fields Supported: timelimit,timeopen,timeclose,attempts
     * @return array<string,mixed>
     */
    public static function apply_both_merge_relative_to_base(
        array $basevalues,
        array $uservalues,
        array $groupvalues,
        array $fields
    ): array {
        $out = [];
        foreach ($fields as $field) {
            $base = array_key_exists($field, $basevalues) ? $basevalues[$field] : null;
            $user = array_key_exists($field, $uservalues) ? $uservalues[$field] : null;
            $group = array_key_exists($field, $groupvalues) ? $groupvalues[$field] : null;

            // If group doesn't set it, nothing to add.
            if ($group === null) {
                $out[$field] = $user;
                continue;
            }
            // If user doesn't set it, use group.
            if ($user === null) {
                $out[$field] = $group;
                continue;
            }

            // Unlimited semantics.
            if ($field === 'timelimit') {
                $u = (int)$user;
                $g = (int)$group;
                if ($u === 0 || $g === 0) {
                    $out[$field] = 0;
                    continue;
                }
            }
            if ($field === 'attempts') {
                $u = (int)$user;
                $g = (int)$group;
                if ($u === 0 || $g === 0) {
                    $out[$field] = 0;
                    continue;
                }
            }

            // If base is unknown, we cannot compute delta safely; fall back to lenient combine.
            if ($base === null) {
                if ($field === 'timelimit') {
                    $out[$field] = self::combine_timelimit_additive($user, $group);
                } else if (in_array($field, ['timeclose', 'timeopen', 'attempts'], true)) {
                    $out[$field] = self::combine_numeric_field_lenient($field, $user, $group);
                } else {
                    $out[$field] = $user;
                }
                continue;
            }

            // Delta-based merge.
            $delta = (int)$group - (int)$base;
            $out[$field] = (int)$user + $delta;
        }
        return $out;
    }

    /**
     * Build a per-user "before/after" preview for timelimit changes.
     *
     * @param int $userid
     * @param array<string, mixed> $requestedoverride
     * @param array<int, array<string, mixed>> $existinguserbyuserid
     * @param array<int, array<string, mixed>> $existinggroupbygroupid
     * @param array<int, array<int, int>> $groupmemberships
     * @return array<string, mixed>
     */
    private static function build_time_extension_preview_for_user(
        int $userid,
        array $requestedoverride,
        array $existinguserbyuserid,
        array $existinggroupbygroupid,
        array $groupmemberships
    ): array {
        $useroverride = $existinguserbyuserid[$userid] ?? null;
        $beforegroupoverrides = [];
        foreach (self::groupids_for_userid($userid, $groupmemberships) as $gid) {
            if (isset($existinggroupbygroupid[$gid])) {
                $beforegroupoverrides[] = $existinggroupbygroupid[$gid];
            }
        }

        $before = self::calculate_effective_timelimit_for_user($useroverride, $beforegroupoverrides);

        // After: apply the requested override in addition to existing ones.
        $afteruseroverride = $useroverride;
        $aftergroupoverrides = $beforegroupoverrides;

        if (isset($requestedoverride['userid']) && (int)$requestedoverride['userid'] === $userid) {
            $afteruseroverride = $requestedoverride;
        }
        if (isset($requestedoverride['groupid'])) {
            $rgid = (int)$requestedoverride['groupid'];
            $inrequestedgroup = in_array($userid, array_map('intval', $groupmemberships[$rgid] ?? []), true);
            if ($inrequestedgroup) {
                $aftergroupoverrides[] = $requestedoverride;
            }
        }

        $after = self::calculate_effective_timelimit_for_user($afteruseroverride, $aftergroupoverrides);

        return [
            'userid' => $userid,
            'before' => $before,
            'after' => $after,
            'changed' => $before['timelimit'] !== $after['timelimit'] || $before['source'] !== $after['source'],
        ];
    }

    /**
     * @param array<string, mixed>|null $useroverride
     * @param array<string, mixed> $requestedoverride
     * @return string[] list of fields (subset of ['timelimit', 'timeclose']) which are set in both and differ.
     */
    private static function shadowed_time_fields_for_user_override(?array $useroverride, array $requestedoverride): array {
        if (!$useroverride) {
            return [];
        }
        $fields = [];
        foreach (['timelimit', 'timeclose'] as $field) {
            $requestedset = array_key_exists($field, $requestedoverride) && $requestedoverride[$field] !== null;
            $userset = array_key_exists($field, $useroverride) && $useroverride[$field] !== null;
            if ($requestedset && $userset) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Fields that are set by group overrides (or requested group override) and therefore would be shadowed by a user override.
     *
     * @param array<int, array<string, mixed>> $applicablegroupoverrides
     * @param array<string, mixed> $requestedoverride
     * @return array<string, bool> map field => true
     */
    private static function effective_group_time_fields_for_user(array $applicablegroupoverrides, array $requestedoverride): array {
        $fields = [];
        foreach (['timelimit', 'timeclose'] as $field) {
            $requestedset = array_key_exists($field, $requestedoverride) && $requestedoverride[$field] !== null;
            if ($requestedset) {
                $fields[$field] = true;
                continue;
            }
            foreach ($applicablegroupoverrides as $go) {
                if (array_key_exists($field, $go) && $go[$field] !== null) {
                    $fields[$field] = true;
                    break;
                }
            }
        }
        return $fields;
    }

    /**
     * Extract a set of fields from an override array (missing becomes null).
     *
     * @param array<string, mixed> $override
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private static function extract_fields(array $override, array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = array_key_exists($field, $override) ? $override[$field] : null;
        }
        return $out;
    }

    /**
     * Calculate "effective group" values for the given fields, combining applicable group overrides leniently.
     *
     * This mirrors the group-combination logic in {@see quiz_update_effective_access()} for the supported fields.
     *
     * @param array<int, array<string, mixed>> $groupoverrides
     * @param string[] $fields Supported: timelimit, timeclose, timeopen, attempts.
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
            if (empty($values)) {
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

    /**
     * Combine supported numeric fields leniently.
     *
     * @param string $field
     * @param mixed $user
     * @param mixed $group
     * @return int|null
     */
    private static function combine_numeric_field_lenient(string $field, $user, $group): ?int {
        $u = ($user === null) ? null : (int)$user;
        $g = ($group === null) ? null : (int)$group;
        if ($u === null) {
            return $g;
        }
        if ($g === null) {
            return $u;
        }

        if (in_array($field, ['timelimit', 'timeclose', 'attempts'], true)) {
            if ($u === 0 || $g === 0) {
                return 0;
            }
            return max($u, $g);
        }

        if ($field === 'timeopen') {
            return min($u, $g);
        }

        return max($u, $g);
    }

    /**
     * Combine timelimits additively, modelling the UI option "user + group".
     *
     * Note: In Moodle, timelimit 0 means unlimited. For additive combine:
     * - if either value is 0 (unlimited), combined is 0 (unlimited)
     * - otherwise combined is user + group
     *
     * @param mixed $user
     * @param mixed $group
     * @return int|null
     */
    private static function combine_timelimit_additive($user, $group): ?int {
        $u = ($user === null) ? null : (int)$user;
        $g = ($group === null) ? null : (int)$group;
        if ($u === null) {
            return $g;
        }
        if ($g === null) {
            return $u;
        }
        if ($u === 0 || $g === 0) {
            return 0;
        }
        return $u + $g;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param string $key
     * @return array<int, array<string, mixed>>
     */
    private static function index_by_int_key(array $rows, string $key): array {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists($key, $row) && $row[$key] !== null) {
                $out[(int)$row[$key]] = $row;
            }
        }
        return $out;
    }

    /**
     * @param int $userid
     * @param array<int, array<int, int>> $groupmemberships
     * @return int[] group ids
     */
    private static function groupids_for_userid(int $userid, array $groupmemberships): array {
        $groupids = [];
        foreach ($groupmemberships as $gid => $members) {
            if (in_array($userid, array_map('intval', $members), true)) {
                $groupids[] = (int)$gid;
            }
        }
        return array_values(array_unique($groupids));
    }

    /**
     * Find which existing group ids overlap membership with the requested group.
     *
     * @param int $requestedgroupid
     * @param int[] $existinggroupids
     * @param array<int, array<int, int>> $groupmemberships
     * @return int[] overlapping group ids
     */
    private static function find_group_overlap_groupids(int $requestedgroupid, array $existinggroupids, array $groupmemberships): array {
        $requestedmembers = array_map('intval', $groupmemberships[$requestedgroupid] ?? []);
        if (empty($requestedmembers)) {
            return [];
        }
        $requestedmembers = array_unique($requestedmembers);
        $overlaps = [];
        foreach ($existinggroupids as $gid) {
            $gid = (int)$gid;
            if ($gid === $requestedgroupid) {
                continue;
            }
            $members = array_map('intval', $groupmemberships[$gid] ?? []);
            if (!$members) {
                continue;
            }
            if (array_intersect($requestedmembers, $members)) {
                $overlaps[] = $gid;
            }
        }
        return array_values(array_unique($overlaps));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolution_options_for_shadowed_group(): array {
        return [
            [
                'id' => 'keep_current_precedence',
                'label' => 'Keep current precedence (user override continues to win for timelimit).',
                'requiresconfirmation' => false,
            ],
            [
                'id' => 'remove_user_override_timelimit',
                'label' => 'Remove timelimit from the user override so the group timelimit can apply.',
                'requiresconfirmation' => true,
            ],
            [
                'id' => 'replace_user_override_with_group',
                'label' => 'Remove the user override entirely for affected users.',
                'requiresconfirmation' => true,
            ],
            [
                'id' => 'create_additional_user_overrides',
                'label' => 'Create/adjust user overrides to match the intended group timelimit (per-user).',
                'requiresconfirmation' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolution_options_for_shadowing_user(): array {
        return [
            [
                'id' => 'keep_user_override',
                'label' => 'Keep user override (it will take precedence over group timelimit).',
                'requiresconfirmation' => false,
            ],
            [
                'id' => 'merge_group_timelimits_into_user_override',
                'label' => 'Adjust the user timelimit to include the effective group timelimit.',
                'requiresconfirmation' => true,
            ],
            [
                'id' => 'remove_conflicting_group_overrides',
                'label' => 'Remove or modify the conflicting group override(s) so they do not apply to this user.',
                'requiresconfirmation' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolution_options_for_overlapping_groups(): array {
        return [
            [
                'id' => 'keep_combined_lenient',
                'label' => 'Keep combined lenient behaviour for group overrides (effective timelimit is most lenient).',
                'requiresconfirmation' => false,
            ],
            [
                'id' => 'review_overlap_members',
                'label' => 'Review users who are members of multiple overridden groups and decide per-user.',
                'requiresconfirmation' => true,
            ],
        ];
    }
}

