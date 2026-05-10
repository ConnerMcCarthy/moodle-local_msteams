<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/form/slot_form.php');

require_login();

$id = optional_param('id', 0, PARAM_INT);
$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

$slot = null;
if ($id) {
    $slot = \local_msteams\local\storage::get_slot($id);
    $slot->description_editor = [
        'text' => $slot->descriptiontext,
        'format' => FORMAT_HTML,
    ];
    $slot->attendeeuserids = $slot->attendeeuserids ?? [];
    $slot->attendeeuseridsjson = json_encode(array_values(array_unique(array_map('intval', $slot->attendeeuserids))));
}

$useroptions = [];
$users = $DB->get_records_select(
    'user',
    'deleted = 0 AND suspended = 0 AND id <> :guestid',
    ['guestid' => $CFG->siteguest],
    'firstname ASC, lastname ASC',
    'id, firstname, lastname, email'
);
foreach ($users as $user) {
    $label = fullname($user);
    if (!empty($user->email)) {
        $label .= ' <' . $user->email . '>';
    }
    $useroptions[(int)$user->id] = $label;
}

$hostoptions = [0 => get_string('unassigned', 'local_msteams')];
$hostroleid = (int)get_config('local_msteams', 'hostroleid');
if ($hostroleid > 0) {
    $systemcontext = context_system::instance();
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
              FROM {user} u
              JOIN {role_assignments} ra ON ra.userid = u.id
             WHERE u.deleted = 0
               AND u.suspended = 0
               AND u.id <> :guestid
               AND ra.roleid = :roleid
               AND ra.contextid = :contextid
          ORDER BY u.firstname ASC, u.lastname ASC";
    $hostusers = $DB->get_records_sql($sql, [
        'guestid' => $CFG->siteguest,
        'roleid' => $hostroleid,
        'contextid' => $systemcontext->id,
    ]);
} else {
    $hostusers = $users;
}
foreach ($hostusers as $user) {
    $label = fullname($user);
    if (!empty($user->email)) {
        $label .= ' <' . $user->email . '>';
    }
    $hostoptions[(int)$user->id] = $label;
}

$customdata = [
    'slot' => $slot,
    'useroptions' => $useroptions,
    'hostoptions' => $hostoptions,
];
$mform = new \local_msteams\form\slot_form(null, $customdata);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/msteams/manage.php'));
}

if ($data = $mform->get_data()) {
    $attendeejson = optional_param('attendeeuseridsjson', '[]', PARAM_RAW_TRIMMED);
    $attendeeids = json_decode($attendeejson, true);
    if (!is_array($attendeeids)) {
        $attendeeids = [];
    }
    $attendeeids = array_values(array_unique(array_filter(array_map('intval', $attendeeids), function(int $userid): bool {
        return $userid > 0;
    })));

    $payload = \local_msteams\local\storage::normalise_form_data($data, $slot);

    if (!$slot && optional_param('setuprecurrence', '', PARAM_RAW_TRIMMED) !== '') {
        $SESSION->local_msteams_recurrence_draft = [
            'payload' => serialize($payload),
            'attendeeids' => $attendeeids,
        ];
        redirect(new moodle_url('/local/msteams/recurrence.php'));
    }

    if ($slot) {
        $eventid = \local_msteams\local\storage::save_slot($payload, $slot);
        \local_msteams\local\storage::save_attendees($eventid, $attendeeids);
        if ($payload->status === 'claimed' || !empty($slot->graph_event_id)) {
            (new \local_msteams\local\teams_service())->sync_slot($eventid);
        }
    } else {
        $eventid = \local_msteams\local\storage::save_slot($payload, null);
        \local_msteams\local\storage::save_attendees($eventid, $attendeeids);
        if ($payload->status === 'claimed') {
            (new \local_msteams\local\teams_service())->sync_slot($eventid);
        }
    }

    redirect(new moodle_url('/local/msteams/manage.php'), get_string('slotsaved', 'local_msteams'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/edit.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title($id ? get_string('editslot', 'local_msteams') : get_string('createslot', 'local_msteams'));
$PAGE->set_heading($PAGE->title);

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
