<?php
namespace local_msteams\task;

defined('MOODLE_INTERNAL') || die();

use local_msteams\local\storage;

final class send_reminders extends \core\task\scheduled_task {
    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task:sendreminders', 'local_msteams');
    }

    /**
     * Send reminder emails for claimed appointment slots.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $windows = [
            '3d' => 3 * DAYSECS,
            '1d' => DAYSECS,
            '2h' => 2 * HOURSECS,
        ];

        foreach (storage::list_slots() as $slot) {
            if ($slot->status !== 'claimed' || empty($slot->hostid)) {
                continue;
            }

            $recipientids = [];
            $recipientids[] = (int)$slot->hostid;
            foreach ($slot->attendeeuserids ?? [] as $attendeeid) {
                $attendeeid = (int)$attendeeid;
                if ($attendeeid > 0 && !in_array($attendeeid, $recipientids, true)) {
                    $recipientids[] = $attendeeid;
                }
            }

            foreach ($windows as $key => $secondsbefore) {
                if (!$this->should_send_reminder($slot, $key, $secondsbefore)) {
                    continue;
                }

                foreach ($recipientids as $recipientid) {
                    $recipient = $DB->get_record('user', ['id' => $recipientid, 'deleted' => 0, 'suspended' => 0], '*');
                    if (!$recipient || empty($recipient->email)) {
                        continue;
                    }

                    if ($this->has_recipient_reminder($slot, $key, $recipientid)) {
                        continue;
                    }

                    if ($this->send_reminder($recipient, $slot, $key)) {
                        storage::mark_reminder_sent((int)$slot->id, $key, $recipientid);
                    }
                }
            }
        }
    }

    /**
     * @param \stdClass $slot
     * @param string $key
     * @param int $secondsbefore
     * @return bool
     */
    private function should_send_reminder(\stdClass $slot, string $key, int $secondsbefore): bool {
        $target = (int)$slot->timestart - $secondsbefore;
        $now = time();
        return $target <= $now && $now < ((int)$slot->timestart);
    }

    /**
     * @param \stdClass $slot
     * @param string $key
     * @param int $recipientid
     * @return bool
     */
    private function has_recipient_reminder(\stdClass $slot, string $key, int $recipientid): bool {
        $sent = $slot->metadata['reminders'][$key][$recipientid] ?? null;
        return !empty($sent);
    }

    /**
     * @param \stdClass $host
     * @param \stdClass $slot
     * @param string $key
     * @return bool
     */
    private function send_reminder(\stdClass $host, \stdClass $slot, string $key): bool {
        global $CFG;

        $support = \core_user::get_support_user();
        $fromemail = get_config('local_msteams', 'reminderfrom');
        if (!empty($fromemail)) {
            $support->email = $fromemail;
        }

        $subject = get_string('reminder_subject_' . $key, 'local_msteams');
        $joinurl = empty($slot->msteams_join_url) ? 'TBD' : $slot->msteams_join_url;
        $message = implode("\n\n", [
            'This is an automated appointment reminder.',
            'Event: ' . $slot->name,
            'Start: ' . userdate((int)$slot->timestart),
            'Join URL: ' . $joinurl,
        ]);

        return email_to_user($host, $support, $subject, $message);
    }
}
