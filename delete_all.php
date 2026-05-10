<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');

require_login();

$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

$confirm = optional_param('confirm', 0, PARAM_BOOL);
$returnurl = new moodle_url('/local/msteams/manage.php');

if ($confirm) {
    require_sesskey();

    $slots = \local_msteams\local\storage::list_slots();
    $client = new \local_msteams\local\graph_client();
    $clientconfigured = $client->is_configured();

    foreach ($slots as $slot) {
        $graphid = $slot->metadata['graph_event_id'] ?? null;
        $organizer = $slot->metadata['graph_organizer'] ?? trim((string)get_config('local_msteams', 'graphorganizer'));
        if ($clientconfigured && !empty($graphid) && !empty($organizer)) {
            $client->delete('users/' . rawurlencode((string)$organizer) . '/events/' . rawurlencode((string)$graphid));
        }
        \local_msteams\local\storage::delete_slot($slot);
    }

    redirect($returnurl, get_string('allslotsdeleted', 'local_msteams'));
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/delete_all.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('deleteallslots', 'local_msteams'));
$PAGE->set_heading(get_string('manageslots', 'local_msteams'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deleteallslots', 'local_msteams'));
echo $OUTPUT->notification(get_string('confirmdeleteallslots', 'local_msteams'), 'warning');
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/delete_all.php', ['confirm' => 1, 'sesskey' => sesskey()]),
        get_string('deleteallslots', 'local_msteams'),
        'post'
    ) .
    $OUTPUT->single_button($returnurl, get_string('cancel'), 'get'),
    'local-msteams-scheduler-delete-confirm'
);
echo $OUTPUT->footer();
