<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for local_msteams.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_msteams_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026051001) {
        $DB->execute(
            "UPDATE {event}
                SET eventtype = :newtype
              WHERE component = :component
                AND eventtype = :oldtype",
            [
                'newtype' => 'appointment_slot',
                'component' => 'local_msteams',
                'oldtype' => 'webinar_slot',
            ]
        );

        upgrade_plugin_savepoint(true, 2026051001, 'local', 'msteams');
    }

    return true;
}
