<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

class mod_typinglesson_basic_test extends \advanced_testcase {

    public function test_tables_exist_after_install() {
        global $DB;

        $this->resetAfterTest(); 
        $this->assertTrue($DB->get_manager()->table_exists('typing_lesson_types'));
        $this->assertTrue($DB->get_manager()->table_exists('typing_lessons'));
        $this->assertTrue($DB->get_manager()->table_exists('custom_lesson_progress'));
        $this->assertTrue($DB->get_manager()->table_exists('typing_custom_lessons'));
    }

    public function test_insert_typing_lesson_type() {
        global $DB;

        $this->resetAfterTest();

        $record = new \stdClass();
        $record->name = 'Beginner';
        $record->alias = 'beginner';

        $id = $DB->insert_record('typing_lesson_types', $record);
        $this->assertIsInt($id);

        $inserted = $DB->get_record('typing_lesson_types', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals('Beginner', $inserted->name);
    }
}
