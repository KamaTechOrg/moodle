<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib/externallib.php');


class local_mdl_capsule_external extends external_api {

    public static function get_pending_commands_parameters() {
        return new external_function_parameters([
            'capsuleid' => new external_value(PARAM_INT, 'ID of the capsule'),
        ]);
    }

    public static function get_pending_commands($capsuleid) {
        global $DB;

        self::validate_parameters(self::get_pending_commands_parameters(), ['capsuleid' => $capsuleid]);

        $records = $DB->get_records('capsule_commands', [
            'capsuleid' => $capsuleid,
            'status' => 'pending'
        ], 'timecreated ASC');

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => $record->id,
                'capsuleid' => $record->capsuleid,
                'command' => $record->command
            ];
        }

        return $result;
    }

    public static function get_pending_commands_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID of the command'),
                'capsuleid' => new external_value(PARAM_INT, 'ID of the capsule'),
                'command' => new external_value(PARAM_TEXT, 'Command string'),
            ])
        );
    }
}
