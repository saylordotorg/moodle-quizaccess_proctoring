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
 * Signs that software is operating the quiz page.
 *
 * Loads the real amd/src/automationSignals.js (and the shipped build) and drives it with a fake
 * page and clock: what a person does with a mouse, keyboard or touch screen reports nothing, each
 * thing a script does reports its own kind of sign, and each kind is reported once per page.
 *
 * Run: node --test tests/js/automation_signals.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const load = function(built) {
    const file = path.resolve(__dirname, '../../amd', built ? 'build/automationSignals.min.js' : 'src/automationSignals.js');
    let module = null;
    const sandbox = {
        define(...args) {
            module = args.at(-1)();
        },
        Object,
        Math,
        Date,
    };
    vm.runInNewContext(fs.readFileSync(file, 'utf8'), sandbox, {filename: file});
    return module;
};

/** Something events can be listened for on and fired at. */
const target = function() {
    const listeners = {};
    return {
        addEventListener(type, fn) {
            (listeners[type] = listeners[type] || []).push(fn);
        },
        fire(type, event) {
            (listeners[type] || []).forEach(fn => fn(Object.assign({type: type}, event)));
        },
    };
};

/** An element inside (or outside) the answer form. */
const element = function(inForm) {
    return {
        closest(selector) {
            return inForm && selector === '#responseform' ? {} : null;
        },
    };
};

/**
 * A page with an answer form, a clock and a record of what was reported.
 *
 * @param {Object} module The loaded module.
 * @param {Object} [options] webdriver: the browser says it is automated; decoy: false to leave it out.
 * @returns {Object}
 */
const page = function(module, options) {
    options = options || {};
    const doc = target();
    const win = target();
    win.navigator = {webdriver: options.webdriver === true};
    const created = [];
    const form = {parentNode: {inserted: [], insertBefore(node) {
        this.inserted.push(node);
    }}};
    doc.querySelector = selector => (selector === '#responseform' ? form : null);
    doc.createElement = function(tag) {
        const node = Object.assign(target(), {tag: tag, children: [], style: {}, attributes: {},
            setAttribute(name, value) {
                this.attributes[name] = value;
            },
            appendChild(child) {
                this.children.push(child);
            }});
        created.push(node);
        return node;
    };
    doc.createTextNode = text => ({text: text});

    const state = {now: 100000, reports: []};
    const watcher = module.create({
        document: doc,
        window: win,
        now: () => state.now,
        decoy: options.decoy,
        report: (kind, detail) => state.reports.push([kind, detail]),
    });
    const answer = element(true);

    return {
        doc: doc,
        win: win,
        form: form,
        answer: answer,
        watcher: watcher,
        decoyBox: () => created.find(node => node.tag === 'input'),
        kinds: () => state.reports.map(report => report[0]),
        reports: state.reports,
        wait(ms) {
            state.now += ms;
        },
        /** A person moves the mouse to a point and clicks an answer there. */
        humanClick(x, y) {
            for (let i = 0; i < 5; i++) {
                doc.fire('mousemove', {isTrusted: true, clientX: x - 5 + i, clientY: y});
            }
            doc.fire('pointerdown', {isTrusted: true, target: answer});
            doc.fire('mousedown', {isTrusted: true, target: answer});
            doc.fire('click', {isTrusted: true, detail: 1, pointerType: 'mouse', clientX: x, clientY: y, target: answer});
            doc.fire('change', {isTrusted: true, target: answer});
        },
    };
};

