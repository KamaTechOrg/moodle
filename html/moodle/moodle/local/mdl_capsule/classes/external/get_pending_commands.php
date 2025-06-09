<?php

// מוודא שהקובץ לא ייטען ישירות אלא רק כחלק ממערכת Moodle
defined('MOODLE_INTERNAL') || die();

// כולל את הספרייה החיצונית של Moodle שמכילה את מחלקת הבסיס לפונקציות חיצוניות (WS)
require_once("$CFG->libdir/externallib.php");

// מחלקה שמגדירה פונקציות Web Service עבור התוסף local_mdl_capsule
class local_mdl_capsule_external extends external_api {

    // פונקציה שמגדירה את הפרמטרים שהפונקציה get_pending_commands מצפה לקבל
    public static function get_pending_commands_parameters() {
        return new external_function_parameters([
            // הפרמטר היחיד שנדרש הוא capsuleid מסוג מספר שלם
            'capsuleid' => new external_value(PARAM_INT, 'Capsule ID'),
        ]);
    }

    // הפונקציה הראשית שמבצעת את הפעולה – שליפת פקודות לפי capsuleid
    public static function get_pending_commands($capsuleid) {
        global $DB; // מאפשר גישה ל־$DB כדי לבצע שאילתות על מסד הנתונים של Moodle

        // שליפה מהטבלה capsule_commands לפי capsuleid שנשלח כפרמטר
        $records = $DB->get_records('capsule_commands', ['capsuleid' => $capsuleid]);

        // מחזיר את התוצאה כמערך פשוט (ללא מפתחות אסוציאטיביים)
        return array_values($records);
    }

    // פונקציה שמגדירה את מבנה הנתונים שהפונקציה תחזיר
    public static function get_pending_commands_returns() {
        return new external_multiple_structure( // פונקציה תחזיר מערך של רשומות
            new external_single_structure([ // כל רשומה במערך תהיה בעלת המבנה הבא:
                'id' => new external_value(PARAM_INT, 'ID'), // מזהה רשומה
                'capsuleid' => new external_value(PARAM_INT, 'Capsule ID'), // מזהה קפסולה
                'command' => new external_value(PARAM_TEXT, 'Command'), // שדה הפקודה
                // אפשר להוסיף כאן שדות נוספים לפי הטבלה capsule_commands
            ])
        );
    }
}
