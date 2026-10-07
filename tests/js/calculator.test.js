/**
 * The on-page calculator's arithmetic (CPIT-482). Run with: node --test tests/js/calculator.test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const test = require('node:test');
const assert = require('node:assert');

const load = () => {
    let factory = null;
    const sandbox = {
        define(deps, fn) {
            factory = fn;
        },
        isFinite,
        parseFloat,
        String,
        Error,
    };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../amd/src/calculator.js'), 'utf8'), sandbox);
    return factory({get_strings: async() => []});
};

test('evaluates arithmetic with precedence, parentheses and unary minus', () => {
    const calc = load();
    assert.strictEqual(calc.evaluate('2+3×4'), 14);
    assert.strictEqual(calc.evaluate('(2+3)×4'), 20);
    assert.strictEqual(calc.evaluate('10÷4'), 2.5);
    assert.strictEqual(calc.evaluate('−3+5'), 2);
    assert.strictEqual(calc.evaluate('-(2-5)*2'), 6);
    assert.strictEqual(calc.format(calc.evaluate('0.1+0.2')), '0.3');
    // A result shown in exponent form can be used again.
    assert.strictEqual(calc.format(calc.evaluate(calc.format(calc.evaluate('1/10000000')) + '*10')), '0.000001');
});

test('rejects anything that is not arithmetic, without evaluating it', () => {
    const calc = load();
    for (const input of ['', '2+', '(2', '2)', 'alert(1)', '1/0', '2..3', 'Math.PI']) {
        assert.ok(Number.isNaN(calc.evaluate(input)), input);
    }
});
