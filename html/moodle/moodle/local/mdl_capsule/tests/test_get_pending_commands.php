<?php
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/mdl_capsule/classes/external/get_pending_commands.php');

use local_mdl_capsule_external; // ודא שהמחלקה קיימת

class local_mdl_capsule_external_testcase extends advanced_testcase {

    public function test_get_pending_commands_returns_array() {
        $this->resetAfterTest(true); // מאפס את מסד הנתונים אחרי הבדיקה

        // צור capsuleid לבדיקה. אפשר ליצור טבלה עם נתונים לבדיקה:
        $capsuleid = 1;

        // הכנס רשומה לדוגמה למסד הנתונים
        global $DB;
        $DB->insert_record('capsule_commands', (object)[
            'capsuleid' => $capsuleid,
            'command' => 'example command'
        ]);

        // קריאה לפונקציה שאת בודקת
        $results = local_mdl_capsule_external::get_pending_commands($capsuleid);

        // בדיקה: האם קיבלנו מערך לא ריק?
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
    }
}

