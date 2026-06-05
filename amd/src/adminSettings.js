define([], function() {
    const controlsId = 'quizaccess-proctoring-admin-controls';
    const pluginPrefix = 's_quizaccess_proctoring_';

    const ready = function(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    };

    const normalise = function(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    };

    const dispatchChange = function(input) {
        let event;
        if (typeof Event === 'function') {
            event = new Event('change', {bubbles: true});
        } else {
            event = document.createEvent('HTMLEvents');
            event.initEvent('change', true, false);
        }
        input.dispatchEvent(event);
    };

    const findRealCheckbox = function(setting) {
        const name = pluginPrefix + setting;
        return Array.from(document.querySelectorAll('input[type="checkbox"]'))
            .find(function(input) {
                return input.name === name;
            }) || null;
    };

    const syncShortcutToggle = function(shortcut, realInput) {
        const wrapper = shortcut.closest('.quizaccess-proctoring-admin-toggle');
        const state = wrapper ? wrapper.querySelector('.quizaccess-proctoring-admin-toggle-state') : null;
        const isAvailable = !!realInput;
        const isChecked = isAvailable && realInput.checked;

        shortcut.checked = isChecked;
        shortcut.disabled = !isAvailable || realInput.disabled;

        if (wrapper) {
            wrapper.classList.toggle('is-enabled', isChecked);
            wrapper.classList.toggle('is-disabled', isAvailable && !isChecked);
            wrapper.classList.toggle('is-unavailable', !isAvailable);
        }

        if (state) {
            state.textContent = isChecked
                ? state.getAttribute('data-on-label')
                : state.getAttribute('data-off-label');
            state.classList.toggle('bg-success', isChecked);
            state.classList.toggle('bg-secondary', !isChecked);
        }
    };

    const setRealCheckbox = function(realInput, checked) {
        if (!realInput || realInput.disabled || realInput.checked === checked) {
            return;
        }

        realInput.checked = checked;
        dispatchChange(realInput);
    };

    const setupShortcutToggles = function() {
        const controls = document.getElementById(controlsId);
        if (!controls) {
            return;
        }

        const shortcuts = Array.from(
            controls.querySelectorAll('.quizaccess-proctoring-admin-toggle-input')
        );

        shortcuts.forEach(function(shortcut) {
            const setting = shortcut.getAttribute('data-proctoring-admin-setting');
            const realInput = findRealCheckbox(setting);

            shortcut.addEventListener('change', function() {
                setRealCheckbox(realInput, shortcut.checked);
                syncShortcutToggle(shortcut, realInput);
            });

            if (realInput) {
                realInput.addEventListener('change', function() {
                    syncShortcutToggle(shortcut, realInput);
                });
            }

            syncShortcutToggle(shortcut, realInput);
        });

        controls.querySelectorAll('[data-proctoring-admin-bulk]').forEach(function(button) {
            button.addEventListener('click', function() {
                const checked = button.getAttribute('data-proctoring-admin-bulk') === 'enable';
                shortcuts.forEach(function(shortcut) {
                    const setting = shortcut.getAttribute('data-proctoring-admin-setting');
                    const realInput = findRealCheckbox(setting);
                    setRealCheckbox(realInput, checked);
                    syncShortcutToggle(shortcut, realInput);
                });
            });
        });

    };

    const getSettingsFieldset = function() {
        const controls = document.getElementById(controlsId);
        if (controls) {
            return controls.closest('fieldset');
        }
        const adminSettings = document.getElementById('adminsettings');
        return adminSettings ? adminSettings.querySelector('fieldset') : null;
    };

    const findHeadingIndex = function(children, heading) {
        const wanted = normalise(heading);
        return children.findIndex(function(child) {
            return child.matches && child.matches('h3.main') && normalise(child.textContent) === wanted;
        });
    };

    const markSections = function(fieldset, tabs) {
        const children = Array.from(fieldset.children);
        const headings = tabs
            .filter(function(tab) {
                return tab.key !== 'all' && tab.heading;
            })
            .map(function(tab) {
                return {
                    key: tab.key,
                    index: findHeadingIndex(children, tab.heading)
                };
            })
            .filter(function(entry) {
                return entry.index >= 0;
            })
            .sort(function(a, b) {
                return a.index - b.index;
            });

        headings.forEach(function(entry, position) {
            const end = position + 1 < headings.length ? headings[position + 1].index : children.length;
            for (let i = entry.index; i < end; i++) {
                children[i].setAttribute('data-proctoring-admin-section', entry.key);
            }
        });

        return headings.map(function(entry) {
            return entry.key;
        });
    };

    const readStoredTab = function(storageKey) {
        if (!storageKey) {
            return '';
        }

        try {
            return window.localStorage.getItem(storageKey) || '';
        } catch (error) {
            return '';
        }
    };

    const writeStoredTab = function(storageKey, tabKey) {
        if (!storageKey) {
            return;
        }

        try {
            window.localStorage.setItem(storageKey, tabKey);
        } catch (error) {
            // Remembering the last open admin tab is optional.
        }
    };

    const readTabs = function(controls) {
        return Array.from(controls.querySelectorAll('[data-proctoring-admin-tab]'))
            .map(function(button) {
                return {
                    key: button.getAttribute('data-proctoring-admin-tab'),
                    heading: button.getAttribute('data-proctoring-admin-heading') || ''
                };
            });
    };

    const setupTabs = function() {
        const fieldset = getSettingsFieldset();
        const controls = document.getElementById(controlsId);
        const tabs = controls ? readTabs(controls) : [];
        if (!fieldset || !controls || !tabs.length) {
            return;
        }

        const storageKey = controls.getAttribute('data-proctoring-admin-storagekey');
        const validSections = markSections(fieldset, tabs);
        const validTabKeys = validSections.concat(['all']);
        const sectionNodes = Array.from(fieldset.querySelectorAll('[data-proctoring-admin-section]'));
        const buttons = Array.from(controls.querySelectorAll('[data-proctoring-admin-tab]'));

        const applyTab = function(tabKey) {
            const activeKey = validTabKeys.indexOf(tabKey) >= 0 ? tabKey : 'all';

            sectionNodes.forEach(function(node) {
                node.hidden = activeKey !== 'all' &&
                    node.getAttribute('data-proctoring-admin-section') !== activeKey;
            });

            buttons.forEach(function(button) {
                const selected = button.getAttribute('data-proctoring-admin-tab') === activeKey;
                button.classList.toggle('active', selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });

            writeStoredTab(storageKey, activeKey);
        };

        buttons.forEach(function(button) {
            button.addEventListener('click', function() {
                applyTab(button.getAttribute('data-proctoring-admin-tab'));
            });
        });

        applyTab(readStoredTab(storageKey) || 'all');
    };

    return {
        init: function() {
            ready(function() {
                setupShortcutToggles();
                setupTabs();
            });
        }
    };
});
