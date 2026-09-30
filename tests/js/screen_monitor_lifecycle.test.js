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
function helper() {
    const events = {};
    const clicks = [];
    const pending = [];
    const intervals = new Map();
    const messages = [];
    let id = 0;
    let channel;
    const status = {};
    const source = fs.readFileSync(path.resolve(__dirname, '../../screenmonitor.php'), 'utf8')
        .match(/\$js = <<<JS\r?\n([\s\S]*?)\r?\nJS;/)[1];
    const config = {channel: 'fixture', statuskey: 'fixture', markerrequired: false, strings: {
        ready: 'ready', stopped: 'stopped', denied: 'denied', unsupported: 'unsupported'
    }};
    const window = {
        localStorage: {setItem() {}},
        BroadcastChannel: true,
        setInterval(fn) { intervals.set(++id, fn); return id; },
        clearInterval(key) { intervals.delete(key); },
        setTimeout(fn) { queueMicrotask(fn); },
        addEventListener(name, fn) { events[name] = fn; }
    };
    vm.runInNewContext(source.replace('(%s)', '(' + JSON.stringify(config) + ')'), {
        window, Date, Promise,
        BroadcastChannel: class {
            constructor() { channel = this; }
            postMessage(value) { messages.push(value); }
        },
        navigator: {mediaDevices: {
            getDisplayMedia() { return new Promise(resolve => pending.push(resolve)); }
        }},
        document: {
            getElementById(id) {
                return id.endsWith('-status') ? status : {addEventListener(name, fn) { clicks.push(fn); }};
            },
            createElement() {
                return {videoWidth: 1280, videoHeight: 720, play: () => Promise.resolve(),
                    getContext: () => ({drawImage() {}}), toDataURL: () => 'data:image/jpeg;base64,fixture'};
            }
        }
    });
    return {
        events, pending, intervals, status, messages,
        share: () => clicks[0]({preventDefault() {}}),
        screenshot: () => channel.onmessage({data: {type: 'screenshot_request'}}),
        grant(index) {
            const track = {stopped: false, stop() { this.stopped = true; },
                getSettings: () => ({displaySurface: 'monitor'}), addEventListener() {}};
            pending[index]({getTracks: () => [track], getVideoTracks: () => [track]});
            return track;
        }
    };
}

test('helper pagehide releases tracks and timers; history restore requires a new share', async () => {
    const env = helper();
    const task = env.share();
    const track = env.grant(0);
    await task;
    assert.equal(env.status.textContent, 'ready');
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
