/* II.4B: progressive enhancement; no fields are removed or disabled on tab changes. */
(function () {
    'use strict';
    document.querySelectorAll('.ls-unit-workbench').forEach(function (form) {
        var tabs = Array.prototype.slice.call(form.querySelectorAll('[data-ls-tab]'));
        var panels = Array.prototype.slice.call(form.querySelectorAll('[data-ls-panel]'));
        if (!tabs.length || !panels.length) { return; }
        var activate = function (name) {
            tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-ls-tab') === name;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-ls-panel') !== name;
            });
        };
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () { activate(tab.getAttribute('data-ls-tab')); });
        });
        // If a required input in another panel is invalid, show its panel for correction.
        form.addEventListener('invalid', function (event) {
            var panel = event.target.closest('[data-ls-panel]');
            if (panel) { activate(panel.getAttribute('data-ls-panel')); }
        }, true);
        activate('overview');
    });
}());
/* II.4C: dynamic row editing. Original field names and server-side limits remain authoritative. */
(function () {
    'use strict';
    document.querySelectorAll('.ls-unit-workbench').forEach(function (form) {
        var lists = {};
        form.querySelectorAll('[data-ls-list]').forEach(function (list) {
            var kind = list.getAttribute('data-ls-list');
            var first = list.querySelector('[data-ls-row]');
            if (!first) { return; }
            var template = first.cloneNode(true);
            template.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (field.tagName === 'SELECT') { field.selectedIndex = 0; }
                else { field.value = field.name && /\[(max)\]$/.test(field.name) ? '1' : (field.name && /\[(min|points)\]$/.test(field.name) ? '0' : ''); }
            });
            lists[kind] = { list: list, template: template, limit: Number(list.getAttribute('data-ls-limit')) };
            // An initially empty catalogue gets a usable Add button, not an empty form row.
            var fields = first.querySelectorAll('input, select');
            var blank = Array.prototype.every.call(fields, function (field) {
                return field.tagName === 'SELECT' ? field.selectedIndex === 0 : (!field.value || (kind === 'upgrades' && /\[(min|points|max)\]$/.test(field.name)));
            });
            if (blank) { first.remove(); }
        });
        function reindex(kind) {
            var state = lists[kind];
            if (!state) { return; }
            state.list.querySelectorAll('[data-ls-row]').forEach(function (row, index) {
                row.querySelectorAll('[name]').forEach(function (field) {
                    field.name = field.name.replace(/\[\d+\]/, '[' + index + ']');
                });
                var legend = row.querySelector('legend');
                if (legend) { legend.textContent = (kind === 'models' ? 'Model ' : 'Option ') + (index + 1); }
            });
            var add = form.querySelector('[data-ls-add="' + kind + '"]');
            if (add) { add.disabled = state.list.querySelectorAll('[data-ls-row]').length >= state.limit; }
        }
        function refreshModelChoices() {
            var names = [];
            if (!lists.models) { return; }
            lists.models.list.querySelectorAll('input[name$="[name]"]').forEach(function (field) {
                if (field.value.trim() && names.indexOf(field.value.trim()) === -1) { names.push(field.value.trim()); }
            });
            ['wargear', 'upgrades'].forEach(function (kind) {
                if (!lists[kind]) { return; }
                lists[kind].list.querySelectorAll('select[name$="[model]"]').forEach(function (select) {
                    var selected = select.value;
                    while (select.options.length > 1) { select.remove(1); }
                    names.forEach(function (name) {
                        var option = document.createElement('option'); option.value = name; option.textContent = name; select.add(option);
                    });
                    select.value = selected;
                });
            });
        }
        form.addEventListener('click', function (event) {
            var add = event.target.closest('[data-ls-add]');
            if (add) {
                var kind = add.getAttribute('data-ls-add'), state = lists[kind];
                if (!state || state.list.querySelectorAll('[data-ls-row]').length >= state.limit) { return; }
                state.list.appendChild(state.template.cloneNode(true));
                reindex(kind); refreshModelChoices();
                return;
            }
            var remove = event.target.closest('[data-ls-remove]');
            if (remove && form.contains(remove)) {
                var row = remove.closest('[data-ls-row]'), list = row && row.closest('[data-ls-list]');
                if (row && list) { row.remove(); reindex(list.getAttribute('data-ls-list')); refreshModelChoices(); }
            }
        });
        form.addEventListener('input', function (event) {
            if (event.target.matches('input[name^="ls_model_profiles"][name$="[name]"]')) { refreshModelChoices(); }
        });
        Object.keys(lists).forEach(reindex);
        refreshModelChoices();
    });
}());