[false, true].forEach(function(built) {
    const name = built ? 'build' : 'source';
    const module = load(built);

    test(name + ': a person using a mouse reports nothing', function() {
        const p = page(module);
        p.humanClick(100, 100);
        p.wait(4000);
        p.humanClick(400, 300);
        p.wait(4000);
        p.humanClick(120, 500);
        assert.deepStrictEqual(p.kinds(), []);
    });

    test(name + ': a person using the keyboard or a touch screen reports nothing', function() {
        const p = page(module);
        // Space on a radio button: a key press, then a click with no pointer and no position.
        p.doc.fire('keydown', {isTrusted: true, target: p.answer});
        p.doc.fire('click', {isTrusted: true, detail: 0, clientX: 0, clientY: 0, target: p.answer});
        p.doc.fire('change', {isTrusted: true, target: p.answer});
        // Typing.
        p.wait(3000);
        p.doc.fire('keydown', {isTrusted: true, target: p.answer});
        p.doc.fire('input', {isTrusted: true, inputType: 'insertText', target: p.answer});
        // Two taps far apart: touch has no path between them.
        [[50, 50], [600, 400]].forEach(function(at) {
            p.wait(3000);
            p.doc.fire('pointerdown', {isTrusted: true, target: p.answer});
            p.doc.fire('click', {isTrusted: true, detail: 1, pointerType: 'touch', clientX: at[0], clientY: at[1],
                target: p.answer});
            p.doc.fire('change', {isTrusted: true, target: p.answer});
        });
        assert.deepStrictEqual(p.kinds(), []);
    });

    test(name + ': text that arrives without key presses for an ordinary reason reports nothing', function() {
        const p = page(module);
        p.wait(5000);
        // Pasting, a spell-check replacement and input-method composition.
        p.doc.fire('input', {isTrusted: true, inputType: 'insertFromPaste', target: p.answer});
        p.doc.fire('input', {isTrusted: true, inputType: 'insertReplacementText', target: p.answer});
        p.doc.fire('input', {isTrusted: true, inputType: 'insertText', isComposing: true, target: p.answer});
        assert.deepStrictEqual(p.kinds(), []);
    });

    test(name + ': a browser that says it is automated is reported', function() {
        const p = page(module, {webdriver: true});
        assert.deepStrictEqual(p.kinds(), ['webdriver']);
        assert.strictEqual(p.reports[0][1].signal, 'webdriver');
        assert.ok(p.reports[0][1].note.length > 0);
    });

    test(name + ': a click made by a script on the answer form is reported, once', function() {
        const p = page(module);
        p.doc.fire('click', {isTrusted: false, detail: 0, target: p.answer});
        p.doc.fire('click', {isTrusted: false, detail: 0, target: p.answer});
        p.doc.fire('keydown', {isTrusted: false, target: p.answer});
        assert.deepStrictEqual(p.kinds().filter(kind => kind === 'untrusted_input'), ['untrusted_input']);
    });

    test(name + ': a scripted click outside the answer form is not reported', function() {
        const p = page(module);
        p.doc.fire('click', {isTrusted: false, detail: 0, target: element(false)});
        assert.deepStrictEqual(p.kinds(), []);
    });

    test(name + ': an answer that changes with no key press or click before it is reported', function() {
        const p = page(module);
        p.humanClick(100, 100);
        p.wait(5000);
        p.doc.fire('change', {isTrusted: true, target: p.answer});
        assert.deepStrictEqual(p.kinds(), ['answer_without_input']);
    });

    test(name + ': text typed into an answer with no key presses is reported', function() {
        const p = page(module);
        p.wait(5000);
        p.doc.fire('input', {isTrusted: true, inputType: 'insertText', target: p.answer});
        assert.deepStrictEqual(p.kinds(), ['answer_without_input']);
    });

    test(name + ': a pointer that jumps between two clicks is reported', function() {
        const p = page(module);
        p.humanClick(100, 100);
        p.wait(1000);
        // The pointer leaving the answer it was on is not the pointer leaving the page.
        p.doc.fire('mouseleave', {isTrusted: true, target: p.answer});
        // Nor is focus moving from one answer to the next the window losing focus.
        p.win.fire('blur', {target: p.answer});
        p.win.fire('focus', {target: p.answer});
        // The way a script clicks: one move straight onto the target, then press and release.
        p.doc.fire('mousemove', {isTrusted: true, clientX: 500, clientY: 400});
        p.doc.fire('pointerdown', {isTrusted: true, target: p.answer});
        p.doc.fire('click', {isTrusted: true, detail: 1, pointerType: 'mouse', clientX: 500, clientY: 400, target: p.answer});
        p.doc.fire('change', {isTrusted: true, target: p.answer});
        assert.deepStrictEqual(p.kinds(), ['teleport_click']);
        assert.strictEqual(p.reports[0][1].distance, 500);
    });

    test(name + ': two clicks in the same place, or after the window was away, are not a jump', function() {
        const p = page(module);
        p.humanClick(100, 100);
        p.doc.fire('pointerdown', {isTrusted: true, target: p.answer});
        p.doc.fire('click', {isTrusted: true, detail: 2, pointerType: 'mouse', clientX: 102, clientY: 101, target: p.answer});
        // The student switches windows; the pointer moves where the page cannot see it.
        p.win.fire('blur', {target: p.win});
        p.win.fire('focus', {target: p.win});
        p.doc.fire('pointerdown', {isTrusted: true, target: p.answer});
        p.doc.fire('click', {isTrusted: true, detail: 1, pointerType: 'mouse', clientX: 700, clientY: 500, target: p.answer});
        // The pointer leaves the page and comes back somewhere else.
        p.doc.fire('mouseleave', {isTrusted: true, target: p.doc});
        p.doc.fire('pointerdown', {isTrusted: true, target: p.answer});
        p.doc.fire('click', {isTrusted: true, detail: 1, pointerType: 'mouse', clientX: 90, clientY: 80, target: p.answer});
        assert.deepStrictEqual(p.kinds(), []);
    });

    test(name + ': the decoy sits beside the form, hidden from people, and reports when used', function() {
        const p = page(module);
        assert.strictEqual(p.form.parentNode.inserted.length, 1);
        const decoy = p.form.parentNode.inserted[0];
        assert.strictEqual(decoy.attributes['aria-hidden'], 'true');
        assert.match(decoy.style.cssText, /left:-10000px/);
        const box = p.decoyBox();
        assert.strictEqual(box.tabIndex, -1);
        assert.strictEqual(box.name, undefined, 'a control without a name is never submitted');

        box.fire('click', {});
        box.fire('change', {});
        assert.deepStrictEqual(p.kinds(), ['decoy_control']);
    });

    test(name + ': the decoy can be left out', function() {
        const p = page(module, {decoy: false});
        assert.strictEqual(p.form.parentNode.inserted.length, 0);
    });

    test(name + ': every kind of sign has a note for the report', function() {
        ['webdriver', 'untrusted_input', 'answer_without_input', 'teleport_click', 'decoy_control'].forEach(function(kind) {
            assert.ok(module.NOTES[kind], kind);
        });
    });
});
