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
 * Tests for amd/src/globalScript.js (CPIT-484).
 *
 * TensorFlow.js and COCO-SSD are UMD bundles. On a Moodle page RequireJS owns a global AMD
 * `define`, so a plain script tag made them register anonymous modules and leave window.tf /
 * window.cocoSsd unset - phone detection never started. These tests run the real module in a
 * vm context whose global object is a fake window with a RequireJS-style define, and execute
 * "inline scripts" the way a browser does: synchronously, inside appendChild, reporting a
 * thrown error as a window error event rather than to the caller.
 *
 * Run: node --test tests/js/global_script.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const SOURCE = fs.readFileSync(path.resolve(__dirname, '..', '..', 'amd', 'src', 'globalScript.js'), 'utf8');

// The same wrapper shape as the deployed tf.min.js.
const UMD = '!function(e,t){"object"==typeof exports&&"undefined"!=typeof module?t(exports)'
    + ':"function"==typeof define&&define.amd?define(["exports"],t)'
    + ':t((e="undefined"!=typeof globalThis?globalThis:e||self).tf=e.tf||{})}'
    + '(this,(function(e){e.version="4.22.0";try{require("crypto")}catch(x){}}));';

function makeEnv(responses) {
    const listeners = {};
    const amdCalls = [];
    const requireCalls = [];
    const amdDefine = function() { amdCalls.push([...arguments]); };
    amdDefine.amd = {};
    const amdRequire = function(name) { requireCalls.push(name); throw new Error('notloaded ' + name); };

    const window = {
        define: amdDefine,
        require: amdRequire,
        console,
        Promise,
        Error,
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        removeEventListener(type, fn) { listeners[type] = (listeners[type] || []).filter((f) => f !== fn); },
        fetch: async function(src) {
            const body = responses[src];
            return body === undefined
                ? {ok: false, status: 404, text: async () => ''}
                : {ok: true, status: 200, text: async () => body};
        },
    };
    window.window = window;
    window.self = window;
    window.globalThis = window;
    const context = vm.createContext(window);

    window.document = {
        createElement() { return {text: ''}; },
        head: {
            appendChild(script) {
                // An inline script runs now; an uncaught error goes to window 'error' listeners.
                try {
                    vm.runInContext(script.text, context);
                } catch (error) {
                    (listeners.error || []).forEach((fn) => fn({error, message: error.message}));
                }
            },
        },
    };

    let module = null;
    vm.runInContext(SOURCE, vm.createContext({
        define(deps, factory) { module = factory(); },
        window,
        document: window.document,
        Error,
        Promise,
    }));

    return {window, module, amdDefine, amdRequire, amdCalls, requireCalls, listeners};
}

test('a UMD bundle lands on window, not in RequireJS', async () => {
    const env = makeEnv({'/lib/tf.min.js': UMD});

    await env.module.load('/lib/tf.min.js');

    assert.strictEqual(env.window.tf.version, '4.22.0');
    assert.strictEqual(env.amdCalls.length, 0, 'the bundle must not call the AMD define');
    assert.strictEqual(env.requireCalls.length, 0, 'the bundle must not reach the AMD require');
    assert.strictEqual(env.window.define, env.amdDefine, 'define is restored');
    assert.strictEqual(env.window.require, env.amdRequire, 'require is restored');
    assert.strictEqual((env.listeners.error || []).length, 0, 'the error listener is removed');
});

test('a plain script tag is what broke it: the same bundle under define goes to RequireJS', () => {
    const env = makeEnv({});
    vm.runInContext(UMD, vm.createContext(env.window));

    assert.strictEqual(env.window.tf, undefined);
    assert.strictEqual(env.amdCalls.length, 1);
});

test('a script that throws rejects, and define and require are still restored', async () => {
    const env = makeEnv({'/lib/bad.js': 'throw new Error("boom");'});

    await assert.rejects(env.module.load('/lib/bad.js'), /boom/);
    assert.strictEqual(env.window.define, env.amdDefine);
    assert.strictEqual(env.window.require, env.amdRequire);
});

test('a missing file rejects without touching define', async () => {
    const env = makeEnv({});

    await assert.rejects(env.module.load('/lib/missing.js'), /HTTP 404/);
    assert.strictEqual(env.window.define, env.amdDefine);
});
