<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_once(__DIR__ . '/lib.php');

if (isguestuser()) {
    throw new require_login_exception('Guests cannot access appointments.');
}

$id = required_param('id', PARAM_INT);
$popup = optional_param('popup', 0, PARAM_BOOL);
$context = context_system::instance();
$slot = \local_msteams\local\storage::get_slot($id);

if ((int)$slot->timestart < time() || $slot->status === 'cancelled') {
    throw new \moodle_exception('invalidslot', 'local_msteams');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/appointment.php', ['id' => $id]));
$PAGE->set_pagelayout($popup ? 'popup' : 'standard');
$PAGE->set_title(format_string($slot->name));
$PAGE->set_heading(get_string('appointmentdetails', 'local_msteams'));

$isattendee = in_array((int)$USER->id, array_map('intval', $slot->attendeeuserids ?? []), true);
$ishost = !empty($slot->hostid) && (int)$slot->hostid === (int)$USER->id;

if (optional_param('joinattendee', 0, PARAM_BOOL) && confirm_sesskey()) {
    if (!$ishost && !$isattendee) {
        $attendeeids = array_values(array_unique(array_merge(
            array_map('intval', $slot->attendeeuserids ?? []),
            [(int)$USER->id]
        )));
        \local_msteams\local\storage::save_attendees($id, $attendeeids);
        if (!empty($slot->hostid) || !empty($slot->graph_event_id)) {
            (new \local_msteams\local\teams_service())->sync_slot($id);
        }
        if ($popup) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification(get_string('joinedasattendee', 'local_msteams'), 'success');
            echo html_writer::script('if (window.opener) { window.opener.location.reload(); } setTimeout(function() { window.close(); }, 900);');
            echo html_writer::div(
                html_writer::link('#', get_string('closebut', 'local_msteams'), ['class' => 'btn btn-primary', 'onclick' => 'window.close(); return false;']),
                '',
                ['style' => 'margin-top:1rem;']
            );
            echo $OUTPUT->footer();
            exit;
        }
        redirect(new moodle_url('/local/msteams/appointment.php', ['id' => $id]), get_string('joinedasattendee', 'local_msteams'));
    }
}

if (optional_param('leaveattendee', 0, PARAM_BOOL) && confirm_sesskey()) {
    if (!$ishost && $isattendee) {
        $attendeeids = array_values(array_filter(
            array_map('intval', $slot->attendeeuserids ?? []),
            function(int $userid) use ($USER): bool {
                return $userid > 0 && $userid !== (int)$USER->id;
            }
        ));
        \local_msteams\local\storage::save_attendees($id, $attendeeids);
        if (!empty($slot->hostid) || !empty($slot->graph_event_id)) {
            (new \local_msteams\local\teams_service())->sync_slot($id);
        }
        if ($popup) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification(get_string('leftappointment', 'local_msteams'), 'success');
            echo html_writer::script('if (window.opener) { window.opener.location.reload(); } setTimeout(function() { window.close(); }, 900);');
            echo html_writer::div(
                html_writer::link('#', get_string('closebut', 'local_msteams'), ['class' => 'btn btn-primary', 'onclick' => 'window.close(); return false;']),
                '',
                ['style' => 'margin-top:1rem;']
            );
            echo $OUTPUT->footer();
            exit;
        }
        redirect(new moodle_url('/local/msteams/appointment.php', ['id' => $id]), get_string('leftappointment', 'local_msteams'));
    }
}

$attendeelabels = [];
if (!empty($slot->attendeeuserids)) {
    list($insql, $params) = $DB->get_in_or_equal(array_map('intval', $slot->attendeeuserids), SQL_PARAMS_NAMED);
    $users = $DB->get_records_select('user', 'id ' . $insql . ' AND deleted = 0', $params, 'firstname ASC, lastname ASC', 'id, firstname, lastname');
    foreach ($users as $user) {
        $attendeelabels[] = fullname($user);
    }
}

echo $OUTPUT->header();
if (!$popup) {
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url('/local/msteams/upcoming_calendar.php'), get_string('calendarview', 'local_msteams')) .
        $OUTPUT->single_button(new moodle_url('/local/msteams/upcoming.php'), get_string('upcomingappointments', 'local_msteams')),
        '',
        ['style' => 'margin:0 0 1.25rem; display:flex; gap:.75rem; flex-wrap:wrap;']
    );
}
echo $OUTPUT->heading(format_string($slot->name));
echo html_writer::div(userdate((int)$slot->timestart), 'mb-2');
echo html_writer::div(get_string('host', 'local_msteams') . ': ' . s($slot->hostname), 'mb-3');

if ($popup) {
    echo $OUTPUT->box(format_text($slot->descriptiontext, FORMAT_HTML));
}

if ($ishost) {
    echo $OUTPUT->notification(get_string('youarehost', 'local_msteams'), 'info');
    if ($popup) {
        echo html_writer::div(
            html_writer::link('#', get_string('closebut', 'local_msteams'), ['class' => 'btn btn-secondary', 'onclick' => 'window.close(); return false;']),
            '',
            ['style' => 'margin-top:1rem;']
        );
    }
} else if ($isattendee) {
    if ($popup) {
        echo html_writer::tag('p', get_string('confirmleaveappointment', 'local_msteams'));
        echo html_writer::start_div('', ['style' => 'display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem;']);
        echo $OUTPUT->single_button(
            new moodle_url('/local/msteams/appointment.php', ['id' => $id, 'popup' => 1, 'leaveattendee' => 1, 'sesskey' => sesskey()]),
            get_string('yesleaveappointment', 'local_msteams'),
            'post',
            ['style' => 'background:#b42318;border-color:#b42318;color:#fff;']
        );
        echo html_writer::link('#', get_string('cancel'), ['class' => 'btn btn-secondary', 'onclick' => 'window.close(); return false;']);
        echo html_writer::end_div();
    } else {
        echo $OUTPUT->notification(get_string('youareattendee', 'local_msteams'), 'success');
    }
} else {
    if ($popup) {
        echo html_writer::tag('p', get_string('confirmjoinappointment', 'local_msteams'));
        echo html_writer::start_div('', ['style' => 'display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem;']);
        echo $OUTPUT->single_button(
            new moodle_url('/local/msteams/appointment.php', ['id' => $id, 'popup' => 1, 'joinattendee' => 1, 'sesskey' => sesskey()]),
            get_string('yesjoinappointment', 'local_msteams'),
            'post',
            ['style' => 'background:#15803d;border-color:#15803d;color:#fff;']
        );
        echo html_writer::link('#', get_string('cancel'), ['class' => 'btn btn-secondary', 'onclick' => 'window.close(); return false;']);
        echo html_writer::end_div();
    } else {
        echo $OUTPUT->single_button(
            new moodle_url('/local/msteams/appointment.php', ['id' => $id, 'joinattendee' => 1, 'sesskey' => sesskey()]),
            get_string('becomeattendee', 'local_msteams'),
            'post'
        );
    }
}

echo $OUTPUT->footer();
