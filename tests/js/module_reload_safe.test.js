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
 * Every AMD module can be loaded twice in one page.
 *
 * Moodle serves all plugins' modules as one script, and sends that whole script again whenever a
 * page requests a single module by URL. A let, const or class declared outside define() is then
 * declared twice, which is a SyntaxError: the browser discards the entire second copy and logs
 * "Identifier '...' has already been declared". proctoring.js did this with isCameraAllowed, and
 * the error showed on Learn pages that had nothing to do with an exam.
 *
 * This runs each module, source and shipped build, twice in one context, as a page would. The
 * stub define() never calls a module's factory, so only its top-level code is exercised.
 *
 * Run: node --test tests/js/module_reload_safe.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const AMD = path.resolve(__dirname, '../../amd');

const scripts = function(dir, suffix) {
    return fs.readdirSync(path.join(AMD, dir))
        .filter((name) => name.endsWith(suffix) && !name.endsWith('.map'))
        .sort()
        .map((name) => path.join(dir, name));
};

// A page with RequireJS present: define() exists and is AMD, and nothing calls the factories.
const page = function() {
    const sandbox = {console};
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    sandbox.globalThis = sandbox;
    sandbox.define = function() {};
    sandbox.define.amd = {};
    return vm.createContext(sandbox);
};

const loadTwice = function(relative) {
    const code = fs.readFileSync(path.join(AMD, relative), 'utf8');
    const context = page();
    vm.runInContext(code, context, {filename: relative});
    vm.runInContext(code, context, {filename: relative});
};

// A source written as an ES module is only ever served as its build, so its build is what counts.
const isEsModule = function(relative) {
    return /^\s*(import|export)\s/m.test(fs.readFileSync(path.join(AMD, relative), 'utf8'));
};

const files = scripts('src', '.js').filter((file) => !isEsModule(file)).concat(scripts('build', '.min.js'));

test('there are modules to check, sources and builds', () => {
    assert.ok(files.some((file) => file.startsWith('src')), 'no source modules found');
    assert.ok(files.some((file) => file.startsWith('build')), 'no built modules found');
    assert.ok(files.includes(path.join('build', 'proctoring.min.js')));
    assert.ok(files.includes(path.join('src', 'proctoring.js')));
});

for (const file of files) {
    test(file + ' can be loaded twice in one page', () => {
        assert.doesNotThrow(() => loadTwice(file));
    });
}

test('the check catches a declaration outside define()', () => {
    const context = page();
    const offending = 'let isCameraAllowed = false; define([], function() {});';
    vm.runInContext(offending, context);
    assert.throws(() => vm.runInContext(offending, context), /already been declared/);
});

test('all the built modules as one script can be loaded twice', () => {
    // The real case: one concatenated script holding every built module, evaluated twice.
    const bundle = scripts('build', '.min.js')
        .map((file) => fs.readFileSync(path.join(AMD, file), 'utf8'))
        .join('\n');
    const context = page();
    vm.runInContext(bundle, context, {filename: 'bundle.js'});
    assert.doesNotThrow(() => vm.runInContext(bundle, context, {filename: 'bundle.js'}));
});
