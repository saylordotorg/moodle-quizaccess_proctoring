/* eslint-disable */
// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Exercise the actual helper window script, including streams granted after navigation.
function helper(options = {}) {
    const events = {};
    const clicks = [];
    const pending = [];
    const intervals = new Map();
    const timeouts = new Map();
    const messages = [];
    const firedDelays = [];
    const videos = [];
    const playRequests = [];
    let elapsed = 0;
    let id = 0;
    let channel;
    const status = {};
    const source = fs.readFileSync(path.resolve(__dirname, '../../screenmonitor.php'), 'utf8')
        .match(/\$js = <<<JS\r?\n([\s\S]*?)\r?\nJS;/)[1];
    const config = {channel: 'fixture', statuskey: 'fixture', markerrequired: !!options.markerrequired, strings: {
        ready: 'ready', stopped: 'stopped', unsupported: 'unsupported',
        permissiondenied: 'permissiondenied', captureunavailable: 'captureunavailable',
        windowinactive: 'windowinactive', requestfailed: 'requestfailed',
        playbackfailed: 'playbackfailed', noframes: 'noframes',
        entirescreenrequired: 'entirescreenrequired', wrongmonitor: 'wrongmonitor'
    }};
    const window = {
        localStorage: {setItem() {}},
        BroadcastChannel: true,
        setInterval(fn) { intervals.set(++id, fn); return id; },
        clearInterval(key) { intervals.delete(key); },
        setTimeout(fn, delay) {
            timeouts.set(++id, {fn, delay, at: elapsed + delay});
            return id;
        },
        clearTimeout(key) { timeouts.delete(key); },
        addEventListener(name, fn) { events[name] = fn; }
    };
    vm.runInNewContext(source.replace('(%s)', '(' + JSON.stringify(config) + ')'), {
        window, Date, Promise,
        BroadcastChannel: class {
            constructor() { channel = this; }
            postMessage(value) { messages.push(value); }
        },
        navigator: {mediaDevices: {
            getDisplayMedia() { return new Promise((resolve, reject) => pending.push({resolve, reject})); }
        }},
        document: {
            getElementById(id) {
                return id.endsWith('-status') ? status : {addEventListener(name, fn) { clicks.push(fn); }};
            },
            createElement(tag) {
                if (tag === 'video') {
                    const video = {
                        videoWidth: options.noframes ? 0 : 1280,
                        videoHeight: options.noframes ? 0 : 720,
                        play() {
                            if (options.playError) {
                                return Promise.reject(options.playError);
                            }
                            if (options.deferPlay) {
                                return new Promise((resolve, reject) => playRequests.push({resolve, reject}));
                            }
                            return Promise.resolve();
                        }
                    };
                    videos.push(video);
                    return video;
                }
                return {
                    getContext: () => ({
                        drawImage() {},
                        getImageData: () => ({width: 16, height: 16, data: new Uint8ClampedArray(16 * 16 * 4)})
                    }),
                    toDataURL: () => 'data:image/jpeg;base64,fixture'
                };
            }
        }
    });
    return {
        events, pending, intervals, timeouts, status, messages, firedDelays, videos, playRequests,
        async advance(milliseconds) {
            const target = elapsed + milliseconds;
            await flush();
            for (;;) {
                const next = [...timeouts.entries()].filter(([, timer]) => timer.at <= target)
                    .sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) {
                    break;
                }
                const [key, timer] = next;
                elapsed = timer.at;
                timeouts.delete(key);
                firedDelays.push(timer.delay);
                timer.fn();
                await flush();
            }
            elapsed = target;
            await flush();
        },
        share: () => clicks[0]({preventDefault() {}}),
        screenshot: () => channel.onmessage({data: {type: 'screenshot_request'}}),
        grant(index, displaySurface = 'monitor') {
            const track = {stopped: false, readyState: 'live', stop() { this.stopped = true; this.readyState = 'ended'; },
                getSettings: () => ({displaySurface}), addEventListener() {}};
            pending[index].resolve({getTracks: () => [track], getVideoTracks: () => [track]});
            return track;
        },
        reject: (index, error) => pending[index].reject(error)
    };
}

async function flush() {
    for (let i = 0; i < 8; i++) {
        await Promise.resolve();
    }
}

test('helper pagehide releases tracks and timers; history restore requires a new share', async () => {
    const env = helper();
    const task = env.share();
    const track = env.grant(0);
    await task;
    assert.equal(env.status.textContent, 'ready');
    assert.equal(env.timeouts.size, 0, 'successful playback must cancel its deadline');
    env.events.pagehide();
    assert.equal(track.stopped, true);
    assert.equal(env.intervals.size, 0);
    const count = env.messages.length;
    env.screenshot();
    assert.equal(env.messages.length, count, 'suspended helper must not send cached screenshots');
    env.events.pageshow({persisted: true});
    assert.equal(env.intervals.size, 1);
    assert.equal(env.pending.length, 1, 'restoring a page must not silently reacquire screen sharing');
    assert.equal(env.status.textContent, 'stopped');
});

function assertFailedShare(env, track, message, messageStart = 0) {
    assert.equal(env.status.textContent, message);
    assert.equal(track.stopped, true, 'the previous or newly granted screen track must be closed');
    assert.ok(env.videos.every(video => !video.srcObject), 'video elements must release failed streams');
    assert.equal(env.timeouts.size, 0, 'failed sharing must not leave a startup deadline running');
    const updates = env.messages.slice(messageStart).filter(message => message.type === 'status');
    assert.ok(updates.length > 0);
    assert.ok(updates.every(message => !message.ready && !message.marker && message.stopped),
        'failure must never publish ready or retain marker readiness');
    const count = env.messages.length;
    env.screenshot();
    assert.equal(env.messages.length, count, 'failed sharing must not provide a screenshot');
}

