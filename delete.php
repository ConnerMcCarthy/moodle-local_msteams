<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');

require_login();
require_sesskey();

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

$slot = \local_msteams\local\storage::get_slot($id);

$graphid = $slot->metadata['graph_event_id'] ?? null;
$organizer = $slot->metadata['graph_organizer'] ?? trim((string)get_config('local_msteams', 'graphorganizer'));
if (!empty($graphid) && !empty($organizer)) {
    $client = new \local_msteams\local\graph_client();
    if ($client->is_configured()) {
        $client->delete('users/' . rawurlencode((string)$organizer) . '/events/' . rawurlencode((string)$graphid));
    }
}

\local_msteams\local\storage::delete_slot($slot);

$target = $returnurl ? new moodle_url($returnurl) : new moodle_url('/local/msteams/manage.php');
redirect($target, get_string('slotdeleted', 'local_msteams'));
