<?php
namespace local_msteams\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/local/msteams/classes/local/attendee_selector.php');

final class attendees_form extends \moodleform {
    /**
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $options = $this->_customdata['useroptions'] ?? [];
        $selectedids = $this->_customdata['selectedids'] ?? [];

        $selectorusers = [];
        foreach ($options as $userid => $label) {
            $selectorusers[] = ['id' => (int)$userid, 'label' => (string)$label];
        }

        $mform->addElement('hidden', 'attendeeuseridsjson', json_encode(array_values(array_unique(array_map('intval', $selectedids)))));
        $mform->setType('attendeeuseridsjson', PARAM_RAW);
        $mform->addElement('html', \local_msteams\local\attendee_selector::render('claimattendee'));
        \local_msteams\local\attendee_selector::queue_js(
            'claimattendee',
            $selectorusers,
            array_values(array_unique(array_map('intval', $selectedids))),
            'form',
            ['hiddenname' => 'attendeeuseridsjson']
        );

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_LOCALURL);

        if (!empty($this->_customdata['defaults'])) {
            $this->set_data($this->_customdata['defaults']);
        }

        $submitlabel = $this->_customdata['submitlabel'] ?? get_string('saveattendees', 'local_msteams');
        $this->add_action_buttons(true, $submitlabel);
    }
}
