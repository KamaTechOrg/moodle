<?php

$functions = [

    'local_mdl_capsule_get_pending_commands' => [
        'classname'   => 'local_mdl_capsule_external',
        'methodname'  => 'get_pending_commands',
        'classpath'   => 'local/mdl_capsule/classes/external/get_pending_commands.php',
        'description' => 'Get pending commands for a capsule by capsuleid',
        'type'        => 'read',
        'ajax'        => true,
    ],

    'local_mdl_capsule_mark_command_done' => [
        'classname'   => 'local_mdl_capsule_external_mark',
        'methodname'  => 'mark_command_done',
        'classpath'   => 'local/mdl_capsule/classes/external/mark_command_done.php',
        'description' => 'Mark a command as done by id',
        'type'        => 'write',
        'ajax'        => true,
    ],
];

$services = [
    'Capsule Web Service' => [
        'functions' => [
            'local_mdl_capsule_get_pending_commands',
            'local_mdl_capsule_mark_command_done',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];
