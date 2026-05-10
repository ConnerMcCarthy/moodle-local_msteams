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

    if ($oldversion < 2026051002) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_msteams_reminder');
        $field = new xmldb_field('recipientuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'reminderkey');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $oldindex = new xmldb_index('slotid_reminderkey_uix', XMLDB_INDEX_UNIQUE, ['slotid', 'reminderkey']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }

        $newindex = new xmldb_index('slotid_reminderkey_userid_uix', XMLDB_INDEX_UNIQUE, ['slotid', 'reminderkey', 'recipientuserid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        $DB->execute("UPDATE {local_msteams_reminder} SET recipientuserid = 0 WHERE recipientuserid IS NULL");
        upgrade_plugin_savepoint(true, 2026051002, 'local', 'msteams');
    }

    return true;
}
