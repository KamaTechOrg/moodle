<?php

// Define what the form will look like.

require_once("$CFG->libdir/formslib.php");

class mod_typinglesson_addlesson_form extends moodleform
{
    public function definition()
    {
        $mform = $this->_form;

        $mform->addElement('text', 'name', 'Lesson Name');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $mform->addElement('textarea', 'description', 'Description');
        $mform->setType('description', PARAM_TEXT);
        $mform->addRule('description', null, 'required');

        $lessonTypes = $this->get_lesson_types();
        $lessonTypes = [
            '' => 'Select a lesson type',
            0 => 'beginner',
            2 => 'expert',
            1 => 'medium'
        ] + $lessonTypes;
        $mform->addElement('select', 'lesson_type_id', 'Lesson Type', $lessonTypes);
        $mform->addRule('lesson_type_id', 'Please select a lesson type', 'required');


        $mform->addElement('textarea', 'required_letters', 'Required Letters');
        $mform->setType('required_letters', PARAM_TEXT);
        $mform->addRule('required_letters', null, 'required');

        $mform->addElement('textarea', 'text_to_type', 'Text to Type');
        $mform->setType('text_to_type', PARAM_TEXT);

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);


        $this->add_action_buttons(true, 'Save Lesson');
    }

    private function get_lesson_types()
    {
        global $DB;
        return $DB->get_records_menu('typing_lesson_types', null, '', 'id, name');
    }
}
