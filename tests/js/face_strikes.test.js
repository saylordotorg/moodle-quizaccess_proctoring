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
 * Escalating "face out of frame" warnings that end the attempt (CPIT-510).
 *
 * Loads the real amd/src/faceStrikes.js (and the shipped build) and drives it with a fake clock:
 * which warning each strike shows, that only a long enough pause counts and only once, that a
 * strike is retried until the server has it, that only the server ends the attempt, and that a
 * pause carried across a page change is not counted twice.
 *
 * Run: node --test tests/js/face_strikes.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const load = function(built) {
    const file = path.resolve(__dirname, '../../amd', built ? 'build/faceStrikes.min.js' : 'src/faceStrikes.js');
    let module = null;
    const sandbox = {
        define(...args) {
            module = args.at(-1)();
        },
        window: {},
        Promise,
        JSON,
        Math,
    };
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox);
    return module;
};

/** A manual clock: timers run only when the test advances time. */
const clock = function() {
    let time = 1000000;
    let nextid = 1;
    const timers = new Map();
    return {
        now: () => time,
        setTimeout(fn, ms) {
            const id = nextid++;
            timers.set(id, {at: time + ms, fn});
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        async advance(ms) {
            const until = time + ms;
            for (;;) {
                const due = [...timers.entries()].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) {
                    break;
                }
                timers.delete(due[0]);
                time = due[1].at;
                due[1].fn();
                await flush();
            }
            time = until;
            await flush();
        },
    };
};

const flush = async function() {
    for (let i = 0; i < 10; i++) {
        await Promise.resolve();
    }
};

const memoryStorage = function() {
    const data = {};
    return {
        getItem: (k) => (k in data ? data[k] : null),
        setItem: (k, v) => { data[k] = String(v); },
        removeItem: (k) => { delete data[k]; },
    };
};

/** A server that counts strikes once per request id and ends the attempt at the limit. */
const server = function(limit, initial = 0) {
    const ids = new Set();
    let strikes = initial;
    let ended = false;
    return {
        sent: [],
        failNext: 0,
        send(strike) {
            this.sent.push(strike);
            if (this.failNext > 0) {
                this.failNext--;
                return Promise.reject(new Error('network error'));
            }
            if (!ids.has(strike.requestid)) {
                ids.add(strike.requestid);
                strikes++;
            }
            if (strikes >= limit) {
                ended = true;
            }
            return Promise.resolve({facestrikes: strikes, facestrikelimit: limit, attemptended: ended});
        },
    };
};

const controller = function(module, overrides) {
    const c = overrides.clock;
    const log = {notices: [], ended: 0};
    const ctl = module.create(Object.assign({
        limit: 4,
        strikes: 0,
        minSeconds: 5,
        attemptId: 77,
        now: c.now,
        setTimeout: c.setTimeout,
        clearTimeout: c.clearTimeout,
        notify: (count, limit) => log.notices.push(module.noticeKey(count, limit)),
        end: () => log.ended++,
    }, overrides));
    return {ctl, log};
};

