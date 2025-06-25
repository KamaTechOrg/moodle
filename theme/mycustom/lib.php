<?php

defined('MOODLE_INTERNAL') || die();

function theme_mycustom_get_main_scss_content($theme) {
    return file_get_contents(__DIR__ . '/scss/custom.scss');
}

function theme_mycustom_process_css($css, $theme) {
    return $css; // אפשר להכניס כאן עיבוד נוסף אם תרצי
}
