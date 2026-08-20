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
 * Desktop capture screen-check tolerance during an attempt.
 *
 * Regression cover for the in-page desktop share on macOS: the screen check marker
 * has to be *on* the shared screen, but it is routinely hidden from the frame we
 * happen to sample at the instant the share is granted -- the browser's own share
 * picker and "you are sharing your screen" bubble, a notification, the Dock, or
 * another app that macOS brings forward as the grant completes all cover it. The
 * old code sampled once, immediately, and on a miss stopped the stream and put the
 * gate back up, so the student could never get past "Desktop capture required".
 *
 * These tests assert the grace-period behaviour that replaced it, mirroring the
 * tolerance screenmonitor.php already applied. Like camera_lifecycle.test.js they
 * load the REAL `amd/src/proctoring.js` inside a Node `vm` sandbox with a mocked
 * DOM, a mocked getDisplayMedia and a controllable clock, so the shipped code is
 * exercised rather than a reimplementation.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const SOURCE_PATH = path.resolve(__dirname, '..', '..', 'amd', 'src', 'proctoring.js');
const SOURCE = fs.readFileSync(SOURCE_PATH, 'utf8');

// The module's marker watcher is the only 2000ms interval it registers.
const WATCHER_INTERVAL_MS = 2000;
// Must stay in step with markerGraceMs in amd/src/proctoring.js.
const GRACE_MS = 30000;

// The three marker swatch colours from styles.css.
const SWATCH_COLOURS = [
    [255, 0, 204],   // #ff00cc magenta
    [0, 255, 204],   // #00ffcc cyan
    [255, 230, 0],   // #ffe600 yellow
];

/**
 * Build a synthetic captured frame at true capture proportions.
 *
 * A 3456x2234 capture of a 1728px-wide screen is analysed at 1280x827, so one CSS
 * pixel of the marker lands as 1280/1728 of a frame pixel. The marker's 58x24 CSS
 * swatches therefore measure ~43x18 and its colour row ~137 -- wider than the 96px
 * window the detector used to search, which is what `markerSearchGeometry()` now
 * sizes correctly.
 *
 * Layouts:
 *  - `marker`: three full-size swatches, i.e. what a real shared screen looks like.
 *  - `specks`: the same three colours, but in blocks far too small to be swatches.
 *    They clear the old flat 18-sample floor while falling well short of the area a
 *    real swatch covers, so they stand in for unrelated colourful desktop content.
 *  - `blank`: nothing on hue.
 */
function makeFrame(layout, width, height) {
    const data = new Uint8ClampedArray(width * height * 4);
    for (let i = 3; i < data.length; i += 4) {
        data[i] = 255;
    }

    const shapes = {
        marker: {size: [43, 18], gap: 4, at: [1000, 100]},
        specks: {size: [12, 12], gap: 4, at: [1000, 100]},
    };
    const shape = shapes[layout];
    if (!shape) {
        return {width, height, data};
    }

    const [blockWidth, blockHeight] = shape.size;
    SWATCH_COLOURS.forEach((rgb, index) => {
        const originX = shape.at[0] + (index * (blockWidth + shape.gap));
        for (let y = shape.at[1]; y < shape.at[1] + blockHeight && y < height; y++) {
            for (let x = originX; x < originX + blockWidth && x < width; x++) {
                const offset = ((y * width) + x) * 4;
                data[offset] = rgb[0];
                data[offset + 1] = rgb[1];
                data[offset + 2] = rgb[2];
            }
        }
    });

    return {width, height, data};
}

/** A DOM node standing in for both the detached <video> and the analysis <canvas>. */
function makeElement(env, tag) {
    const ctx2d = {
        fillStyle: '',
        fillRect() {},
        drawImage() {},
        getImageData(x, y, width, height) {
            return makeFrame(env.frameLayout, width, height);
        },
    };
    return {
        tagName: (tag || 'div').toUpperCase(),
        id: '',
        className: '',
        style: {},
        srcObject: null,
        // A real capture reports its dimensions as soon as the first frame lands, and a
        // Retina laptop screen captures at 3456x2234. `frameArrives: false` models a
        // stream that never delivers a frame at all.
        get videoWidth() {
            return env.frameArrives ? 3456 : 0;
        },
        get videoHeight() {
            return env.frameArrives ? 2234 : 0;
        },
        width: 0,
        height: 0,
        muted: false,
        playsInline: false,
        innerHTML: '',
        firstChild: null,
        dataset: {},
        classList: {add() {}, remove() {}, toggle() {}, contains() { return false; }},
        play() {
            return Promise.resolve();
        },
        setAttribute() {},
        getAttribute() {
            return null;
        },
        getBoundingClientRect() {
            return {width: 0, height: 0, top: 0, left: 0, x: 0, y: 0};
        },
        getContext() {
            return ctx2d;
        },
        toDataURL() {
            return 'data:image/jpeg;base64,';
        },
        insertBefore() {},
        appendChild() {},
        addEventListener() {},
        removeEventListener() {},
        closest() {
            return null;
        },
        querySelector() {
            return null;
        },
    };
}