for (const [name, expected] of [
    ['NotAllowedError', 'permissiondenied'],
    ['SecurityError', 'permissiondenied'],
    ['NotReadableError', 'captureunavailable'],
    ['InvalidStateError', 'windowinactive'],
    ['private-device-name', 'requestfailed']
]) {
    test(`screen request ${name} has safe diagnostics and clears previous readiness`, async () => {
        const env = helper();
        const previous = env.share();
        const previousTrack = env.grant(0);
        await previous;
        assert.equal(env.messages.at(-1).ready, true);
        const messageStart = env.messages.length;
        const task = env.share();
        env.reject(1, {name, message: 'private browser details and device identifier'});
        await task;
        assertFailedShare(env, previousTrack, expected, messageStart);
        assert.ok(!JSON.stringify(env.messages.slice(messageStart)).includes('private'));
    });
}

test('screen playback rejection is distinct from permission failure and releases the granted stream', async () => {
    const env = helper({playError: {name: 'NotAllowedError', message: 'private playback details'}});
    const task = env.share();
    const track = env.grant(0);
    await task;
    assertFailedShare(env, track, 'playbackfailed');
    assert.equal(env.firedDelays.length, 0, 'playback failure must not wait for a frame or deadline');
});

test('a shared stream without frames fails within a bounded wait and closes the stream', async () => {
    const env = helper({noframes: true});
    const task = env.share();
    const track = env.grant(0);
    await env.advance(2500);
    await task;
    assertFailedShare(env, track, 'noframes');
    const delayTotal = env.firedDelays.reduce((total, delay) => total + delay, 0);
    assert.ok(delayTotal > 0 && delayTotal <= 2500, 'no-frame handling must have a short finite deadline');
    assert.ok(env.firedDelays.length <= 25, 'no-frame handling must not leave an unbounded retry loop');
});

for (const outcome of ['resolve', 'reject']) {
    test(`hanging playback stops after five seconds and ignores a late ${outcome}`, async () => {
        const env = helper({deferPlay: true});
        const task = env.share();
        const track = env.grant(0);
        await env.advance(4999);
        assert.equal(track.stopped, false, 'startup should still be pending before its deadline');
        assert.ok(!env.messages.some(message => message.ready));
        assert.equal(env.playRequests.length, 1);
        await env.advance(1);
        await task;
        assertFailedShare(env, track, 'noframes');
        assert.deepEqual(env.firedDelays, [5000]);
        const messageCount = env.messages.length;
        env.playRequests[0][outcome](new Error('private late playback detail'));
        await flush();
        assert.equal(env.messages.length, messageCount, 'late playback must not publish or resume sharing');
        assert.equal(env.status.textContent, 'noframes');
        assert.equal(track.stopped, true);
        assert.equal(env.timeouts.size, 0);
    });
}

test('navigation cancels pending playback immediately and late failure cannot stop a restored share', async () => {
    const env = helper({deferPlay: true});
    const first = env.share();
    const oldTrack = env.grant(0);
    await flush();
    assert.equal(env.playRequests.length, 1);
    assert.equal(env.timeouts.size, 1);
    env.events.pagehide();
    await first;
    assert.equal(oldTrack.stopped, true);
    assert.equal(env.timeouts.size, 0);
    assert.equal(env.intervals.size, 0);
    assert.equal(env.firedDelays.length, 0, 'navigation should not wait for the playback deadline');
    env.events.pageshow({persisted: true});
    const second = env.share();
    const newTrack = env.grant(1);
    await flush();
    env.playRequests[1].resolve();
    await second;
    assert.equal(env.status.textContent, 'ready');
    const messageCount = env.messages.length;
    env.playRequests[0].reject(new Error('private old playback detail'));
    await flush();
    assert.equal(env.messages.length, messageCount);
    assert.equal(env.status.textContent, 'ready');
    assert.equal(newTrack.stopped, false);
    assert.equal(env.timeouts.size, 0);
    env.events.pagehide();
    assert.equal(newTrack.stopped, true);
});

test('diagnostics do not accept a window in place of the entire screen', async () => {
    const env = helper();
    const task = env.share();
    const track = env.grant(0, 'window');
    await task;
    assertFailedShare(env, track, 'entirescreenrequired');
});

test('a working entire-screen stream still requires the configured quiz marker', async () => {
    const env = helper({markerrequired: true});
    const task = env.share();
    const track = env.grant(0);
    await task;
    assert.equal(track.stopped, false);
    assert.equal(env.status.textContent, 'wrongmonitor');
    assert.equal(env.messages.at(-1).marker, false);
    assert.ok(!env.messages.some(message => message.type === 'status' && message.ready && message.marker));
    env.events.pagehide();
    assert.equal(track.stopped, true);
});

test('a pending screen grant is released even if the helper page returns before permission resolves', async () => {
    const env = helper();
    const task = env.share();
    env.events.pagehide();
    env.events.pageshow({persisted: true});
    const track = env.grant(0);
    await task;
    assert.equal(track.stopped, true);
    assert.equal(env.messages.at(-1).ready, false);
});

test('late permission from an older share request cannot replace a newer live stream', async () => {
    const env = helper();
    const first = env.share();
    const second = env.share();
    const newerTrack = env.grant(1);
    await second;
    const olderTrack = env.grant(0);
    await first;
    assert.equal(olderTrack.stopped, true);
    assert.equal(newerTrack.stopped, false);
    env.events.pagehide();
    assert.equal(newerTrack.stopped, true);
});
