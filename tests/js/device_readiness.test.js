/* eslint-disable */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const nodeTest = require('node:test');
const assert = require('node:assert/strict');

// Run identical behavioral assertions against the editable source and the shipped AMD artifact.
for (const [variant, filename] of [
    ['source', '../../amd/src/deviceReadiness.js'],
    ['shipped AMD', '../../amd/build/deviceReadiness.min.js'],
]) {
const source = fs.readFileSync(path.join(__dirname, filename), 'utf8');
const test = (name, ...args) => nodeTest(variant + ': ' + name, ...args);

function load(environment = {}) {
    let module;
    vm.runInNewContext(source, {
        define(...args) { module = args.at(-1)({}); },
        setTimeout, clearTimeout, setInterval, clearInterval, Uint8Array,
        ...environment,
    });
    return module;
}
function stream(kind = 'camera', surface = 'monitor') {
    const track = {
        readyState: 'live', stopped: 0,
        stop() { this.stopped++; this.readyState = 'ended'; },
        getSettings() { return {displaySurface: surface}; },
        addEventListener() {},
    };
    return {track, getTracks() { return [track]; },
        getVideoTracks() { return kind === 'microphone' ? [] : [track]; },
        getAudioTracks() { return kind === 'microphone' ? [track] : []; }};
}
function setup(mediaDevices, extra = {}, environment = {}) {
    const statuses = [], streams = [];
    const preview = {readyState: 2, videoWidth: 640, videoHeight: 480, play: () => Promise.resolve()};
    const controller = load(environment).createDeviceTests({
        mediaDevices, onStatus(...args) { statuses.push(args); },
        onStream(...args) { streams.push(args); return args[0] && args[1] !== 'microphone' ? preview : null; },
        ...extra,
    });
    return {controller, statuses, streams, preview};
}
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

test('readiness does not request media until an explicit test and releases it on exit', async () => {
    const camera = stream();
    let calls = 0;
    const env = setup({getUserMedia() { calls++; return Promise.resolve(camera); }});
    assert.equal(calls, 0);
    await env.controller.run('camera');
    assert.equal(calls, 1);
    assert.deepEqual(env.statuses.at(-1), ['camera', 'passed']);
    env.controller.dispose();
    assert.equal(camera.track.stopped, 1);
    await env.controller.run('camera');
    assert.equal(calls, 1);
});

test('late permission after leaving the page cannot keep a device running', async () => {
    const camera = stream();
    let grant;
    const env = setup({getUserMedia() { return new Promise(resolve => { grant = resolve; }); }});
    const pending = env.controller.run('camera');
    env.controller.dispose();
    grant(camera);
    await pending;
    assert.equal(camera.track.stopped, 1);
    assert.equal(env.statuses.some(row => row[1] === 'passed'), false);
});

test('ignored permission prompts time out and late streams are stopped', async () => {
    const camera = stream();
    let grant;
    const env = setup({getUserMedia() { return new Promise(resolve => { grant = resolve; }); }},
        {permissionTimeout: 10});
    await env.controller.run('camera');
    assert.deepEqual(env.statuses.at(-1), ['camera', 'timeout']);
    grant(camera);
    await sleep(0);
    assert.equal(camera.track.stopped, 1);
    env.controller.dispose();
});

test('screen test rejects a selected window and releases the stream', async () => {
    const screen = stream('screen', 'window');
    const env = setup({getDisplayMedia() { return Promise.resolve(screen); }});
    await env.controller.run('screen');
    assert.deepEqual(env.statuses.at(-1), ['screen', 'wrongscreen']);
    assert.equal(screen.track.stopped, 1);
    env.controller.dispose();
});

// Firefox reports no displaySurface at all, only the captured size (CPIT-456).
function firefoxScreen(width, height) {
    const screen = stream('screen');
    screen.track.getSettings = () => ({width, height});
    return screen;
}
const firefoxWindow = {screen: {width: 1280, height: 720}, devicePixelRatio: 1.5};

test('Firefox entire-screen share is accepted from its size when no surface is reported', async () => {
    const screen = firefoxScreen(1920, 1080);
    const env = setup({getDisplayMedia() { return Promise.resolve(screen); }}, {}, {window: firefoxWindow});
    await env.controller.run('screen');
    assert.deepEqual(env.statuses.at(-1), ['screen', 'passed']);
    env.controller.dispose();
});

test('Firefox window share smaller than the screen is still rejected', async () => {
    const screen = firefoxScreen(1920, 1040);
    const env = setup({getDisplayMedia() { return Promise.resolve(screen); }}, {}, {window: firefoxWindow});
    await env.controller.run('screen');
    assert.deepEqual(env.statuses.at(-1), ['screen', 'wrongscreen']);
    assert.equal(screen.track.stopped, 1);
    env.controller.dispose();
});

test('screen share with neither a surface nor a size is reported as unconfirmed', async () => {
    const screen = firefoxScreen(undefined, undefined);
    const env = setup({getDisplayMedia() { return Promise.resolve(screen); }}, {}, {window: firefoxWindow});
    await env.controller.run('screen');
    assert.deepEqual(env.statuses.at(-1), ['screen', 'screenunknown']);
    env.controller.dispose();
});

test('microphone test uses audio only and preview stops automatically', async () => {
    const mic = stream('microphone');
    let constraints;
    const env = setup({getUserMedia(value) { constraints = value; return Promise.resolve(mic); }},
        {previewDuration: 10});
    await env.controller.run('microphone');
    assert.equal(constraints.video, false);
    assert.equal(constraints.audio, true);
    assert.deepEqual(env.statuses.at(-1), ['microphone', 'passed']);
    await sleep(25);
    assert.equal(mic.track.stopped, 1);
    env.controller.dispose();
});

test('insecure context and denied permissions produce actionable outcomes', async () => {
    let calls = 0;
    const blocked = setup({getUserMedia() { calls++; }}, {secure: false});
    await blocked.controller.run('camera');
    assert.equal(calls, 0);
    assert.deepEqual(blocked.statuses.at(-1), ['camera', 'secure']);
    blocked.controller.dispose();
    const denied = setup({getUserMedia() { return Promise.reject({name: 'NotAllowedError'}); }});
    await denied.controller.run('camera');
    assert.deepEqual(denied.statuses.at(-1), ['camera', 'permission']);
    denied.controller.dispose();
});

test('starting another test discards a superseded late camera grant', async () => {
    const camera = stream(), mic = stream('microphone');
    let grant;
    const env = setup({getUserMedia(options) {
        return options.audio ? Promise.resolve(mic) : new Promise(resolve => { grant = resolve; });
    }});
    const first = env.controller.run('camera');
    await env.controller.run('microphone');
    grant(camera);
    await first;
    assert.equal(camera.track.stopped, 1);
    assert.equal(mic.track.stopped, 0);
    env.controller.dispose();
    assert.equal(mic.track.stopped, 1);
});

async function settle() {
    for (let i = 0; i < 12; i++) await Promise.resolve();
}

function timers() {
    let id = 0;
    const entries = new Map();
    return {entries,
        setTimeout(fn, delay) { entries.set(++id, {fn, delay}); return id; },
        clearTimeout(key) { entries.delete(key); },
        fire(delay) {
            const entry = [...entries].find(([, value]) => value.delay === delay);
            assert.ok(entry, 'Expected an active ' + delay + 'ms timer');
            entries.delete(entry[0]);
            entry[1].fn();
        }};
}

test('camera and screen playback rejection cannot pass and releases preview resources', async () => {
    for (const kind of ['camera', 'screen']) {
        const clock = timers(), media = stream(kind);
        const env = setup({getUserMedia: () => Promise.resolve(media),
            getDisplayMedia: () => Promise.resolve(media)}, {}, clock);
        env.preview.play = () => Promise.reject({name: 'NotAllowedError'});
        await env.controller.run(kind);
        assert.equal(env.statuses.some(row => row[1] === 'passed'), false, kind);
        assert.deepEqual(env.statuses.at(-1), [kind, 'previewfailed']);
        assert.equal(media.track.stopped, 1);
        assert.deepEqual(env.streams.at(-1), [null, '']);
        assert.equal(clock.entries.size, 0);
        env.controller.dispose();
    }
});

test('camera and screen require fulfilled playback and nonzero decoded frames before passing', async () => {
    for (const kind of ['camera', 'screen']) {
        const clock = timers(), media = stream(kind);
        const env = setup({getUserMedia: () => Promise.resolve(media),
            getDisplayMedia: () => Promise.resolve(media)}, {}, clock);
        let play;
        env.preview.play = () => new Promise(resolve => { play = resolve; });
        const pending = env.controller.run(kind);
        await settle();
        assert.deepEqual(env.statuses.at(-1), [kind, 'checking'], 'Dimensions alone cannot pass before play resolves');
        env.preview.readyState = 1;
        play();
        await settle();
        assert.deepEqual(env.statuses.at(-1), [kind, 'checking'], 'Metadata is not a decoded frame');
        env.preview.readyState = 2;
        env.preview.videoHeight = 0;
        clock.fire(100);
        await settle();
        assert.deepEqual(env.statuses.at(-1), [kind, 'checking'], 'Both frame dimensions must be nonzero');
        env.preview.videoHeight = 480;
        clock.fire(100);
        await pending;
        assert.deepEqual(env.statuses.at(-1), [kind, 'passed']);
        assert.equal(media.track.stopped, 0);
        assert.equal(clock.entries.size, 1, 'Only the automatic preview-stop timer remains');
        env.controller.dispose();
        assert.equal(media.track.stopped, 1);
        assert.equal(clock.entries.size, 0);
    }
});

test('a permitted camera or screen without decoded frames fails within five seconds', async () => {
    for (const kind of ['camera', 'screen']) {
        const clock = timers(), media = stream(kind);
        const env = setup({getUserMedia: () => Promise.resolve(media),
            getDisplayMedia: () => Promise.resolve(media)}, {}, clock);
        env.preview.readyState = 1;
        const pending = env.controller.run(kind);
        await settle();
        assert.equal(env.statuses.some(row => row[1] === 'passed'), false);
        clock.fire(5000);
        await pending;
        assert.deepEqual(env.statuses.at(-1), [kind, 'noframes']);
        assert.equal(media.track.stopped, 1);
        assert.deepEqual(env.streams.at(-1), [null, '']);
        assert.equal(clock.entries.size, 0);
        env.controller.dispose();
    }
});

test('a hanging preview playback promise is bounded and cannot pass after timeout', async () => {
    const clock = timers(), media = stream();
    const env = setup({getUserMedia: () => Promise.resolve(media)}, {}, clock);
    let play;
    env.preview.play = () => new Promise(resolve => { play = resolve; });
    const pending = env.controller.run('camera');
    await settle();
    clock.fire(5000);
    await pending;
    assert.deepEqual(env.statuses.at(-1), ['camera', 'noframes']);
    assert.equal(media.track.stopped, 1);
    play();
    await settle();
    assert.equal(env.statuses.some(row => row[1] === 'passed'), false);
    assert.equal(clock.entries.size, 0);
    env.controller.dispose();
});

test('Stop settles pending playback immediately and ignores its late resolution', async () => {
    const clock = timers(), media = stream();
    const env = setup({getUserMedia: () => Promise.resolve(media)}, {}, clock);
    let play;
    env.preview.play = () => new Promise(resolve => { play = resolve; });
    let finished = false;
    const pending = env.controller.run('camera').then(() => { finished = true; });
    await settle();
    env.controller.stop();
    await settle();
    assert.equal(finished, true);
    assert.equal(media.track.stopped, 1);
    assert.deepEqual(env.streams.at(-1), [null, '']);
    assert.deepEqual(env.statuses.at(-1), ['camera', 'stopped']);
    assert.equal(clock.entries.size, 0);
    play();
    await pending;
    await settle();
    assert.equal(env.statuses.some(row => row[1] === 'passed'), false);
    env.controller.dispose();
});

test('Stop cancels the decoded-frame polling timer and releases the camera', async () => {
    const clock = timers(), media = stream();
    const env = setup({getUserMedia: () => Promise.resolve(media)}, {}, clock);
    env.preview.readyState = 0;
    const pending = env.controller.run('camera');
    await settle();
    assert.equal(clock.entries.size, 2, 'Frame polling and deadline are both active');
    env.controller.stop();
    await pending;
    assert.equal(media.track.stopped, 1);
    assert.deepEqual(env.statuses.at(-1), ['camera', 'stopped']);
    assert.equal(env.statuses.some(row => row[1] === 'passed'), false);
    assert.equal(clock.entries.size, 0);
    env.controller.dispose();
});

test('stopping an ignored permission prompt settles immediately and clears its wait timer', async () => {
    const clock = timers();
    let grant;
    const env = setup({getUserMedia() { return new Promise(resolve => { grant = resolve; }); }}, {}, clock);
    let finished = false;
    const pending = env.controller.run('camera').then(() => { finished = true; });
    assert.equal(clock.entries.size, 1);
    env.controller.stop();
    await settle();
    assert.equal(clock.entries.size, 0);
    assert.equal(finished, true, 'Stop must not leave run() awaiting the permission timeout');
    assert.deepEqual(env.statuses.at(-1), ['camera', 'stopped']);
    const late = stream();
    grant(late);
    await pending;
    await settle();
    assert.equal(late.track.stopped, 1);
    env.controller.dispose();
});

function page(preloadStrings = false) {
    const clock = timers();
    const events = {};
    const requests = [];
    const previews = [];
    const messages = {
        checking: 'checking', notchecked: 'notchecked', stopped: 'stopped', passed: 'passed',
        roundtrip: '{ms} ms', reachable: 'reachable', unavailable: 'unavailable'
    };
    const nodes = {};
    const node = key => nodes[key] || (nodes[key] = {hidden: false, disabled: false, value: 0, listeners: {},
        readyState: 2, videoWidth: 640, videoHeight: 480, pause() {}, play: () => Promise.resolve(),
        getAttribute() { return key; }, addEventListener(name, callback) { this.listeners[name] = callback; }});
    const root = {
        querySelector(selector) {
            const status = selector.match(/data-readiness-status="([^"]+)"/);
            return node(status ? status[1] + 'status' : selector.slice(16, -1));
        },
        querySelectorAll() { return ['camera', 'screen', 'microphone'].map(node); }
    };
    let module;
    load({
        ...clock,
        define(...args) {
            module = args.at(-1)({call(...args) {
                const entry = {args};
                const result = new Promise(resolve => { entry.resolve = resolve; });
                requests.push(entry);
                return [result];
            }});
        },
        window: {isSecureContext: true,
            addEventListener(name, fn) { events[name] = fn; },
            removeEventListener(name) { delete events[name]; }},
        document: {getElementById() { return root; }},
        navigator: {mediaDevices: {getUserMedia() {
            const value = stream();
            previews.push(value);
            return Promise.resolve(value);
        }}},
        M: preloadStrings ? {str: {quizaccess_proctoring:
            Object.fromEntries(Object.entries(messages).map(([key, value]) => ['readiness:' + key, value]))}} : undefined,
        performance: {now: () => 1000}
    });
    const controller = module.init(preloadStrings ? {cmid: 3} : {cmid: 3, strings: messages});
    return {controller, clock, events, nodes, previews, requests};
}

