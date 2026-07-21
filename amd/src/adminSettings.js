define([], function() {
    const controlsId = 'quizaccess-proctoring-admin-controls';
    const pluginPrefix = 's_quizaccess_proctoring_';

    const primarySettings = {
        precheck: [
            'honorstatementrequired',
            'privacynoticerequired',
            'captchabeforeattemptenabled',
            'fcheckstartchk'
        ],
        face: ['adminimage', 'autoreconfigurecamshotdelay', 'fcmethod'],
        identity: [
            'idverificationenabled',
            'idverificationrequireback',
            'idverificationcheckface',
            'idverificationcheckname'
        ],
        monitoring: [
            'monitorbrowseractivity',
            'blockclipboard',
            'requireentirescreen',
            'multimonitormode',
            'captureviolationdesktop',
            'detectphone'
        ],
        review: [
            'riskreviewenabled',
            'riskreviewthreshold',
            'studentholdnoticeenabled',
            'cheatinglockoutenabled',
            'speedreviewenabled'
        ],
        ai: ['aireviewenabled', 'aireviewprovider', 'aireviewdesktopmode', 'aireviewtriggermode'],
        reporting: ['dailyreportenabled', 'dailyreportemails', 'dailyreportincludeall'],
        retention: ['imageretentiondays']
    };

    const presetDefinitions = {
        essential: {
            honorstatementrequired: true,
            privacynoticerequired: true,
            captchabeforeattemptenabled: false,
            fcheckstartchk: false,
            idverificationenabled: false,
            continuousfacecheck: false,
            monitorbrowseractivity: false,
            monitormouseactivity: false,
            blockclipboard: false,
            requireentirescreen: false,
            multimonitormode: 'off',
            blurquizwithmultiplemonitors: false,
            captureviolationdesktop: false,
            blurquizwithoutface: false,
            detectphone: false,
            riskreviewenabled: '0',
            cheatinglockoutenabled: false,
            speedreviewenabled: false,
            aireviewenabled: false,
            dailyreportenabled: false
        },
        recommended: {
            honorstatementrequired: true,
            privacynoticerequired: true,
            captchabeforeattemptenabled: false,
            fcheckstartchk: false,
            idverificationenabled: false,
            continuousfacecheck: false,
            monitorbrowseractivity: true,
            monitormouseactivity: false,
            blockclipboard: true,
            requireentirescreen: true,
            multimonitormode: 'warn',
            blurquizwithmultiplemonitors: false,
            captureviolationdesktop: true,
            mobilescreensharemode: 'bypass',
            blurquizwithoutface: false,
            detectphone: false,
            riskreviewenabled: '0',
            studentholdnoticeenabled: true,
            cheatinglockoutenabled: false,
            speedreviewenabled: false,
            aireviewenabled: false,
            dailyreportenabled: false
        },
        maximum: {
            honorstatementrequired: true,
            privacynoticerequired: true,
            captchabeforeattemptenabled: true,
            fcheckstartchk: true,
            idverificationenabled: true,
            idverificationrequireback: true,
            idverificationcheckface: true,
            idverificationcheckname: true,
            continuousfacecheck: true,
            monitorbrowseractivity: true,
            monitormouseactivity: true,
            blockclipboard: true,
            requireentirescreen: true,
            multimonitormode: 'block',
            blurquizwithmultiplemonitors: true,
            captureviolationdesktop: true,
            mobilescreensharemode: 'block',
            blurquizwithoutface: true,
            detectphone: true,
            riskreviewenabled: '1',
            riskreviewthreshold: '50',
            studentholdnoticeenabled: true,
            cheatinglockoutenabled: true,
            speedreviewenabled: true,
            aireviewenabled: true,
            aireviewdesktopmode: 'all',
            aireviewtriggermode: 'everyattempt',
            dailyreportenabled: true
        }
    };

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

    const findField = function(setting) {
        const name = pluginPrefix + setting;
        const fields = Array.from(document.querySelectorAll('[name="' + name + '"]'));
        return fields.find(function(field) {
            return field.type !== 'hidden';
        }) || fields[0] || null;
    };

    const getFieldValue = function(setting) {
        const field = findField(setting);
        if (!field) {
            return null;
        }
        if (field.type === 'checkbox') {
            return field.checked;
        }
        return String(field.value);
    };

    const setFieldValue = function(setting, value) {
        const field = findField(setting);
        if (!field || field.disabled) {
            return;
        }

        const current = field.type === 'checkbox' ? field.checked : String(field.value);
        const next = field.type === 'checkbox' ? Boolean(value) : String(value);
        if (current === next) {
            return;
        }

        if (field.type === 'checkbox') {
            field.checked = next;
        } else {
            field.value = next;
        }
        dispatchChange(field);
    };

    const hasConfiguredValue = function(setting) {
        const value = getFieldValue(setting);
        return value !== null && normalise(value) !== '';
    };

    const getPresetConfiguration = function(key) {
        const values = Object.assign({}, presetDefinitions[key]);
        let skipped = false;
        if (key !== 'maximum') {
            return {values: values, skipped: skipped};
        }

        const turnstileReady = getFieldValue('captchaprovider') === 'turnstile' &&
            hasConfiguredValue('turnstilesitekey') && hasConfiguredValue('turnstilesecretkey');
        const faceReady = getFieldValue('fcmethod') === 'customapi' &&
            hasConfiguredValue('custom_ai_endpoint') && hasConfiguredValue('custom_api_key');
        const idReady = hasConfiguredValue('idverificationendpoint') &&
            hasConfiguredValue('idverificationapikey');
        const aiProvider = getFieldValue('aireviewprovider');
        const aiReady = (aiProvider === 'openai' && hasConfiguredValue('aireviewopenaiapikey') &&
                hasConfiguredValue('aireviewopenaimodel')) ||
            (aiProvider === 'anthropic' && hasConfiguredValue('aireviewanthropicapikey') &&
                hasConfiguredValue('aireviewanthropicmodel')) ||
            (aiProvider === 'compatible' && hasConfiguredValue('aireviewcompatibleendpoint') &&
                hasConfiguredValue('aireviewcompatiblemodel'));

        values.captchabeforeattemptenabled = turnstileReady;
        values.fcheckstartchk = faceReady;
        values.continuousfacecheck = faceReady;
        values.idverificationenabled = idReady;
        values.aireviewenabled = aiReady;
        values.dailyreportenabled = hasConfiguredValue('dailyreportemails');
        skipped = !turnstileReady || !faceReady || !idReady || !aiReady || !values.dailyreportenabled;
        return {values: values, skipped: skipped};
    };

    const readFormValues = function(form) {
        const values = {};
        if (!form) {
            return values;
        }
        Array.from(form.querySelectorAll('[name]')).forEach(function(field) {
            if (!field.name || field.name.indexOf(pluginPrefix) !== 0 || field.type === 'hidden' || field.disabled) {
                return;
            }
            if (field.type === 'radio' && !field.checked) {
                return;
            }
            values[field.name] = field.type === 'checkbox' ? field.checked : String(field.value);
        });
        return values;
    };

    const serialiseFormValues = function(form) {
        return JSON.stringify(readFormValues(form));
    };

    const settingNameForNode = function(node) {
        const field = Array.from(node.querySelectorAll('[name]')).find(function(candidate) {
            return candidate.name && candidate.name.indexOf(pluginPrefix) === 0;
        });
        if (field) {
            return field.name.substring(pluginPrefix.length);
        }

        const nodeId = normalise(node.id).replace(/[^a-z0-9]/g, '');
        if (nodeId.indexOf('deleteallimages') !== -1) {
            return 'deleteallimages';
        }
        if (nodeId.indexOf('adminimage') !== -1) {
            return 'adminimage';
        }
        return '';
    };

    const getSettingsFieldset = function(controls) {
        if (controls) {
            const fieldset = controls.closest('fieldset');
            if (fieldset) {
                return fieldset;
            }
        }
        const adminSettings = document.getElementById('adminsettings');
        return adminSettings ? adminSettings.querySelector('fieldset') : null;
    };

    const isSectionHeading = function(node, heading) {
        return node.matches && node.matches('h3.main') && normalise(node.textContent) === normalise(heading);
    };

    const readSectionDefinitions = function(controls) {
        return Array.from(controls.querySelectorAll('[data-proctoring-admin-nav]')).map(function(button) {
            return {
                key: button.getAttribute('data-proctoring-admin-nav'),
                heading: button.textContent
            };
        });
    };

    const collectSections = function(fieldset, definitions) {
        const children = Array.from(fieldset.children);
        const headings = definitions.map(function(definition) {
            return {
                key: definition.key,
                heading: definition.heading,
                index: children.findIndex(function(child) {
                    return isSectionHeading(child, definition.heading);
                })
            };
        }).filter(function(definition) {
            return definition.index >= 0;
        }).sort(function(first, second) {
            return first.index - second.index;
        });

        return headings.map(function(definition, index) {
            let end = index + 1 < headings.length ? headings[index + 1].index : children.length;
            for (let position = definition.index + 1; position < end; position++) {
                if (children[position].matches('.form-buttons') || children[position].querySelector('[type="submit"]')) {
                    end = position;
                    break;
                }
            }
            return {
                key: definition.key,
                nodes: children.slice(definition.index, end)
            };
        });
    };

    const updateSwitch = function(field, state, controls) {
        const checked = field.checked;
        state.textContent = controls.getAttribute(checked ? 'data-on-label' : 'data-off-label');
        state.classList.toggle('is-on', checked);
    };

    const enhanceSettingNode = function(node, setting, controls) {
        node.classList.add('quizaccess-proctoring-admin-row');
        node.setAttribute('data-proctoring-setting', setting || 'description');
        node.setAttribute('data-proctoring-search-text', normalise(node.textContent));

        const row = node.matches('.form-item') ? node : node.querySelector('.form-item');
        if (!row) {
            return;
        }

        const label = row.querySelector('.form-label, .col-form-label');
        const fieldArea = row.querySelector('.form-setting');
        if (label && fieldArea && label.parentNode === row) {
            const copy = document.createElement('div');
            copy.className = 'quizaccess-proctoring-admin-row-copy';
            const description = fieldArea.querySelector('.form-description');
            copy.appendChild(label);
            if (description) {
                copy.appendChild(description);
            }
            row.insertBefore(copy, fieldArea);
            fieldArea.classList.add('quizaccess-proctoring-admin-row-control');
        }

        const field = setting ? findField(setting) : null;
        if (!field) {
            node.classList.add('is-description');
            return;
        }

        if (field.type === 'checkbox') {
            field.classList.add('quizaccess-proctoring-admin-switch');
            const state = document.createElement('span');
            state.className = 'quizaccess-proctoring-admin-switch-state';
            field.parentNode.insertBefore(state, field);
            const sync = function() {
                updateSwitch(field, state, controls);
            };
            field.addEventListener('change', sync);
            sync();
        } else {
            field.classList.add('quizaccess-proctoring-admin-field');
        }
    };

    const createTechnicalGroup = function(section, nodes, controls) {
        if (!nodes.length) {
            return null;
        }

        const button = document.createElement('button');
        const body = document.createElement('div');
        const bodyId = section.id + '-technical';
        const label = document.createElement('span');
        const hint = document.createElement('span');

        button.type = 'button';
        button.className = 'quizaccess-proctoring-admin-technical-toggle';
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', bodyId);
        button.setAttribute('data-proctoring-technical-toggle', '');
        button.setAttribute('data-user-expanded', 'false');

        label.className = 'quizaccess-proctoring-admin-technical-label';
        label.textContent = controls.getAttribute('data-technical-label') + ' (' + nodes.length + ')';
        hint.className = 'quizaccess-proctoring-admin-technical-hint';
        hint.textContent = '— ' + controls.getAttribute('data-technical-hint');
        button.appendChild(label);
        button.appendChild(hint);

        body.id = bodyId;
        body.className = 'quizaccess-proctoring-admin-technical-body';
        body.hidden = true;
        body.setAttribute('data-proctoring-technical-body', '');
        nodes.forEach(function(node) {
            node.classList.add('is-technical');
            body.appendChild(node);
        });

        button.addEventListener('click', function() {
            const open = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('data-user-expanded', open ? 'true' : 'false');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            body.hidden = !open;
        });

        section.appendChild(button);
        section.appendChild(body);
        return {button: button, body: body};
    };

    const buildSection = function(definition, controls) {
        const section = document.createElement('section');
        const header = document.createElement('header');
        const body = document.createElement('div');
        const common = [];
        const technical = [];
        let danger = null;

        section.id = 'proctoring-settings-' + definition.key;
        section.className = 'quizaccess-proctoring-admin-section';
        section.setAttribute('data-proctoring-section', definition.key);
        header.className = 'quizaccess-proctoring-admin-section-header';
        body.className = 'quizaccess-proctoring-admin-section-body';

        definition.nodes.forEach(function(node, index) {
            if (index === 0 && node.matches('h3.main')) {
                header.appendChild(node);
                return;
            }

            const setting = settingNameForNode(node);
            if (!setting && index === 1 && !node.querySelector('input, select, textarea, button, a')) {
                header.appendChild(node);
                return;
            }
            if (setting === 'deleteallimages') {
                enhanceSettingNode(node, setting, controls);
                danger = node;
                return;
            }

            enhanceSettingNode(node, setting, controls);
            if (!setting || (primarySettings[definition.key] || []).indexOf(setting) !== -1) {
                common.push(node);
            } else {
                technical.push(node);
            }
        });

        section.appendChild(header);
        common.forEach(function(node) {
            body.appendChild(node);
        });
        section.appendChild(body);
        const technicalGroup = createTechnicalGroup(section, technical, controls);

        section.setAttribute('data-proctoring-search-text', normalise(header.textContent));
        return {element: section, technical: technicalGroup, danger: danger};
    };

    const buildLayout = function(controls, fieldset) {
        const controlsItem = controls.closest('.form-item') || controls.parentNode;
        const navigation = controls.querySelector('[data-proctoring-admin-nav-list]');
        const definitions = readSectionDefinitions(controls);
        const noResults = controls.querySelector('[data-proctoring-admin-no-results]');
        const layout = document.createElement('div');
        const content = document.createElement('div');
        const sections = [];
        let danger = null;

        controlsItem.classList.add('quizaccess-proctoring-admin-shell');
        layout.className = 'quizaccess-proctoring-admin-layout';
        content.className = 'quizaccess-proctoring-admin-content';
        layout.appendChild(navigation);
        layout.appendChild(content);
        controlsItem.insertAdjacentElement('afterend', layout);

        collectSections(fieldset, definitions).forEach(function(definition) {
            const built = buildSection(definition, controls);
            sections.push(built);
            content.appendChild(built.element);
            if (built.danger) {
                danger = built.danger;
            }
        });

        if (danger) {
            danger.classList.add('quizaccess-proctoring-admin-danger');
            content.appendChild(danger);
        }
        content.appendChild(noResults);

        return {sections: sections, danger: danger, noResults: noResults, navigation: navigation};
    };

    const setupNavigation = function(controls) {
        const buttons = Array.from(controls.querySelectorAll('[data-proctoring-admin-nav]'));
        buttons.forEach(function(button) {
            button.addEventListener('click', function() {
                const key = button.getAttribute('data-proctoring-admin-nav');
                const section = document.getElementById('proctoring-settings-' + key);
                if (!section) {
                    return;
                }
                buttons.forEach(function(candidate) {
                    candidate.classList.toggle('is-active', candidate === button);
                });
                section.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });
    };

    const setupSearch = function(controls, layout) {
        const search = controls.querySelector('[data-proctoring-admin-search]');
        const status = controls.querySelector('[data-proctoring-admin-search-status]');
        if (!search) {
            return;
        }

        const applySearch = function() {
            const query = normalise(search.value);
            let visibleCount = 0;

            layout.sections.forEach(function(section) {
                const sectionMatches = query && section.element.getAttribute('data-proctoring-search-text').indexOf(query) !== -1;
                const commonRows = Array.from(
                    section.element.querySelectorAll('.quizaccess-proctoring-admin-section-body .quizaccess-proctoring-admin-row')
                );
                const technicalRows = section.technical ? Array.from(
                    section.technical.body.querySelectorAll('.quizaccess-proctoring-admin-row')
                ) : [];
                let sectionCount = 0;
                let technicalCount = 0;

                commonRows.forEach(function(row) {
                    const matches = !query || sectionMatches ||
                        row.getAttribute('data-proctoring-search-text').indexOf(query) !== -1;
                    row.hidden = !matches;
                    if (matches) {
                        sectionCount++;
                    }
                });

                technicalRows.forEach(function(row) {
                    const matches = !query || sectionMatches ||
                        row.getAttribute('data-proctoring-search-text').indexOf(query) !== -1;
                    row.hidden = !matches;
                    if (matches) {
                        sectionCount++;
                        technicalCount++;
                    }
                });

                if (section.technical) {
                    if (query) {
                        section.technical.button.hidden = technicalCount === 0;
                        section.technical.button.setAttribute('aria-expanded', technicalCount > 0 ? 'true' : 'false');
                        section.technical.body.hidden = technicalCount === 0;
                    } else {
                        const userOpen = section.technical.button.getAttribute('data-user-expanded') === 'true';
                        section.technical.button.hidden = false;
                        section.technical.button.setAttribute('aria-expanded', userOpen ? 'true' : 'false');
                        section.technical.body.hidden = !userOpen;
                    }
                }

                section.element.hidden = query !== '' && sectionCount === 0;
                if (!section.element.hidden) {
                    visibleCount += sectionCount;
                }

                const navButton = layout.navigation.querySelector(
                    '[data-proctoring-admin-nav="' + section.element.getAttribute('data-proctoring-section') + '"]'
                );
                if (navButton) {
                    navButton.hidden = section.element.hidden;
                }
            });

            if (layout.danger) {
                const dangerMatches = !query ||
                    layout.danger.getAttribute('data-proctoring-search-text').indexOf(query) !== -1;
                layout.danger.hidden = !dangerMatches;
                if (dangerMatches) {
                    visibleCount++;
                }
            }
            layout.noResults.hidden = visibleCount !== 0;
            if (status) {
                status.textContent = query
                    ? (visibleCount === 0
                        ? controls.getAttribute('data-search-no-result')
                        : visibleCount + ' ' + controls.getAttribute('data-search-result-label'))
                    : '';
            }
        };

        search.addEventListener('input', applySearch);
        applySearch();
    };

    const matchesPreset = function(key) {
        const preset = getPresetConfiguration(key).values;
        return Object.keys(preset).every(function(setting) {
            const current = getFieldValue(setting);
            return current !== null && current === preset[setting];
        });
    };

    const findActivePreset = function() {
        return Object.keys(presetDefinitions).find(function(key) {
            return matchesPreset(key);
        }) || '';
    };

    const setupSaveBar = function(controls) {
        const form = controls.closest('form') || document.getElementById('adminsettings');
        if (!form) {
            return {initialValues: {}, refresh: function() {}, setActive: function() {}};
        }

        const submit = form.querySelector('[type="submit"]');
        if (!submit) {
            return {initialValues: readFormValues(form), refresh: function() {}, setActive: function() {}};
        }

        const note = document.createElement('span');
        const initialValues = readFormValues(form);
        const initialState = serialiseFormValues(form);
        const state = {dirty: false, active: ''};
        note.className = 'quizaccess-proctoring-admin-save-note';
        note.setAttribute('aria-live', 'polite');

        // Moodle wraps the submit in Bootstrap column divs (offset-sm-3 col-sm-3) whose
        // width caps distorted the tray, and a transformed theme ancestor breaks
        // viewport-fixed positioning. Move the submit into a clean bar appended as the
        // tall form's last child, where the sticky CSS can float it while scrolling.
        const previousHolder = submit.parentNode;
        const saveBar = document.createElement('div');
        saveBar.className = 'quizaccess-proctoring-admin-savebar';
        saveBar.appendChild(note);
        saveBar.appendChild(submit);
        form.appendChild(saveBar);
        if (previousHolder && previousHolder !== form &&
                previousHolder.childElementCount === 0 && previousHolder.textContent.trim() === '') {
            const previousRow = previousHolder.parentNode;
            previousHolder.remove();
            if (previousRow && previousRow !== form &&
                    previousRow.childElementCount === 0 && previousRow.textContent.trim() === '') {
                previousRow.remove();
            }
        }

        const render = function() {
            const attribute = state.dirty
                ? (state.active ? 'data-save-changed' : 'data-save-changed-custom')
                : (state.active ? 'data-save-current' : 'data-save-current-custom');
            note.textContent = controls.getAttribute(attribute);
        };

        const refresh = function() {
            state.dirty = serialiseFormValues(form) !== initialState;
            render();
        };

        form.addEventListener('change', function(event) {
            if (event.target.name && event.target.name.indexOf(pluginPrefix) === 0) {
                refresh();
            }
        });

        render();
        return {
            initialValues: initialValues,
            refresh: refresh,
            setActive: function(active) {
                state.active = active;
                render();
            }
        };
    };

    const setupPresets = function(controls, saveBar) {
        const buttons = Array.from(controls.querySelectorAll('[data-proctoring-admin-preset]'));
        const customNote = controls.querySelector('[data-proctoring-admin-custom]');
        const presetNotice = controls.querySelector('[data-proctoring-admin-preset-notice]');
        const form = controls.closest('form') || document.getElementById('adminsettings');
        const managedSettings = new Set();
        let applyingPreset = false;

        Object.keys(presetDefinitions).forEach(function(key) {
            Object.keys(presetDefinitions[key]).forEach(function(setting) {
                managedSettings.add(setting);
            });
        });

        const hasNonPresetChanges = function() {
            const current = readFormValues(form);
            return Object.keys(current).some(function(name) {
                const setting = name.substring(pluginPrefix.length);
                return !managedSettings.has(setting) && current[name] !== saveBar.initialValues[name];
            });
        };

        const resolveActive = function() {
            return hasNonPresetChanges() ? '' : findActivePreset();
        };

        const render = function(active) {
            buttons.forEach(function(button) {
                const selected = button.getAttribute('data-proctoring-admin-preset') === active;
                button.classList.toggle('is-selected', selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            customNote.hidden = active !== '';
            saveBar.setActive(active);
        };

        buttons.forEach(function(button) {
            button.addEventListener('click', function() {
                const key = button.getAttribute('data-proctoring-admin-preset');
                const configuration = getPresetConfiguration(key);
                applyingPreset = true;
                Object.keys(configuration.values).forEach(function(setting) {
                    setFieldValue(setting, configuration.values[setting]);
                });
                applyingPreset = false;
                saveBar.refresh();
                presetNotice.textContent = configuration.skipped
                    ? controls.getAttribute('data-preset-skipped')
                    : '';
                presetNotice.hidden = !configuration.skipped;
                render(resolveActive());
            });
        });

        if (form) {
            form.addEventListener('change', function(event) {
                if (applyingPreset || !event.target.name || event.target.name.indexOf(pluginPrefix) !== 0) {
                    return;
                }
                presetNotice.hidden = true;
                presetNotice.textContent = '';
                render(resolveActive());
            });
        }

        render(resolveActive());
    };

    return {
        init: function() {
            ready(function() {
                const controls = document.getElementById(controlsId);
                const fieldset = getSettingsFieldset(controls);
                if (!controls || !fieldset) {
                    return;
                }

                document.body.classList.add('quizaccess-proctoring-settings-page');
                const layout = buildLayout(controls, fieldset);
                setupNavigation(layout.navigation);
                setupSearch(controls, layout);
                const saveBar = setupSaveBar(controls);
                setupPresets(controls, saveBar);
            });
        }
    };
});
