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
     * Send host reminder emails for claimed appointment slots.
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

            $host = $DB->get_record('user', ['id' => $slot->hostid, 'deleted' => 0, 'suspended' => 0], '*');
            if (!$host || empty($host->email)) {
                continue;
            }

            foreach ($windows as $key => $secondsbefore) {
                if (!$this->should_send_reminder($slot, $key, $secondsbefore)) {
                    continue;
                }

                if ($this->send_reminder($host, $slot, $key)) {
                    storage::mark_reminder_sent((int)$slot->id, $key);
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
        $sent = $slot->metadata['reminders'][$key] ?? null;
        if (!empty($sent)) {
            return false;
        }

        $target = (int)$slot->timestart - $secondsbefore;
        $now = time();
        return $target <= $now && $now < ((int)$slot->timestart);
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
