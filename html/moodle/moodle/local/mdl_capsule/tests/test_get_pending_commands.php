<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Class test_get_pending_commands
 *
 * @package    local_mdl_capsule
 * @runTestsInSeparateProcesses
 */
class test_get_pending_commands extends advanced_testcase {

    public function setUp(): void {
        $this->resetAfterTest(true);
    }

    public function test_get_pending_commands_returns_expected_records() {
        global $DB;

        // טוען את הקובץ רק בתוך הפונקציה כדי למנוע שגיאת isolation
        require_once(__DIR__ . '/../externallib.php');

        $this->setAdminUser();

        $capsuleid = 456;

        $cmd1 = (object)[
            'capsuleid' => $capsuleid,
            'command' => 'do_something',
            'status' => 'pending',
            'timecreated' => time(),
        ];
        $cmd2 = (object)[
            'capsuleid' => $capsuleid,
            'command' => 'do_another_thing',
            'status' => 'pending',
            'timecreated' => time(),
        ];

        $cmd1->id = $DB->insert_record('capsule_commands', $cmd1);
        $cmd2->id = $DB->insert_record('capsule_commands', $cmd2);

        $result = \local_mdl_capsule_external::get_pending_commands($capsuleid);

        $this->assertCount(2, $result);
        $this->assertEquals($cmd1->id, $result[0]['id']);
        $this->assertEquals($cmd1->capsuleid, $result[0]['capsuleid']);
        $this->assertEquals($cmd1->command, $result[0]['command']);
        $this->assertEquals($cmd2->id, $result[1]['id']);
        $this->assertEquals($cmd2->capsuleid, $result[1]['capsuleid']);
        $this->assertEquals($cmd2->command, $result[1]['command']);
    }

    public function test_get_pending_commands_empty_result() {
        require_once(__DIR__ . '/../externallib.php');

        $this->setAdminUser();

        $result = \local_mdl_capsule_external::get_pending_commands(999999);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }
}
