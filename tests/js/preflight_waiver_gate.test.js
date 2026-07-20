/* eslint-disable */
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
 * Feature: per-student-proctoring-overrides
 *
 * Preflight waived-step omission tests for the Start-attempt gate (Requirement 5).
 *
 * These are example-based/interaction tests (per the design's Testing Strategy).
 * The plugin ships no JS unit-test harness (no package.json / Jest / Mocha) and no
 * Behat suite, so — mirroring `camera_lifecycle.test.js` — this test is
 * self-contained: it loads the REAL `amd/src/startAttempt.js` source inside a Node
 * `vm` sandbox with a mocked DOM and mocked Moodle AMD dependencies, then drives the
 * module's `setup()` entry point to exercise `updatePreflightGate()` /
 * `getCurrentPreflightStep()` / `updateGuidedPreflight()`.
 *
 * Each of the five overridable Pre_Check steps (CAPTCHA/Turnstile, webcam/face, ID
 * verification, entire-screen, multi-monitor) is guarded solely by its own config
 * flag, and each overridable readiness flag is initialised to the negation of its
 * required flag, so a waived (off) flag starts "ready" and the gate never awaits it.
 *
 * It asserts:
 *   (R5.2) A disabled requirement flag omits its Pre_Check step: the omitted step
 *          never becomes the active step, the gate advances to the next ENABLED
 *          unmet step, and no error/action state or notification references the
 *          omitted step.
 *   (R5.3) A waived CAPTCHA/Turnstile step is skipped and Start becomes enabled
 *          once the remaining (non-waived) requirements are met.
 *   (R5.5) With all five overridable requirements waived, Start is reachable after
 *          the privacy/honor consent, and (all-off) an all-waived config with no
 *          consent leaves Start unlocked with no preflight step engaged.
 *
 * Run with a modern Node (>= 18):
 *   node --test tests/js/
 * or directly:
 *   node --test tests/js/preflight_waiver_gate.test.js
 *
 * Validates: Requirements 5.2, 5.3, 5.5
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const SOURCE_PATH = path.resolve(__dirname, '..', '..', 'amd', 'src', 'startAttempt.js');
const SOURCE = fs.readFileSync(SOURCE_PATH, 'utf8');

// The seven preflight step keys, in the gate's evaluation order. The five
// overridable requirements are captcha, face, identity, screen, multimonitor;
// privacy and honor are non-overridable consent gates.
const STEP_KEYS = ['privacy', 'honor', 'captcha', 'identity', 'face', 'screen', 'multimonitor'];

/**
 * Build a minimal fake DOM element with just enough surface for the module.
 * Tracks CSS classes in a Set so tests can inspect is-active / is-action state.
 */
function makeEl(id, opts) {
    opts = opts || {};
    const classes = new Set(opts.classes || []);
    const attrs = Object.assign({}, opts.attrs || {});
    const listeners = {};
    const ctx2d = {
        fillStyle: '',
        fillRect() {},
        drawImage() {},
        getImageData() {
            return {data: [], width: 0, height: 0};
        },
    };
    return {
        id: id || '',
        tagName: (opts.tag || 'div').toUpperCase(),
        style: {},
        value: opts.value !== undefined ? opts.value : '',
        textContent: '',
        checked: !!opts.checked,
        files: null,
        videoWidth: 0,
        videoHeight: 0,
        width: 0,
        height: 0,
        classList: {
            add(...c) {
                c.forEach((x) => classes.add(x));
            },
            remove(...c) {
                c.forEach((x) => classes.delete(x));
            },
            toggle(c, force) {
                const has = classes.has(c);
                const want = force === undefined ? !has : !!force;
                if (want) {
                    classes.add(c);
                } else {
                    classes.delete(c);
                }
                return want;
            },
            contains(c) {
                return classes.has(c);
            },
        },
        setAttribute(k, v) {
            attrs[k] = String(v);
        },
        getAttribute(k) {
            return k in attrs ? attrs[k] : null;
        },
        removeAttribute(k) {
            delete attrs[k];
        },
        addEventListener(t, fn) {
            (listeners[t] = listeners[t] || []).push(fn);
        },
        removeEventListener() {},
        appendChild() {},
        getContext() {
            return ctx2d;
        },
        toDataURL() {
            return 'data:image/png;base64,';
        },
        play() {
            return Promise.resolve();
        },
        _classes: classes,
        _attrs: attrs,
        _fire(t, ev) {
            (listeners[t] || []).forEach((fn) => fn(ev || {}));
        },
    };
}

/**
 * Construct a fresh mock environment: a DOM wired with the preflight step/status
 * nodes, the ready node and the consent checkboxes, plus mocked Moodle AMD deps
 * and a submit-button spy.
 */
function buildEnv() {
    const byId = {};
    const byClass = {};
    const byName = {};

    const indexClass = (el) => {
        el._classes.forEach((cls) => {
            (byClass[cls] = byClass[cls] || []).push(el);
        });
    };
    const register = (el) => {
        if (el.id) {
            byId[el.id] = el;
        }
        indexClass(el);
        return el;
    };

    const steps = {};
    const items = {};
    const status = {};
    STEP_KEYS.forEach((key) => {
        // Guided preflight step element (drives is-active / is-complete).
        steps[key] = register(makeEl('proctoring-step-' + key, {
            classes: ['proctoring-preflight-step'],
            attrs: {'data-preflight-step': key},
        }));
        // Requirement list item + status pill (drives is-pending / is-action / is-complete).
        items[key] = register(makeEl('proctoring-check-' + key, {classes: ['proctoring-preflight-item']}));
        status[key] = register(makeEl('proctoring-check-' + key + '-status'));
    });

    const readyNode = register(makeEl('proctoring-preflight-ready'));
    register(makeEl('id_multimonitorconfirmed', {tag: 'input'}));
    register(makeEl('id_entirescreenconfirmed', {tag: 'input'}));
    register(makeEl('id_idverificationconfirmed', {tag: 'input'}));

    const privacyCheckbox = makeEl('proctoringprivacy', {tag: 'input'});
    const honorCheckbox = makeEl('proctoring', {tag: 'input'});
    byName['proctoringprivacy'] = privacyCheckbox;
    byName['proctoring'] = honorCheckbox;

    const document = {
        body: makeEl('body'),
        getElementById(id) {
            return byId[id] || null;
        },
        getElementsByClassName(cls) {
            return (byClass[cls] || []).slice();
        },
        querySelector(sel) {
            const nameMatch = sel.match(/name="([^"]+)"/);
            if (nameMatch) {
                return byName[nameMatch[1]] || null;
            }
            if (sel[0] === '.') {
                const list = byClass[sel.slice(1)];
                return (list && list[0]) || null;
            }
            if (sel[0] === '#') {
                return byId[sel.slice(1)] || null;
            }
            return null;
        },
        querySelectorAll(sel) {
            if (sel[0] === '.') {
                return (byClass[sel.slice(1)] || []).slice();
            }
            return [];
        },
        createElement(tag) {
            return makeEl(null, {tag});
        },
        addEventListener() {},
        removeEventListener() {},
        visibilityState: 'visible',
    };

    const window = {
        location: {href: 'https://example.test/mod/quiz/startattempt.php'},
        screen: {},
        addEventListener() {},
        removeEventListener() {},
        setInterval() {
            return 0;
        },
        clearInterval() {},
        setTimeout() {
            return 0;
        },
        clearTimeout() {},
    };

    const navigator = {mediaDevices: {}};

    // Submit-button spy. The module treats it as a <button> (is('input') -> false),
    // so it reads/writes the label via text(); prop('disabled', ...) is the gate signal.
    const submitState = {disabled: false, label: 'Start attempt', shown: false};
    const submitJq = {
        is() {
            return false;
        },
        val(v) {
            if (v === undefined) {
                return submitState.label;
            }
            submitState.label = v;
            return submitJq;
        },
        text(v) {
            if (v === undefined) {
                return submitState.label;
            }
            submitState.label = v;
            return submitJq;
        },
        prop(k, v) {
            if (k === 'disabled') {
                submitState.disabled = v;
            }
            return submitJq;
        },
        attr() {
            return submitJq;
        },
        addClass() {
            return submitJq;
        },
        removeClass() {
            return submitJq;
        },
        toggleClass() {
            return submitJq;
        },
        show() {
            submitState.shown = true;
            return submitJq;
        },
        hide() {
            return submitJq;
        },
        css() {
            return submitJq;
        },
        html() {
            return submitJq;
        },
        append() {
            return submitJq;
        },
        click() {
            return submitJq;
        },
        on() {
            return submitJq;
        },
    };

    // Generic no-op chainable jQuery object for every other selector.
    const genericJq = {};
    ['is', 'val', 'text', 'prop', 'attr', 'addClass', 'removeClass', 'toggleClass', 'show',
        'hide', 'css', 'html', 'append', 'remove', 'click', 'on', 'trigger', 'each', 'find',
        'ready', 'appendTo'].forEach((m) => {
        genericJq[m] = () => (m === 'is' ? false : genericJq);
    });

    const $ = function(sel) {
        if (typeof sel === 'function') {
            return $;
        }
        if (sel === '#id_submitbutton') {
            return submitJq;
        }
        return genericJq;
    };

    const notifications = [];
    const exceptions = [];
    const Notification = {
        addNotification(n) {
            notifications.push(n);
        },
        exception(e) {
            exceptions.push(e);
        },
    };
    const Ajax = {
        call() {
            const result = {
                done() {
                    return result;
                },
                fail() {
                    return result;
                },
            };
            return [result];
        },
    };
    const Str = {
        get_strings(keys) {
            return Promise.resolve((keys || []).map((k) => (k && k.key) || ''));
        },
        get_string() {
            return Promise.resolve('');
        },
    };
    const ScreenMonitorClient = {
        create() {
            return {start() {}, open() {}};
        },
    };

    return {
        deps: {
            'jquery': $,
            'core/ajax': Ajax,
            'core/notification': Notification,
            'core/str': Str,
            'quizaccess_proctoring/screenMonitorClient': ScreenMonitorClient,
        },
        window,
        document,
        navigator,
        // Test handles.
        dom: {steps, items, status, readyNode, privacyCheckbox, honorCheckbox},
        submitState,
        notifications,
        exceptions,
    };
}

