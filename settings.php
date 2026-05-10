<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $roleoptions = [0 => get_string('none')];
    foreach (role_fix_names(get_all_roles(\context_system::instance())) as $role) {
        $roleoptions[(int)$role->id] = $role->localname;
    }

    $settings = new admin_settingpage(
        'local_msteams',
        get_string('pluginname', 'local_msteams')
    );

    $settings->add(new admin_setting_configselect(
        'local_msteams/hostroleid',
        get_string('hostroleid', 'local_msteams'),
        get_string('hostroleid_desc', 'local_msteams'),
        0,
        $roleoptions
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_msteams/showtimezoneinfo',
        get_string('showtimezoneinfo', 'local_msteams'),
        get_string('showtimezoneinfo_desc', 'local_msteams'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_msteams/reminderfrom',
        get_string('reminderfrom', 'local_msteams'),
        get_string('reminderfrom_desc', 'local_msteams'),
        '',
        PARAM_EMAIL
    ));

    $settings->add(new admin_setting_heading(
        'local_msteams/graphheading',
        get_string('graphheading', 'local_msteams'),
        get_string('graphheading_desc', 'local_msteams')
    ));

    $settings->add(new admin_setting_configtext(
        'local_msteams/graphtenantid',
        get_string('graphtenantid', 'local_msteams'),
        get_string('graphtenantid_desc', 'local_msteams'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_msteams/graphclientid',
        get_string('graphclientid', 'local_msteams'),
        get_string('graphclientid_desc', 'local_msteams'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_msteams/graphclientsecret',
        get_string('graphclientsecret', 'local_msteams'),
        get_string('graphclientsecret_desc', 'local_msteams'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_msteams/graphorganizer',
        get_string('graphorganizer', 'local_msteams'),
        get_string('graphorganizer_desc', 'local_msteams'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_msteams/graphtimezone',
        get_string('graphtimezone', 'local_msteams'),
        get_string('graphtimezone_desc', 'local_msteams'),
        'UTC',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