for (const built of [false, true]) {
    const label = built ? 'build' : 'source';

    test(`${label}: each strike shows the warning for its place before the end`, () => {
        const m = load(built);
        const keys = (limit) => Array.from({length: limit}, (_, i) => m.noticeKey(i + 1, limit));
        assert.deepStrictEqual(keys(4),
            ['facestrike:first', 'facestrike:again', 'facestrike:final', 'facestrike:ended']);
        assert.deepStrictEqual(keys(3), ['facestrike:first', 'facestrike:final', 'facestrike:ended']);
        assert.deepStrictEqual(keys(2), ['facestrike:onlywarning', 'facestrike:ended']);
        assert.deepStrictEqual(JSON.parse(JSON.stringify(m.noticeParams(2, 4, 'be held'))),
            {count: 2, warnings: 3, remaining: 2, limit: 4, consequence: 'be held'});
    });

    test(`${label}: only a pause that lasts long enough counts, and only once`, async () => {
        const m = load(built);
        const c = clock();
        const srv = server(4);
        const {ctl, log} = controller(m, {clock: c, send: (s) => srv.send(s)});

        // A short pause, under the five seconds, is not a strike.
        ctl.pauseStarted();
        await c.advance(4900);
        ctl.pauseEnded();
        await c.advance(10000);
        assert.strictEqual(srv.sent.length, 0);
        assert.deepStrictEqual(log.notices, []);

        // A long one counts once, however long it lasts.
        ctl.pauseStarted();
        await c.advance(60000);
        ctl.pauseEnded();
        assert.strictEqual(srv.sent.length, 1);
        assert.deepStrictEqual(log.notices, ['facestrike:first']);

        ctl.pauseStarted();
        await c.advance(5000);
        ctl.pauseEnded();
        assert.deepStrictEqual(log.notices, ['facestrike:first', 'facestrike:again']);
        assert.strictEqual(ctl.state().count, 2);
        assert.strictEqual(log.ended, 0);
    });

    test(`${label}: the attempt ends only when the server says so`, async () => {
        const m = load(built);
        const c = clock();
        // The server already has three strikes from earlier pages.
        const srv = server(4, 3);
        const {ctl, log} = controller(m, {clock: c, strikes: 3, send: (s) => srv.send(s)});

        srv.failNext = 2;
        ctl.pauseStarted();
        await c.advance(5000);
        // Neither told nor ended until the server has it.
        assert.deepStrictEqual(log.notices, []);
        assert.strictEqual(log.ended, 0);

        // Retried with the same request id until it lands.
        await c.advance(10000);
        assert.strictEqual(srv.sent.length, 3);
        assert.strictEqual(new Set(srv.sent.map((s) => s.requestid)).size, 1);
        assert.deepStrictEqual(log.notices, ['facestrike:ended']);
        assert.strictEqual(log.ended, 1);
        assert.strictEqual(ctl.state().ended, true);

        // Nothing after the end counts.
        ctl.pauseEnded();
        ctl.pauseStarted();
        await c.advance(60000);
        assert.strictEqual(srv.sent.length, 3);
        assert.strictEqual(log.ended, 1);
    });

    test(`${label}: a strike still on its way counts, and the end waits for it to land`, async () => {
        const m = load(built);
        const c = clock();
        const srv = server(4, 2);
        const {ctl, log} = controller(m, {clock: c, strikes: 2, send: (s) => srv.send(s)});

        // The third strike fails twice: retried after 2 s, then 5 s more.
        srv.failNext = 2;
        ctl.pauseStarted();
        await c.advance(5000);
        ctl.pauseEnded();
        ctl.pauseStarted();
        // The fourth lands first; the server then has three, so the attempt has not ended yet.
        await c.advance(5100);
        assert.deepStrictEqual(log.notices, ['facestrike:final']);
        assert.strictEqual(ctl.state().count, 4);
        assert.strictEqual(log.ended, 0);

        // The third lands on its retry, and the server ends the attempt.
        await c.advance(2000);
        assert.strictEqual(log.ended, 1);
        assert.deepStrictEqual(log.notices, ['facestrike:final', 'facestrike:ended']);
    });

    test(`${label}: answers arriving out of order are not counted twice`, async () => {
        const m = load(built);
        const c = clock();
        // A server that takes each strike at once but answers only when the test says so.
        let total = 0;
        const answers = [];
        const send = () => {
            total++;
            const snapshot = total;
            return new Promise((resolve) => answers.push(() => resolve({
                facestrikes: snapshot === 2 ? total : snapshot,
                facestrikelimit: 4,
                attemptended: false,
            })));
        };
        const {ctl, log} = controller(m, {clock: c, send});
        for (let i = 0; i < 2; i++) {
            ctl.pauseStarted();
            await c.advance(5000);
            ctl.pauseEnded();
        }
        // The second answer, already counting both, arrives before the first.
        answers[1]();
        await c.advance(10);
        answers[0]();
        await c.advance(10);
        assert.strictEqual(ctl.state().count, 2);

        ctl.pauseStarted();
        await c.advance(5000);
        assert.deepStrictEqual(log.notices, ['facestrike:first', 'facestrike:again', 'facestrike:final']);
    });

    test(`${label}: a strike not yet delivered when the page goes is delivered by the next`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(4);
        srv.failNext = 1;
        const first = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        first.ctl.pauseStarted();
        await c.advance(5000);
        first.ctl.pageLeaving();
        first.ctl.pauseEnded();
        assert.strictEqual(srv.sent.length, 1);

        // The next page loads with the server still at zero, and sends the strike again.
        await c.advance(1000);
        const second = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        await c.advance(10);
        assert.strictEqual(srv.sent.length, 2);
        assert.strictEqual(srv.sent[0].requestid, srv.sent[1].requestid);
        assert.ok(srv.sent[1].capturedat >= srv.sent[0].capturedat, 'Each try carries a fresh capture time.');
        assert.strictEqual(second.ctl.state().count, 1);
        assert.deepStrictEqual(second.log.notices, ['facestrike:first']);

        // Delivered: a third page has nothing left to send.
        controller(m, {clock: c, storage, strikes: 1, send: (s) => srv.send(s)});
        await c.advance(10);
        assert.strictEqual(srv.sent.length, 2);
    });

    test(`${label}: a recovered strike counts toward the next warning before it lands`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(3);
        srv.failNext = 1;
        const first = controller(m, {clock: c, limit: 3, storage, send: (s) => srv.send(s)});
        first.ctl.pauseStarted();
        await c.advance(5000);
        first.ctl.pageLeaving();

        // The next page cannot reach the server for a while: its own strike is the second of three,
        // and the student is told it is the final warning.
        srv.failNext = 100;
        const second = controller(m, {clock: c, limit: 3, storage, send: (s) => srv.send(s)});
        await c.advance(10);
        second.ctl.faceSeen();
        second.ctl.pauseStarted();
        await c.advance(5000);
        assert.deepStrictEqual(second.log.notices, ['facestrike:final']);
        assert.strictEqual(second.log.ended, 0);
    });

    test(`${label}: a pause spanning several pages keeps its whole length`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(4);
        // Twenty seconds on each page, against a sixty-second threshold.
        for (let page = 1; page <= 3; page++) {
            const {ctl} = controller(m, {clock: c, storage, minSeconds: 60, send: (s) => srv.send(s)});
            ctl.pauseStarted();
            await c.advance(20000);
            ctl.pageLeaving();
            ctl.pauseEnded();
            await c.advance(1000);
        }
        assert.strictEqual(srv.sent.length, 1, 'Sixty seconds out of frame across three pages is a strike.');
    });

    test(`${label}: time with the camera dead does not count`, async () => {
        const m = load(built);
        const c = clock();
        const srv = server(4);
        const {ctl} = controller(m, {clock: c, send: (s) => srv.send(s)});
        ctl.pauseStarted();
        await c.advance(3000);
        ctl.cameraLost();
        await c.advance(60000);
        assert.strictEqual(srv.sent.length, 0, 'A camera failure is not a face out of frame.');

        // Back, the pause carries on from the three seconds it had.
        ctl.cameraBack();
        await c.advance(1900);
        assert.strictEqual(srv.sent.length, 0);
        await c.advance(100);
        assert.strictEqual(srv.sent.length, 1);
    });

    test(`${label}: a camera found dead when the strike is due holds the strike back`, async () => {
        const m = load(built);
        const c = clock();
        const srv = server(4);
        let live = true;
        const {ctl} = controller(m, {clock: c, cameraLive: () => live, send: (s) => srv.send(s)});
        ctl.pauseStarted();
        await c.advance(4000);
        live = false;
        await c.advance(30000);
        assert.strictEqual(srv.sent.length, 0);
        live = true;
        ctl.cameraBack();
        await c.advance(10);
        assert.strictEqual(srv.sent.length, 1, 'Back, with the pause still on and its time already up.');
    });

    test(`${label}: a page that left does not time its pause any more`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(4);
        // As the browser's back-forward cache would keep it: the page is hidden but not destroyed.
        const kept = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        kept.ctl.pauseStarted();
        await c.advance(3000);
        kept.ctl.pageLeaving();
        const next = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        next.ctl.pauseStarted();
        await c.advance(30000);
        assert.strictEqual(srv.sent.length, 1, 'One absence, one strike.');
    });

    test(`${label}: a page restored from the back-forward cache carries its pause on`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(4);
        const page = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        page.ctl.pauseStarted();
        await c.advance(6000);
        assert.strictEqual(srv.sent.length, 1);
        page.ctl.pageLeaving();

        // Back without the face ever seen: the same absence, whether or not another page read it.
        await c.advance(3000);
        page.ctl.pauseStarted();
        await c.advance(30000);
        assert.strictEqual(srv.sent.length, 1);
    });

    test(`${label}: a pause carried over a page change is not counted twice`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(4);
        const first = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        first.ctl.pauseStarted();
        await c.advance(6000);
        assert.strictEqual(srv.sent.length, 1);
        first.ctl.pageLeaving();

        // The next page pauses again within moments: the same absence.
        await c.advance(8000);
        const second = controller(m, {clock: c, storage, strikes: 1, send: (s) => srv.send(s)});
        second.ctl.pauseStarted();
        await c.advance(30000);
        assert.strictEqual(srv.sent.length, 1);
        second.ctl.pauseEnded();

        // Once a face has been seen, a new pause is a new strike.
        second.ctl.faceSeen();
        second.ctl.pauseStarted();
        await c.advance(5000);
        assert.strictEqual(srv.sent.length, 2);
    });

    test(`${label}: an uncounted pause carries its time to the next page`, async () => {
        const m = load(built);
        const storage = memoryStorage();
        const c = clock();
        const srv = server(4);
        const first = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        first.ctl.pauseStarted();
        await c.advance(3000);
        first.ctl.pageLeaving();
        // The page is gone, and its timer with it.
        first.ctl.pauseEnded();

        await c.advance(2000);
        const second = controller(m, {clock: c, storage, send: (s) => srv.send(s)});
        second.ctl.pauseStarted();
        await c.advance(2000);
        assert.strictEqual(srv.sent.length, 1, 'Three seconds before and two after make the five.');

        // A pause long after the page change is a pause of its own.
        const third = controller(m, {clock: c, storage, strikes: 1, send: (s) => srv.send(s)});
        await c.advance(60000);
        third.ctl.pauseStarted();
        await c.advance(4000);
        assert.strictEqual(srv.sent.length, 1);
    });

    test(`${label}: nothing runs when the rule is off`, async () => {
        const m = load(built);
        m.configure({limit: 0});
        assert.strictEqual(m.settings(), null);
        m.configure({limit: 4, strikes: 1, minseconds: 5, outcome: 'hold', lockoutdays: 0});
        assert.strictEqual(m.settings().limit, 4);

        const c = clock();
        const srv = server(4);
        const {ctl} = controller(m, {clock: c, limit: 0, send: (s) => srv.send(s)});
        ctl.pauseStarted();
        await c.advance(60000);
        assert.strictEqual(srv.sent.length, 0);
    });
}
