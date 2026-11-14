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
