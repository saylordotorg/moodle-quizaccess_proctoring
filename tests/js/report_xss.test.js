/* eslint-disable */
// This file is part of Moodle - http://moodle.org/
// Licensed under the GNU GPL v3 or later, http://www.gnu.org/copyleft/gpl.html.

/** Regression tests for the real source and shipped AMD modules' HTML sinks. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const escape = value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// A small DOM serializer. Setting attributes and text must remain distinguishable from HTML.
function element(tag) {
    const attributes = {};
    const children = [];
    return {
        style: {},
        textContent: '',
        setAttribute(name, value) { attributes[name] = String(value); },
        appendChild(child) { children.push(child); },
        get outerHTML() {
            const attrs = Object.entries(attributes).map(([name, value]) => ` ${name}="${escape(value)}"`).join('');
            const open = `<${tag}${attrs}>`;
            return tag === 'img' ? open : open + escape(this.textContent) +
                children.map(child => child.outerHTML).join('') + `</${tag}>`;
        }
    };
}

function source(name, built) {
    return fs.readFileSync(path.resolve(__dirname, '../../amd', built ? 'build' : 'src',
        name + (built ? '.min.js' : '.js')), 'utf8');
}

for (const built of [false, true]) {
    const label = built ? 'shipped AMD' : 'source';
    test(`profile image modal keeps malicious names and URL attributes inert (${label})`, async () => {
        const name = '" onload="alert(1)" x="<img src=x onerror=alert(2)>';
        const imageurl = '/pluginfile.php/picture.png?name=" onerror="alert(3)';
        let click;
        let settings;
        let shown = false;
        const ModalFactory = {
            types: {DEFAULT: 'default'},
            create(value) { settings = value; return Promise.resolve({show() { shown = true; }}); }
        };
        const context = {
            document: {
                createElement: element,
                querySelectorAll() {
                    return [{
                        getAttribute: key => key === 'data-imgsrc' ? imageurl : name,
                        addEventListener(type, callback) { assert.equal(type, 'click'); click = callback; }
                    }];
                }
            },
            ModalFactory,
            define(...args) {
                const exports = {};
                args.at(-1)(exports, ModalFactory);
                context.init = exports.init;
            }
        };
        let code = source('userpic_modal', built);
        if (!built) {
            code = code.replace(/^import .*;\s*/m, '').replace('export const init =', 'globalThis.init =');
        }
        vm.runInNewContext(code, context);
        context.init();
        await click();
        assert.equal(settings.title, `<span>${escape(name)}</span>`);
        assert.equal(settings.body, `<div><img src="${escape(imageurl)}" alt="${escape(name)}"></div>`);
        assert.ok(shown);
    });

    test(`lightbox renders decoded captions as text (${label})`, () => {
        let module;
        let caption;
        const chain = {
            ready() {}, // Do not build the unrelated widget DOM.
            fadeIn() { return this; }, hide() { return this; }, removeClass() { return this; },
            find() { return this; },
            text(value) { caption = value; return this; },
            html() { assert.fail('Untrusted captions must not reach an HTML sink'); }
        };
        const jquery = () => chain;
        jquery.extend = Object.assign;
        vm.runInNewContext(source('lightbox2', built), {
            document: {},
            define(...args) { module = args.at(-1)(jquery); }
        });
        const lightbox = module.init('', {});
        const payload = '<img src=x onerror=alert(document.cookie)>';
        lightbox.album = [{title: payload}];
        lightbox.currentImageIndex = 0;
        lightbox.$lightbox = chain;
        lightbox.$outerContainer = chain;
        lightbox.updateDetails();
        assert.equal(caption, payload);
    });
}
