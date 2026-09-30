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
}
