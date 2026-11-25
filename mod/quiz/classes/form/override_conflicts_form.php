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

namespace mod_quiz\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Shared conflict resolution form (used by core and local plugin flows).
 *
 * This form expects custom data:
 * - users: list from override_conflict_resolver::list_group_members_with_user_override_merge_choices()['users']
 * - returnurl: url to go back to
 * - title: heading text
 *
 * @package mod_quiz
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class override_conflicts_form extends moodleform {

    protected function definition(): void {
        $mform = $this->_form;
        $users = $this->_customdata['users'] ?? [];

        $mform->addElement('header', 'conflicts', $this->_customdata['title'] ?? get_string('overrides', 'quiz'));

        if (empty($users)) {
            $mform->addElement('static', 'noconflicts', '', get_string('none'));
        } else {
            foreach ($users as $u) {
                $userid = (int)($u['userid'] ?? 0);
                if ($userid <= 0) {
                    continue;
                }
                $label = $u['label'] ?? ('User ' . $userid);
                $options = [];
                foreach (($u['choices'] ?? []) as $choice) {
                    if (!isset($choice['id'], $choice['label'])) {
                        continue;
                    }
                    $options[$choice['id']] = $choice['label'];
                }
                if (!$options) {
                    continue;
                }
                $mform->addElement('select', "choice[$userid]", $label, $options);
                $mform->setDefault("choice[$userid]", 'use_user');
            }
        }

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton', get_string('savechanges'));
        $buttonarray[] = $mform->createElement('cancel', 'cancel', get_string('cancel'));
        $mform->addGroup($buttonarray, 'buttonbar', '', [' '], false);
        $mform->closeHeaderBefore('buttonbar');
    }
}

