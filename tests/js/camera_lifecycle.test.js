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
 * Camera-lifecycle interaction tests for the Pre-Check modal (Requirement 6).
 *
 * These are example-based/interaction tests (per the design's Testing Strategy,
 * "Requirement 6.1/6.2/6.3 (EXAMPLE, JS)"). The plugin ships no JS unit-test
 * harness (no package.json / Jest / Mocha) and no Behat suite, so this test is
 * self-contained: it loads the REAL `amd/src/proctoring.js` source inside a Node
 * `vm` sandbox with a mocked DOM, mocked Moodle AMD dependencies, and a mocked
 * `navigator.mediaDevices.getUserMedia`, then drives the module's `init()` entry
 * point to exercise `acquirePrecheckCamera`/`teardownPrecheckCamera`/
 * `bindPrecheckModalCamera`.
 *
 * It asserts:
 *   (a) getUserMedia is NOT called before the Pre-Check modal is opened (Req 6.1).
 *   (b) getUserMedia IS called when the modal becomes visible, and the resulting
 *       MediaStream is bound to the modal <video> (Req 6.2).
 *   (c) On cancel / hide / pagehide, every MediaStreamTrack is stopped and
 *       video.srcObject is nulled (Req 6.3).
 *
 * Run with a modern Node (>= 18), which bundles the test runner and assert:
 *   node --test proctoring/tests/js/
 * or directly:
 *   node --test proctoring/tests/js/camera_lifecycle.test.js
 *
 * Validates: Requirements 6.1, 6.2, 6.3
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const SOURCE_PATH = path.resolve(__dirname, '..', '..', 'amd', 'src', 'proctoring.js');
const SOURCE = fs.readFileSync(SOURCE_PATH, 'utf8');

/**
 * Build a fake DOM element with just enough surface for the module under test.
 *
 * `_open` toggles the perceived visibility the module uses to decide whether the
 * Pre-Check modal is open (via offsetParent / getClientRects).
 */
function makeElement(id) {
    const listeners = {};
    const ctx2d = {
        fillStyle: '',
        fillRect() {},
        drawImage() {},
    };
    return {
        id,
        _open: false,
        srcObject: null,
        _played: false,
        videoWidth: 640,
        videoHeight: 480,
        width: 0,
        height: 0,
        style: {},
        classList: {contains() { return false; }},
        get offsetParent() {
            return this._open ? {} : null;
        },
        getClientRects() {
            return this._open ? [{}] : [];
        },
        getBoundingClientRect() {
            return {width: 0, height: 0, top: 0, left: 0};
        },
        play() {
            this._played = true;
            return Promise.resolve();
        },
        setAttribute() {},
        getAttribute() {
            return null;
        },
        getContext() {
            return ctx2d;
        },
        toDataURL() {
            return 'data:image/png;base64,';
        },
        addEventListener(type, fn) {
            (listeners[type] = listeners[type] || []).push(fn);
        },
        removeEventListener() {},
        closest() {
            return null;
        },
        _fire(type, ev) {
            (listeners[type] || []).forEach((fn) => fn(ev || {}));
        },
    };
}

/**
 * Construct a fresh mock environment (DOM + Moodle AMD deps + getUserMedia spy).
 */
