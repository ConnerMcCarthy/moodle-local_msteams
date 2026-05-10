<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/local/attendee_selector.php');

require_login();

$id = required_param('id', PARAM_INT);
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$adduserid = optional_param('adduserid', 0, PARAM_INT);
$removeuserid = optional_param('removeuserid', 0, PARAM_INT);
$syncandreturn = optional_param('syncandreturn', 0, PARAM_INT);

$context = context_system::instance();
require_capability('local/msteams:claimslot', $context);

$slot = \local_msteams\local\storage::get_slot($id);
$canmanage = has_capability('local/msteams:manageslots', $context);
if ((int)$slot->hostid !== (int)$USER->id && !$canmanage) {
    throw new required_capability_exception($context, 'local/msteams:claimslot', 'nopermissions', '');
}

$target = new moodle_url('/local/msteams/manage.php');

if ($syncandreturn) {
    require_sesskey();
    (new \local_msteams\local\teams_service())->sync_slot($id);
    redirect($target, get_string('attendeessaved', 'local_msteams'));
}

if ($adduserid > 0) {
    require_sesskey();
    $attendees = array_values(array_unique(array_merge($slot->attendeeuserids ?? [], [$adduserid])));
    \local_msteams\local\storage::save_attendees($id, $attendees);
    redirect(new moodle_url('/local/msteams/attendees.php', ['id' => $id, 'q' => $q, 'sesskey' => sesskey()]), get_string('attendeeadded', 'local_msteams'));
}

if ($removeuserid > 0) {
    require_sesskey();
    $attendees = array_values(array_diff(array_map('intval', $slot->attendeeuserids ?? []), [$removeuserid]));
    \local_msteams\local\storage::save_attendees($id, $attendees);
    redirect(new moodle_url('/local/msteams/attendees.php', ['id' => $id, 'q' => $q, 'sesskey' => sesskey()]), get_string('attendeeremoved', 'local_msteams'));
}

$slot = \local_msteams\local\storage::get_slot($id);
$savedids = array_map('intval', $slot->attendeeuserids ?? []);

$savedusers = [];
if ($savedids) {
    list($insql, $params) = $DB->get_in_or_equal($savedids, SQL_PARAMS_NAMED);
    $savedusers = $DB->get_records_select('user', 'id ' . $insql . ' AND deleted = 0', $params, '', 'id, firstname, lastname, email');
}

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

echo html_writer::tag('p', get_string('selectedattendeessummary', 'local_msteams', count($savedids) ? implode(', ', array_map(function($u) {
    return fullname($u) . (!empty($u->email) ? ' <' . $u->email . '>' : '');
}, $savedusers)) : get_string('noattendeesselected', 'local_msteams')));

$jsusers = [];
foreach ($allusers as $user) {
    $jsusers[] = [
        'id' => (int)$user->id,
        'label' => fullname($user) . (!empty($user->email) ? ' <' . $user->email . '>' : ''),
    ];
}
$addbase = (new moodle_url('/local/msteams/attendees.php', [
    'id' => $id,
    'sesskey' => sesskey(),
]))->out(false) . '&adduserid=';
echo \local_msteams\local\attendee_selector::render('manageattendee');
\local_msteams\local\attendee_selector::queue_js(
    'manageattendee',
    $jsusers,
    $savedids,
    'server',
    [
        'addbase' => $addbase,
        'removebase' => (new moodle_url('/local/msteams/attendees.php', [
            'id' => $id,
            'sesskey' => sesskey(),
        ]))->out(false) . '&removeuserid=',
    ]
);

echo html_writer::start_div('mt-3');
echo html_writer::link(
    new moodle_url('/local/msteams/attendees.php', [
        'id' => $id,
        'syncandreturn' => 1,
        'sesskey' => sesskey(),
    ]),
    get_string('saveattendees', 'local_msteams'),
    ['class' => 'btn btn-primary mr-2']
);
echo html_writer::link(
    new moodle_url('/local/msteams/manage.php'),
    get_string('cancel'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();
echo $OUTPUT->footer();
