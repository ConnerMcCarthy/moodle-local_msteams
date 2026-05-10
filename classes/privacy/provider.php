<?php
namespace local_msteams\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;

/**
 * Privacy provider for the table-backed scheduler variant.
 */
final class provider implements \core_privacy\local\metadata\provider {
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
            'timesent' => 'privacy:metadata:reminder:timesent',
        ], 'privacy:metadata:local_msteams_reminder');

        return $collection;
    }
}
