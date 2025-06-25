<?php

$THEME->name = 'mycustom';

$THEME->parents = ['boost']; // אפשר גם 'classic'

$THEME->sheets = [];

$THEME->editor_sheets = [];

$THEME->scss = function($theme) {
    return theme_mycustom_get_main_scss_content($theme);
};

$THEME->rendererfactory = 'theme_overridden_renderer_factory';

$THEME->csspostprocess = 'theme_mycustom_process_css';
