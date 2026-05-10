<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_once(__DIR__ . '/lib.php');

$context = context_system::instance();
if (!local_msteams_user_can_access_scheduler()) {
    require_capability('local/msteams:viewmine', $context);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/my.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myappointments', 'local_msteams'));
$PAGE->set_heading(get_string('myappointments', 'local_msteams'));

$slots = \local_msteams\local\storage::filter_archive(
    \local_msteams\local\storage::list_slots((int)$USER->id),
    false
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('myappointments', 'local_msteams'));
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/my_calendar.php'),
        get_string('calendarview', 'local_msteams'),
        'get',
        ['style' => 'background:#175cd3;border-color:#175cd3;color:#fff;']
    ) .
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/index.php'),
        get_string('siteappointments', 'local_msteams')
    ) .
    (has_capability('local/msteams:manageslots', $context)
        ? $OUTPUT->single_button(
            new moodle_url('/local/msteams/edit.php'),
            get_string('createslot', 'local_msteams'),
            'get',
            ['style' => 'background:#15803d;border-color:#15803d;color:#fff;']
        )
        : ''),
    '',
    ['style' => 'margin:0 0 1.25rem;']
);
echo \local_msteams\local\view::render_calendar(
    $slots,
    new moodle_url('/local/msteams/my.php'),
    true
);
echo $OUTPUT->footer();
