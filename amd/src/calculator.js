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
 * An on-page calculator for proctored quizzes that allow one (CPIT-482).
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
     * Evaluate an arithmetic expression: numbers, + - * /, parentheses and unary minus.
     *
     * A small recursive-descent parser, never eval(): the input is typed by the student.
     *
     * @param {string} input Expression.
     * @return {number} The value, NaN when the expression is not valid.
     */
    const evaluate = function(input) {
        const text = String(input).replace(/\s+/g, '').replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-');
        let pos = 0;
        const peek = () => text[pos];
        const fail = () => {
            throw new Error('invalid');
        };
        let expression;
        const number = function() {
            // Exponents too: a very small or large result is shown as, say, 1e-7, and can be reused.
            const match = /^(\d+\.?\d*|\.\d+)(e[+-]?\d+)?/i.exec(text.slice(pos));
            if (!match) {
                fail();
            }
            pos += match[0].length;
            return parseFloat(match[0]);
        };
        const factor = function() {
            if (peek() === '-') {
                pos++;
                return -factor();
            }
            if (peek() === '+') {
                pos++;
                return factor();
            }
            if (peek() === '(') {
                pos++;
                const value = expression();
                if (peek() !== ')') {
                    fail();
                }
                pos++;
                return value;
            }
            return number();
        };
        const term = function() {
            let value = factor();
            while (peek() === '*' || peek() === '/') {
                const op = text[pos++];
                const right = factor();
                value = op === '*' ? value * right : value / right;
            }
            return value;
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

    const KEYS = ['7', '8', '9', '÷', '4', '5', '6', '×', '1', '2', '3', '−', '0', '.', '=', '+', '(', ')', '⌫', 'C'];

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
            const [title, open, close, error] = await Str.get_strings([
                {key: 'calculator:title', component: 'quizaccess_proctoring'},
                {key: 'calculator:open', component: 'quizaccess_proctoring'},
                {key: 'calculator:close', component: 'quizaccess_proctoring'},
                {key: 'calculator:error', component: 'quizaccess_proctoring'},
            ]);

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

            const display = document.createElement('input');
            display.type = 'text';
            display.className = 'form-control proctoring-calculator-display';
            display.setAttribute('aria-label', title);
            display.autocomplete = 'off';
            panel.appendChild(display);

            const grid = document.createElement('div');
            grid.className = 'proctoring-calculator-keys';
            const calculate = function() {
                const value = evaluate(display.value);
                display.value = isNaN(value) ? error : format(value);
            };
            KEYS.forEach(function(key) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-light';
                button.textContent = key;
                button.addEventListener('click', function() {
                    if (display.value === error) {
                        display.value = '';
                    }
                    if (key === '=') {
                        calculate();
                    } else if (key === 'C') {
                        display.value = '';
                    } else if (key === '⌫') {
                        display.value = display.value.slice(0, -1);
                    } else {
                        display.value += key;
                    }
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
