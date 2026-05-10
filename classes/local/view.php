<?php
namespace local_msteams\local;

defined('MOODLE_INTERNAL') || die();

final class view {
    /**
     * @param array $slots
     * @param moodle_url|null $baseurl
     * @param bool $showactions
     * @return string
     */
    public static function render_calendar(
        array $slots,
        ?\moodle_url $baseurl = null,
        bool $showactions = false,
        ?\moodle_url $entrybaseurl = null,
        ?\moodle_url $joinbaseurl = null,
        bool $joinpopup = false
    ): string {
        global $OUTPUT, $USER;

        if (!$slots) {
            return $OUTPUT->notification(get_string('noappointments', 'local_msteams'), 'info');
        }

        $months = [];
        foreach ($slots as $slot) {
            $monthkey = userdate((int)$slot->timestart, '%Y-%m');
            $daykey = userdate((int)$slot->timestart, '%Y-%m-%d');
            $months[$monthkey]['label'] = userdate((int)$slot->timestart, '%B %Y');
            $months[$monthkey]['days'][$daykey]['label'] = userdate((int)$slot->timestart, '%A, %e %B');
            $months[$monthkey]['days'][$daykey]['slots'][] = $slot;
        }

        $out = [];
        foreach ($months as $month) {
            $out[] = \html_writer::tag('h3', s($month['label']));
            foreach ($month['days'] as $day) {
                $out[] = \html_writer::tag('h4', s($day['label']));
                $table = new \html_table();
                $table->head = [
                    get_string('starttime', 'local_msteams'),
                    get_string('eventname', 'local_msteams'),
                    get_string('host', 'local_msteams'),
                    $joinbaseurl ? get_string('appointmentaction', 'local_msteams') : get_string('status', 'local_msteams'),
                ];
                if ($showactions) {
                    $table->head[] = get_string('actions');
                }

                foreach ($day['slots'] as $slot) {
                    if ($joinbaseurl) {
                        $isjoined = in_array((int)$USER->id, array_map('intval', $slot->attendeeuserids ?? []), true);
                        $joinurl = new \moodle_url($joinbaseurl, [
                            'id' => $slot->id,
                            'popup' => 1,
                            $isjoined ? 'leaveattendee' : 'joinattendee' => 0,
                        ]);
                        $meetinglink = \html_writer::link(
                            $joinurl,
                            $isjoined ? get_string('leaveappointment', 'local_msteams') : get_string('joinappointment', 'local_msteams'),
                            [
                                'class' => $isjoined ? 'btn btn-danger btn-sm' : 'btn btn-success btn-sm',
                                'onclick' => $joinpopup ? self::popup_onclick_js('msteamsjoin') : null,
                            ]
                        );
                    } else {
                        $meetinglink = empty($slot->msteams_join_url)
                            ? '-'
                            : \html_writer::link($slot->msteams_join_url, 'Teams link', [
                                'target' => '_blank',
                                'rel' => 'noopener noreferrer',
                            ]);
                    }

                    $eventname = format_string($slot->name);
                    if ($entrybaseurl) {
                        $eventname = \html_writer::link(
                            new \moodle_url($entrybaseurl, ['id' => $slot->id]),
                            $eventname
                        );
                    }

                    $row = [
                        userdate((int)$slot->timestart, get_string('strftimetime', 'langconfig')),
                        $eventname,
                        s($slot->hostname),
                        $meetinglink,
                    ];

                    if ($showactions) {
                        $row[] = self::render_actions($slot, $baseurl);
                    }

                    $table->data[] = $row;
                }

                $out[] = \html_writer::table($table);
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param array $slots
     * @return string
     */
    /**
     * @param array $slots
     * @param string $month
     * @return string
     */
    public static function render_visual_calendar(array $slots, string $month, ?\moodle_url $entrybaseurl = null, bool $entrypopup = false): string {
        global $OUTPUT, $USER;

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = gmdate('Y-m');
        }

        $slotsbyday = [];
        foreach ($slots as $slot) {
            $daykey = userdate((int)$slot->timestart, '%Y-%m') . '-' . sprintf('%02d', (int)userdate((int)$slot->timestart, '%d'));
            $slotsbyday[$daykey][] = $slot;
        }

        $selected = new \DateTimeImmutable($month . '-01 00:00:00', new \DateTimeZone('UTC'));
        $label = $selected->format('F Y');
        $monthstart = $selected->modify('-' . (int)$selected->format('w') . ' days');
        $monthend = $selected->modify('last day of this month');
        $gridend = $monthend->modify('+' . (6 - (int)$monthend->format('w')) . ' days');

        $weekdayheaders = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $out = [];
        $out[] = '<style>
.local-msteams-scheduler-calendar-nav { display: flex; gap: .75rem; flex-wrap: wrap; margin: 0 0 1rem; }
.local-msteams-scheduler-calendar-nav .singlebutton { margin: 0; }
.local-msteams-scheduler-month { margin: 0 0 2rem; }
.local-msteams-scheduler-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
.local-msteams-scheduler-grid th { background: #f5f7fb; font-weight: 600; padding: .55rem; border: 1px solid #d9e0ea; text-align: center; }
.local-msteams-scheduler-grid td { vertical-align: top; height: 8.75rem; border: 1px solid #d9e0ea; padding: .45rem; background: #fff; }
.local-msteams-scheduler-grid td.local-msteams-scheduler-has-slot { background: #eef7ff; }
.local-msteams-scheduler-grid td.local-msteams-scheduler-outside { background: #f8fafc; color: #7a8699; }
.local-msteams-scheduler-daynum { font-weight: 700; margin-bottom: .4rem; }
.local-msteams-scheduler-entry { display: block; margin: 0 0 .35rem; padding: .35rem .45rem; border-radius: .45rem; background: #dbeafe; color: inherit; text-decoration: none; }
.local-msteams-scheduler-entry:hover { background: #bfdbfe; }
.local-msteams-scheduler-entry-preview { background: #fef3c7; }
.local-msteams-scheduler-entry-preview:hover { background: #fde68a; }
.local-msteams-scheduler-entry-joined { background: #dcfce7; }
.local-msteams-scheduler-entry-joined:hover { background: #bbf7d0; }
.local-msteams-scheduler-entrytime { font-size: .85rem; font-weight: 700; }
.local-msteams-scheduler-entrytitle { font-size: .85rem; margin-top: .1rem; }
.local-msteams-scheduler-entryhost { font-size: .85rem; }
.local-msteams-scheduler-entrystatus { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #4b5563; }
.local-msteams-scheduler-action-delete { color: #b42318; font-weight: 600; }
</style>';

        $table = new \html_table();
        $table->attributes['class'] = 'local-msteams-scheduler-grid generaltable';
        $table->head = $weekdayheaders;
        $table->data = [];

        $cursor = $monthstart;
        while ($cursor <= $gridend) {
            $row = [];
            for ($i = 0; $i < 7; $i++) {
                $daykey = $cursor->format('Y-m-d');
                $classes = [];
                if ($cursor->format('Y-m') !== $month) {
                    $classes[] = 'local-msteams-scheduler-outside';
                }
                if (!empty($slotsbyday[$daykey])) {
                    $classes[] = 'local-msteams-scheduler-has-slot';
                }

                $content = [];
                $content[] = \html_writer::div($cursor->format('j'), 'local-msteams-scheduler-daynum');
                foreach ($slotsbyday[$daykey] ?? [] as $slot) {
                    $isjoined = $entrypopup && in_array((int)$USER->id, array_map('intval', $slot->attendeeuserids ?? []), true);
                    $entrycontent =
                        \html_writer::div(userdate((int)$slot->timestart, get_string('strftimetime', 'langconfig')), 'local-msteams-scheduler-entrytime') .
                        \html_writer::div(format_string($slot->name), 'local-msteams-scheduler-entrytitle') .
                        \html_writer::div('- ' . s($slot->hostname), 'local-msteams-scheduler-entryhost') .
                        ($isjoined ? \html_writer::div(get_string('joinedlabel', 'local_msteams'), 'local-msteams-scheduler-entrystatus') : '');

                    $entryclasses = 'local-msteams-scheduler-entry';
                    if (!empty($slot->ispreview)) {
                        $entryclasses .= ' local-msteams-scheduler-entry-preview';
                    } else if ($isjoined) {
                        $entryclasses .= ' local-msteams-scheduler-entry-joined';
                    }

                    if ($entrybaseurl && empty($slot->ispreview) && !empty($slot->id)) {
                        $entryurl = new \moodle_url($entrybaseurl, ['id' => $slot->id, 'popup' => 1]);
                        $content[] = \html_writer::link(
                            $entryurl,
                            $entrycontent,
                            [
                                'class' => $entryclasses,
                                'onclick' => $entrypopup ? self::popup_onclick_js('msteamsjoin') : null,
                            ]
                        );
                    } else if (empty($slot->ispreview) && !empty($slot->id) && has_capability('local/msteams:manageslots', \context_system::instance())) {
                        $content[] = \html_writer::link(
                            new \moodle_url('/local/msteams/edit.php', ['id' => $slot->id]),
                            $entrycontent,
                            ['class' => $entryclasses]
                        );
                    } else {
                        $content[] = \html_writer::div($entrycontent, $entryclasses);
                    }
                }

                $cell = new \html_table_cell(implode('', $content));
                if ($classes) {
                    $cell->attributes['class'] = implode(' ', $classes);
                }
                $row[] = $cell;
                $cursor = $cursor->modify('+1 day');
            }
            $table->data[] = $row;
        }

        if (empty($slotsbyday)) {
            $out[] = $OUTPUT->notification(get_string('noappointments', 'local_msteams'), 'info');
        }

        $out[] = \html_writer::div(
            \html_writer::tag('h3', s($label)) . \html_writer::table($table),
            'local-msteams-scheduler-month'
        );

        return implode("
", $out);
    }

    /**
     * @param \stdClass $slot
     * @param moodle_url|null $baseurl
     * @return string
     */
    public static function render_slot_card(\stdClass $slot, ?\moodle_url $baseurl = null): string {
        $items = [];
        $items[] = \html_writer::tag('div', userdate((int)$slot->timestart) . ' (' . format_time((int)$slot->timeduration) . ')');
        $items[] = \html_writer::tag('div', get_string('host', 'local_msteams') . ': ' . s($slot->hostname));
        $items[] = \html_writer::tag('div', get_string('status', 'local_msteams') . ': ' . s($slot->statuslabel));

        if (!empty($slot->msteams_join_url)) {
            $items[] = \html_writer::tag('div', \html_writer::link(
                $slot->msteams_join_url,
                get_string('meetinglink', 'local_msteams'),
                [
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer',
                ]
            ));
        }

        if ($baseurl) {
            $items[] = \html_writer::tag('div', self::render_actions($slot, $baseurl));
        }

        return \html_writer::div(
            \html_writer::tag('h4', format_string($slot->name)) . implode("\n", $items),
            'local-msteams-scheduler-slot'
        );
    }

    /**
     * @param \stdClass $slot
     * @param moodle_url|null $baseurl
     * @return string
     */
    private static function render_actions(\stdClass $slot, ?\moodle_url $baseurl = null): string {
        global $USER;

        $links = [];
        $returnurl = $baseurl ? $baseurl->out(false) : (new \moodle_url('/local/msteams/index.php'))->out(false);

        if (has_capability('local/msteams:manageslots', \context_system::instance())) {
            $links[] = \html_writer::link(
                new \moodle_url('/local/msteams/edit.php', ['id' => $slot->id]),
                get_string('edit')
            );
            if (!empty($slot->hostid)) {
                $links[] = \html_writer::link(
                    new \moodle_url('/local/msteams/attendees.php', ['id' => $slot->id]),
                    get_string('manageattendees', 'local_msteams')
                );
            }
        }

        if ($slot->status !== 'cancelled' && has_capability('local/msteams:claimslot', \context_system::instance())) {
            if (empty($slot->hostid)) {
                $links[] = \html_writer::link(
                    new \moodle_url('/local/msteams/claim.php', ['id' => $slot->id, 'sesskey' => sesskey(), 'returnurl' => $returnurl]),
                    get_string('claimslot', 'local_msteams')
                );
            } else if ((int)$slot->hostid === (int)$USER->id || has_capability('local/msteams:manageslots', \context_system::instance())) {
                $links[] = \html_writer::link(
                    new \moodle_url('/local/msteams/release.php', ['id' => $slot->id, 'returnurl' => $returnurl]),
                    get_string('releaseslot', 'local_msteams')
                );
            }
        }

        if (has_capability('local/msteams:manageslots', \context_system::instance())) {
            $links[] = \html_writer::link(
                new \moodle_url('/local/msteams/toggle.php', ['id' => $slot->id, 'sesskey' => sesskey(), 'returnurl' => $returnurl]),
                $slot->status === 'cancelled' ? get_string('reopenslot', 'local_msteams') : get_string('cancelslot', 'local_msteams')
            );
            $links[] = \html_writer::link(
                new \moodle_url('/local/msteams/delete.php', ['id' => $slot->id, 'sesskey' => sesskey(), 'returnurl' => $returnurl]),
                get_string('deleteslot', 'local_msteams'),
                ['style' => 'color:#b42318;font-weight:600;']
            );
        }

        return implode(' | ', $links);
    }

    /**
     * @param string $windowname
     * @return string
     */
    private static function popup_onclick_js(string $windowname): string {
        return "var w=window.open(this.href,'{$windowname}','width=560,height=420,resizable=yes,scrollbars=yes'); if(!w){ window.location=this.href; } return false;";
    }
}