function createEnvironment() {
    let getUserMediaCalls = 0;
    let lastStream = null;

    const makeTrack = () => ({stopped: false, kind: 'video', stop() {
        this.stopped = true;
    }});
    const makeStream = () => {
        const tracks = [makeTrack(), makeTrack()];
        return {_tracks: tracks, getTracks() {
            return tracks;
        }};
    };

    const mediaDevices = {
        getUserMedia() {
            getUserMediaCalls++;
            lastStream = makeStream();
            return Promise.resolve(lastStream);
        },
    };
    const navigator = {mediaDevices};

    const video = makeElement('video');
    const canvas = makeElement('canvas');
    const photo = makeElement('photo');
    const elements = {video, canvas, photo};

    const docListeners = {};
    const document = {
        body: {nodeType: 1},
        getElementById(id) {
            return elements[id] || null;
        },
        getElementsByClassName() {
            return [];
        },
        createElement() {
            return makeElement('created');
        },
        addEventListener(type, fn) {
            (docListeners[type] = docListeners[type] || []).push(fn);
        },
        removeEventListener() {},
        _fire(type, ev) {
            (docListeners[type] || []).forEach((fn) => fn(ev || {}));
        },
    };

    const winListeners = {};
    const pollCallbacks = [];
    const window = {
        addEventListener(type, fn) {
            (winListeners[type] = winListeners[type] || []).push(fn);
        },
        removeEventListener() {},
        setInterval(fn) {
            pollCallbacks.push(fn);
            return pollCallbacks.length;
        },
        clearInterval() {},
        setTimeout() {
            return 0;
        },
        _fire(type, ev) {
            (winListeners[type] || []).forEach((fn) => fn(ev || {}));
        },
    };

    let mutationCallback = null;
    class MutationObserver {
        constructor(cb) {
            mutationCallback = cb;
        }
        observe() {}
        disconnect() {}
    }

    // Minimal chainable jQuery stub.
    const jqObj = {};
    ['prop', 'on', 'html', 'trigger', 'ready', 'append', 'remove', 'addClass', 'removeClass',
        'attr', 'css', 'find', 'each', 'val', 'text', 'hide', 'show'].forEach((m) => {
        jqObj[m] = () => jqObj;
    });
    const $ = function() {
        // $(fn) document-ready registration is a no-op here; it is irrelevant to the
        // camera lifecycle and would only pull in unrelated submit-button wiring.
        return jqObj;
    };

    const Ajax = {
        call() {
            return [{done() {
                return {fail() {}};
            }}];
        },
    };
    const Notification = {
        _notifications: [],
        addNotification(n) {
            this._notifications.push(n);
        },
        exception() {},
    };
    const Str = {
        get_strings(keys) {
            return Promise.resolve((keys || []).map(() => ''));
        },
        get_string() {
            return Promise.resolve('');
        },
    };
    const ScreenMonitorClient = new Proxy({}, {
        get() {
            return () => {};
        },
    });

    return {
        navigator,
        window,
        document,
        MutationObserver,
        deps: {
            'jquery': $,
            'core/ajax': Ajax,
            'core/notification': Notification,
            'core/str': Str,
            'quizaccess_proctoring/screenMonitorClient': ScreenMonitorClient,
        },
        // Test handles.
        video,
        canvas,
        photo,
        Notification,
        getUserMediaCalls() {
            return getUserMediaCalls;
        },
        lastStream() {
            return lastStream;
        },
        openModal() {
            video._open = true;
        },
        closeModal() {
            video._open = false;
        },
        // Fire the module's visibility poll (its window.setInterval callback) and its
        // MutationObserver callback, mirroring the browser reacting to modal show/hide.
        syncModal() {
            pollCallbacks.forEach((fn) => fn());
            if (mutationCallback) {
                mutationCallback([]);
            }
        },
    };
}

