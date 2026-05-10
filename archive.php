<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/archive.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('archiveslots', 'local_msteams'));
$PAGE->set_heading(get_string('archiveslots', 'local_msteams'));

$slots = \local_msteams\local\storage::filter_archive(
    \local_msteams\local\storage::list_slots(),
    true
);

echo $OUTPUT->header();
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/manage.php'),
        get_string('manageslots', 'local_msteams')
    ),
    'local-msteams-scheduler-manage-actions'
);
echo \local_msteams\local\view::render_calendar(
    $slots,
    new moodle_url('/local/msteams/archive.php'),
    true
);
echo $OUTPUT->footer();
