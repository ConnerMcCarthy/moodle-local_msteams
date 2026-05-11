<?php
namespace local_msteams\local;

defined('MOODLE_INTERNAL') || die();

final class attendee_selector {
    /**
     * Render the attendee selector markup.
     *
     * @param string $prefix
     * @return string
     */
    public static function render(string $prefix): string {
        $searchlabel = get_string('searchattendeesplaceholder', 'local_msteams');

        $html = '';
        $html .= \html_writer::start_div('fitem local-msteams-attendee-selector');
        $html .= \html_writer::tag('label', get_string('addattendees', 'local_msteams'), [
            'class' => 'col-form-label d-block',
            'for' => $prefix . '_search',
        ]);
        $html .= \html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => $prefix . '_search',
            'class' => 'form-control d-inline-block',
            'style' => 'max-width:30rem',
            'autocomplete' => 'off',
            'placeholder' => $searchlabel,
        ]);
        $html .= \html_writer::div('', 'mt-2', ['id' => $prefix . '_results']);
        $html .= \html_writer::tag('label', get_string('selectedattendees', 'local_msteams'), [
            'class' => 'col-form-label d-block mt-3',
            'for' => $prefix . '_selected',
        ]);
        $html .= \html_writer::div('', 'border rounded p-2', ['id' => $prefix . '_selected']);
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * Queue the attendee selector JavaScript.
     *
     * @param string $prefix
     * @param array $users
     * @param array $selectedids
     * @param string $mode
     * @param array $options
     * @return void
     */
    public static function queue_js(string $prefix, array $users, array $selectedids, string $mode, array $options = []): void {
        global $PAGE;

        $config = [
            'prefix' => $prefix,
            'mode' => $mode,
            'users' => array_values($users),
            'selectedids' => array_values(array_unique(array_map('intval', $selectedids))),
            'hiddenname' => $options['hiddenname'] ?? '',
            'addbase' => $options['addbase'] ?? '',
            'removebase' => $options['removebase'] ?? '',
            'selectedlabel' => get_string('selectedattendees', 'local_msteams'),
            'addlabel' => get_string('add'),
            'removelabel' => get_string('remove'),
            'emptylabel' => get_string('noattendeematches', 'local_msteams'),
            'nonechosen' => get_string('noattendeesselected', 'local_msteams'),
        ];
        $PAGE->requires->js_call_amd('local_msteams/attendee_selector', 'init', [$config]);
    }
}
