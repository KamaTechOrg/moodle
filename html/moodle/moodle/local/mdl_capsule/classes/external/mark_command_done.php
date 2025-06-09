<?php
// מונע גישה ישירה לקובץ
defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

class local_mdl_capsule_external_mark extends external_api {

    // שלב 2.1: הגדרת הפרמטרים שהפונקציה מקבלת
    public static function mark_command_done_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Command ID'),
        ]);
    }

    // שלב 2.2: פונקציית ההרצה עצמה
    public static function mark_command_done($id) {
        global $DB;

        // עדכון שורת הפקודה לסטטוס "בוצע"
        $DB->update_record('capsule_commands', [
            'id' => $id,
            'status' => 'בוצע'
        ]);

        return ['status' => 'success'];
    }

    // שלב 2.3: הגדרת מבנה הפלט
    public static function mark_command_done_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status'),
        ]);
    }
}
