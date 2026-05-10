<?php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/manage.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('manageslots', 'local_msteams'));
$PAGE->set_heading(get_string('manageslots', 'local_msteams'));

$slots = \local_msteams\local\storage::filter_archive(
    \local_msteams\local\storage::list_slots(),
    false
);

echo $OUTPUT->header();
echo html_writer::tag('style', '.local-msteams-scheduler-manage-actions{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin:0 0 1.25rem;}.local-msteams-scheduler-manage-actions .singlebutton{margin:0;}.local-msteams-scheduler-manage-actions .local-msteams-scheduler-danger{margin-left:auto;}');
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/calendar.php'),
        get_string('calendarview', 'local_msteams'),
        'get',
        ['style' => 'background:#175cd3;border-color:#175cd3;color:#fff;']
    ) .
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/my.php'),
        get_string('myappointments', 'local_msteams')
    ) .
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/edit.php'),
        get_string('createslot', 'local_msteams'),
        'get',
        ['style' => 'background:#15803d;border-color:#15803d;color:#fff;']
    ) .
    html_writer::div(
        $OUTPUT->single_button(
            new moodle_url('/local/msteams/delete_all.php'),
            get_string('deleteallslots', 'local_msteams'),
            'get',
            ['style' => 'background:#b42318;border-color:#b42318;color:#fff;']
        ),
        'local-msteams-scheduler-danger'
    ),
    'local-msteams-scheduler-manage-actions'
);
echo \local_msteams\local\view::render_calendar(
    $slots,
    new moodle_url('/local/msteams/manage.php'),
    true
);
echo $OUTPUT->footer();
