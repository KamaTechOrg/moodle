<?php

defined('MOODLE_INTERNAL') || die(); 

class mdl_capsule_lib_test extends advanced_testcase{

public function test_mdl_capsule_insert_or_update_insert()
{
    // $DB is the object through which insert, update, get, etc. calls are accessed.
    global $DB;

    // Resets the entire Moodle database after this test is completed.
    $this->resetAfterTest(true);

    // Here we create a stdClass object with fields that suit to the capsules table.
    $data = (object)[
        'capsuleid' => 999,
        'cohortid' => 1,
        'parentid' => 'PARENT',
        'installerid' => 1,
        'install_date' => time(),
        'mac_address' => '00:11:22:33:44:55',
        'status' => 'active',
        'last_update' => time()
    ];

    // Call the function we want to test
    $result = mdl_capsule_insert_or_update($data);

    // Checks that the result obtained is an integer
    $this->assertIsInt($result);

    // Here we retrieve the record that was just inserted into the database.
    $record = $DB->get_record('capsules', ['id' => $result], '*', MUST_EXIST);

    // Checks that the value of capsuleid in the table is equal to the value we sent.
    $this->assertEquals($data->capsuleid, $record->capsuleid);
}

}



