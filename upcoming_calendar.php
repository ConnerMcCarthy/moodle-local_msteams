<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_once(__DIR__ . '/lib.php');

if (isguestuser()) {
    throw new require_login_exception('Guests cannot access appointments.');
}

$context = context_system::instance();
$month = optional_param('month', '', PARAM_TEXT);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = gmdate('Y-m');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/upcoming_calendar.php', ['month' => $month]));
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
$selected = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('UTC'));
$prevmonth = $selected->modify('-1 month')->format('Y-m');
$nextmonth = $selected->modify('+1 month')->format('Y-m');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('upcomingappointments', 'local_msteams'));
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/local/msteams/upcoming.php'),
        get_string('listview', 'local_msteams'),
        'get',
        ['style' => 'background:#175cd3;border-color:#175cd3;color:#fff;']
    ) .
    $OUTPUT->single_button(new moodle_url('/local/msteams/upcoming_calendar.php', ['month' => $prevmonth]), get_string('previousmonth', 'local_msteams'), 'get') .
    $OUTPUT->single_button(new moodle_url('/local/msteams/upcoming_calendar.php', ['month' => $nextmonth]), get_string('nextmonth', 'local_msteams'), 'get'),
    'local-msteams-scheduler-calendar-nav'
);
echo \local_msteams\local\view::render_visual_calendar(
    $slots,
    $month,
    new moodle_url('/local/msteams/appointment.php'),
    true
);
echo $OUTPUT->footer();
