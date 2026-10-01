/* eslint-disable */
// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

/**
 * Quiz-page screen monitor client: trusting the helper's stored status on page load.
 *
 * The helper window runs in the background, where browsers throttle its timers, so the
 * status it last stored can be close to a minute old when the next quiz page loads. Loads
 * the real amd/src/screenMonitorClient.js in a Node vm sandbox with a controllable clock.
 */

'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.resolve(__dirname, '../../amd/src/screenMonitorClient.js'), 'utf8');

function loadClient(storedStatus, now) {
    let client = null;
    const storage = {fixture: storedStatus ? JSON.stringify(storedStatus) : null};
    const window = {
        localStorage: {getItem: (key) => storage[key] || null},
        matchMedia: () => ({matches: false}),
        addEventListener() {},
        setInterval: () => 1,
        clearInterval() {},
        setTimeout: () => 1,
    };
    vm.runInNewContext(SOURCE, {
        define(deps, factory) {
            client = factory();
        },
        window,
        navigator: {userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'},
        Date: {now: () => now},
        JSON,
    });
    return client;
}

function start(storedStatus, ageMs) {
    const now = 1700000000000;
    const calls = [];
    const status = storedStatus ? Object.assign({type: 'status', ts: now - ageMs}, storedStatus) : null;
    const instance = loadClient(status, now).create({screenmonitorstatuskey: 'fixture'}, {
        onReady: () => calls.push('ready'),
        onUnavailable: () => calls.push('unavailable'),
        onWrongScreen: () => calls.push('wrongscreen'),
    });
    instance.start();
    return {calls, isReady: instance.isReady()};
}

const READY = {ready: true, marker: true, stopped: false};

test('a recent ready status is used straight away on page load', () => {
    const result = start(READY, 2000);
    assert.deepEqual(result.calls, ['ready']);
    assert.equal(result.isReady, true);
});

test('a ready status the throttled helper wrote long ago is not acted on at page load', () => {
    const result = start(READY, 15000);
    assert.deepEqual(result.calls, [], 'an old status must neither accept nor fault the share');
    assert.equal(result.isReady, false, 'readiness waits for the helper to answer live');
});

test('a recent stopped status is passed on at page load', () => {
    const result = start({ready: false, marker: false, stopped: true}, 1000);
    assert.deepEqual(result.calls, ['unavailable']);
    assert.equal(result.isReady, false);
});
