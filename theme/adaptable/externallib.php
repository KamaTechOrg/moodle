<?php
namespace theme_adaptable\output;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

class external extends external_api {

    public static function load_fontawesome_icon_map_parameters() {
        return new external_function_parameters([]);
    }

    public static function load_fontawesome_icon_map() {
        return [];
    }

    public static function load_fontawesome_icon_map_returns() {
        return new external_single_structure([]);
    }
}
