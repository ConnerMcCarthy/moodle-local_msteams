<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/form/recurrence_form.php');

require_login();

$context = context_system::instance();
require_capability('local/msteams:manageslots', $context);

if (empty($SESSION->local_msteams_recurrence_draft['payload'])) {
    redirect(new moodle_url('/local/msteams/edit.php'));
}

$previewpayload = @unserialize($SESSION->local_msteams_recurrence_draft['payload']);
if (!$previewpayload instanceof stdClass) {
    unset($SESSION->local_msteams_recurrence_draft);
    redirect(new moodle_url('/local/msteams/edit.php'));
}

$previewattendeeids = array_values(array_unique(array_filter(
    array_map('intval', (array)($SESSION->local_msteams_recurrence_draft['attendeeids'] ?? [])),
    function(int $userid): bool {
        return $userid > 0;
    }
)));

$hostlabel = get_string('unassigned', 'local_msteams');
if (!empty($previewpayload->hostid)) {
    $hostuser = $DB->get_record('user', ['id' => (int)$previewpayload->hostid, 'deleted' => 0], 'id, firstname, lastname, email');
    if ($hostuser) {
        $hostlabel = fullname($hostuser);
        if (!empty($hostuser->email)) {
            $hostlabel .= ' <' . $hostuser->email . '>';
        }
    }
}

$attendeelabels = [];
if ($previewattendeeids) {
    list($insql, $inparams) = $DB->get_in_or_equal($previewattendeeids, SQL_PARAMS_NAMED);
    $attendees = $DB->get_records_select('user', 'id ' . $insql . ' AND deleted = 0', $inparams, 'firstname ASC, lastname ASC', 'id, firstname, lastname, email');
    foreach ($previewattendeeids as $userid) {
        if (!isset($attendees[$userid])) {
            continue;
        }
        $user = $attendees[$userid];
        $label = fullname($user);
        if (!empty($user->email)) {
            $label .= ' <' . $user->email . '>';
        }
        $attendeelabels[] = $label;
    }
}

$summaryitems = [];
$summaryitems[] = get_string('eventname', 'local_msteams') . ': ' . format_string($previewpayload->name);
$summaryitems[] = get_string('starttime', 'local_msteams') . ': ' . userdate((int)$previewpayload->timestart);
$summaryitems[] = get_string('duration', 'local_msteams') . ': ' . format_time((int)$previewpayload->timeduration);
$summaryitems[] = get_string('host', 'local_msteams') . ': ' . s($hostlabel);
$summaryitems[] = get_string('attendees', 'local_msteams') . ': ' . (!empty($attendeelabels) ? s(implode(', ', $attendeelabels)) : get_string('noattendeesselected', 'local_msteams'));
$draftsummary = html_writer::alist($summaryitems);

$customdata = ['draftsummary' => $draftsummary];
$mform = new \local_msteams\form\recurrence_form(null, $customdata);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/local/msteams/edit.php'));
}

$previewcount = 4;
$previewintervalweeks = 1;

if ($data = $mform->get_data()) {
    $previewcount = max(1, (int)($data->repeatcount ?? 1));
    $previewintervalweeks = max(1, (int)($data->repeatintervalweeks ?? 1));

    if (!empty($previewpayload->hostid)) {
        for ($i = 0; $i < $previewcount; $i++) {
            $occurrencestart = (int)$previewpayload->timestart + ($i * $previewintervalweeks * WEEKSECS);
            $conflict = \local_msteams\local\storage::find_conflict((int)$previewpayload->hostid, $occurrencestart, (int)$previewpayload->timeduration, 0);
            if ($conflict) {
                redirect(
                    new moodle_url('/local/msteams/recurrence.php'),
                    get_string('hostconflict', 'local_msteams', userdate((int)$conflict->timestart)),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
        }
    }

    if (optional_param('createrecurrence', '', PARAM_RAW_TRIMMED) !== '') {
        for ($i = 0; $i < $previewcount; $i++) {
            $occurrence = clone $previewpayload;
            $occurrence->timestart = (int)$previewpayload->timestart + ($i * $previewintervalweeks * WEEKSECS);
            $eventid = \local_msteams\local\storage::save_slot($occurrence, null);
            \local_msteams\local\storage::save_attendees($eventid, $previewattendeeids);
            if ($occurrence->status === 'claimed') {
                (new \local_msteams\local\teams_service())->sync_slot($eventid);
            }
        }

        unset($SESSION->local_msteams_recurrence_draft);
        redirect(
            new moodle_url('/local/msteams/manage.php'),
            get_string('slotscreatedseries', 'local_msteams', $previewcount)
        );
    }
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/msteams/recurrence.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('weeklyrecurrence', 'local_msteams'));
$PAGE->set_heading(get_string('weeklyrecurrence', 'local_msteams'));

echo $OUTPUT->header();
$mform->display();

if ($previewpayload && $previewcount > 0) {
    $previewslots = [];
    $months = [];

    for ($i = 0; $i < $previewcount; $i++) {
        $slot = new stdClass();
        $slot->id = 0;
        $slot->slotid = 0;
        $slot->name = $previewpayload->name;
        $slot->timestart = $previewpayload->timestart + ($i * $previewintervalweeks * WEEKSECS);
        $slot->timeduration = (int)$previewpayload->timeduration;
        $slot->hostname = $hostlabel;
        $slot->status = 'preview';
        $slot->statuslabel = get_string('previewstatus', 'local_msteams');
        $slot->hostid = empty($previewpayload->hostid) ? null : (int)$previewpayload->hostid;
        $slot->msteams_join_url = (string)$previewpayload->msteams_join_url;
        $slot->ispreview = true;
        $previewslots[] = $slot;
        $months[userdate((int)$slot->timestart, '%Y-%m')] = true;
    }

    $activeslots = \local_msteams\local\storage::filter_archive(\local_msteams\local\storage::list_slots(), false);
    $mergedslots = array_merge($activeslots, $previewslots);

    echo $OUTPUT->heading(get_string('recurrencepreview', 'local_msteams'), 3);
    foreach (array_keys($months) as $monthkey) {
        echo \local_msteams\local\view::render_visual_calendar($mergedslots, $monthkey);
    }
}

echo $OUTPUT->footer();
