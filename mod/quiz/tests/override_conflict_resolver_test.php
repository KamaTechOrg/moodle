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

namespace mod_quiz;

use advanced_testcase;
use mod_quiz\local\override_conflict_resolver;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for {@see override_conflict_resolver}.
 *
 * @package   mod_quiz
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class override_conflict_resolver_test extends advanced_testcase {

    public function test_existing_user_override_shadows_requested_group_override_for_timelimit(): void {
        $existinguseroverrides = [
            ['id' => 10, 'userid' => 101, 'timelimit' => 1200, 'timeclose' => 10000],
        ];
        $existinggroupoverrides = [];

        $requestedoverride = [
            'groupid' => 5,
            'timelimit' => 1800,
            'timeclose' => 20000,
        ];
        $groupmemberships = [
            5 => [101, 202],
        ];

        $result = override_conflict_resolver::resolve_time_extension_group_conflicts(
            $existinguseroverrides,
            $existinggroupoverrides,
            $requestedoverride,
            $groupmemberships
        );

        $this->assertTrue($result['hasconflict']);
        $this->assertContains(101, $result['affecteduserids']);

        $types = array_map(static fn(array $c) => $c['type'], $result['conflicts']);
        $this->assertContains(override_conflict_resolver::CONFLICT_EXISTING_USER_SHADOWS_REQUESTED_GROUP, $types);

        // Field-level merge preview should include both differing fields for the shadowed user.
        $shadowconflicts = array_values(array_filter($result['conflicts'], static fn(array $c) =>
            $c['type'] === override_conflict_resolver::CONFLICT_EXISTING_USER_SHADOWS_REQUESTED_GROUP
                && ($c['userid'] ?? 0) === 101
        ));
        $this->assertNotEmpty($shadowconflicts);
        $this->assertArrayHasKey('fieldmerges', $shadowconflicts[0]);
        $this->assertArrayHasKey('timelimit', $shadowconflicts[0]['fieldmerges']);
        $this->assertArrayHasKey('timeclose', $shadowconflicts[0]['fieldmerges']);

        $choiceids = array_map(static fn(array $c) => $c['id'], $shadowconflicts[0]['fieldmerges']['timelimit']['choices']);
        $this->assertContains('use_user', $choiceids);
        $this->assertContains('use_group', $choiceids);
        $this->assertContains('combine_lenient', $choiceids);
        $this->assertContains('combine_additive', $choiceids);

        // Preview: user 101 should remain sourced from user override after adding group override.
        $this->assertArrayHasKey(101, $result['preview']);
        $this->assertSame('user', $result['preview'][101]['after']['source']);
        $this->assertSame(1200, $result['preview'][101]['after']['timelimit']);

        // Preview: user 202 should be affected by the requested group override.
        $this->assertArrayHasKey(202, $result['preview']);
        $this->assertSame('group', $result['preview'][202]['after']['source']);
        $this->assertSame(1800, $result['preview'][202]['after']['timelimit']);
    }

    public function test_list_group_members_with_user_override_merge_choices_returns_three_options_per_user(): void {
        $existinguseroverrides = [
            ['id' => 10, 'userid' => 101, 'timelimit' => 1200, 'timeclose' => 10000],
            ['id' => 11, 'userid' => 999, 'timelimit' => 600],
        ];
        $requestedgroupoverride = [
            'groupid' => 5,
            'timelimit' => 1800,
            'timeclose' => 20000,
        ];
        $groupmemberships = [
            5 => [101, 202],
        ];

        $result = override_conflict_resolver::list_group_members_with_user_override_merge_choices(
            5,
            $existinguseroverrides,
            $requestedgroupoverride,
            $groupmemberships
        );

        $this->assertTrue($result['hasconflict']);
        $this->assertSame(5, $result['groupid']);
        $this->assertCount(1, $result['users']); // Only user 101 is both in group and has a user override.

        $u = $result['users'][0];
        $this->assertSame(101, $u['userid']);
        $this->assertSame(10, $u['useroverrideid']);

        $this->assertArrayHasKey('choices', $u);
        $this->assertCount(3, $u['choices']);
        $ids = array_map(static fn(array $c) => $c['id'], $u['choices']);
        $this->assertSame(['use_user', 'use_group', 'use_both'], $ids);

        // Without base values, the resolver cannot compute a delta-based merge; it falls back to older combine rules.
        // We validate delta-based merge separately below.
    }

    public function test_apply_both_merge_relative_to_base_applies_only_group_delta(): void {
        // Base quiz: 3h time limit and close at 10:00.
        $base = [
            'timelimit' => 3 * 3600,
            'timeclose' => 10000,
            'timeopen' => 5000,
            'attempts' => 3,
        ];
        // User override: 4h, close at 11:00, open at 09:00, attempts 4.
        $user = [
            'timelimit' => 4 * 3600,
            'timeclose' => 11000,
            'timeopen' => 4000,
            'attempts' => 4,
        ];
        // Group override: 3.5h, close at 10:30, open at 08:30, attempts 4 (i.e. +1 attempt from base).
        $group = [
            'timelimit' => (3 * 3600) + 1800,
            'timeclose' => 10000 + 1800,
            'timeopen' => 5000 - 1800,
            'attempts' => 4,
        ];

        $both = override_conflict_resolver::apply_both_merge_relative_to_base(
            $base,
            $user,
            $group,
            ['timelimit', 'timeclose', 'timeopen', 'attempts']
        );

        // Apply only delta: +0.5h => 4.5h.
        $this->assertSame((4 * 3600) + 1800, $both['timelimit']);
        // Close delta +30m => 11:30.
        $this->assertSame(11000 + 1800, $both['timeclose']);
        // Open delta -30m => 08:30.
        $this->assertSame(4000 - 1800, $both['timeopen']);
        // Attempts delta +1 => 5.
        $this->assertSame(5, $both['attempts']);
    }

    public function test_requested_user_override_shadows_existing_group_overrides_for_timelimit(): void {
        $existinguseroverrides = [];
        $existinggroupoverrides = [
            ['id' => 20, 'groupid' => 7, 'timelimit' => 900],
        ];

        $requestedoverride = [
            'userid' => 303,
            'timelimit' => 1500,
        ];
        $groupmemberships = [
            7 => [303],
        ];

        $result = override_conflict_resolver::resolve_time_extension_group_conflicts(
            $existinguseroverrides,
            $existinggroupoverrides,
            $requestedoverride,
            $groupmemberships
        );

        $this->assertTrue($result['hasconflict']);
        $this->assertSame([303], $result['affecteduserids']);

        $types = array_map(static fn(array $c) => $c['type'], $result['conflicts']);
        $this->assertContains(override_conflict_resolver::CONFLICT_REQUESTED_USER_SHADOWS_EXISTING_GROUPS, $types);

        $this->assertArrayHasKey(303, $result['preview']);
        $this->assertSame('user', $result['preview'][303]['after']['source']);
        $this->assertSame(1500, $result['preview'][303]['after']['timelimit']);
    }

    public function test_calculate_effective_timelimit_combines_groups_leniently(): void {
        $none = override_conflict_resolver::calculate_effective_timelimit_for_user(null, []);
        $this->assertSame('none', $none['source']);
        $this->assertNull($none['timelimit']);

        $group = override_conflict_resolver::calculate_effective_timelimit_for_user(null, [
            ['groupid' => 1, 'timelimit' => 600],
            ['groupid' => 2, 'timelimit' => 1200],
        ]);
        $this->assertSame('group', $group['source']);
        $this->assertSame(1200, $group['timelimit']);

        $unlimited = override_conflict_resolver::calculate_effective_timelimit_for_user(null, [
            ['groupid' => 1, 'timelimit' => 0],
            ['groupid' => 2, 'timelimit' => 1200],
        ]);
        $this->assertSame('group', $unlimited['source']);
        $this->assertSame(0, $unlimited['timelimit']);

        $userwins = override_conflict_resolver::calculate_effective_timelimit_for_user(
            ['userid' => 9, 'timelimit' => 1800],
            [
                ['groupid' => 1, 'timelimit' => 0],
                ['groupid' => 2, 'timelimit' => 1200],
            ]
        );
        $this->assertSame('user', $userwins['source']);
        $this->assertSame(1800, $userwins['timelimit']);
    }
}

