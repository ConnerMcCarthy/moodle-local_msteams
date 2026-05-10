<?php
namespace local_msteams\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/local/msteams/classes/local/storage.php');
require_once($CFG->dirroot . '/local/msteams/classes/local/attendee_selector.php');

final class recurrence_form extends \moodleform {
    public function definition(): void {
        $mform = $this->_form;
        $draftsummary = $this->_customdata['draftsummary'] ?? '';

        if ($draftsummary !== '') {
            $mform->addElement('static', 'draftsummary', get_string('recurrencebasis', 'local_msteams'), $draftsummary);
        }

        $intervaloptions = [];
        for ($i = 1; $i <= 4; $i++) {
            $intervaloptions[$i] = get_string('repeateveryweeks', 'local_msteams', $i);
        }
        $mform->addElement('select', 'repeatintervalweeks', get_string('repeatintervalweeks', 'local_msteams'), $intervaloptions);
        $mform->setDefault('repeatintervalweeks', 1);

        $mform->addElement('text', 'repeatcount', get_string('repeatcount', 'local_msteams'), ['size' => 4]);
        $mform->setType('repeatcount', PARAM_INT);
        $mform->setDefault('repeatcount', 4);

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'previewseries', get_string('previewseries', 'local_msteams'));
        $buttons[] = $mform->createElement('submit', 'createrecurrence', get_string('createrecurrence', 'local_msteams'));
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->addElement('html', '<div style="margin-top:1rem"></div>');
        $mform->closeHeaderBefore('buttonar');
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        $repeatcount = max(0, (int)($data['repeatcount'] ?? 0));
        if ($repeatcount < 1) {
            $errors['repeatcount'] = get_string('invalidrepeatcount', 'local_msteams');
        }

        return $errors;
    }
}