function createEnvironment() {
    const env = {
        frameLayout: 'blank',
        frameArrives: true,
        now: 1700000000000,
        loggedEvents: [],
        intervals: [],
        displayMediaCalls: 0,
        tracks: [],
    };

    const makeTrack = () => {
        const listeners = {};
        return {
            kind: 'video',
            stopped: false,
            getSettings() {
                return {displaySurface: 'monitor', width: 3456, height: 2234};
            },
            stop() {
                this.stopped = true;
            },
            addEventListener(type, fn) {
                (listeners[type] = listeners[type] || []).push(fn);
            },
            _fire(type) {
                (listeners[type] || []).forEach((fn) => fn({}));
            },
        };
    };

    const navigator = {
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        mediaDevices: {
            getDisplayMedia() {
                env.displayMediaCalls++;
                const track = makeTrack();
                env.tracks.push(track);
                return Promise.resolve({
                    getTracks() {
                        return [track];
                    },
                    getVideoTracks() {
                        return [track];
                    },
                });
            },
            getUserMedia() {
                return Promise.resolve({getTracks() {
                    return [];
                }});
            },
        },
    };

    // The gate markup is injected as an HTML string through jQuery, so its nodes only
    // become findable once that append has happened -- initScreenShareGate() bails out
    // early if the gate id already resolves.
    let gateAppended = false;
    const gate = {style: {}};
    const status = {className: '', textContent: ''};

    const document = {
        visibilityState: 'visible',
        // The warning banner is docked to the body rather than inserted into the content
        // region, so the stub body has to accept an appendChild the way a real one does.
        body: {nodeType: 1, insertBefore() {}, appendChild() {}, firstChild: null},
        getElementById(id) {
            if (!gateAppended) {
                return null;
            }
            if (id === 'proctoring-screen-share-gate') {
                return gate;
            }
            if (id === 'proctoring-screen-share-status') {
                return status;
            }
            return null;
        },
        getElementsByClassName() {
            return [];
        },
        querySelector() {
            return null;
        },
        createElement(tag) {
            return makeElement(env, tag);
        },
        addEventListener() {},
        removeEventListener() {},
    };

    const window = {
        innerWidth: 1728,
        innerHeight: 879,
        outerHeight: 1022,
        screenX: 0,
        screenY: 33,
        devicePixelRatio: 2,
        location: {href: 'https://example.org/mod/quiz/attempt.php'},
        screen: {width: 1728, height: 1117, isExtended: false},
        matchMedia() {
            return {matches: false, addEventListener() {}};
        },
        addEventListener() {},
        removeEventListener() {},
        setInterval(fn, delay) {
            env.intervals.push({fn, delay});
            return env.intervals.length;
        },
        clearInterval(handle) {
            const entry = env.intervals[handle - 1];
            if (entry) {
                entry.cleared = true;
            }
        },
        setTimeout(fn) {
            // Fire on the next macrotask rather than after the real delay: waitForScreenFrame()
            // paces its retries with window.setTimeout and would otherwise never finish.
            setImmediate(fn);
            return 0;
        },
        clearTimeout() {},
    };

    const clickHandlers = {};
    const chainable = ['prop', 'on', 'html', 'trigger', 'ready', 'append', 'appendTo', 'remove',
        'addClass', 'removeClass', 'toggleClass', 'attr', 'css', 'find', 'each', 'val', 'text',
        'hide', 'show', 'before', 'after', 'insertBefore', 'closest', 'empty', 'off'];
    const $ = function(selector) {
        const node = {};
        chainable.forEach((method) => {
            node[method] = () => node;
        });
        node.on = (type, fn) => {
            if (typeof selector === 'string') {
                (clickHandlers[selector + ':' + type] = clickHandlers[selector + ':' + type] || []).push(fn);
            }
            return node;
        };
        node.append = (content) => {
            if (typeof content === 'string' && content.indexOf('proctoring-screen-share-gate') !== -1) {
                gateAppended = true;
            }
            return node;
        };
        return node;
    };

    const Ajax = {
        call(requests) {
            (requests || []).forEach((request) => {
                if (request.args && request.args.eventtype) {
                    env.loggedEvents.push(request.args);
                }
            });
            return [{
                fail() {
                    return this;
                },
                done() {
                    return {fail() {}};
                },
            }];
        },
    };

    const deps = {
        'jquery': $,
        'core/ajax': Ajax,
        'core/notification': {addNotification() {}, exception() {}},
        // Return each key verbatim so assertions can name the string that was shown.
        'core/str': {
            get_strings(keys) {
                return Promise.resolve((keys || []).map((k) => k.key));
            },
            get_string(key) {
                return Promise.resolve(key);
            },
        },
        'quizaccess_proctoring/screenMonitorClient': new Proxy({}, {
            get() {
                return () => ({});
            },
        }),
    };

    Object.assign(env, {
        navigator,
        window,
        document,
        deps,
        gate,
        status,
        setMarkerVisible(visible) {
            env.frameLayout = visible ? 'marker' : 'blank';
        },
        setFrameLayout(layout) {
            env.frameLayout = layout;
        },
        advance(ms) {
            env.now += ms;
        },
        clickShare() {
            const handlers = clickHandlers['#proctoring-screen-share-button:click'] || [];
            assert.ok(handlers.length, 'the gate must wire a click handler on its share button');
            return handlers[handlers.length - 1]({preventDefault() {}});
        },
        tickWatcher() {
            const watchers = env.intervals.filter((i) => i.delay === WATCHER_INTERVAL_MS && !i.cleared);
            assert.strictEqual(watchers.length, 1, 'exactly one marker watcher must be running');
            watchers[0].fn();
        },
        watcherCount() {
            return env.intervals.filter((i) => i.delay === WATCHER_INTERVAL_MS && !i.cleared).length;
        },
        markerMissingEvents() {
            return env.loggedEvents.filter((e) => e.eventtype === 'screen_marker_missing');
        },
        liveTracks() {
            return env.tracks.filter((t) => !t.stopped);
        },
    });

    return env;
}

