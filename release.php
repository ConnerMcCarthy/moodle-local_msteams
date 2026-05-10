<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/form/change_host_form.php');

require_login();

$id = required_param('id', PARAM_INT);
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);

$context = context_system::instance();
require_capability('local/msteams:claimslot', $context);

$slot = \local_msteams\local\storage::get_slot($id);
$canforce = has_capability('local/msteams:manageslots', $context);
if (!$canforce && (int)$slot->hostid !== (int)$USER->id) {
    throw new \moodle_exception('cannotreleaseslot', 'local_msteams');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/release.php', ['id' => $id, 'returnurl' => $returnurl]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('releaseslot', 'local_msteams'));
$PAGE->set_heading(format_string($slot->name));

$users = $DB->get_records_select(
    'user',
    'deleted = 0 AND suspended = 0 AND id <> :guestid',
    ['guestid' => $CFG->siteguest],
    'firstname ASC, lastname ASC',
    'id, firstname, lastname, email'
);

$hostoptions = [0 => get_string('removehost', 'local_msteams')];
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

$defaults = (object)[
    'id' => $id,
    'returnurl' => $returnurl,
    'hostid' => empty($slot->hostid) ? 0 : (int)$slot->hostid,
];

$form = new \local_msteams\form\change_host_form(null, [
    'hostoptions' => $hostoptions,
    'defaults' => $defaults,
    'submitlabel' => get_string('savehost', 'local_msteams'),
]);

if ($form->is_cancelled()) {
    $target = $returnurl ? new moodle_url($returnurl) : new moodle_url('/local/msteams/my.php');
    redirect($target);
}

if ($data = $form->get_data()) {
    require_sesskey();
    $newhostid = !empty($data->hostid) ? (int)$data->hostid : 0;
    \local_msteams\local\storage::change_host($id, (int)$USER->id, $newhostid, $canforce);
    (new \local_msteams\local\teams_service())->sync_slot($id);

    $target = !empty($data->returnurl) ? new moodle_url($data->returnurl) : new moodle_url('/local/msteams/my.php');
    redirect($target, get_string('slotreleased', 'local_msteams'));
}

echo $OUTPUT->header();
echo $OUTPUT->box(format_text($slot->descriptiontext, FORMAT_HTML));
$form->display();
echo $OUTPUT->footer();
