define([], function() {
    var contains = function(list, id) {
        var i;
        for (i = 0; i < list.length; i++) {
            if (parseInt(list[i], 10) === id) {
                return true;
            }
        }
        return false;
    };

    var init = function(cfg) {
        var input = document.getElementById(cfg.prefix + '_search');
        var results = document.getElementById(cfg.prefix + '_results');
        var selected = document.getElementById(cfg.prefix + '_selected');
        var hidden = null;

        if (!input || !results || !selected) {
            return;
        }

        if (cfg.hiddenname) {
            hidden = document.querySelector('input[name="' + cfg.hiddenname + '"]');
        }

        var parseHiddenIds = function() {
            var parsed;
            var clean = [];
            var id;
            var i;

            if (!hidden || !hidden.value) {
                return [];
            }

            try {
                parsed = JSON.parse(hidden.value);
            } catch (e) {
                return [];
            }

            if (Object.prototype.toString.call(parsed) !== '[object Array]') {
                return [];
            }

            for (i = 0; i < parsed.length; i++) {
                id = parseInt(parsed[i], 10);
                if (id > 0 && !contains(clean, id)) {
                    clean.push(id);
                }
            }

            return clean;
        };

        var currentSelectedIds = function() {
            var ids;

            if (cfg.mode === 'form') {
                ids = parseHiddenIds();
                if (!ids.length && cfg.selectedids.length) {
                    ids = cfg.selectedids.slice(0);
                    if (hidden) {
                        hidden.value = JSON.stringify(ids);
                    }
                }
                return ids;
            }

            return cfg.selectedids.slice(0);
        };

        var saveSelected = function(ids) {
            if (cfg.mode === 'form') {
                cfg.selectedids = ids.slice(0);
                if (hidden) {
                    hidden.value = JSON.stringify(ids);
                }
            }
        };

        var labelForId = function(id) {
            var i;
            for (i = 0; i < cfg.users.length; i++) {
                if (parseInt(cfg.users[i].id, 10) === id) {
                    return cfg.users[i].label;
                }
            }
            return String(id);
        };

        var renderSelected = function() {
            var ids = currentSelectedIds();
            var i;

            selected.innerHTML = '';
            if (!ids.length) {
                selected.appendChild(document.createTextNode(cfg.nonechosen));
                return;
            }

            for (i = 0; i < ids.length; i++) {
                (function(id) {
                    var row = document.createElement('div');
                    row.className = 'border rounded p-2 mb-2';
                    row.appendChild(document.createTextNode(labelForId(id) + ' '));

                    if (cfg.mode === 'form') {
                        var remove = document.createElement('a');
                        remove.href = '#';
                        remove.className = 'btn btn-sm btn-outline-danger';
                        remove.appendChild(document.createTextNode(cfg.removelabel));
                        remove.onclick = function(e) {
                            var current = currentSelectedIds();
                            var next = [];
                            var k;

                            if (e && e.preventDefault) {
                                e.preventDefault();
                            }

                            for (k = 0; k < current.length; k++) {
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
                        var removelink = document.createElement('a');
                        removelink.href = cfg.removebase + String(id);
                        removelink.className = 'btn btn-sm btn-outline-danger';
                        removelink.appendChild(document.createTextNode(cfg.removelabel));
                        row.appendChild(removelink);
                    }

                    selected.appendChild(row);
                })(parseInt(ids[i], 10));
            }
        };

        var renderResults = function() {
            var term = (input.value || '').toLowerCase().replace(/^\s+|\s+$/g, '');
            var ids = currentSelectedIds();
            var matches = 0;
            var i;

            results.innerHTML = '';
            if (!term) {
                return;
            }

            for (i = 0; i < cfg.users.length && matches < 20; i++) {
                (function(user) {
                    if (user.label.toLowerCase().indexOf(term) === -1) {
                        return;
                    }

                    matches++;

                    var row = document.createElement('div');
                    row.className = 'border rounded p-2 mb-2';
                    row.appendChild(document.createTextNode(user.label + ' '));

                    if (contains(ids, parseInt(user.id, 10))) {
                        var badge = document.createElement('span');
                        badge.className = 'badge badge-secondary';
                        badge.appendChild(document.createTextNode(cfg.selectedlabel));
                        row.appendChild(badge);
                    } else if (cfg.mode === 'form') {
                        var add = document.createElement('a');
                        add.href = '#';
                        add.className = 'btn btn-sm btn-primary';
                        add.appendChild(document.createTextNode(cfg.addlabel));
                        add.onclick = function(e) {
                            var current;

                            if (e && e.preventDefault) {
                                e.preventDefault();
                            }

                            current = currentSelectedIds();
                            current.push(parseInt(user.id, 10));
                            saveSelected(current);
                            renderSelected();
                            renderResults();
                            return false;
                        };
                        row.appendChild(add);
                    } else {
                        var addlink = document.createElement('a');
                        addlink.href = cfg.addbase + String(user.id);
                        addlink.className = 'btn btn-sm btn-primary';
                        addlink.appendChild(document.createTextNode(cfg.addlabel));
                        row.appendChild(addlink);
                    }

                    results.appendChild(row);
                })(cfg.users[i]);
            }

            if (!matches) {
                var empty = document.createElement('div');
                empty.className = 'text-muted';
                empty.appendChild(document.createTextNode(cfg.emptylabel));
                results.appendChild(empty);
            }
        };

        renderSelected();
        if (input.addEventListener) {
            input.addEventListener('input', renderResults);
        } else if (input.attachEvent) {
            input.attachEvent('onkeyup', renderResults);
        }
    };

    return {
        init: init
    };
});
