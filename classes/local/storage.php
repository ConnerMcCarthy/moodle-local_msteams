<?php
namespace local_msteams\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Table-backed storage over Moodle calendar events.
 */
final class storage {
    private const SLOT_TABLE = 'local_msteams_slot';
    private const ATTENDEE_TABLE = 'local_msteams_attendee';
    private const REMINDER_TABLE = 'local_msteams_reminder';
    private const CONTACT_MARKER = 'local_msteams_contact';

    /**
     * Fetch all appointment slots, optionally filtered by host.
     *
     * @param int|null $hostid
     * @return array
     */
    public static function list_slots(?int $hostid = null): array {
        global $DB;

        $params = [];
        $sql = "SELECT
                    s.id AS slotid,
                    s.eventid,
                    s.status,
                    s.hostid,
                    s.basedescription,
                    s.msteams_join_url,
                    s.graph_event_id,
                    s.graph_organizer,
                    s.timecreated AS slottimecreated,
                    s.timemodified AS slottimemodified,
                    e.*
                  FROM {" . self::SLOT_TABLE . "} s
                  JOIN {event} e ON e.id = s.eventid";
        if ($hostid !== null) {
            $sql .= ' WHERE s.hostid = :hostid';
            $params['hostid'] = $hostid;
        }
        $sql .= ' ORDER BY e.timestart ASC';

        $records = $DB->get_records_sql($sql, $params);
        if (!$records) {
            return [];
        }

        $slotids = [];
        $hostids = [];
        foreach ($records as $record) {
            $slotids[] = (int)$record->slotid;
            if (!empty($record->hostid)) {
                $hostids[] = (int)$record->hostid;
            }
        }

        $attendees = self::load_attendee_map($slotids);
        $reminders = self::load_reminder_map($slotids);
        $hosts = self::load_host_map(array_values(array_unique($hostids)));

        $slots = [];
        foreach ($records as $record) {
            $slot = self::decorate_slot(
                $record,
                $attendees[(int)$record->slotid] ?? [],
                $reminders[(int)$record->slotid] ?? [],
                $hosts[(int)$record->hostid] ?? null
            );
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * Filter slots by archive status.
     * Archived means the slot belongs to a calendar day before today.
     *
     * @param array $slots
     * @param bool $archived
     * @return array
     */
    public static function filter_archive(array $slots, bool $archived): array {
        $todayparts = usergetdate(time());
        $todaykey = sprintf('%04d%02d%02d', (int)$todayparts['year'], (int)$todayparts['mon'], (int)$todayparts['mday']);
        $filtered = [];

        foreach ($slots as $slot) {
            $slotparts = usergetdate((int)$slot->timestart);
            $slotday = sprintf('%04d%02d%02d', (int)$slotparts['year'], (int)$slotparts['mon'], (int)$slotparts['mday']);
            $isslotarchived = $slotday < $todaykey;
            if ($isslotarchived === $archived) {
                $filtered[] = $slot;
            }
        }

        return $filtered;
    }

    /**
     * Fetch a single slot by calendar event id.
     *
     * @param int $eventid
     * @return \stdClass
     */
    public static function get_slot(int $eventid): \stdClass {
        global $DB;

        $sql = "SELECT
                    s.id AS slotid,
                    s.eventid,
                    s.status,
                    s.hostid,
                    s.basedescription,
                    s.msteams_join_url,
                    s.graph_event_id,
                    s.graph_organizer,
                    s.timecreated AS slottimecreated,
                    s.timemodified AS slottimemodified,
                    e.*
                  FROM {" . self::SLOT_TABLE . "} s
                  JOIN {event} e ON e.id = s.eventid
                 WHERE e.id = :eventid";
        $record = $DB->get_record_sql($sql, ['eventid' => $eventid]);
        if (!$record) {
            throw new \moodle_exception('invalidslot', 'local_msteams');
        }

        $attendees = self::load_attendee_map([(int)$record->slotid]);
        $reminders = self::load_reminder_map([(int)$record->slotid]);
        $host = null;
        if (!empty($record->hostid)) {
            $hosts = self::load_host_map([(int)$record->hostid]);
            $host = $hosts[(int)$record->hostid] ?? null;
        }

        return self::decorate_slot(
            $record,
            $attendees[(int)$record->slotid] ?? [],
            $reminders[(int)$record->slotid] ?? [],
            $host
        );
    }

    /**
     * Persist metadata changes.
     *
     * @param int $eventid
     * @param array $metadata
     * @return void
     */
    public static function save_metadata(int $eventid, array $metadata): void {
        global $DB;

        $slot = self::get_slot($eventid);
        $merged = self::merge_metadata($slot->metadata, $metadata);

        $record = (object)[
            'id' => $slot->slotid,
            'status' => (string)$merged['status'],
            'hostid' => empty($merged['hostid']) ? 0 : (int)$merged['hostid'],
            'basedescription' => $slot->descriptiontext,
            'msteams_join_url' => $merged['msteams_join_url'],
            'graph_event_id' => $merged['graph_event_id'],
            'graph_organizer' => $merged['graph_organizer'],
            'timemodified' => time(),
        ];
        $DB->update_record(self::SLOT_TABLE, $record);

        if (array_key_exists('attendeeuserids', $metadata)) {
            self::store_attendees((int)$slot->slotid, (array)$merged['attendeeuserids'], (int)$merged['hostid']);
        }

        if (array_key_exists('reminders', $metadata)) {
            self::store_reminders((int)$slot->slotid, (array)$merged['reminders']);
        }

        self::update_event_description((int)$eventid, (string)$slot->descriptiontext, (int)$record->hostid);
    }

    /**
     * Create or update a slot-backed calendar event.
     *
     * @param \stdClass $payload
     * @param \stdClass|null $existing
     * @return int
     */
    public static function save_slot(\stdClass $payload, ?\stdClass $existing = null): int {
        global $DB, $USER;

        if ((int)$payload->timestart <= time()) {
            throw new \moodle_exception('slotmustbefuture', 'local_msteams');
        }

        $description = self::render_event_description($payload->description, (int)($payload->hostid ?? 0));

        if ($existing) {
            $event = \calendar_event::load($existing->id);
            $event->update((object)[
                'id' => $existing->id,
                'name' => $payload->name,
                'description' => $description,
                'format' => FORMAT_HTML,
                'timestart' => $payload->timestart,
                'timeduration' => $payload->timeduration,
                'timesort' => $payload->timestart,
                'component' => 'local_msteams',
                'eventtype' => 'appointment_slot',
                'type' => CALENDAR_EVENT_TYPE_STANDARD,
            ], false);

            $slotrecord = (object)[
                'id' => $existing->slotid,
                'status' => $payload->status,
                'hostid' => empty($payload->hostid) ? 0 : (int)$payload->hostid,
                'basedescription' => $payload->description,
                'msteams_join_url' => $payload->msteams_join_url ?: null,
                'timemodified' => time(),
            ];
            if ((int)$existing->timestart !== (int)$payload->timestart || (int)$existing->timeduration !== (int)$payload->timeduration) {
                self::clear_reminders((int)$existing->slotid);
            }
            $DB->update_record(self::SLOT_TABLE, $slotrecord);
            return (int)$existing->id;
        }

        $eventrecord = (object)[
            'name' => $payload->name,
            'description' => $description,
            'format' => FORMAT_HTML,
            'type' => CALENDAR_EVENT_TYPE_STANDARD,
            'categoryid' => 0,
            'courseid' => SITEID,
            'groupid' => 0,
            'userid' => $USER->id,
            'modulename' => '',
            'instance' => 0,
            'eventtype' => 'appointment_slot',
            'timestart' => $payload->timestart,
            'timeduration' => $payload->timeduration,
            'timesort' => $payload->timestart,
            'visible' => 1,
            'component' => 'local_msteams',
        ];

        $event = \calendar_event::create($eventrecord, false);
        $now = time();
        $slotrecord = (object)[
            'eventid' => (int)$event->id,
            'status' => $payload->status,
            'hostid' => empty($payload->hostid) ? 0 : (int)$payload->hostid,
            'basedescription' => $payload->description,
            'msteams_join_url' => $payload->msteams_join_url ?: null,
            'graph_event_id' => null,
            'graph_organizer' => null,
            'createdby' => (int)$USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $DB->insert_record(self::SLOT_TABLE, $slotrecord);
        return (int)$event->id;
    }

    /**
     * @param \stdClass $data
     * @param \stdClass|null $existing
     * @return \stdClass
     */
    public static function normalise_form_data(\stdClass $data, ?\stdClass $existing = null): \stdClass {
        $payload = new \stdClass();
        $payload->name = trim((string)$data->name);
        $payload->description = trim((string)($data->description_editor['text'] ?? ''));
        $startdate = !empty($data->startdate) ? (int)$data->startdate : 0;
        $starthour = isset($data->starthour) ? (int)$data->starthour : 0;
        $startminute = isset($data->startminute) ? (int)$data->startminute : 0;
        $payload->timestart = self::build_timestamp_from_form($startdate, $starthour, $startminute);
        $durationvalue = isset($data->durationvalue) ? (int)$data->durationvalue : 0;
        $durationunit = isset($data->durationunit) ? (string)$data->durationunit : 'minutes';
        $payload->timeduration = $durationunit === 'hours' ? ($durationvalue * HOURSECS) : ($durationvalue * MINSECS);
        $payload->hostid = !empty($data->hostid) ? (int)$data->hostid : null;
        $payload->status = !empty($payload->hostid) ? 'claimed' : 'open';
        if ($existing && ($existing->status ?? '') === 'cancelled') {
            $payload->status = 'cancelled';
        }
        $payload->msteams_join_url = trim((string)($data->msteams_join_url ?? ''));
        return $payload;
    }

    /**
     * @param int $eventid
     * @param int $userid
     * @return void
     */
    public static function claim_slot(int $eventid, int $userid): void {
        global $DB;

        $slot = self::get_slot($eventid);
        if ($slot->status === 'cancelled') {
            throw new \moodle_exception('slotcancelled', 'local_msteams');
        }
        if (!empty($slot->hostid) && (int)$slot->hostid !== $userid) {
            throw new \moodle_exception('slotalreadyclaimed', 'local_msteams');
        }

        $DB->update_record(self::SLOT_TABLE, (object)[
            'id' => $slot->slotid,
            'status' => 'claimed',
            'hostid' => $userid,
            'timemodified' => time(),
        ]);
        self::clear_reminders((int)$slot->slotid);
        self::update_event_description($eventid, $slot->descriptiontext, $userid);
    }

    /**
     * @param int $eventid
     * @param int $userid
     * @param bool $force
     * @return void
     */
    public static function release_slot(int $eventid, int $userid, bool $force = false): void {
        global $DB;

        $slot = self::get_slot($eventid);
        if (!$force && (int)$slot->hostid !== $userid) {
            throw new \moodle_exception('cannotreleaseslot', 'local_msteams');
        }

        $DB->update_record(self::SLOT_TABLE, (object)[
            'id' => $slot->slotid,
            'hostid' => 0,
            'status' => 'open',
            'timemodified' => time(),
        ]);
        self::store_attendees((int)$slot->slotid, [], 0);
        self::clear_reminders((int)$slot->slotid);
        self::update_event_description($eventid, $slot->descriptiontext, 0);
    }

    /**
     * Change or remove the assigned host for a slot.
     *
     * @param int $eventid
     * @param int $actoruserid
     * @param int $newhostid
     * @param bool $force
     * @return void
     */
    public static function change_host(int $eventid, int $actoruserid, int $newhostid, bool $force = false): void {
        global $DB;

        $slot = self::get_slot($eventid);
        if (!$force && (int)$slot->hostid !== $actoruserid) {
            throw new \moodle_exception('cannotreleaseslot', 'local_msteams');
        }

        $status = $newhostid > 0 ? 'claimed' : 'open';
        if ($slot->status === 'cancelled') {
            $status = 'cancelled';
        }

        $DB->update_record(self::SLOT_TABLE, (object)[
            'id' => $slot->slotid,
            'hostid' => $newhostid > 0 ? $newhostid : 0,
            'status' => $status,
            'timemodified' => time(),
        ]);
        self::clear_reminders((int)$slot->slotid);
        self::update_event_description($eventid, $slot->descriptiontext, $newhostid > 0 ? $newhostid : 0);
    }

    /**
     * @param int $hostid
     * @param int $timestart
     * @param int $timeduration
     * @param int $excludeeventid
     * @return \stdClass|null
     */
    public static function find_conflict(int $hostid, int $timestart, int $timeduration, int $excludeeventid = 0): ?\stdClass {
        if ($hostid <= 0 || $timeduration <= 0) {
            return null;
        }

        $targetend = $timestart + $timeduration;
        foreach (self::list_slots($hostid) as $slot) {
            if ($excludeeventid > 0 && (int)$slot->id === $excludeeventid) {
                continue;
            }
            if ($slot->status === 'cancelled') {
                continue;
            }

            $slotstart = (int)$slot->timestart;
            $slotend = $slotstart + (int)$slot->timeduration;
            if ($timestart < $slotend && $targetend > $slotstart) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param int $eventid
     * @param array $userids
     * @return void
     */
    public static function save_attendees(int $eventid, array $userids): void {
        $slot = self::get_slot($eventid);
        self::store_attendees((int)$slot->slotid, $userids, (int)$slot->hostid);
    }

    /**
     * @param int $eventid
     * @return void
     */
    public static function toggle_cancel_slot(int $eventid): void {
        global $DB;

        $slot = self::get_slot($eventid);
        $status = $slot->status === 'cancelled' ? 'open' : 'cancelled';
        $DB->update_record(self::SLOT_TABLE, (object)[
            'id' => $slot->slotid,
            'status' => $status,
            'timemodified' => time(),
        ]);
        self::clear_reminders((int)$slot->slotid);
    }

    /**
     * @param int|\stdClass $slotoreventid
     * @return void
     */
    public static function delete_slot($slotoreventid): void {
        global $DB;

        $slot = is_object($slotoreventid) ? $slotoreventid : self::get_slot((int)$slotoreventid);
        if ($DB->record_exists('event', ['id' => (int)$slot->id])) {
            $event = \calendar_event::load((int)$slot->id);
            $event->delete(true);
        }

        $DB->delete_records(self::ATTENDEE_TABLE, ['slotid' => (int)$slot->slotid]);
        $DB->delete_records(self::REMINDER_TABLE, ['slotid' => (int)$slot->slotid]);
        $DB->delete_records(self::SLOT_TABLE, ['id' => (int)$slot->slotid]);
    }

    /**
     * Mark a reminder as sent.
     *
     * @param int $eventid
     * @param string $key
     * @return void
     */
    public static function mark_reminder_sent(int $eventid, string $key, int $recipientuserid): void {
        global $DB;

        $slot = self::get_slot($eventid);
        $existing = $DB->get_record(self::REMINDER_TABLE, [
            'slotid' => $slot->slotid,
            'reminderkey' => $key,
            'recipientuserid' => $recipientuserid,
        ]);
        if ($existing) {
            $existing->timesent = time();
            $DB->update_record(self::REMINDER_TABLE, $existing);
            return;
        }

        $DB->insert_record(self::REMINDER_TABLE, (object)[
            'slotid' => $slot->slotid,
            'reminderkey' => $key,
            'recipientuserid' => $recipientuserid,
            'timesent' => time(),
        ]);
    }

    /**
     * Build a default slot metadata payload.
     *
     * @return array
     */
    public static function default_metadata(): array {
        return [
            'kind' => 'appointment_slot',
            'status' => 'open',
            'hostid' => 0,
            'attendeeuserids' => [],
            'msteams_join_url' => null,
            'graph_event_id' => null,
            'graph_organizer' => null,
            'reminders' => [],
        ];
    }

    /**
     * @param \stdClass $record
     * @param array $attendeeids
     * @param array $reminders
     * @param \stdClass|null $host
     * @return \stdClass
     */
    private static function decorate_slot(\stdClass $record, array $attendeeids, array $reminders, ?\stdClass $host): \stdClass {
        $slot = clone $record;
        $slot->id = (int)$record->eventid;
        $slot->slotid = (int)$record->slotid;
        $slot->descriptiontext = (string)$record->basedescription;
        $slot->status = (string)$record->status;
        $slot->statuslabel = get_string($slot->status, 'local_msteams');
        $slot->hostid = empty($record->hostid) ? null : (int)$record->hostid;
        $slot->msteams_join_url = $record->msteams_join_url;
        $slot->graph_event_id = $record->graph_event_id;
        $slot->graph_organizer = $record->graph_organizer;
        $slot->hostname = $host ? fullname($host) : get_string('unassigned', 'local_msteams');
        $slot->attendeeuserids = array_values(array_unique(array_map('intval', $attendeeids)));
        $slot->metadata = self::merge_metadata(self::default_metadata(), [
            'status' => $slot->status,
            'hostid' => $slot->hostid,
            'attendeeuserids' => $slot->attendeeuserids,
            'msteams_join_url' => $slot->msteams_join_url,
            'graph_event_id' => $slot->graph_event_id,
            'graph_organizer' => $slot->graph_organizer,
            'reminders' => $reminders,
        ]);
        return $slot;
    }

    /**
     * @param array $slotids
     * @return array
     */
    private static function load_attendee_map(array $slotids): array {
        global $DB;

        if (!$slotids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($slotids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(self::ATTENDEE_TABLE, 'slotid ' . $insql, $params, '', 'id, slotid, userid');
        $map = [];
        foreach ($records as $record) {
            $map[(int)$record->slotid][] = (int)$record->userid;
        }
        return $map;
    }

    /**
     * @param array $slotids
     * @return array
     */
    private static function load_reminder_map(array $slotids): array {
        global $DB;

        if (!$slotids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($slotids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(self::REMINDER_TABLE, 'slotid ' . $insql, $params, '', 'id, slotid, reminderkey, timesent');
        $map = [];
        foreach ($records as $record) {
            $map[(int)$record->slotid][(string)$record->reminderkey][(int)$record->recipientuserid] = (int)$record->timesent;
        }
        return $map;
    }

    /**
     * @param array $hostids
     * @return array
     */
    private static function load_host_map(array $hostids): array {
        global $DB;

        if (!$hostids) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($hostids, SQL_PARAMS_NAMED);
        return $DB->get_records_select('user', 'id ' . $insql . ' AND deleted = 0', $params, '', 'id, firstname, lastname, email');
    }

    /**
     * @param int $slotid
     * @param array $userids
     * @param int $hostid
     * @return void
     */
    private static function store_attendees(int $slotid, array $userids, int $hostid): void {
        global $DB;

        $cleanids = [];
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            if ($userid > 0 && $userid !== $hostid) {
                $cleanids[$userid] = $userid;
            }
        }

        $DB->delete_records(self::ATTENDEE_TABLE, ['slotid' => $slotid]);
        foreach ($cleanids as $userid) {
            $DB->insert_record(self::ATTENDEE_TABLE, (object)[
                'slotid' => $slotid,
                'userid' => $userid,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * @param int $slotid
     * @param array $reminders
     * @return void
     */
    private static function store_reminders(int $slotid, array $reminders): void {
        global $DB;

        $DB->delete_records(self::REMINDER_TABLE, ['slotid' => $slotid]);
        foreach ($reminders as $key => $timesent) {
            if (is_array($timesent)) {
                foreach ($timesent as $userid => $recipienttimesent) {
                    $DB->insert_record(self::REMINDER_TABLE, (object)[
                        'slotid' => $slotid,
                        'reminderkey' => (string)$key,
                        'recipientuserid' => (int)$userid,
                        'timesent' => (int)$recipienttimesent,
                    ]);
                }
            } else {
                $DB->insert_record(self::REMINDER_TABLE, (object)[
                    'slotid' => $slotid,
                    'reminderkey' => (string)$key,
                    'recipientuserid' => 0,
                    'timesent' => (int)$timesent,
                ]);
            }
        }
    }

    /**
     * @param int $slotid
     * @return void
     */
    private static function clear_reminders(int $slotid): void {
        global $DB;
        $DB->delete_records(self::REMINDER_TABLE, ['slotid' => $slotid]);
    }

    /**
     * @param int $eventid
     * @param string $basedescription
     * @param int $hostid
     * @return void
     */
    private static function update_event_description(int $eventid, string $basedescription, int $hostid): void {
        $event = \calendar_event::load($eventid);
        $event->update((object)[
            'id' => $eventid,
            'description' => self::render_event_description($basedescription, $hostid),
            'format' => FORMAT_HTML,
        ], false);
    }

    /**
     * @param string $description
     * @param int $hostid
     * @return string
     */
    private static function render_event_description(string $description, int $hostid): string {
        global $DB;

        $body = trim($description);
        if ($hostid <= 0) {
            return $body;
        }

        $host = $DB->get_record('user', ['id' => $hostid, 'deleted' => 0, 'suspended' => 0], 'id, firstname, lastname, email');
        if (!$host) {
            return $body;
        }

        $contact = [];
        $contact[] = '<p><strong>' . get_string('host', 'local_msteams') . ':</strong> ' . s(fullname($host)) . '</p>';
        if (!empty($host->email)) {
            $contact[] = '<p><strong>' . get_string('email') . ':</strong> ' . s($host->email) . '</p>';
        }

        return rtrim($body) . "\n<!-- " . self::CONTACT_MARKER . " -->\n" . implode("\n", $contact) . "\n<!-- /" . self::CONTACT_MARKER . " -->";
    }

    /**
     * @param array $existing
     * @param array $changes
     * @return array
     */
    private static function merge_metadata(array $existing, array $changes): array {
        $metadata = array_merge(self::default_metadata(), $existing);
        foreach ($changes as $key => $value) {
            $metadata[$key] = $value;
        }
        return $metadata;
    }

    /**
     * Build a UTC timestamp from the form's local date/hour/minute controls.
     *
     * @param int $startdate
     * @param int $starthour
     * @param int $startminute
     * @return int
     */
    public static function build_timestamp_from_form(int $startdate, int $starthour, int $startminute): int {
        if ($startdate <= 0) {
            return 0;
        }

        $parts = usergetdate($startdate);
        return make_timestamp(
            (int)$parts['year'],
            (int)$parts['mon'],
            (int)$parts['mday'],
            $starthour,
            $startminute,
            0,
            99,
            true
        );
    }
}
