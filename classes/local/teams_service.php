<?php
namespace local_msteams\local;

defined('MOODLE_INTERNAL') || die();

final class teams_service {
    /** @var graph_client */
    private $client;

    public function __construct(?graph_client $client = null) {
        $this->client = $client ?? new graph_client();
    }

    /**
     * @param int $eventid
     * @return void
     */
    public function sync_slot(int $eventid): void {
        $slot = storage::get_slot($eventid);
        if (!$this->client->is_configured()) {
            return;
        }

        if ($slot->status !== 'claimed' || empty($slot->hostid)) {
            $this->delete_remote_event($slot);
            return;
        }

        $host = $this->get_host($slot);
        if (empty($host->email)) {
            throw new \moodle_exception('hostemailrequired', 'local_msteams');
        }

        $organizer = trim((string)get_config('local_msteams', 'graphorganizer'));
        $timezone = trim((string)get_config('local_msteams', 'graphtimezone'));
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        $payload = [
            'subject' => $slot->name,
            'body' => [
                'contentType' => 'HTML',
                'content' => $slot->descriptiontext ?: $slot->name,
            ],
            'start' => [
                'dateTime' => $this->format_graph_datetime((int)$slot->timestart, $timezone),
                'timeZone' => $this->normalise_graph_timezone($timezone),
            ],
            'end' => [
                'dateTime' => $this->format_graph_datetime((int)$slot->timestart + (int)$slot->timeduration, $timezone),
                'timeZone' => $this->normalise_graph_timezone($timezone),
            ],
            'isOnlineMeeting' => true,
            'onlineMeetingProvider' => 'teamsForBusiness',
            'attendees' => $this->build_attendees($slot, $host),
        ];

        $graphid = $slot->metadata['graph_event_id'] ?? null;
        if (empty($graphid)) {
            $response = $this->client->post('users/' . rawurlencode($organizer) . '/events', $payload);
        } else {
            $response = $this->client->patch(
                'users/' . rawurlencode($organizer) . '/events/' . rawurlencode((string)$graphid),
                $payload
            );
            if (empty($response)) {
                $response = [
                    'id' => $graphid,
                    'onlineMeeting' => [
                        'joinUrl' => $slot->msteams_join_url,
                    ],
                ];
            }
        }

        $metadata = $slot->metadata;
        $metadata['graph_event_id'] = $response['id'] ?? $graphid;
        $metadata['graph_organizer'] = $organizer;
        $metadata['msteams_join_url'] = $response['onlineMeeting']['joinUrl'] ?? ($response['onlineMeetingUrl'] ?? $slot->msteams_join_url);
        storage::save_metadata($eventid, $metadata);
    }

    /**
     * @param \stdClass $slot
     * @return void
     */
    private function delete_remote_event(\stdClass $slot): void {
        $graphid = $slot->metadata['graph_event_id'] ?? null;
        $organizer = $slot->metadata['graph_organizer'] ?? trim((string)get_config('local_msteams', 'graphorganizer'));

        if (!empty($graphid) && !empty($organizer)) {
            $this->client->delete(
                'users/' . rawurlencode((string)$organizer) . '/events/' . rawurlencode((string)$graphid)
            );
        }

        $metadata = $slot->metadata;
        $metadata['graph_event_id'] = null;
        $metadata['graph_organizer'] = null;
        if (!empty($graphid)) {
            $metadata['msteams_join_url'] = null;
        }
        storage::save_metadata((int)$slot->id, $metadata);
    }

    /**
     * @param \stdClass $slot
     * @return \stdClass
     */
    private function get_host(\stdClass $slot): \stdClass {
        global $DB;

        return $DB->get_record('user', ['id' => $slot->hostid, 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
    }

    /**
     * @param \stdClass $slot
     * @param \stdClass $host
     * @return array
     */
    private function build_attendees(\stdClass $slot, \stdClass $host): array {
        global $DB;

        $attendees = [];
        $seen = [];

        $append = static function(\stdClass $user) use (&$attendees, &$seen): void {
            $email = trim((string)($user->email ?? ''));
            if ($email === '') {
                return;
            }

            $key = \core_text::strtolower($email);
            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $attendees[] = [
                'emailAddress' => [
                    'address' => $email,
                    'name' => fullname($user),
                ],
                'type' => 'required',
            ];
        };

        $append($host);

        $extraids = array_values(array_unique(array_map('intval', $slot->attendeeuserids ?? [])));
        if ($extraids) {
            list($insql, $params) = $DB->get_in_or_equal($extraids, SQL_PARAMS_NAMED);
            $records = $DB->get_records_select('user', 'id ' . $insql . ' AND deleted = 0 AND suspended = 0', $params, '', 'id, firstname, lastname, email');
            foreach ($extraids as $userid) {
                if (!empty($records[$userid])) {
                    $append($records[$userid]);
                }
            }
        }

        return $attendees;
    }

    /**
     * @param int $timestamp
     * @param string $timezone
     * @return string
     */
    private function format_graph_datetime(int $timestamp, string $timezone): string {
        $phpzone = $this->to_php_timezone($timezone);
        $dt = new \DateTimeImmutable('@' . $timestamp);
        $dt = $dt->setTimezone(new \DateTimeZone($phpzone));
        return $dt->format('Y-m-d\TH:i:s');
    }

    /**
     * @param string $timezone
     * @return string
     */
    private function normalise_graph_timezone(string $timezone): string {
        return $this->to_php_timezone($timezone) === 'UTC' ? 'UTC' : $timezone;
    }

    /**
     * @param string $timezone
     * @return string
     */
    private function to_php_timezone(string $timezone): string {
        if ($timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception $e) {
            return 'UTC';
        }
    }
}
