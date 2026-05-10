<?php
namespace local_msteams\privacy;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_msteams.
 */
final class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_msteams_slot', [
            'eventid' => 'privacy:metadata:slot:eventid',
            'status' => 'privacy:metadata:slot:status',
            'hostid' => 'privacy:metadata:slot:hostid',
            'basedescription' => 'privacy:metadata:slot:basedescription',
            'msteams_join_url' => 'privacy:metadata:slot:msteams_join_url',
            'graph_event_id' => 'privacy:metadata:slot:graph_event_id',
            'graph_organizer' => 'privacy:metadata:slot:graph_organizer',
            'createdby' => 'privacy:metadata:slot:createdby',
            'timecreated' => 'privacy:metadata:slot:timecreated',
            'timemodified' => 'privacy:metadata:slot:timemodified',
        ], 'privacy:metadata:local_msteams_slot');

        $collection->add_database_table('local_msteams_attendee', [
            'slotid' => 'privacy:metadata:attendee:slotid',
            'userid' => 'privacy:metadata:attendee:userid',
            'timecreated' => 'privacy:metadata:attendee:timecreated',
        ], 'privacy:metadata:local_msteams_attendee');

        $collection->add_database_table('local_msteams_reminder', [
            'slotid' => 'privacy:metadata:reminder:slotid',
            'reminderkey' => 'privacy:metadata:reminder:reminderkey',
            'recipientuserid' => 'privacy:metadata:reminder:recipientuserid',
            'timesent' => 'privacy:metadata:reminder:timesent',
        ], 'privacy:metadata:local_msteams_reminder');

        return $collection;
    }

    /**
     * Get contexts containing user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $hasdata =
            $DB->record_exists('local_msteams_slot', ['hostid' => $userid]) ||
            $DB->record_exists('local_msteams_slot', ['createdby' => $userid]) ||
            $DB->record_exists('local_msteams_attendee', ['userid' => $userid]) ||
            $DB->record_exists('local_msteams_reminder', ['recipientuserid' => $userid]);

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get users who have data in the supplied context.
     *
     * @param userlist $userlist
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }

        $userids = [];
        $hostids = $DB->get_fieldset_select('local_msteams_slot', 'DISTINCT hostid', 'hostid > 0');
        $creatorids = $DB->get_fieldset_select('local_msteams_slot', 'DISTINCT createdby', 'createdby > 0');
        $attendeeids = $DB->get_fieldset_select('local_msteams_attendee', 'DISTINCT userid', 'userid > 0');
        $reminderids = $DB->get_fieldset_select('local_msteams_reminder', 'DISTINCT recipientuserid', 'recipientuserid > 0');
        $userids = array_values(array_unique(array_map('intval', array_merge($hostids, $creatorids, $attendeeids, $reminderids))));

        if ($userids) {
            $userlist->add_users($userids);
        }
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist as $context) {
            if (!$context instanceof context_system) {
                continue;
            }

            $export = (object)[
                'hostedappointments' => [],
                'createdappointments' => [],
                'attendingappointments' => [],
            ];

            $hosted = $DB->get_records('local_msteams_slot', ['hostid' => (int)$user->id], 'id ASC');
            foreach ($hosted as $slot) {
                $export->hostedappointments[] = self::export_slot_record($slot, (int)$user->id);
            }

            $created = $DB->get_records('local_msteams_slot', ['createdby' => (int)$user->id], 'id ASC');
            foreach ($created as $slot) {
                $export->createdappointments[] = self::export_slot_record($slot, (int)$user->id);
            }

            $attendeejoins = $DB->get_records('local_msteams_attendee', ['userid' => (int)$user->id], 'id ASC');
            foreach ($attendeejoins as $join) {
                $slot = $DB->get_record('local_msteams_slot', ['id' => (int)$join->slotid]);
                if (!$slot) {
                    continue;
                }
                $item = self::export_slot_record($slot, (int)$user->id);
                $item->attendeemappingcreated = userdate((int)$join->timecreated);
                $export->attendingappointments[] = $item;
            }

            writer::with_context($context)->export_data([get_string('pluginname', 'local_msteams')], $export);
        }
    }

    /**
     * Delete all user data in a context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof context_system) {
            return;
        }

        $DB->delete_records('local_msteams_attendee', []);
        $DB->delete_records('local_msteams_reminder', []);
        $DB->execute("UPDATE {local_msteams_slot} SET createdby = 0 WHERE createdby <> 0");
        $DB->execute("UPDATE {local_msteams_slot} SET hostid = 0, status = CASE WHEN status = 'cancelled' THEN status ELSE 'open' END WHERE hostid <> 0");
    }

    /**
     * Delete data for one user across approved contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        self::apply_user_deletion((int)$contextlist->get_user()->id, $contextlist->get_contextids());
    }

    /**
     * Delete data for multiple users in a context.
     *
     * @param approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::apply_user_deletion((int)$userid, [$userlist->get_context()->id]);
        }
    }

    /**
     * Apply the agreed deletion/anonymisation policy for one user.
     *
     * @param int $userid
     * @param array $contextids
     * @return void
     */
    private static function apply_user_deletion(int $userid, array $contextids): void {
        global $DB;

        if ($userid <= 0 || !in_array(SYSCONTEXTID, array_map('intval', $contextids), true)) {
            return;
        }

        $DB->delete_records('local_msteams_attendee', ['userid' => $userid]);
        $DB->delete_records('local_msteams_reminder', ['recipientuserid' => $userid]);
        $DB->execute(
            "UPDATE {local_msteams_slot}
                SET hostid = 0,
                    status = CASE WHEN status = 'cancelled' THEN status ELSE 'open' END
              WHERE hostid = :userid",
            ['userid' => $userid]
        );
        $DB->set_field('local_msteams_slot', 'createdby', 0, ['createdby' => $userid]);
    }

    /**
     * Build a simple export object for a slot record.
     *
     * @param \stdClass $slot
     * @param int $userid
     * @return \stdClass
     */
    private static function export_slot_record(\stdClass $slot, int $userid): \stdClass {
        global $DB;

        $eventname = '';
        $event = $DB->get_record('event', ['id' => (int)$slot->eventid], 'id,name,timestart,timeduration');
        if ($event) {
            $eventname = (string)$event->name;
        }

        $reminders = $DB->get_records('local_msteams_reminder', [
            'slotid' => (int)$slot->id,
            'recipientuserid' => $userid,
        ], 'id ASC');
        $reminderdata = [];
        foreach ($reminders as $reminder) {
            $reminderdata[] = (object)[
                'key' => (string)$reminder->reminderkey,
                'recipientuserid' => (int)$reminder->recipientuserid,
                'timesent' => userdate((int)$reminder->timesent),
            ];
        }

        return (object)[
            'slotid' => (int)$slot->id,
            'eventid' => (int)$slot->eventid,
            'eventname' => $eventname,
            'status' => (string)$slot->status,
            'hostid' => (int)$slot->hostid,
            'createdby' => (int)$slot->createdby,
            'basedescription' => (string)$slot->basedescription,
            'msteams_join_url' => (string)$slot->msteams_join_url,
            'graph_event_id' => (string)$slot->graph_event_id,
            'graph_organizer' => (string)$slot->graph_organizer,
            'timecreated' => userdate((int)$slot->timecreated),
            'timemodified' => userdate((int)$slot->timemodified),
            'eventstart' => $event ? userdate((int)$event->timestart) : '',
            'eventduration' => $event ? format_time((int)$event->timeduration) : '',
            'reminders' => $reminderdata,
        ];
    }
}
