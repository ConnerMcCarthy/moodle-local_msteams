<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_once(__DIR__ . '/lib.php');

if (isguestuser()) {
    throw new require_login_exception('Guests cannot access appointments.');
}

$context = context_system::instance();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/upcoming.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('upcomingappointments', 'local_msteams'));
$PAGE->set_heading(get_string('upcomingappointments', 'local_msteams'));

$slots = \local_msteams\local\storage::filter_archive(
    \local_msteams\local\storage::list_slots(),
    false
);
$slots = array_values(array_filter($slots, static function($slot): bool {
    return !empty($slot->hostid);
}));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('upcomingappointments', 'local_msteams'));
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/upcoming_calendar.php'),
        get_string('calendarview', 'local_msteams'),
        'get',
        ['style' => 'background:#175cd3;border-color:#175cd3;color:#fff;']
    ),
    '',
    ['style' => 'margin:0 0 1.25rem;']
);
echo \local_msteams\local\view::render_calendar(
    $slots,
    new moodle_url('/local/msteams/upcoming.php'),
    false,
    new moodle_url('/local/msteams/appointment.php'),
    new moodle_url('/local/msteams/appointment.php'),
    true
);
echo $OUTPUT->footer();
