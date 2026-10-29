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
 * An on-page scientific calculator for proctored quizzes that allow one (CPIT-482).
 *
 * A desktop calculator takes the focus away from the quiz, which the proctoring records as leaving
 * the exam. This one lives on the page, so using it is not a switch away.
 *
 * @module     quizaccess_proctoring/calculator
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str'], function(Str) {
    'use strict';

    /**
     * Sine, cosine and tangent of an angle in degrees, exact where the answer is 0, 1 or -1.
     *
     * Math.sin(Math.PI) is 1.2e-16, not 0, and a student expects sin(180) to be 0.
     *
     * @param {string} name sin, cos or tan.
     * @param {number} degrees Angle.
     * @return {number}
     */
    const trigDegrees = function(name, degrees) {
        const turn = ((degrees % 360) + 360) % 360;
        if (name === 'sin' && turn % 90 === 0) {
            return [0, 1, 0, -1][turn / 90];
        }
        if (name === 'cos' && turn % 90 === 0) {
            return [1, 0, -1, 0][turn / 90];
        }
        if (name === 'tan' && turn % 90 === 0) {
            return turn % 180 === 0 ? 0 : NaN;
        }
        return Math[name](degrees * Math.PI / 180);
    };

    /**
     * n! for a whole number n.
     *
     * @param {number} n
     * @return {number} NaN when n is negative or not whole.
     */
    const factorial = function(n) {
        if (n < 0 || n !== Math.floor(n) || n > 170) {
            return NaN;
        }
        let value = 1;
        for (let i = 2; i <= n; i++) {
            value *= i;
        }
        return value;
    };

    // Functions take their argument in parentheses: sin(30), log(100).
    const FUNCTIONS = {
        asin: (x, deg) => deg ? Math.asin(x) * 180 / Math.PI : Math.asin(x),
        acos: (x, deg) => deg ? Math.acos(x) * 180 / Math.PI : Math.acos(x),
        atan: (x, deg) => deg ? Math.atan(x) * 180 / Math.PI : Math.atan(x),
        sin: (x, deg) => deg ? trigDegrees('sin', x) : Math.sin(x),
        cos: (x, deg) => deg ? trigDegrees('cos', x) : Math.cos(x),
        tan: (x, deg) => deg ? trigDegrees('tan', x) : Math.tan(x),
        sqrt: (x) => Math.sqrt(x),
        abs: (x) => Math.abs(x),
        log: (x) => Math.log10(x),
        ln: (x) => Math.log(x),
    };
    // Longest first, so "asin" is not read as "a" followed by "sin".
    const FUNCTION_NAMES = Object.keys(FUNCTIONS).sort((a, b) => b.length - a.length);

    /**
     * Evaluate a scientific expression, never with eval(): the input is typed by the student.
     *
     * Numbers (also in exponent form, 1.5e-7), + - × ÷, ^ for powers, √, x! and x%, parentheses,
     * the constants π and e, Ans for the previous answer, and the functions sin cos tan asin acos
     * atan (in degrees unless options.radians), log (base 10), ln, sqrt and abs. A number next to a
     * bracket, constant or function multiplies it: 2π, 3(4+1), 2sin(30).
     *
     * @param {string} input Expression.
     * @param {Object} [options]
     * @param {boolean} [options.radians] Angles are in radians, not degrees.
     * @param {number} [options.ans] The value of Ans.
     * @return {number} The value, NaN when the expression is not valid.
     */
    const evaluate = function(input, options) {
        const radians = Boolean(options && options.radians);
        const ans = options && typeof options.ans === 'number' ? options.ans : NaN;
        const text = String(input).replace(/\s+/g, '').replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-')
            .replace(/π/g, 'pi');
        let pos = 0;
        const peek = () => text[pos];
        const fail = () => {
            throw new Error('invalid');
        };
        const word = function(name) {
            return text.slice(pos, pos + name.length).toLowerCase() === name;
        };
        let expression;
        let unary;
        const number = function() {
            // Exponents too: a very small or large result is shown as, say, 1e-7, and can be reused.
            const match = /^(\d+\.?\d*|\.\d+)(e[+-]?\d+)?/i.exec(text.slice(pos));
            if (!match) {
                fail();
            }
            pos += match[0].length;
            return parseFloat(match[0]);
        };
        const bracketed = function() {
            if (peek() !== '(') {
                fail();
            }
            pos++;
            const value = expression();
            if (peek() !== ')') {
                fail();
            }
            pos++;
            return value;
        };
        const primary = function() {
            if (peek() === '(') {
                return bracketed();
            }
            const name = FUNCTION_NAMES.find(word);
            if (name) {
                pos += name.length;
                return FUNCTIONS[name](bracketed(), !radians);
            }
            if (word('ans')) {
                pos += 3;
                if (isNaN(ans)) {
                    fail();
                }
                return ans;
            }
            if (word('pi')) {
                pos += 2;
                return Math.PI;
            }
            if (word('e')) {
                pos++;
                return Math.E;
            }
            return number();
        };
        const postfix = function() {
            let value = primary();
            while (peek() === '!' || peek() === '%') {
                value = text[pos++] === '!' ? factorial(value) : value / 100;
            }
            return value;
        };
        const root = function() {
            if (peek() === '√') {
                pos++;
                return Math.sqrt(root());
            }
            return postfix();
        };
        const power = function() {
            const base = root();
            if (peek() === '^') {
                pos++;
                // Right to left, as on paper: 2^3^2 is 2^9. The exponent may be negative: 2^-1.
                return Math.pow(base, unary());
            }
            return base;
        };
        // Below powers, so -2^2 is -4.
        unary = function() {
            if (peek() === '-') {
                pos++;
                return -unary();
            }
            if (peek() === '+') {
                pos++;
                return unary();
            }
            return power();
        };
        const startsOperand = () => pos < text.length && /[\d(a-z√]/i.test(peek());
        const term = function() {
            let value = unary();
            for (;;) {
                if (peek() === '*' || peek() === '/') {
                    const op = text[pos++];
                    const right = unary();
                    value = op === '*' ? value * right : value / right;
                } else if (startsOperand()) {
                    value *= unary();
                } else {
                    return value;
                }
            }
        };
        expression = function() {
            let value = term();
            while (peek() === '+' || peek() === '-') {
                const op = text[pos++];
                const right = term();
                value = op === '+' ? value + right : value - right;
            }
            return value;
        };
        try {
            if (text === '') {
                return NaN;
            }
            const value = expression();
            return pos === text.length && isFinite(value) ? value : NaN;
        } catch (e) {
            return NaN;
        }
    };

    /**
     * Format a result without floating-point noise such as 0.30000000000000004.
     *
     * @param {number} value Result.
     * @return {string}
     */
    const format = function(value) {
        return String(parseFloat(value.toPrecision(12)));
    };

    // Each key: what it shows, what it types (or the action it takes), and its spoken name when the
    // label alone is not clear.
    const KEYS = [
        {label: 'sin', insert: 'sin('}, {label: 'cos', insert: 'cos('}, {label: 'tan', insert: 'tan('},
        {label: 'ln', insert: 'ln(', name: 'ln'}, {label: 'log', insert: 'log(', name: 'log'},
        {label: 'sin⁻¹', insert: 'asin(', name: 'asin'}, {label: 'cos⁻¹', insert: 'acos(', name: 'acos'},
        {label: 'tan⁻¹', insert: 'atan(', name: 'atan'}, {label: 'π', insert: 'π', name: 'pi'},
        {label: 'e', insert: 'e', name: 'e'},
        {label: 'x²', insert: '^2', name: 'square'}, {label: 'xʸ', insert: '^', name: 'power'},
        {label: '√', insert: '√(', name: 'sqrt'}, {label: 'x!', insert: '!', name: 'factorial'},
        {label: '%', insert: '%', name: 'percent'},
        {label: '7'}, {label: '8'}, {label: '9'}, {label: '('}, {label: ')'},
        {label: '4'}, {label: '5'}, {label: '6'}, {label: '×'}, {label: '÷'},
        {label: '1'}, {label: '2'}, {label: '3'}, {label: '+'}, {label: '−'},
        {label: '0'}, {label: '.'}, {label: 'Ans', insert: 'Ans', name: 'ans'},
        {label: '⌫', action: 'back', name: 'backspace'}, {label: 'C', action: 'clear', name: 'clear'},
        {label: '=', action: 'equals', className: 'proctoring-calculator-equals'},
    ];

    return {
        evaluate: evaluate,
        format: format,

        /**
         * Add the calculator to the attempt page.
         */
        init: async function() {
            if (document.getElementById('proctoring-calculator')) {
                return;
            }
            const named = KEYS.filter((key) => key.name);
            const strings = await Str.get_strings([
                {key: 'calculator:title', component: 'quizaccess_proctoring'},
                {key: 'calculator:open', component: 'quizaccess_proctoring'},
                {key: 'calculator:close', component: 'quizaccess_proctoring'},
                {key: 'calculator:error', component: 'quizaccess_proctoring'},
                {key: 'calculator:degrees', component: 'quizaccess_proctoring'},
                {key: 'calculator:radians', component: 'quizaccess_proctoring'},
                {key: 'calculator:angleunit', component: 'quizaccess_proctoring'},
            ].concat(named.map((key) => ({key: 'calculator:key:' + key.name, component: 'quizaccess_proctoring'}))));
            const [title, open, close, error, degrees, radians, angleunit] = strings;
            const spoken = {};
            named.forEach((key, i) => {
                spoken[key.name] = strings[7 + i];
            });

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'btn btn-secondary proctoring-calculator-toggle';
            toggle.textContent = open;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-controls', 'proctoring-calculator');

            const panel = document.createElement('div');
            panel.id = 'proctoring-calculator';
            panel.className = 'proctoring-calculator card';
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-label', title);
            panel.hidden = true;

            // Degrees by default: what a student expects of sin(30).
            let useRadians = false;
            let ans = NaN;
            // After =, a number starts a new calculation and an operator carries on from the answer.
            let showingResult = false;

            const header = document.createElement('div');
            header.className = 'proctoring-calculator-header';
            const unit = document.createElement('button');
            unit.type = 'button';
            unit.className = 'btn btn-sm btn-outline-secondary';
            const showUnit = function() {
                unit.textContent = useRadians ? radians : degrees;
                unit.setAttribute('aria-label', angleunit + ': ' + unit.textContent);
            };
            showUnit();
            unit.addEventListener('click', function() {
                useRadians = !useRadians;
                showUnit();
            });
            header.appendChild(unit);
            panel.appendChild(header);

            const display = document.createElement('input');
            display.type = 'text';
            display.className = 'form-control proctoring-calculator-display';
            display.setAttribute('aria-label', title);
            display.autocomplete = 'off';
            display.addEventListener('input', function() {
                showingResult = false;
            });
            panel.appendChild(display);

            const grid = document.createElement('div');
            grid.className = 'proctoring-calculator-keys';
            const calculate = function() {
                const value = evaluate(display.value, {radians: useRadians, ans: ans});
                if (isNaN(value)) {
                    display.value = error;
                } else {
                    ans = value;
                    display.value = format(value);
                    showingResult = true;
                }
            };
            KEYS.forEach(function(key) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-light' + (key.className ? ' ' + key.className : '');
                button.textContent = key.label;
                if (key.name) {
                    button.setAttribute('aria-label', spoken[key.name]);
                }
                button.addEventListener('click', function() {
                    if (display.value === error) {
                        display.value = '';
                    }
                    let insert = key.insert || key.label;
                    if (key.action === 'equals') {
                        calculate();
                        return;
                    } else if (key.action === 'clear') {
                        display.value = '';
                    } else if (key.action === 'back') {
                        display.value = display.value.slice(0, -1);
                    } else {
                        if (showingResult && !/^[×÷+−^!%)]/.test(insert)) {
                            display.value = '';
                        }
                        // "2" then "e" then "3" would read as 2e3, two thousand.
                        if (insert === 'e' && /[\d.]$/.test(display.value)) {
                            insert = '×e';
                        }
                        display.value += insert;
                    }
                    showingResult = false;
                });
                grid.appendChild(button);
            });
            panel.appendChild(grid);
            display.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    // Never submit the quiz form from the calculator.
                    event.preventDefault();
                    calculate();
                }
            });

            toggle.addEventListener('click', function() {
                panel.hidden = !panel.hidden;
                toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                toggle.textContent = panel.hidden ? open : close;
                if (!panel.hidden) {
                    display.focus();
                }
            });

            const dock = document.createElement('div');
            dock.className = 'proctoring-calculator-dock';
            dock.appendChild(panel);
            dock.appendChild(toggle);
            document.body.appendChild(dock);
        },
    };
});
