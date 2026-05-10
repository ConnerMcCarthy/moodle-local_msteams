<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/local/attendee_selector.php');

require_login();

$id = required_param('id', PARAM_INT);

$context = context_system::instance();
require_capability('local/msteams:claimslot', $context);

$slot = \local_msteams\local\storage::get_slot($id);
$canmanage = has_capability('local/msteams:manageslots', $context);
if ((int)$slot->hostid !== (int)$USER->id && !$canmanage) {
    throw new required_capability_exception($context, 'local/msteams:claimslot', 'nopermissions', '');
}

$target = new moodle_url('/local/msteams/manage.php');

if (optional_param('saveattendees', 0, PARAM_BOOL) && confirm_sesskey()) {
    $attendeejson = optional_param('attendeeuseridsjson', '[]', PARAM_RAW_TRIMMED);
    $attendees = json_decode($attendeejson, true);
    if (!is_array($attendees)) {
        $attendees = [];
    }
    $attendees = array_values(array_unique(array_filter(array_map('intval', $attendees), function(int $userid): bool {
        return $userid > 0;
    })));
    \local_msteams\local\storage::save_attendees($id, $attendees);
    require_sesskey();
    (new \local_msteams\local\teams_service())->sync_slot($id);
    redirect($target, get_string('attendeessaved', 'local_msteams'));
}

$slot = \local_msteams\local\storage::get_slot($id);
$savedids = array_map('intval', $slot->attendeeuserids ?? []);

$allusers = $DB->get_records_select(
    'user',
    'deleted = 0 AND suspended = 0 AND id <> :guestid',
    ['guestid' => $CFG->siteguest],
    'firstname ASC, lastname ASC',
    'id, firstname, lastname, email'
);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/attendees.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('manageattendees', 'local_msteams'));
$PAGE->set_heading(format_string($slot->name));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageattendees', 'local_msteams'));
echo $OUTPUT->box(format_text($slot->descriptiontext, FORMAT_HTML));

$jsusers = [];
foreach ($allusers as $user) {
    $jsusers[] = [
        'id' => (int)$user->id,
        'label' => fullname($user) . (!empty($user->email) ? ' <' . $user->email . '>' : ''),
    ];
}
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/msteams/attendees.php', ['id' => $id]))->out(false),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'sesskey',
    'value' => sesskey(),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'attendeeuseridsjson',
    'value' => json_encode($savedids),
]);
echo \local_msteams\local\attendee_selector::render('manageattendee');
\local_msteams\local\attendee_selector::queue_js(
    'manageattendee',
    $jsusers,
    $savedids,
    'form',
    [
        'hiddenname' => 'attendeeuseridsjson',
    ]
);

echo html_writer::start_div('mt-3');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'saveattendees',
    'value' => get_string('saveattendees', 'local_msteams'),
    'class' => 'btn btn-primary mr-2',
]);
echo html_writer::link(
    new moodle_url('/local/msteams/manage.php'),
    get_string('cancel'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