test('readiness history restore releases previous previews and keeps device buttons usable', async () => {
    const env = page();
    env.nodes.camera.listeners.click();
    await settle();
    assert.equal(env.previews.length, 1);
    env.events.pagehide();
    assert.equal(env.previews[0].track.stopped, 1);
    assert.equal(env.clock.entries.size, 0);
    env.events.pageshow({persisted: true});
    env.nodes.camera.listeners.click();
    await settle();
    assert.equal(env.previews.length, 2);
    assert.equal(env.previews[1].track.stopped, 0);
    env.controller.dispose();
    assert.equal(env.previews[1].track.stopped, 1);
    assert.equal(env.clock.entries.size, 0);
});

test('pagehide during pending playback releases media and history restore allows a fresh preview', async () => {
    const env = page();
    let play;
    env.nodes.preview.play = () => new Promise(resolve => { play = resolve; });
    env.nodes.camera.listeners.click();
    await settle();
    assert.equal(env.nodes.camerastatus.textContent, 'checking');
    env.events.pagehide();
    await settle();
    assert.equal(env.previews[0].track.stopped, 1);
    assert.equal(env.nodes.preview.srcObject, null);
    assert.equal(env.clock.entries.size, 0);
    play();
    await settle();
    assert.equal(env.nodes.camerastatus.textContent, 'stopped');
    env.events.pageshow({persisted: true});
    env.nodes.preview.play = () => Promise.resolve();
    env.nodes.camera.listeners.click();
    await settle();
    assert.equal(env.nodes.camerastatus.textContent, 'passed');
    assert.equal(env.previews[1].track.stopped, 0);
    env.controller.dispose();
    assert.equal(env.previews[1].track.stopped, 1);
    assert.equal(env.clock.entries.size, 0);
});

