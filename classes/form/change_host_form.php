<?php
namespace local_msteams\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

final class change_host_form extends \moodleform {
    public function definition(): void {
        $mform = $this->_form;
        $hostoptions = $this->_customdata['hostoptions'] ?? [0 => get_string('unassigned', 'local_msteams')];
        $submitlabel = $this->_customdata['submitlabel'] ?? get_string('savehost', 'local_msteams');

        $mform->addElement('select', 'hostid', get_string('host', 'local_msteams'), $hostoptions);
        $mform->setType('hostid', PARAM_INT);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_LOCALURL);

        if (!empty($this->_customdata['defaults'])) {
            $this->set_data($this->_customdata['defaults']);
        }

        $this->add_action_buttons(true, $submitlabel);
    }
}
