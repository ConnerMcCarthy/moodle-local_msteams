<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/form/attendees_form.php');

require_login();

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$context = context_system::instance();
require_capability('local/msteams:claimslot', $context);

$slot = \local_msteams\local\storage::get_slot($id);
if ($slot->status === 'cancelled') {
    throw new \moodle_exception('slotcancelled', 'local_msteams');
}
if (!empty($slot->hostid) && (int)$slot->hostid !== (int)$USER->id) {
    throw new \moodle_exception('slotalreadyclaimed', 'local_msteams');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/claim.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('claimslot', 'local_msteams'));
$PAGE->set_heading(format_string($slot->name));

$useroptions = [];
$users = $DB->get_records_select(
    'user',
    'deleted = 0 AND suspended = 0 AND id <> :guestid',
    ['guestid' => $CFG->siteguest],
    'firstname ASC, lastname ASC',
    'id, firstname, lastname, email'
);
foreach ($users as $user) {
    if ((int)$user->id === (int)$USER->id) {
        continue;
    }

    $label = fullname($user);
    if (!empty($user->email)) {
        $label .= ' <' . $user->email . '>';
    }
    $useroptions[(int)$user->id] = $label;
}

$defaults = (object)[
    'id' => $id,
    'returnurl' => $returnurl,
    'attendeeuseridsjson' => json_encode(array_values(array_unique(array_map('intval', $slot->attendeeuserids ?? [])))),
];

$form = new \local_msteams\form\attendees_form(null, [
    'useroptions' => $useroptions,
    'selectedids' => $slot->attendeeuserids ?? [],
    'defaults' => $defaults,
    'submitlabel' => get_string('claimslot', 'local_msteams'),
]);

if ($form->is_cancelled()) {
    $target = $returnurl ? new moodle_url($returnurl) : new moodle_url('/local/msteams/index.php');
    redirect($target);
}

if ($data = $form->get_data()) {
    \local_msteams\local\storage::claim_slot($id, (int)$USER->id);
    $attendeejson = optional_param('attendeeuseridsjson', '[]', PARAM_RAW_TRIMMED);
    $selected = json_decode($attendeejson, true);
    if (!is_array($selected)) {
        $selected = [];
    }
    $selected = array_values(array_unique(array_filter(array_map('intval', $selected), function(int $userid): bool {
        return $userid > 0;
    })));
    \local_msteams\local\storage::save_attendees($id, $selected);
    \core\session\manager::write_close();
    (new \local_msteams\local\teams_service())->sync_slot($id);

    $target = !empty($data->returnurl) ? new moodle_url($data->returnurl) : new moodle_url('/local/msteams/my.php');
    redirect($target, get_string('slotclaimed', 'local_msteams'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('claimslot', 'local_msteams'));
echo $OUTPUT->box(format_text($slot->descriptiontext, FORMAT_HTML));
$form->display();
echo $OUTPUT->footer();
