<?php

// This page creates the add/edit form for the typinglesson activity in the course,
// including a field for the activity name, introduction, standard settings, and save buttons.

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_typinglesson_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('typinglessonname', 'typinglesson'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}