/**
 * Load the real startAttempt.js source in an isolated sandbox and return the AMD
 * module export (fresh module-scoped state each call).
 */
function loadModule(env) {
    let moduleExport = null;
    const sandbox = {
        define(deps, factory) {
            moduleExport = factory.apply(null, deps.map((d) => env.deps[d]));
        },
        navigator: env.navigator,
        window: env.window,
        document: env.document,
        console,
        Promise,
        Math,
        setTimeout() {
            return 0;
        },
        setInterval() {
            return 0;
        },
        clearInterval() {},
        clearTimeout() {},
        queueMicrotask,
    };
    vm.createContext(sandbox);
    vm.runInContext(SOURCE, sandbox, {filename: 'startAttempt.js'});
    return moduleExport;
}

/** Flush pending microtasks / promise chains. */
async function flush(times = 5) {
    for (let i = 0; i < times; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

/** Base props with every requirement OFF; override per test. */
function props(overrides) {
    return Object.assign({
        courseid: '1',
        cmid: '2',
        quizid: '3',
        attemptid: '0',
        imagewidth: '480',
        faceidcheck: '0',
        requireentirescreen: '0',
        privacyrequired: '0',
        honorrequired: '0',
        captcharequired: '0',
        idverificationrequired: '0',
        idverificationrequireback: '0',
        multimonitormode: 'off',
    }, overrides || {});
}

test('R5.2 - a disabled requirement flag omits its Pre_Check step and the gate advances to the next enabled step with no error referencing the omitted step', async () => {
    const env = buildEnv();
    const mod = loadModule(env);

    // CAPTCHA + identity + multi-monitor waived (off); webcam/face + entire-screen enabled.
    const result = await mod.setup(props({faceidcheck: '1', requireentirescreen: '1'}), null);
    await flush();

    assert.strictEqual(result, true, 'setup() must complete without throwing');

    // Waived steps are omitted: they never become the active step.
    assert.ok(!env.dom.steps.captcha._classes.has('is-active'), 'waived CAPTCHA step must not be active');
    assert.ok(!env.dom.steps.identity._classes.has('is-active'), 'waived identity step must not be active');
    assert.ok(!env.dom.steps.multimonitor._classes.has('is-active'), 'waived multi-monitor step must not be active');

    // The gate advances to the first ENABLED unmet step (face), not the omitted ones.
    assert.ok(env.dom.steps.face._classes.has('is-active'),
        'gate must advance to the first enabled unmet step (face)');

    // Start remains locked because an enabled requirement is still unmet.
    assert.strictEqual(env.submitState.disabled, true, 'Start stays locked while an enabled step is unmet');

    // No error/action state references the omitted steps, and no notification was raised.
    assert.ok(!env.dom.status.captcha._classes.has('is-action'), 'no error state on the omitted CAPTCHA step');
    assert.ok(!env.dom.status.identity._classes.has('is-action'), 'no error state on the omitted identity step');
    assert.ok(!env.dom.status.multimonitor._classes.has('is-action'),
        'no error state on the omitted multi-monitor step');
    assert.strictEqual(env.notifications.length, 0, 'no notifications raised for omitted steps');
    assert.strictEqual(env.exceptions.length, 0, 'no exceptions raised for omitted steps');
});

test('R5.3 - a waived CAPTCHA/Turnstile step is skipped and Start becomes enabled once the remaining requirement is met', async () => {
    const env = buildEnv();
    const mod = loadModule(env);

    // CAPTCHA waived; only the privacy consent remains.
    await mod.setup(props({privacyrequired: '1', captcharequired: '0'}), null);
    await flush();

    // Initially locked on the privacy consent; the waived CAPTCHA step is skipped.
    assert.strictEqual(env.submitState.disabled, true, 'Start locked until the privacy consent is given');
    assert.ok(!env.dom.steps.captcha._classes.has('is-active'), 'waived CAPTCHA step must not be active');
    assert.ok(env.dom.steps.privacy._classes.has('is-active'), 'gate advances to the enabled privacy step');

    // Satisfy the only remaining (non-waived) requirement.
    env.dom.privacyCheckbox.checked = true;
    env.dom.privacyCheckbox._fire('change');
    await flush();

    // Start becomes enabled; the waived CAPTCHA never gated it and never errored.
    assert.strictEqual(env.submitState.disabled, false, 'Start enables with CAPTCHA waived and privacy met');
    assert.strictEqual(env.dom.readyNode.style.display, 'block', 'ready panel is shown once the gate opens');
    assert.ok(!env.dom.status.captcha._classes.has('is-action'), 'waived CAPTCHA must not raise an error');
    assert.strictEqual(env.notifications.length, 0, 'no notifications raised for the waived CAPTCHA');
});

test('R5.5 - with all five overridable requirements waived, Start is reachable after privacy/honor consent', async () => {
    const env = buildEnv();
    const mod = loadModule(env);

    // All five overridable requirements waived: captcha, face, identity, screen, multimonitor.
    await mod.setup(props({privacyrequired: '1', honorrequired: '1'}), null);
    await flush();

    assert.strictEqual(env.submitState.disabled, true, 'Start locked until privacy/honor consent is given');

    // Give both consents.
    env.dom.privacyCheckbox.checked = true;
    env.dom.privacyCheckbox._fire('change');
    env.dom.honorCheckbox.checked = true;
    env.dom.honorCheckbox._fire('change');
    await flush();

    assert.strictEqual(env.submitState.disabled, false,
        'Start reachable after privacy/honor with all five requirements waived');
    assert.strictEqual(env.dom.readyNode.style.display, 'block', 'ready panel shown once the gate opens');

    // None of the five waived steps was ever engaged or errored.
    ['captcha', 'identity', 'face', 'screen', 'multimonitor'].forEach((key) => {
        assert.ok(!env.dom.steps[key]._classes.has('is-active'), `waived ${key} step must never be active`);
        assert.ok(!env.dom.status[key]._classes.has('is-action'), `waived ${key} step must never error`);
    });
    assert.strictEqual(env.notifications.length, 0, 'no notifications raised for waived steps');
});

test('R5.5 - an all-waived config with no consent required leaves Start unlocked and no preflight step engaged', async () => {
    const env = buildEnv();
    const mod = loadModule(env);

    // Everything off: no proctoring requirement engages the gate at all.
    const result = await mod.setup(props(), null);
    await flush();

    assert.strictEqual(result, true, 'setup() completes cleanly with no requirements');
    assert.strictEqual(env.submitState.disabled, false, 'Start is never locked when nothing is required');

    STEP_KEYS.forEach((key) => {
        assert.ok(!env.dom.steps[key]._classes.has('is-active'), `no step should be active (${key})`);
        assert.ok(!env.dom.status[key]._classes.has('is-action'), `no step should error (${key})`);
    });
    assert.strictEqual(env.notifications.length, 0, 'no notifications raised');
    assert.strictEqual(env.exceptions.length, 0, 'no exceptions raised');
});
