<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Check whether the current user has the configured host role.
 *
 * @param int|null $userid
 * @return bool
 */
function local_msteams_user_has_hostrole(?int $userid = null): bool {
    global $DB, $USER;

    if (!isloggedin() || isguestuser()) {
        return false;
    }

    $userid = $userid ?? (int)$USER->id;
    $roleid = (int)get_config('local_msteams', 'hostroleid');
    if ($roleid <= 0) {
        return false;
    }

    $context = context_system::instance();
    return $DB->record_exists('role_assignments', [
        'userid' => $userid,
        'roleid' => $roleid,
        'contextid' => $context->id,
    ]);
}

/**
 * Check whether the current user can access host-facing scheduler pages.
 *
 * @return bool
 */
function local_msteams_user_can_access_scheduler(): bool {
    $context = context_system::instance();

    return has_capability('local/msteams:viewall', $context)
        || has_capability('local/msteams:viewmine', $context)
        || has_capability('local/msteams:manageslots', $context)
        || local_msteams_user_has_hostrole();
}

/**
 * Adds plugin links to site navigation.
 *
 * @param global_navigation $nav
 * @return void
 */
function local_msteams_extend_navigation(global_navigation $nav): void {
    if (!isloggedin() || isguestuser()) {
        return;
    }

    $upcomingnode = $nav->add(
        get_string('upcomingappointments', 'local_msteams'),
        new moodle_url('/local/msteams/upcoming.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_msteams_upcoming'
    );

    if (local_msteams_user_can_access_scheduler()) {
        $nav->add(
            get_string('pluginname', 'local_msteams'),
            new moodle_url('/local/msteams/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_msteams'
        );
    }
}
