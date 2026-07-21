// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Transforms the Risk factor scoring admin settings page into a grouped card UI.
 *
 * The module never replaces the underlying Moodle settings fields: it relocates the real
 * checkbox/text inputs into a custom layout, so the standard settings form still owns
 * validation, defaults, and saving. Everything here is presentation plus a few live-computed
 * read-outs (per-factor share bar, the "if every factor fired" total, the level-band gradient).
 *
 * @module     quizaccess_proctoring/riskFactorSettings
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    const PREFIX = 's_quizaccess_proctoring_';

    // Factor groups mirror the score model's evidence classes. Each key matches
    // risk_calculator::FACTOR_DEFAULTS and the riskfactor_<key>_{enabled,points,cap} config.
    const GROUPS = [
        {
            title: 'Webcam & identity',
            desc: 'Evidence from the camera about who is taking the exam.',
            factors: ['facemismatch', 'multiplefaces', 'noface', 'phonedetected', 'webcammissing'],
        },
        {
            title: 'Screen & monitors',
            desc: 'Evidence about what was on the student’s display.',
            factors: ['screenshare', 'multimonitor', 'tabactivity'],
        },
        {
            title: 'AI tools & copying',
            desc: 'The signals most associated with AI-assisted cheating.',
            factors: ['aitool', 'aitoolscreenshot', 'clipboard'],
        },
        {
            title: 'Keyboard shortcuts',
            desc: 'Shortcuts that open hidden tools or developer consoles.',
            factors: ['f12', 'shortcut'],
        },
        {
            title: 'Audio & pacing',
            desc: 'Sounds in the room and how fast the exam was finished.',
            factors: ['audio', 'speed'],
        },
    ];

    const BOUNDS = [
        {key: 'risklevelmoderate', name: 'Moderate', color: '#b07407'},
        {key: 'risklevelhigh', name: 'High', color: '#c9762a'},
        {key: 'risklevelcritical', name: 'Critical', color: '#b3423a'},
    ];
    const LOWCOLOR = '#3e7d48';

    const ready = function(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    };

    const el = function(tag, className, attrs) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (attrs) {
            Object.keys(attrs).forEach(function(name) {
                node.setAttribute(name, attrs[name]);
            });
        }
        return node;
    };

    const field = function(setting) {
        const fields = Array.from(document.querySelectorAll('[name="' + PREFIX + setting + '"]'));
        return fields.find(function(candidate) {
            return candidate.type !== 'hidden';
        }) || fields[0] || null;
    };

    // The .form-item row Moodle rendered for a setting; used to read the label/description and to
    // hide the original row once its input has been relocated.
    const rowFor = function(setting) {
        const input = field(setting);
        if (!input) {
            return null;
        }
        const item = input.closest('.form-item');
        return item || null;
    };

    const labelFor = function(setting, fallback) {
        const row = rowFor(setting);
        if (row) {
            const label = row.querySelector('.form-label .form-label-addon, .form-label label, label');
            if (label && label.textContent.trim()) {
                return label.textContent.trim();
            }
        }
        return fallback || setting;
    };

    const descFor = function(setting) {
        const row = rowFor(setting);
        if (!row) {
            return '';
        }
        const desc = row.querySelector('.form-description, .form-defaultinfo');
        return desc ? desc.textContent.trim() : '';
    };

    const intVal = function(input, fallback) {
        const value = parseInt(input && input.value, 10);
        return isNaN(value) ? (fallback || 0) : value;
    };

    const clampInt = function(value, min, max) {
        value = parseInt(value, 10);
        if (isNaN(value)) {
            value = min;
        }
        return Math.max(min, Math.min(max, value));
    };

    /**
     * Build the "cap risk scores at 100" card. Returns a refresh() hook so the total read-out can
     * be recomputed when factor caps or the cap toggle change.
     *
     * @param {function} totalFn Returns the current summed max across enabled factors.
     * @returns {object} {element, refresh}
     */
    const buildCapCard = function(totalFn) {
        const capInput = field('riskscorecapenabled');
        const card = el('div', 'quizaccess-proctoring-rfs-card quizaccess-proctoring-rfs-capcard');
        const head = el('div', 'quizaccess-proctoring-rfs-capcopy');
        const title = el('div', 'quizaccess-proctoring-rfs-cardtitle');
        title.textContent = 'Cap risk scores at 100';
        const desc = el('div', 'quizaccess-proctoring-rfs-carddesc');
        desc.textContent = 'Recommended. When several factors add up past 100, the score is shown as 100. '
            + 'Turning this off lets scores run higher (e.g. 135/100) — useful only if your review team '
            + 'wants to rank severe cases against each other.';
        head.appendChild(title);
        head.appendChild(desc);

        const toggle = buildToggle(capInput);
        const footer = el('div', 'quizaccess-proctoring-rfs-captotal');

        card.appendChild(head);
        card.appendChild(toggle.element);
        card.appendChild(footer);

        const refresh = function() {
            const total = totalFn();
            const capped = capInput && capInput.checked;
            const note = capped && total > 100
                ? ' — shown as 100 because the cap is on.'
                : '.';
            footer.innerHTML = '';
            const strong = el('strong');
            strong.textContent = 'If every factor fired at its maximum:';
            footer.appendChild(strong);
            footer.appendChild(document.createTextNode(' ' + total + ' points' + note));
        };
        toggle.onChange(refresh);
        return {element: card, refresh: refresh};
    };

    /**
     * Wrap a real checkbox in a switch control. The checkbox itself is moved inside the switch and
     * hidden visually, so it remains the single source of truth for the form.
     *
     * @param {HTMLInputElement} checkbox The real settings checkbox.
     * @returns {object} {element, onChange, isOn}
     */
    const buildToggle = function(checkbox) {
        const wrap = el('label', 'quizaccess-proctoring-rfs-toggle');
        const track = el('span', 'quizaccess-proctoring-rfs-toggle-track');
        const knob = el('span', 'quizaccess-proctoring-rfs-toggle-knob');
        const state = el('span', 'quizaccess-proctoring-rfs-toggle-state');
        track.appendChild(knob);
        const listeners = [];

        const sync = function() {
            const on = checkbox && checkbox.checked;
            wrap.classList.toggle('is-on', !!on);
            state.textContent = on ? 'On' : 'Off';
        };

        if (checkbox) {
            checkbox.parentNode.insertBefore(wrap, checkbox);
            checkbox.classList.add('quizaccess-proctoring-rfs-visually-hidden');
            wrap.appendChild(state);
            wrap.appendChild(track);
            wrap.appendChild(checkbox);
            checkbox.addEventListener('change', function() {
                sync();
                listeners.forEach(function(fn) {
                    fn();
                });
            });
        }
        sync();

        return {
            element: wrap,
            onChange: function(fn) {
                listeners.push(fn);
            },
            isOn: function() {
                return checkbox && checkbox.checked;
            },
        };
    };

    /**
     * Build a number stepper bound to a real text input, relocating the input into a compact cell.
     *
     * @param {HTMLInputElement} input The real settings text input.
     * @param {string} caption Small caption shown under the field (e.g. "default 35").
     * @param {function} onInput Callback fired on value change.
     * @returns {HTMLElement} The cell element.
     */
    const buildNumberCell = function(input, caption, onInput) {
        const cell = el('div', 'quizaccess-proctoring-rfs-numcell');
        if (input) {
            input.classList.add('quizaccess-proctoring-rfs-num');
            input.setAttribute('inputmode', 'numeric');
            cell.appendChild(input);
            input.addEventListener('input', onInput);
            input.addEventListener('change', onInput);
        }
        if (caption) {
            const cap = el('div', 'quizaccess-proctoring-rfs-numcaption');
            cap.textContent = caption;
            cell.appendChild(cap);
        }
        return cell;
    };

    const shareBarColor = function(cap) {
        if (cap >= 30) {
            return '#b3423a';
        }
        if (cap >= 20) {
            return '#c9762a';
        }
        return '#b07407';
    };

    /**
     * Build one factor row: toggle + label/help + points cell + cap cell + share-of-score bar.
     *
     * @param {string} key Factor key.
     * @param {function} onAnyChange Called whenever the toggle or the numbers change.
     * @returns {object} {element, enabledCap}
     */
    const buildFactorRow = function(key, onAnyChange) {
        const enabled = field('riskfactor_' + key + '_enabled');
        const points = field('riskfactor_' + key + '_points');
        const cap = field('riskfactor_' + key + '_cap');
        if (!enabled || !points || !cap) {
            return null;
        }

        const defPoints = points.getAttribute('data-default') || defaultFromRow('riskfactor_' + key + '_points');
        const defCap = cap.getAttribute('data-default') || defaultFromRow('riskfactor_' + key + '_cap');

        const row = el('div', 'quizaccess-proctoring-rfs-factor');
        const toggle = buildToggle(enabled);

        const copy = el('div', 'quizaccess-proctoring-rfs-factorcopy');
        const label = el('div', 'quizaccess-proctoring-rfs-factorlabel');
        label.textContent = FACTOR_LABELS[key] || labelFor('riskfactor_' + key + '_enabled', key);
        const help = el('div', 'quizaccess-proctoring-rfs-factorhelp');
        help.textContent = FACTOR_HELP[key] || '';
        copy.appendChild(label);
        if (help.textContent) {
            copy.appendChild(help);
        }

        const controls = el('div', 'quizaccess-proctoring-rfs-controls');
        const pointsCell = buildNumberCell(points, defPoints ? 'default ' + defPoints : '', function() {
            onAnyChange();
        });
        const capCell = buildNumberCell(cap, defCap ? 'default ' + defCap : '', function() {
            updateBar();
            onAnyChange();
        });

        const barWrap = el('div', 'quizaccess-proctoring-rfs-share');
        const barTrack = el('div', 'quizaccess-proctoring-rfs-sharetrack');
        const barFill = el('div', 'quizaccess-proctoring-rfs-sharefill');
        barTrack.appendChild(barFill);
        const barLabel = el('div', 'quizaccess-proctoring-rfs-sharelabel');
        barWrap.appendChild(barTrack);
        barWrap.appendChild(barLabel);

        const offNote = el('div', 'quizaccess-proctoring-rfs-offnote');
        offNote.textContent = 'Off — adds no points and is hidden from reports.';

        controls.appendChild(pointsCell);
        controls.appendChild(capCell);
        controls.appendChild(barWrap);
        controls.appendChild(offNote);

        row.appendChild(toggle.element);
        row.appendChild(copy);
        row.appendChild(controls);

        const updateBar = function() {
            const capValue = clampInt(cap.value, 0, 100);
            barFill.style.width = Math.min(100, capValue) + '%';
            barFill.style.background = shareBarColor(capValue);
            barLabel.textContent = 'up to ' + capValue + ' of 100';
        };

        const sync = function() {
            const on = toggle.isOn();
            row.classList.toggle('is-off', !on);
        };
        toggle.onChange(function() {
            sync();
            onAnyChange();
        });
        sync();
        updateBar();

        return {
            element: row,
            enabledCap: function() {
                return toggle.isOn() ? clampInt(cap.value, 0, 100) : 0;
            },
        };
    };

    // Read a field's shipped default from the row's default-info text ("Default: 35").
    const defaultFromRow = function(setting) {
        const row = rowFor(setting);
        if (!row) {
            return '';
        }
        const info = row.querySelector('.form-defaultinfo, .defaultsnext, .form-default');
        if (!info) {
            return '';
        }
        const match = info.textContent.match(/(\d+)/);
        return match ? match[1] : '';
    };

    const FACTOR_LABELS = {
        facemismatch: 'Face doesn’t match the student',
        multiplefaces: 'More than one face visible',
        noface: 'No face visible',
        phonedetected: 'Phone visible in webcam',
        webcammissing: 'Webcam never recorded',
        screenshare: 'Screen sharing stopped or wrong screen shared',
        multimonitor: 'Extra monitor detected',
        tabactivity: 'Switched away from the exam',
        aitool: 'Possible AI tool in use',
        aitoolscreenshot: 'AI panel captured on screen',
        clipboard: 'Copying, pasting or right-clicking',
        f12: 'Developer tools opened (F12)',
        shortcut: 'Other monitored shortcuts',
        audio: 'Voices or sounds detected',
        speed: 'Finished unusually fast',
    };

    const FACTOR_HELP = {
        facemismatch: 'The person on camera doesn’t look like the student’s profile photo. '
            + 'The strongest sign of a stand-in test-taker.',
        multiplefaces: 'A second person appeared on camera — possibly helping with answers.',
        noface: 'Nobody was detected in frame. Often just poor lighting or leaning away, so it scores low per event.',
        phonedetected: 'A phone was spotted in the student’s hands or on the desk.',
        webcammissing: 'The whole attempt finished without a single webcam photo — the camera was blocked '
            + 'or disconnected.',
        screenshare: 'The student stopped sharing their screen mid-exam, or shared a different screen than the exam.',
        multimonitor: 'A second screen was connected — it can show notes or another person’s help.',
        tabactivity: 'The exam window lost focus — often looking something up. Common and usually brief, '
            + 'so it scores low per event.',
        aitool: 'Activity matched a known AI chat tool (like ChatGPT) during the exam.',
        aitoolscreenshot: 'A desktop capture actually shows an AI tool open — stronger evidence than the '
            + 'signal above.',
        clipboard: 'The student copied question text or pasted something into an answer.',
        f12: 'Developer tools can reveal answers hidden in the page or disable monitoring.',
        shortcut: 'Other watched key combinations, like screenshots or window switching.',
        audio: 'The microphone picked up talking — possibly someone dictating answers.',
        speed: 'The attempt was completed much faster than classmates’ — a sign of pre-known answers. '
            + 'Only scored when speed review is turned on in the main settings.',
    };

    /**
     * Build the risk-level boundary editor: a gradient bar plus four band cards, three editable.
     * The three boundary inputs are relocated into the cards and kept in non-inverting order.
     *
     * @returns {object} {element, refresh}
     */
    const buildBoundaryCard = function() {
        const card = el('div', 'quizaccess-proctoring-rfs-card');
        const title = el('div', 'quizaccess-proctoring-rfs-cardtitle');
        title.textContent = 'Risk level labels';
        const desc = el('div', 'quizaccess-proctoring-rfs-carddesc');
        desc.textContent = 'Where the Low / Moderate / High / Critical labels begin on reports. '
            + 'Labels are for readability only — they never change grades on their own.';
        const gradient = el('div', 'quizaccess-proctoring-rfs-gradient');
        const bands = el('div', 'quizaccess-proctoring-rfs-bands');
        const foot = el('div', 'quizaccess-proctoring-rfs-carddesc quizaccess-proctoring-rfs-boundnote');
        foot.textContent = 'Boundaries keep their order automatically — High can’t start below Moderate, '
            + 'and Critical can’t start below High.';

        card.appendChild(title);
        card.appendChild(desc);
        card.appendChild(gradient);
        card.appendChild(bands);
        card.appendChild(foot);

        const inputs = {};
        BOUNDS.forEach(function(bound) {
            inputs[bound.key] = field(bound.key);
        });

        const value = function(key, fallback) {
            return clampInt(inputs[key] ? inputs[key].value : fallback, 1, 100);
        };

        const refresh = function() {
            const mod = value('risklevelmoderate', 20);
            let high = value('risklevelhigh', 50);
            let crit = value('risklevelcritical', 80);
            high = Math.max(high, mod);
            crit = Math.max(crit, high);

            // Persist the non-inverting order back into the real inputs so what is saved matches
            // what is shown (the backend also clamps on read, but this keeps stored data clean).
            if (inputs.risklevelhigh && inputs.risklevelhigh.value !== String(high)) {
                inputs.risklevelhigh.value = high;
            }
            if (inputs.risklevelcritical && inputs.risklevelcritical.value !== String(crit)) {
                inputs.risklevelcritical.value = crit;
            }

            gradient.style.background = 'linear-gradient(to right, ' + LOWCOLOR + ' 0%, ' + LOWCOLOR + ' ' + mod + '%, '
                + '#b07407 ' + mod + '%, #b07407 ' + high + '%, '
                + '#c9762a ' + high + '%, #c9762a ' + crit + '%, '
                + '#b3423a ' + crit + '%, #b3423a 100%)';

            const ranges = [
                {name: 'Low', color: LOWCOLOR, range: '0 – ' + (mod - 1), editable: false},
                {name: 'Moderate', color: '#b07407', range: mod + ' – ' + (high - 1), key: 'risklevelmoderate'},
                {name: 'High', color: '#c9762a', range: high + ' – ' + (crit - 1), key: 'risklevelhigh'},
                {name: 'Critical', color: '#b3423a', range: crit + ' and up', key: 'risklevelcritical'},
            ];
            bands.querySelectorAll('.quizaccess-proctoring-rfs-bandrange').forEach(function(node, index) {
                node.textContent = ranges[index].range;
            });
        };

        [
            {name: 'Low', color: LOWCOLOR, editable: false},
            {name: 'Moderate', color: '#b07407', key: 'risklevelmoderate'},
            {name: 'High', color: '#c9762a', key: 'risklevelhigh'},
            {name: 'Critical', color: '#b3423a', key: 'risklevelcritical'},
        ].forEach(function(band) {
            const bandCard = el('div', 'quizaccess-proctoring-rfs-band');
            const dot = el('span', 'quizaccess-proctoring-rfs-banddot');
            dot.style.background = band.color;
            const copy = el('div', 'quizaccess-proctoring-rfs-bandcopy');
            const name = el('div', 'quizaccess-proctoring-rfs-bandname');
            name.textContent = band.name;
            const range = el('div', 'quizaccess-proctoring-rfs-bandrange');
            copy.appendChild(name);
            copy.appendChild(range);
            bandCard.appendChild(dot);
            bandCard.appendChild(copy);
            if (band.editable !== false && inputs[band.key]) {
                const cell = buildNumberCell(inputs[band.key], 'starts at', refresh);
                bandCard.appendChild(cell);
            }
            bands.appendChild(bandCard);
        });

        return {element: card, refresh: refresh};
    };

    const buildSaveBar = function(root) {
        const form = root.closest('form') || document.getElementById('adminsettings');
        if (!form) {
            return;
        }
        const buttons = form.querySelector('.form-buttons');
        const submit = form.querySelector('[type="submit"]');
        if (!buttons || !submit) {
            return;
        }
        buttons.classList.add('quizaccess-proctoring-rfs-savebar');
        const note = el('span', 'quizaccess-proctoring-rfs-savenote');
        note.textContent = 'Changes apply to new attempts; past reports keep the score they had.';
        buttons.insertBefore(note, buttons.firstChild);
    };

    return {
        init: function() {
            ready(function() {
                const admin = document.getElementById('adminsettings');
                const fieldset = admin ? admin.querySelector('fieldset') : null;
                const capField = field('riskscorecapenabled');
                if (!fieldset || !capField) {
                    return;
                }

                document.body.classList.add('quizaccess-proctoring-rfs-page');

                const layout = el('div', 'quizaccess-proctoring-rfs-layout');
                const factorRows = [];

                const header = el('div', 'quizaccess-proctoring-rfs-header');
                const intro = el('div', 'quizaccess-proctoring-rfs-intro');
                intro.textContent = 'Decide how much each kind of evidence counts toward a student’s risk score. '
                    + 'Every time something is detected, its factor adds points — up to that factor’s own maximum. '
                    + 'All factors add together to make the attempt’s score out of 100.';
                header.appendChild(intro);
                layout.appendChild(header);

                const totalMax = function() {
                    return factorRows.reduce(function(sum, row) {
                        return sum + row.enabledCap();
                    }, 0);
                };

                const capCard = buildCapCard(totalMax);

                // Column legend.
                const legend = el('div', 'quizaccess-proctoring-rfs-legend');
                ['What was detected', 'Points each time', 'Most it can add', 'Share of score'].forEach(
                    function(text, index) {
                        const col = el('div', 'quizaccess-proctoring-rfs-legendcol'
                            + (index === 0 ? ' is-wide' : ''));
                        col.textContent = text;
                        legend.appendChild(col);
                    }
                );

                const onAnyChange = function() {
                    capCard.refresh();
                };

                const groupCards = GROUPS.map(function(group) {
                    const card = el('div', 'quizaccess-proctoring-rfs-card');
                    const head = el('div', 'quizaccess-proctoring-rfs-grouphead');
                    const title = el('div', 'quizaccess-proctoring-rfs-cardtitle');
                    title.textContent = group.title;
                    const desc = el('div', 'quizaccess-proctoring-rfs-carddesc');
                    desc.textContent = group.desc;
                    head.appendChild(title);
                    head.appendChild(desc);
                    card.appendChild(head);

                    group.factors.forEach(function(key) {
                        const built = buildFactorRow(key, onAnyChange);
                        if (built) {
                            factorRows.push(built);
                            card.appendChild(built.element);
                        }
                    });
                    return card;
                });

                const boundaryCard = buildBoundaryCard();

                layout.appendChild(capCard.element);
                layout.appendChild(legend);
                groupCards.forEach(function(card) {
                    layout.appendChild(card);
                });
                layout.appendChild(boundaryCard.element);

                // Relocate the false-positive analytics block (rendered as a description setting).
                const fpRow = rowFor('fpanalytics') ||
                    (document.getElementById('admin-fpanalytics'));
                if (fpRow) {
                    const fpCard = el('div', 'quizaccess-proctoring-rfs-card quizaccess-proctoring-rfs-fpcard');
                    fpCard.appendChild(fpRow);
                    layout.appendChild(fpCard);
                }

                fieldset.appendChild(layout);

                // Hide every original settings row and section heading now that their inputs have
                // been relocated into the card layout. This is done in JS (not a CSS child selector)
                // because Moodle's real settings markup nests the rows more deeply than a
                // "#adminsettings > fieldset > .form-item" rule can reach, which previously left the
                // raw list visible above the redesigned cards.
                Array.from(admin.querySelectorAll('.form-item, h3, .form-description')).forEach(
                    function(node) {
                        if (!layout.contains(node)) {
                            node.classList.add('quizaccess-proctoring-rfs-original-hidden');
                        }
                    }
                );

                capCard.refresh();
                boundaryCard.refresh();
                buildSaveBar(admin);
            });
        },
    };
});
