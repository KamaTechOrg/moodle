<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_mdl_capsule_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025051400)  {

        // טבלה 1: local_capsule
        $table = new xmldb_table('local_capsule');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, 10, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // טבלה 2: capsules
        $table = new xmldb_table('capsules');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, 10, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('capsuleid', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_field('cohortid', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_field('parentid', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_field('installerid', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_field('install_date', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_field('mac_address', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('last_update', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // טבלה 3: capsule_courses_log
        $table = new xmldb_table('capsule_courses_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, 10, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('capsuleid', XMLDB_TYPE_INTEGER, 10, XMLDB_NOTNULL, null, null);
            $table->add_field('child_capsuleid', XMLDB_TYPE_INTEGER, 10, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, 10, XMLDB_NOTNULL, null, null);
            $table->add_field('transferredon', XMLDB_TYPE_INTEGER, 10, null, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $dbman->create_table($table);
        }

        // נקודת שמירה של גרסה
        upgrade_plugin_savepoint(true, 2025051400, 'local', 'mdl_capsule');
    }

    return true;
}
