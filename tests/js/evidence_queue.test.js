/* eslint-disable */
// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function load(built = false) {
    let module;
    const filename = path.resolve(__dirname, '../../amd', built ? 'build/evidenceQueue.min.js' : 'src/evidenceQueue.js');
    vm.runInNewContext(fs.readFileSync(filename, 'utf8'), {
        define(...args) { module = args.at(-1)(); },
        Date, Math, Promise, crypto: {randomUUID: () => 'fixture-' + Math.random().toString(36).slice(2)}
    });
    return module;
}

async function settle() {
    for (let i = 0; i < 6; i++) await Promise.resolve();
}

function fixture(options = {}, built = false) {
    let time = 1000000;
    let online = true;
    let id = 0;
    const timers = new Map();
    const requests = [];
    const outcomes = [];
    const states = [];
    const queue = load(built).create(Object.assign({
        now: () => time,
        online: () => online,
        setTimeout(fn, delay) { timers.set(++id, {fn, due: time + delay}); return id; },
        clearTimeout(timer) { timers.delete(timer); },
        onState(state) { states.push(state); },
        send(request) {
            requests.push(request);
            const result = outcomes.shift();
            return result ? result() : Promise.resolve({warnings: []});
        }
    }, options));
    return {
        queue, timers, requests, outcomes, states,
        offline() { online = false; queue.flush(); },
        online() { online = true; queue.flush(); },
        async advance(ms) {
            time += ms;
            for (const [key, timer] of Array.from(timers)) {
                if (timer.due <= time) { timers.delete(key); timer.fn(); await settle(); }
            }
            await settle();
        }
    };
}
const request = (size = 10) => ({methodname: 'quizaccess_proctoring_send_camshot', args: {
    screenshotid: 5, webcampicture: 'x'.repeat(size)
}});