function loadModule(env) {
    let moduleExport = null;
    const sandbox = {
        define(deps, factory) {
            moduleExport = factory.apply(null, deps.map((d) => env.deps[d]));
        },
        navigator: env.navigator,
        window: env.window,
        document: env.document,
        // Controllable clock: the module only ever reads Date.now().
        Date: {now: () => env.now},
        MutationObserver: class {
            observe() {}
            disconnect() {}
        },
        console,
        Promise,
        JSON,
        Math,
        parseInt,
        parseFloat,
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

async function flush(times = 6) {
    for (let i = 0; i < times; i++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
}

const PROPS = {
    courseid: 1,
    id: 2,
    quizid: 3,
    status: 4,
    image_width: 480,
    camshotdelay: 30000,
    captureviolationdesktop: 1,
    monitorbrowseractivity: 1,
    screenmarkerrequired: 1,
};

/**
 * Boot the attempt-page monitoring and open the desktop share gate.
 *
 * setup() continues into unrelated webcam wiring after it installs the gate, which the
 * minimal DOM here does not model; the gate is already wired by then, so a late failure
 * in that tail is irrelevant to these assertions.
 */
async function bootWithShare(overrides, envSetup) {
    const env = createEnvironment();
    if (envSetup) {
        envSetup(env);
    }
    const mod = loadModule(env);
    await Promise.resolve(mod.setup(Object.assign({}, PROPS, overrides || {}), null)).catch(() => {});
    await flush();
    await env.clickShare();
    await flush(30);
    return env;
}

test('a share granted while the marker is covered is kept and re-checked, not rejected', async () => {
    const env = await bootWithShare();

    assert.strictEqual(env.displayMediaCalls, 1, 'the share must have been requested');
    assert.strictEqual(env.liveTracks().length, 1,
        'the stream must stay alive: it is a valid entire-screen share showing the wrong thing');
    assert.strictEqual(env.markerMissingEvents().length, 0,
        'a single covered frame must not be logged against the student');
    assert.strictEqual(env.status.textContent, 'screenmarkerchecking',
        'the gate must say it is still checking, not that the wrong screen was shared');
    // The gate renders visible from the stylesheet, so "still up" means never hidden.
    assert.notStrictEqual(env.gate.style.display, 'none', 'the gate stays up until the marker is seen');
    assert.strictEqual(env.watcherCount(), 1, 'a marker watcher must be running to re-check');
});

test('the share is accepted as soon as the marker becomes visible within the grace period', async () => {
    const env = await bootWithShare();

    // The covering window goes away a few seconds later.
    env.advance(4000);
    env.setMarkerVisible(true);
    env.tickWatcher();

    assert.strictEqual(env.gate.style.display, 'none', 'the gate must close once the marker is seen');
    assert.strictEqual(env.status.textContent, 'screenshareaccepted');
    assert.strictEqual(env.markerMissingEvents().length, 0,
        'no violation may be logged for a share that came good inside the grace period');
    assert.strictEqual(env.displayMediaCalls, 1, 'the student must not be re-prompted to share');
    assert.strictEqual(env.liveTracks().length, 1);
});

test('a marker missing for the whole grace period faults, keeps the stream, and can recover', async () => {
    const env = await bootWithShare();

    env.advance(GRACE_MS + 1000);
    env.tickWatcher();

    assert.strictEqual(env.markerMissingEvents().length, 1,
        'once the grace period lapses the miss must be logged exactly once');
    assert.strictEqual(env.markerMissingEvents()[0].eventdetail.includes('initial_marker_check_failed'), true,
        'the logged reason must identify the initial check');
    assert.strictEqual(env.status.textContent, 'screenmarkerwrongmonitor');
    assert.strictEqual(env.gate.style.display, 'flex');
    assert.strictEqual(env.liveTracks().length, 1,
        'the share must be kept so bringing the quiz forward recovers without a re-prompt');

    // Still missing on the next tick: no second event inside the same grace window.
    env.advance(WATCHER_INTERVAL_MS);
    env.tickWatcher();
    assert.strictEqual(env.markerMissingEvents().length, 1, 'the miss must not be logged every tick');

    // The student brings the quiz window forward.
    env.setMarkerVisible(true);
    env.tickWatcher();
    assert.strictEqual(env.gate.style.display, 'none', 'recovery must close the gate');
    assert.strictEqual(env.status.textContent, 'screenshareaccepted');
    assert.strictEqual(env.displayMediaCalls, 1, 'recovery must not need a fresh share prompt');
});

test('with the marker requirement off, an entire-screen share is accepted outright', async () => {
    const env = await bootWithShare({screenmarkerrequired: 0});

    assert.strictEqual(env.gate.style.display, 'none',
        'nothing is being looked for, so the gate must not be left up with no explanation');
    assert.strictEqual(env.status.textContent, 'screenshareaccepted');
    assert.strictEqual(env.markerMissingEvents().length, 0);
    assert.strictEqual(env.liveTracks().length, 1);
    assert.strictEqual(env.watcherCount(), 0, 'there is no marker to watch for');
});

test('a full-size marker is found, though its colour row is wider than the old search window', async () => {
    // ~137px of analysed frame against the 96px window the detector used to search: the
    // old code only ever matched this by clipping the outer two swatches.
    const env = await bootWithShare({}, (e) => {
        e.frameLayout = 'marker';
    });

    assert.strictEqual(env.gate.style.display, 'none', 'a correctly shared screen must be accepted');
    assert.strictEqual(env.status.textContent, 'screenshareaccepted');
    assert.strictEqual(env.markerMissingEvents().length, 0);
});

test('colour blocks too small to be swatches are not mistaken for the marker', async () => {
    // These clear the old flat 18-sample floor, so this is the false accept that simply
    // widening the window would have made easier: the floor now scales with the area a
    // real swatch covers.
    const env = await bootWithShare({}, (e) => {
        e.frameLayout = 'specks';
    });

    assert.notStrictEqual(env.gate.style.display, 'none',
        'unrelated colourful content must not pass the screen check');
    assert.strictEqual(env.status.textContent, 'screenmarkerchecking');

    env.advance(GRACE_MS + 1000);
    env.tickWatcher();
    assert.strictEqual(env.status.textContent, 'screenmarkerwrongmonitor');
    assert.strictEqual(env.markerMissingEvents().length, 1);
});

test('a share that never delivers a frame reports an error instead of stalling the gate', async () => {
    // With the marker requirement off there is no marker verdict to report, so this is the
    // path that used to dead-end: the gate stayed up with no message and no way forward.
    const env = await bootWithShare({screenmarkerrequired: 0}, (e) => {
        e.frameArrives = false;
    });

    assert.strictEqual(env.status.textContent, 'screensharedenied',
        'a share that yields no frame must say so rather than leaving the gate silent');
    assert.notStrictEqual(env.gate.style.display, 'none', 'the gate must remain available to retry');
    assert.strictEqual(env.liveTracks().length, 0, 'the unusable stream must be released');
    assert.strictEqual(env.markerMissingEvents().length, 0,
        'a missing frame is not evidence the student shared the wrong screen');
});
