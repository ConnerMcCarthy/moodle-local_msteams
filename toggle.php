<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

\local_msteams\local\storage::toggle_cancel_slot($id);
(new \local_msteams\local\teams_service())->sync_slot($id);

$target = $returnurl ? new moodle_url($returnurl) : new moodle_url('/local/msteams/manage.php');
redirect($target, get_string('slotupdated', 'local_msteams'));