for (const built of [false, true]) {
    const suffix = built ? 'shipped AMD' : 'source';
    test(`temporary failure retries once with original capture time and request ID (${suffix})`, async () => {
        const env = fixture({}, built);
        env.outcomes.push(() => Promise.reject('timeout'));
        env.queue.enqueue(request(), 991);
        await settle();
        assert.equal(env.requests.length, 1);
        assert.equal(env.queue.snapshot().retrying, true);
        await env.advance(1999);
        assert.equal(env.requests.length, 1);
        await env.advance(1);
        assert.equal(env.requests.length, 2);
        assert.equal(env.requests[0].args.requestid, env.requests[1].args.requestid);
        assert.equal(env.requests[1].args.capturedat, 991);
        assert.equal(env.queue.snapshot().pending, 0);
        assert.equal(env.queue.snapshot().recovered, 1);
    });

    test(`auth failures clear sensitive payloads and never retry (${suffix})`, async () => {
        for (const error of [{errorcode: 'invalidsesskey'}, {errorcode: 'nopermissions'}, {status: 403},
            {errorcode: 'servicerequireslogin'}]) {
            const env = fixture({}, built);
            env.outcomes.push(() => Promise.reject(error));
            env.queue.enqueue(request());
            env.queue.enqueue(request());
            await settle();
            assert.equal(env.queue.snapshot().auth, true);
            assert.equal(env.queue.snapshot().pending, 0);
            assert.equal(env.queue.snapshot().bytes, 0);
            assert.equal(env.queue.enqueue(request()), false);
            env.online();
            await env.advance(200000);
            assert.equal(env.requests.length, 1);
        }
    });

    test(`validation failures and warning responses are visible drops (${suffix})`, async () => {
        const env = fixture({}, built);
        env.outcomes.push(() => Promise.reject({errorcode: 'invalidparameter'}));
        env.queue.enqueue(request());
        await settle();
        assert.equal(env.queue.snapshot().problem, 'rejected');
        assert.equal(env.queue.snapshot().dropped, 1);
        env.outcomes.push(() => Promise.resolve({warnings: [{message: 'Rejected'}]}));
        env.queue.enqueue(request());
        await settle();
        assert.equal(env.queue.snapshot().dropped, 2);
        await env.advance(200000);
        assert.equal(env.requests.length, 2);
    });

    test(`offline buffering is bounded, expires, and only sends fresh items on reconnect (${suffix})`, async () => {
        const env = fixture({maxItems: 3, maxBytes: 1500}, built);
        env.offline();
        for (let i = 0; i < 8; i++) env.queue.enqueue(request(100));
        assert.ok(env.queue.snapshot().pending <= 3);
        assert.ok(env.queue.snapshot().bytes <= 1500);
        assert.ok(env.queue.snapshot().dropped > 0);
        assert.equal(env.requests.length, 0);
        await env.advance(120001);
        assert.equal(env.queue.snapshot().pending, 0);
        assert.equal(env.queue.snapshot().bytes, 0);
        env.queue.enqueue(request());
        env.online();
        await settle();
        assert.equal(env.requests.length, 1);
        assert.equal(env.queue.snapshot().pending, 0);
        assert.equal(env.queue.snapshot().recovered, 1, 'offline-only delivery is reported as recovery');
    });

    test(`in-flight payload is bounded and disposal prevents late retries (${suffix})`, async () => {
        let reject;
        const env = fixture({maxItems: 2, maxBytes: 1000}, built);
        env.outcomes.push(() => new Promise((resolve, fail) => { reject = fail; }));
        env.queue.enqueue(request(100));
        for (let i = 0; i < 5; i++) env.queue.enqueue(request(100));
        assert.equal(env.requests.length, 1);
        assert.ok(env.queue.snapshot().pending <= 2);
        assert.ok(env.queue.snapshot().bytes <= 1000);
        env.queue.dispose();
        reject('timeout');
        await settle();
        await env.advance(500000);
        assert.equal(env.queue.snapshot().pending, 0);
        assert.equal(env.timers.size, 0);
        assert.equal(env.requests.length, 1);
    });

    test(`temporary server errors back off and expire without an unbounded retry (${suffix})`, async () => {
        for (const failure of [{status: 503}, {status: 429}, 'Internal Server Error']) {
            const env = fixture({ttl: 3000}, built);
            env.outcomes.push(() => Promise.reject(failure), () => Promise.reject(failure));
            env.queue.enqueue(request());
            await settle();
            await env.advance(2000);
            assert.equal(env.requests.length, 2);
            await env.advance(1000);
            assert.equal(env.queue.snapshot().pending, 0);
            assert.equal(env.queue.snapshot().problem, 'expired');
            await env.advance(100000);
            assert.equal(env.requests.length, 2);
        }
    });

    test(`the server's rate limit is retried, not dropped as rejected (${suffix})`, async () => {
        // The flood-protection contract (CPIT-503): 'ratelimitexceeded' means slow down and try
        // again. Treating it as a permanent rejection dropped every flooded upload for good,
        // including the student-message log entries.
        const env = fixture({}, built);
        env.outcomes.push(() => Promise.reject({errorcode: 'ratelimitexceeded'}));
        env.queue.enqueue(request());
        await settle();
        assert.equal(env.queue.snapshot().retrying, true);
        assert.equal(env.queue.snapshot().dropped, 0);
        await env.advance(2000);
        assert.equal(env.requests.length, 2);
        assert.equal(env.queue.snapshot().pending, 0);
        assert.equal(env.queue.snapshot().recovered, 1);
    });

    test(`drops and recoveries carry their time, so banners can stand down (${suffix})`, async () => {
        const env = fixture({}, built);
        assert.equal(env.queue.snapshot().lastdropat, 0);
        env.outcomes.push(() => Promise.reject({status: 400}));
        env.queue.enqueue(request());
        await settle();
        const afterdrop = env.queue.snapshot();
        assert.equal(afterdrop.problem, 'rejected');
        assert.equal(afterdrop.lastdropat, 1000000, 'the drop is stamped with the queue clock');
        env.outcomes.push(() => Promise.reject({status: 503}));
        env.queue.enqueue(request());
        await settle();
        await env.advance(2000);
        const afterrecovery = env.queue.snapshot();
        assert.equal(afterrecovery.recovered, 1);
        assert.ok(afterrecovery.lastrecoveredat >= afterrecovery.lastdropat,
            'the recovery is stamped after the drop');
    });

    test(`capacity pressure drops mouse boundary context before captured evidence (${suffix})`, async () => {
        let resolveactive;
        const env = fixture({maxItems: 3}, built);
        env.outcomes.push(() => new Promise(resolve => { resolveactive = resolve; }));
        const mouse = {methodname: 'quizaccess_proctoring_log_event', args: {
            eventtype: 'mouse_left_window', eventdetail: '{}'
        }};
        const evidence = (name) => ({methodname: 'quizaccess_proctoring_log_event', args: {
            eventtype: 'away_capture', eventdetail: name, screenshot: 'x'.repeat(20)
        }});
        env.queue.enqueue(evidence('first'), 991); // Becomes the active upload.
        env.queue.enqueue(mouse, 992);
        env.queue.enqueue(evidence('second'), 993);
        env.queue.enqueue(evidence('third'), 994); // Over capacity: the mouse event goes, not 'second'.
        assert.equal(env.queue.snapshot().pending, 3);
        assert.equal(env.queue.snapshot().dropped, 1);
        assert.equal(env.queue.snapshot().problem, 'capacity');
        resolveactive({warnings: []});
        await settle();
        // Each advance only fires the timers that existed when it began; the queue sends one
        // upload at a time, so each remaining item needs its own tick.
        await env.advance(1000);
        await env.advance(1000);
        const sent = env.requests.map(sentrequest => sentrequest.args.eventtype);
        assert.deepEqual(sent, ['away_capture', 'away_capture', 'away_capture'],
            'every capture is delivered and the mouse event is the one dropped');
    });

    test(`a diagnostic log never evicts evidence, and its discard is not evidence loss (${suffix})`, async () => {
        // The coverage-banner logs travel through the same queue they report on: letting one
        // evict a capture - or counting its own discard as a drop - re-raises the banner it was
        // logging, evicting more captures each cycle.
        let resolveactive;
        const env = fixture({maxItems: 3}, built);
        env.outcomes.push(() => new Promise(resolve => { resolveactive = resolve; }));
        const evidence = (name) => ({methodname: 'quizaccess_proctoring_log_event', args: {
            eventtype: 'away_capture', eventdetail: name, screenshot: 'x'.repeat(20)
        }});
        const coverage = {methodname: 'quizaccess_proctoring_log_event', args: {
            eventtype: 'warning_shown', eventdetail: '{"key":"coverage:lost"}'
        }};
        env.queue.enqueue(evidence('first'), 991); // Becomes the active upload.
        env.queue.enqueue(evidence('second'), 992);
        env.queue.enqueue(evidence('third'), 993);
        // Full queue: the diagnostic is refused outright, silently.
        assert.equal(env.queue.enqueue(coverage, 994, {expendable: true}), false);
        assert.equal(env.queue.snapshot().pending, 3, 'no capture was evicted for the diagnostic');
        assert.equal(env.queue.snapshot().dropped, 0, 'its discard is not counted as evidence loss');
        // With room, a queued diagnostic gives its place back to arriving evidence, silently.
        resolveactive({warnings: []});
        await settle();
        assert.equal(env.queue.enqueue(coverage, 995, {expendable: true}), true);
        env.queue.enqueue(evidence('fourth'), 996);
        assert.equal(env.queue.snapshot().dropped, 0, 'the diagnostic made way without counting as a drop');
        await env.advance(1000);
        await env.advance(1000);
        await env.advance(1000);
        const sent = env.requests.map(sentrequest => sentrequest.args.eventtype);
        assert.deepEqual(sent, ['away_capture', 'away_capture', 'away_capture', 'away_capture'],
            'every capture is delivered; only the diagnostic stood aside');
    });

    test(`an expired or refused diagnostic is not evidence loss either (${suffix})`, async () => {
        const coverage = (key) => ({methodname: 'quizaccess_proctoring_log_event', args: {
            eventtype: 'warning_shown', eventdetail: key
        }});
        // Expiry: queued offline past the TTL.
        const env = fixture({ttl: 3000}, built);
        env.offline();
        env.queue.enqueue(coverage('a'), 991, {expendable: true});
        await env.advance(3001);
        assert.equal(env.queue.snapshot().pending, 0);
        assert.equal(env.queue.snapshot().dropped, 0, 'an expired diagnostic is silent');
        assert.equal(env.queue.snapshot().lastdropat, 0);
        // Refused delivery: the server rejects the diagnostic itself.
        const env2 = fixture({}, built);
        env2.outcomes.push(() => Promise.reject({status: 400}));
        env2.queue.enqueue(coverage('b'), 992, {expendable: true});
        await settle();
        assert.equal(env2.queue.snapshot().pending, 0);
        assert.equal(env2.queue.snapshot().dropped, 0, 'a refused diagnostic is silent');
        assert.equal(env2.queue.snapshot().problem, '');
    });
}
