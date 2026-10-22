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
 * The "Messages to students" edit page's preview and placeholder buttons (CPIT-512).
 *
 * Loads the real amd/src/messagePreview.js (and the shipped build) with a minimal DOM: sample
 * values fill only real placeholders, the preview keeps line breaks and links addresses without
 * ever parsing typed text as HTML, and Insert puts a placeholder at the cursor of the field it
 * belongs in.
 *
 * Run: node --test tests/js/message_preview.test.js
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

/** Just enough of a DOM: elements with children, text and attributes. */
const makeDocument = function() {
    const doc = {
        byId: {},
        buttons: [],
        previews: {},
        createElement(tag) {
            return makeNode(doc, tag);
        },
        createTextNode(text) {
            return {nodeType: 3, textContent: text};
        },
        getElementById(id) {
            return doc.byId[id] || null;
        },
        querySelector(selector) {
            const match = /data-proctoring-preview="([^"]+)"/.exec(selector);
            return match ? doc.previews[match[1]] || null : null;
        },
        querySelectorAll(selector) {
            return selector === '[data-proctoring-placeholder]' ? doc.buttons : [];
        },
    };
    return doc;
};

const makeNode = function(doc, tag) {
    const listeners = {};
    return {
        tagName: tag.toUpperCase(),
        ownerDocument: doc,
        childNodes: [],
        attributes: {},
        get firstChild() {
            return this.childNodes[0] || null;
        },
        appendChild(child) {
            this.childNodes.push(child);
            return child;
        },
        removeChild(child) {
            this.childNodes.splice(this.childNodes.indexOf(child), 1);
        },
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
        getAttribute(name) {
            return this.attributes[name];
        },
        addEventListener(type, fn) {
            (listeners[type] = listeners[type] || []).push(fn);
        },
        fire(type) {
            (listeners[type] || []).forEach((fn) => fn({preventDefault() {}}));
        },
        focus() {
            this.fire('focus');
        },
    };
};

const makeField = function(doc, id, value) {
    const field = makeNode(doc, 'textarea');
    field.value = value;
    field.selectionStart = value.length;
    field.selectionEnd = value.length;
    field.setSelectionRange = function(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    };
    doc.byId[id] = field;
    return field;
};

/** The preview as text, with links shown as [text -> href] and breaks as newlines. */
const flatten = function(node) {
    return node.childNodes.map((child) => {
        if (child.nodeType === 3) {
            return child.textContent;
        }
        if (child.tagName === 'BR') {
            return '\n';
        }
        return '[' + child.textContent + ' -> ' + child.href + ']';
    }).join('');
};

const load = function(built) {
    const file = path.resolve(__dirname, '../../amd', built ? 'build/messagePreview.min.js' : 'src/messagePreview.js');
    const doc = makeDocument();
    let module = null;
    const sandbox = {
        define(...args) {
            module = args.at(-1)();
        },
        document: doc,
    };
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox);
    return {module, doc};
};

for (const built of [false, true]) {
    const label = built ? 'build' : 'source';

    test(`${label}: sample values fill only real placeholders`, () => {
        const {module} = load(built);
        assert.strictEqual(module.fill('[quiz] in [course] [nope]', {quiz: 'Final Exam', course: 'BUS101'}),
            'Final Exam in BUS101 [nope]');
        assert.strictEqual(module.fill('Held[window].', {window: ''}), 'Held.');
    });

    test(`${label}: the preview keeps lines, links addresses and never parses HTML`, () => {
        const {module, doc} = load(built);
        const preview = doc.createElement('div');
        module.render(preview, '<b>Hi</b>\n- see https://learn.saylor.org/x?a=1.\n- ask help@example.org');
        assert.strictEqual(flatten(preview),
            '<b>Hi</b>\n- see [https://learn.saylor.org/x?a=1 -> https://learn.saylor.org/x?a=1].\n'
            + '- ask [help@example.org -> mailto:help@example.org]');
        // Rendering again replaces, not appends.
        module.render(preview, 'Plain');
        assert.strictEqual(flatten(preview), 'Plain');
    });

    test(`${label}: Insert puts a placeholder where the message has it`, () => {
        const {module, doc} = load(built);
        const subject = makeField(doc, 'id_subject', 'Review complete');
        const body = makeField(doc, 'id_body', 'Hello ');
        doc.previews.subject = doc.createElement('div');
        doc.previews.body = doc.createElement('div');
        const button = makeNode(doc, 'button');
        button.setAttribute('data-proctoring-placeholder', 'quiz');
        doc.buttons.push(button);

        module.init({parts: {subject: [], body: ['quiz', 'course']}, samples: {quiz: 'Final Exam'}});
        assert.strictEqual(flatten(doc.previews.body), 'Hello ');

        // The subject has no [quiz], so even with the cursor there it goes into the message.
        subject.fire('focus');
        button.fire('click');
        assert.strictEqual(subject.value, 'Review complete');
        assert.strictEqual(body.value, 'Hello [quiz]');
        assert.strictEqual(flatten(doc.previews.body), 'Hello Final Exam');

        // At the cursor, replacing a selection.
        body.selectionStart = 0;
        body.selectionEnd = 5;
        button.fire('click');
        assert.strictEqual(body.value, '[quiz] [quiz]');

        // Typing updates the preview.
        body.value = 'Now [quiz]!';
        body.fire('input');
        assert.strictEqual(flatten(doc.previews.body), 'Now Final Exam!');
    });
}