test('network probes bound transport, cancel waits on exit, and recover after history restore', async () => {
    const env = page();
    const pending = env.nodes.network.listeners.click();
    assert.equal(env.requests[0].args[4], 10000, 'synthetic upload needs an actual Ajax transport timeout');
    assert.equal(env.nodes.network.disabled, true);
    env.events.pagehide();
    await pending;
    assert.equal(env.clock.entries.size, 0);
    assert.equal(env.nodes.network.disabled, false);
    env.requests[0].resolve({receivedbytes: 32768});
    await settle();
    assert.equal(env.requests.length, 1, 'a canceled first probe must not start the provider probe');
    env.events.pageshow({persisted: true});
    const restarted = env.nodes.network.listeners.click();
    env.requests[1].resolve({receivedbytes: 32768});
    await settle();
    assert.equal(env.requests[2].args[4], 15000, 'provider probe needs an actual Ajax transport timeout');
    env.requests[2].resolve({providers: [{name: 'face', status: 'reachable'}]});
    await restarted;
    assert.equal(env.nodes.network.disabled, false);
    assert.equal(env.nodes.facestatus.textContent, 'reachable');
    assert.equal(env.clock.entries.size, 0);
    env.controller.dispose();
});

test('preloaded Moodle strings support offline device checks without string arguments or network loading', async () => {
    const env = page(true);
    env.nodes.camera.listeners.click();
    assert.equal(env.nodes.camerastatus.textContent, 'checking');
    await settle();
    assert.equal(env.nodes.camerastatus.textContent, 'passed');
    assert.equal(env.requests.length, 0, 'local device checks must not fetch translations');
    const network = env.nodes.network.listeners.click();
    env.requests[0].resolve({receivedbytes: 32768});
    await settle();
    assert.equal(env.nodes.networkstatus.textContent, 'passed 0 ms');
    env.requests[1].resolve({providers: [{name: 'face', status: 'reachable'}]});
    await network;
    assert.equal(env.nodes.facestatus.textContent, 'reachable');
    env.controller.dispose();
});
}
