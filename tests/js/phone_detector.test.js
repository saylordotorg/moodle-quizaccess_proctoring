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
 * Tests for amd/src/phoneDetector.js (CPIT-484).
 *
 * face-api.js keeps TensorFlow.js core 1.7's engine in window._tfengine, and TensorFlow.js 4
 * adopted it, so COCO-SSD failed to load on the attempt page and phone detection never started.
 * The detector now runs in an iframe: these tests give the module a fake page whose iframe is a
 * separate vm context, run "inline scripts" there the way a browser does (synchronously, inside
 * appendChild, with a thrown error reported to that window's error listeners), and check that
 * nothing reaches the page's own globals.
 *
 * Run: node --test tests/js/phone_detector.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const SOURCE = fs.readFileSync(path.resolve(__dirname, '..', '..', 'amd', 'src', 'phoneDetector.js'), 'utf8');
const BASE = '/lib/objectdetect';

// The deployed bundles' wrapper shape; TensorFlow.js claims the global engine as the real one does.
const TF = '!function(e,t){"object"==typeof exports&&"undefined"!=typeof module?t(exports)'
    + ':"function"==typeof define&&define.amd?define(["exports"],t)'
    + ':t((e="undefined"!=typeof globalThis?globalThis:e||self).tf=e.tf||{})}'
    + '(this,(function(e){e.version="4.22.0";globalThis._tfengine=globalThis._tfengine||{owner:"tf4"};}));';
const COCO = 'globalThis.cocoSsd={load:async function(o){globalThis.modelUrl=o.modelUrl;'
    + 'return {detect:async function(c){globalThis.detected=(globalThis.detected||[]).concat([c]);'
    + 'return [{class:"cell phone",score:0.9}];}};}};';

function makeRealm(responses) {
    const listeners = {};
    const realm = {
        console,
        Promise,
        Error,
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        removeEventListener(type, fn) { listeners[type] = (listeners[type] || []).filter((f) => f !== fn); },
    };
    realm.window = realm;
    realm.self = realm;
    realm.globalThis = realm;
    const context = vm.createContext(realm);
    const drawn = [];
    realm.document = {
        createElement(tag) {
            if (tag === 'canvas') {
                const canvas = {width: 0, height: 0, owner: 'frame'};
                canvas.getContext = () => ({drawImage(source, x, y, w, h) { drawn.push({source, w, h}); }});
                return canvas;
            }
            return {text: ''};
        },
        head: {
            appendChild(script) {
                try {
                    vm.runInContext(script.text, context);
                } catch (error) {
                    (listeners.error || []).forEach((fn) => fn({error, message: error.message}));
                }
            },
        },
    };
    return {realm, listeners, drawn};
}

function makePage(responses) {
    const frame = makeRealm(responses);
    const page = {
        // face-api.js's engine, already on the page.
        _tfengine: {owner: 'face-api 1.7'},
        fetch: async function(src) {
            const body = responses[src];
            return body === undefined
                ? {ok: false, status: 404, text: async () => ''}
                : {ok: true, status: 200, text: async () => body};
        },
    };
    page.window = page;
    const iframes = [];
    page.document = {
        createElement(tag) {
            const element = {style: {}, attributes: {}, listeners: {}};
            element.setAttribute = (name, value) => { element.attributes[name] = value; };
            element.addEventListener = (type, fn) => { element.listeners[type] = fn; };
            return element;
        },
        body: {
            appendChild(element) {
                iframes.push(element);
                element.contentWindow = frame.realm;
                // The frame's srcdoc document loads after the current task.
                setTimeout(() => element.listeners.load(), 0);
            },
        },
    };

    let module = null;
    vm.runInContext(SOURCE, vm.createContext({
        define(deps, factory) { module = factory(); },
        window: page,
        document: page.document,
        Error,
        Promise,
    }));
    return {page, frame, iframes, module};
}

test('the libraries load into the frame and never touch the page or face-api\'s engine', async () => {
    const env = makePage({[BASE + '/tf.min.js']: TF, [BASE + '/coco-ssd.min.js']: COCO});

    await env.module.load(BASE);

    assert.strictEqual(env.iframes.length, 1);
    assert.strictEqual(env.iframes[0].style.display, 'none');
    assert.strictEqual(env.iframes[0].attributes['aria-hidden'], 'true');
    assert.ok(env.iframes[0].srcdoc.includes('<html>'), 'a srcdoc document, not the replaceable about:blank');
    assert.strictEqual(env.frame.realm.tf.version, '4.22.0');
    assert.strictEqual(env.frame.realm._tfengine.owner, 'tf4', 'TensorFlow.js 4 has an engine of its own');
    assert.strictEqual(env.page.tf, undefined, 'nothing lands on the quiz page');
    assert.strictEqual(env.page._tfengine.owner, 'face-api 1.7', 'face-api.js keeps its engine');
    assert.strictEqual(env.frame.realm.modelUrl, BASE + '/model/model.json');
});

test('a check draws the frame onto the iframe\'s canvas and detects on that', async () => {
    const env = makePage({[BASE + '/tf.min.js']: TF, [BASE + '/coco-ssd.min.js']: COCO});
    const detector = await env.module.load(BASE);
    const video = {videoWidth: 640, videoHeight: 480};

    const predictions = await detector.detect(video);

    assert.strictEqual(predictions[0].class, 'cell phone');
    assert.strictEqual(env.frame.drawn.length, 1);
    assert.strictEqual(env.frame.drawn[0].source, video);
    assert.strictEqual(env.frame.drawn[0].w, 640);
    const detected = env.frame.realm.detected[0];
    assert.strictEqual(detected.owner, 'frame', 'the model gets a canvas of its own window');
    assert.strictEqual(detected.width, 640);
    assert.strictEqual(detected.height, 480);
});

test('a video with no frame yet is not passed to the model', async () => {
    const env = makePage({[BASE + '/tf.min.js']: TF, [BASE + '/coco-ssd.min.js']: COCO});
    const detector = await env.module.load(BASE);

    // An array of the module's own context, so compared by length rather than by prototype.
    assert.strictEqual((await detector.detect({videoWidth: 0, videoHeight: 0})).length, 0);
    assert.strictEqual(env.frame.realm.detected, undefined);
});

test('a library that throws rejects, with its error, and leaves no listener behind', async () => {
    const env = makePage({[BASE + '/tf.min.js']: 'throw new Error("boom");', [BASE + '/coco-ssd.min.js']: COCO});

    await assert.rejects(env.module.load(BASE), /boom/);
    assert.strictEqual((env.frame.listeners.error || []).length, 0);
});

test('a missing file rejects', async () => {
    const env = makePage({[BASE + '/tf.min.js']: TF});

    await assert.rejects(env.module.load(BASE), /HTTP 404/);
});
