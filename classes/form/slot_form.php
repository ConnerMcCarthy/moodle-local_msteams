<?php
namespace local_msteams\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/local/msteams/classes/local/storage.php');
require_once($CFG->dirroot . '/local/msteams/classes/local/attendee_selector.php');

final class slot_form extends \moodleform {
    public function definition(): void {
        $mform = $this->_form;
        $slot = $this->_customdata['slot'] ?? null;
        $useroptions = $this->_customdata['useroptions'] ?? [];
        $hostoptions = $this->_customdata['hostoptions'] ?? [0 => get_string('unassigned', 'local_msteams')];
        $minuteoptions = [0 => '00', 15 => '15', 30 => '30', 45 => '45'];

        $mform->addElement('text', 'name', get_string('eventname', 'local_msteams'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('editor', 'description_editor', get_string('description'), null, [
            'maxfiles' => 0,
            'maxbytes' => 0,
            'trusttext' => false,
            'context' => \context_system::instance(),
        ]);
        $mform->setType('description_editor', PARAM_RAW);

        $starthouroptions = [];
        for ($hour = 0; $hour <= 23; $hour++) {
            $starthouroptions[$hour] = sprintf('%02d', $hour);
        }
        $startgroup = [];
        $startgroup[] = $mform->createElement('date_selector', 'startdate', get_string('date'));
        $startgroup[] = $mform->createElement('select', 'starthour', get_string('hour'), $starthouroptions);
        $startgroup[] = $mform->createElement('select', 'startminute', get_string('minutes'), $minuteoptions);
        $mform->addGroup($startgroup, 'startgroup', get_string('starttime', 'local_msteams'), ' ', false);
        if ((int)get_config('local_msteams', 'showtimezoneinfo') === 1) {
            $mform->addElement('static', 'starttimezoneinfo', '', get_string('starttime_timezone_note', 'local_msteams', \core_date::get_user_timezone()));
        }

        $durationunitoptions = ['minutes' => get_string('minutes'), 'hours' => get_string('hours')];
        $durationgroup = [];
        $durationgroup[] = $mform->createElement('text', 'durationvalue', '', ['size' => 4]);
        $durationgroup[] = $mform->createElement('select', 'durationunit', '', $durationunitoptions);
        $mform->addGroup($durationgroup, 'durationgroup', get_string('duration', 'local_msteams'), ' ', false);
        $mform->setType('durationvalue', PARAM_INT);
        $mform->setDefault('durationvalue', 60);
        $mform->setDefault('durationunit', 'minutes');

        $mform->addElement('select', 'hostid', get_string('host', 'local_msteams'), $hostoptions);
        $mform->setType('hostid', PARAM_INT);

        $mform->addElement('text', 'msteams_join_url', get_string('meetinglink', 'local_msteams'), ['size' => 80]);
        $mform->setType('msteams_join_url', PARAM_RAW_TRIMMED);

        $savedattendeeids = array_values(array_unique(array_map('intval', $slot->attendeeuserids ?? [])));
        $selectorusers = [];
        foreach ($useroptions as $userid => $label) {
            $selectorusers[] = ['id' => (int)$userid, 'label' => (string)$label];
        }

        $mform->addElement('hidden', 'attendeeuseridsjson', json_encode($savedattendeeids));
        $mform->setType('attendeeuseridsjson', PARAM_RAW);
        $mform->addElement('html', \local_msteams\local\attendee_selector::render('slotattendee'));
        \local_msteams\local\attendee_selector::queue_js(
            'slotattendee',
            $selectorusers,
            $savedattendeeids,
            'form',
            ['hiddenname' => 'attendeeuseridsjson']
        );

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        if ($slot) {
            $slot->startdate = (int)$slot->timestart;
            $slot->starthour = (int)userdate((int)$slot->timestart, '%H', 99, false);
            $slot->startminute = (int)userdate((int)$slot->timestart, '%M', 99, false);
            $durationseconds = (int)$slot->timeduration;
            if ($durationseconds % HOURSECS === 0) {
                $slot->durationvalue = (int)($durationseconds / HOURSECS);
                $slot->durationunit = 'hours';
            } else {
                $slot->durationvalue = (int)($durationseconds / MINSECS);
                $slot->durationunit = 'minutes';
            }
            $this->set_data($slot);
        } else {
            $mform->setDefault('starthour', 12);
            $mform->setDefault('startminute', 0);
        }

        if ($slot) {
            $this->add_action_buttons(true, get_string('saveslot', 'local_msteams'));
        } else {
            $buttons = [];
            $buttons[] = $mform->createElement('submit', 'submitbutton', get_string('saveslot', 'local_msteams'));
            $buttons[] = $mform->createElement('submit', 'setuprecurrence', get_string('setupweeklyrecurrence', 'local_msteams'));
            $buttons[] = $mform->createElement('cancel');
            $mform->addGroup($buttons, 'buttonar', '', [' '], false);
            $mform->closeHeaderBefore('buttonar');
        }
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $slot = $this->_customdata['slot'] ?? null;

        if (!empty($data['msteams_join_url']) && !preg_match('#^https?://#', (string)$data['msteams_join_url'])) {
            $errors['msteams_join_url'] = get_string('invalidurl', 'local_msteams');
        }
        if (empty($data['startdate'])) {
            $errors['startgroup'] = get_string('required');
        }
        $durationvalue = isset($data['durationvalue']) ? (int)$data['durationvalue'] : 0;
        if ($durationvalue <= 0) {
            $errors['durationgroup'] = get_string('invalidduration', 'local_msteams');
        }
        if (empty($errors['startgroup'])) {
            $startdate = !empty($data['startdate']) ? (int)$data['startdate'] : 0;
            $starthour = isset($data['starthour']) ? (int)$data['starthour'] : 0;
            $startminute = isset($data['startminute']) ? (int)$data['startminute'] : 0;
            $timestart = \local_msteams\local\storage::build_timestamp_from_form($startdate, $starthour, $startminute);
            if ($timestart <= time()) {
                $errors['startgroup'] = get_string('slotmustbefuture', 'local_msteams');
            }
        }
        $hostid = !empty($data['hostid']) ? (int)$data['hostid'] : 0;
        if ($hostid > 0 && empty($errors['startgroup']) && empty($errors['durationgroup'])) {
            $durationunit = isset($data['durationunit']) ? (string)$data['durationunit'] : 'minutes';
            $timeduration = $durationunit === 'hours' ? ($durationvalue * HOURSECS) : ($durationvalue * MINSECS);
            $excludeid = $slot->id ?? 0;
            $conflict = \local_msteams\local\storage::find_conflict($hostid, $timestart, $timeduration, (int)$excludeid);
            if ($conflict) {
                $errors['hostid'] = get_string('hostconflict', 'local_msteams', userdate((int)$conflict->timestart));
            }
        }
        return $errors;
    }

}
