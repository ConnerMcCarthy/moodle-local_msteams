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

        $code = '(function(cfg) {
            var input = document.getElementById(cfg.prefix + "_search");
            var results = document.getElementById(cfg.prefix + "_results");
            var selected = document.getElementById(cfg.prefix + "_selected");
            if (!input || !results || !selected) {
                return;
            }
            var hidden = null;
            if (cfg.hiddenname) {
                hidden = document.querySelector(\'input[name="\' + cfg.hiddenname + \'"]\');
            }

            function contains(list, id) {
                for (var i = 0; i < list.length; i++) {
                    if (parseInt(list[i], 10) === id) {
                        return true;
                    }
                }
                return false;
            }

            function parseHiddenIds() {
                if (!hidden || !hidden.value) {
                    return [];
                }
                try {
                    var parsed = JSON.parse(hidden.value);
                    if (Object.prototype.toString.call(parsed) !== "[object Array]") {
                        return [];
                    }
                    var clean = [];
                    for (var i = 0; i < parsed.length; i++) {
                        var id = parseInt(parsed[i], 10);
                        if (id > 0 && !contains(clean, id)) {
                            clean.push(id);
                        }
                    }
                    return clean;
                } catch (e) {
                    return [];
                }
            }

            function currentSelectedIds() {
                if (cfg.mode === "form") {
                    var ids = parseHiddenIds();
                    if (!ids.length && cfg.selectedids.length) {
                        ids = cfg.selectedids.slice(0);
                        if (hidden) {
                            hidden.value = JSON.stringify(ids);
                        }
                    }
                    return ids;
                }
                return cfg.selectedids.slice(0);
            }

            function saveSelected(ids) {
                if (cfg.mode === "form") {
                    cfg.selectedids = ids.slice(0);
                    if (hidden) {
                        hidden.value = JSON.stringify(ids);
                    }
                }
            }

            function labelForId(id) {
                for (var i = 0; i < cfg.users.length; i++) {
                    if (parseInt(cfg.users[i].id, 10) === id) {
                        return cfg.users[i].label;
                    }
                }
                return String(id);
            }

            function renderSelected() {
                var ids = currentSelectedIds();
                selected.innerHTML = "";
                if (!ids.length) {
                    selected.appendChild(document.createTextNode(cfg.nonechosen));
                    return;
                }
                for (var i = 0; i < ids.length; i++) {
                    (function(id) {
                        var row = document.createElement("div");
                        row.className = "border rounded p-2 mb-2";
                        row.appendChild(document.createTextNode(labelForId(id) + " "));
                        if (cfg.mode === "form") {
                            var remove = document.createElement("a");
                            remove.href = "#";
                            remove.className = "btn btn-sm btn-outline-danger";
                            remove.appendChild(document.createTextNode(cfg.removelabel));
                            remove.onclick = function(e) {
                                if (e && e.preventDefault) {
                                    e.preventDefault();
                                }
                                var current = currentSelectedIds();
                                var next = [];
                                for (var k = 0; k < current.length; k++) {
                                    if (parseInt(current[k], 10) !== id) {
                                        next.push(parseInt(current[k], 10));
                                    }
                                }
                                saveSelected(next);
                                renderSelected();
                                renderResults();
                                return false;
                            };
                            row.appendChild(remove);
                        } else {
                            var removelink = document.createElement("a");
                            removelink.href = cfg.removebase + String(id);
                            removelink.className = "btn btn-sm btn-outline-danger";
                            removelink.appendChild(document.createTextNode(cfg.removelabel));
                            row.appendChild(removelink);
                        }
                        selected.appendChild(row);
                    })(parseInt(ids[i], 10));
                }
            }

            function renderResults() {
                var term = (input.value || "").toLowerCase().replace(/^\\s+|\\s+$/g, "");
                results.innerHTML = "";
                if (!term) {
                    return;
                }
                var ids = currentSelectedIds();
                var matches = 0;
                for (var i = 0; i < cfg.users.length && matches < 20; i++) {
                    var user = cfg.users[i];
                    if (user.label.toLowerCase().indexOf(term) === -1) {
                        continue;
                    }
                    matches++;
                    (function(user) {
                        var row = document.createElement("div");
                        row.className = "border rounded p-2 mb-2";
                        row.appendChild(document.createTextNode(user.label + " "));
                        if (contains(ids, parseInt(user.id, 10))) {
                            var badge = document.createElement("span");
                            badge.className = "badge badge-secondary";
                            badge.appendChild(document.createTextNode(cfg.selectedlabel));
                            row.appendChild(badge);
                        } else if (cfg.mode === "form") {
                            var add = document.createElement("a");
                            add.href = "#";
                            add.className = "btn btn-sm btn-primary";
                            add.appendChild(document.createTextNode(cfg.addlabel));
                            add.onclick = function(e) {
                                if (e && e.preventDefault) {
                                    e.preventDefault();
                                }
                                var current = currentSelectedIds();
                                current.push(parseInt(user.id, 10));
                                saveSelected(current);
                                renderSelected();
                                renderResults();
                                return false;
                            };
                            row.appendChild(add);
                        } else {
                            var addlink = document.createElement("a");
                            addlink.href = cfg.addbase + String(user.id);
                            addlink.className = "btn btn-sm btn-primary";
                            addlink.appendChild(document.createTextNode(cfg.addlabel));
                            row.appendChild(addlink);
                        }
                        results.appendChild(row);
                    })(user);
                }
                if (!matches) {
                    var empty = document.createElement("div");
                    empty.className = "text-muted";
                    empty.appendChild(document.createTextNode(cfg.emptylabel));
                    results.appendChild(empty);
                }
            }

            renderSelected();
            if (input.addEventListener) {
                input.addEventListener("input", renderResults);
            } else if (input.attachEvent) {
                input.attachEvent("onkeyup", renderResults);
            }
        })(' . json_encode($config) . ');';

        $PAGE->requires->js_init_code($code);
    }
}