/**
 * Load the real proctoring.js source in an isolated sandbox and return the AMD
 * module export. Each call yields a fresh module (fresh module-scoped
 * precheckStream / isCameraAllowed state).
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
        MutationObserver: env.MutationObserver,
        console,
        Promise,
        // Provide inert global timers so any stray reference cannot keep the
        // process alive; the modal poll uses window.setInterval, captured above.
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
    vm.runInContext(SOURCE, sandbox, {filename: 'proctoring.js'});
    return moduleExport;
}

/** Flush pending microtasks/promise chains. */
async function flush(times = 5) {
    for (let i = 0; i < times; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

const PROPS = {
    image_width: 480,
    courseid: 1,
    id: 2,
    quizid: 3,
    cameraallow: 'Camera allowed',
    allowcamerawarning: 'Please allow the camera',
};

test('Req 6.1 - getUserMedia is not called before the Pre-Check modal opens', async () => {
    const env = createEnvironment();
    const mod = loadModule(env);

    await mod.init(PROPS);
    await flush();
    // Even the module's initial visibility sync must not acquire the camera while
    // the modal is closed.
    env.syncModal();
    await flush();

    assert.strictEqual(env.getUserMediaCalls(), 0,
        'getUserMedia must not be called on activity page load / before modal open');
    assert.ok(!env.video.srcObject, 'video.srcObject must not be bound before the modal opens');
});

test('Req 6.2 - opening the modal acquires the camera and binds the stream', async () => {
    const env = createEnvironment();
    const mod = loadModule(env);

    await mod.init(PROPS);
    await flush();
    assert.strictEqual(env.getUserMediaCalls(), 0, 'camera must still be off before open');

    env.openModal();
    env.syncModal();
    await flush();

    assert.strictEqual(env.getUserMediaCalls(), 1,
        'getUserMedia must be called exactly once when the modal opens');
    assert.ok(env.video.srcObject, 'the acquired MediaStream must be bound to the modal <video>');
    assert.strictEqual(env.video.srcObject, env.lastStream(),
        'video.srcObject must be the stream returned by getUserMedia');

    // Idempotency: staying open should not re-acquire.
    env.syncModal();
    await flush();
    assert.strictEqual(env.getUserMediaCalls(), 1, 'camera must not be re-acquired while already open');
});

test('Req 6.3 - cancelling the modal stops all tracks and nulls srcObject', async () => {
    const env = createEnvironment();
    const mod = loadModule(env);

    await mod.init(PROPS);
    env.openModal();
    env.syncModal();
    await flush();
    const stream = env.lastStream();
    assert.ok(env.video.srcObject, 'precondition: stream bound after open');

    // Simulate a click on the Pre-Check modal cancel/close control.
    env.document._fire('click', {
        target: {
            closest(selector) {
                // The module targets cancel/close buttons within the preflight popup.
                return selector.indexOf('cancel') !== -1 || selector.indexOf('closebutton') !== -1
                    ? {matched: true}
                    : null;
            },
        },
    });
    await flush();

    assert.ok(stream._tracks.every((t) => t.stopped), 'every MediaStreamTrack must be stopped on cancel');
    assert.strictEqual(env.video.srcObject, null, 'video.srcObject must be nulled on cancel');
});

test('Req 6.3 - hiding the modal (visibility off) tears down the camera', async () => {
    const env = createEnvironment();
    const mod = loadModule(env);

    await mod.init(PROPS);
    env.openModal();
    env.syncModal();
    await flush();
    const stream = env.lastStream();
    assert.ok(env.video.srcObject, 'precondition: stream bound after open');

    // Modal becomes hidden; the visibility poll should tear the camera down.
    env.closeModal();
    env.syncModal();
    await flush();

    assert.ok(stream._tracks.every((t) => t.stopped), 'every MediaStreamTrack must be stopped when the modal hides');
    assert.strictEqual(env.video.srcObject, null, 'video.srcObject must be nulled when the modal hides');
});

test('Req 6.3 - pagehide tears down the camera', async () => {
    const env = createEnvironment();
    const mod = loadModule(env);

    await mod.init(PROPS);
    env.openModal();
    env.syncModal();
    await flush();
    const stream = env.lastStream();
    assert.ok(env.video.srcObject, 'precondition: stream bound after open');

    env.window._fire('pagehide');
    await flush();

    assert.ok(stream._tracks.every((t) => t.stopped), 'every MediaStreamTrack must be stopped on pagehide');
    assert.strictEqual(env.video.srcObject, null, 'video.srcObject must be nulled on pagehide');
});

test('Req 6.3 - beforeunload (navigation away) tears down the camera', async () => {
    const env = createEnvironment();
    const mod = loadModule(env);

    await mod.init(PROPS);
    env.openModal();
    env.syncModal();
    await flush();
    const stream = env.lastStream();
    assert.ok(env.video.srcObject, 'precondition: stream bound after open');

    env.window._fire('beforeunload');
    await flush();

    assert.ok(stream._tracks.every((t) => t.stopped), 'every MediaStreamTrack must be stopped on beforeunload');
    assert.strictEqual(env.video.srcObject, null, 'video.srcObject must be nulled on beforeunload');
});
