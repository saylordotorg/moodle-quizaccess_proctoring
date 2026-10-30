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
    for (const input of ['', '2+', '(2', '2)', 'alert(1)', '1/0', '2..3', 'Math.PI', 'sin30', 'sqrt', '(-1)!', '2.5!', 'tan(90)', 'log(0)', 'asin(2)',
        '√(-4)', 'Ans', 'constructor', 'sinh(1)']) {
        assert.ok(Number.isNaN(calc.evaluate(input)), input);
    }
});

test('after a result, operators carry on from it and anything else starts afresh', () => {
    const calc = load();
    for (const input of ['×', '÷', '+', '−', '^', '!', '%', ')', '*', '/', '-']) {
        assert.ok(calc.continuesAnswer(input), input);
    }
    for (const input of ['3', '.', '(', 'π', 'e', 'sin(', '√(', 'Ans']) {
        assert.ok(!calc.continuesAnswer(input), input);
    }
});

test('scientific functions, constants and powers', () => {
    const calc = load();
    const near = (input, expected, options) => assert.strictEqual(calc.format(calc.evaluate(input, options)), expected, input);
    // Degrees by default, exact where the answer is 0 or 1.
    near('sin(30)', '0.5');
    near('sin(180)', '0');
    near('cos(90)', '0');
    near('cos(-360)', '1');
    near('tan(45)', '1');
    near('asin(1)', '90');
    near('atan(1)', '45');
    near('sin(π/2)', '1', {radians: true});
    near('acos(-1)', String(parseFloat(Math.PI.toPrecision(12))), {radians: true});
    near('log(1000)', '3');
    near('ln(e)', '1');
    near('√(16)+√9', '7');
    near('2^10', '1024');
    near('2^3^2', '512');
    near('2^-1', '0.5');
    near('-2^2', '-4');
    near('(-2)^2', '4');
    near('5!', '120');
    near('3!^2', '36');
    near('50%×80', '40');
    near('2π', String(parseFloat((2 * Math.PI).toPrecision(12))));
    near('3(4+1)', '15');
    near('2sin(30)', '1');
    near('abs(-3)', '3');
    near('Ans×2', '14', {ans: 7});
    near('SIN(30)', '0.5');
    // 2e3 is a number in exponent form; e alone is the constant.
    near('2e3', '2000');
    near('2×e', String(parseFloat((2 * Math.E).toPrecision(12))));
});
