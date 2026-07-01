<?php
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
 * Property-based tests for the identity-mismatch Yes/No mapping helper.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Property-based tests for quizaccess_proctoring_identity_mismatch_label().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_identity_mismatch_label
 */
final class identity_mismatch_label_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 12: Identity Mismatch renders as Yes or No
     *
     * For any identity/name-mismatch flag value — regardless of its type or shape (bool, int,
     * float, numeric string, word token such as 'yes'/'no', null, '' or an array) — the report
     * helper renders EXACTLY the localized core "Yes" (get_string('yes')) when a mismatch is
     * present and EXACTLY the localized core "No" (get_string('no')) otherwise. It is never blank
     * and never the raw stored flag.
     *
     * The expected presence is derived independently from the acceptance criteria (not from the
     * implementation): null/false/zero-numbers/blank/'0'/'no'/'false'/'n'/'off'/zero-valued
     * numeric strings are "not present"; true/non-zero numbers/'1'/'yes'/'true'/'y'/'on'/any other
     * non-empty token/non-empty array are "present".
     *
     * Validates: Requirements 18.2
     */
    public function test_identity_mismatch_label_is_always_yes_or_no(): void {
        // Uses get_string(), so reset language/config state after the test.
        $this->resetAfterTest();

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240120);

        $yes = get_string('yes');
        $no = get_string('no');

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            [$flag, $expectedpresent] = $this->generate_flag();

            $actual = quizaccess_proctoring_identity_mismatch_label($flag);

            $context = 'iteration=' . $iteration
                . ' flag=' . var_export($flag, true)
                . ' expectedpresent=' . var_export($expectedpresent, true);

            // The result is always exactly one of the two localized strings.
            $this->assertContains($actual, [$yes, $no],
                'label must be exactly the localized Yes or No: ' . $context);

            // It is never blank and never a raw representation of the flag.
            $this->assertNotSame('', $actual,
                'label must never be blank: ' . $context);

            // It matches the independently-derived presence expectation.
            $expected = $expectedpresent ? $yes : $no;
            $this->assertSame($expected, $actual,
                'label did not match expected presence: ' . $context);
        }
    }

    /**
     * Generate a flag value spanning every relevant shape, paired with the independently-derived
     * expected "present" boolean from the acceptance criteria.
     *
     * @return array{0: mixed, 1: bool} The generated flag and its expected presence.
     */
    private function generate_flag(): array {
        $kind = mt_rand(0, 8);

        switch ($kind) {
            case 0:
                // Null is never a mismatch.
                return [null, false];

            case 1:
                // Booleans map directly.
                $value = (bool) mt_rand(0, 1);
                return [$value, $value];

            case 2:
                // Integers: any non-zero is present.
                $value = mt_rand(-5, 5);
                return [$value, $value !== 0];

            case 3:
                // Floats: any non-zero is present.
                $floats = [-2.5, -1.0, 0.0, 0.5, 1.0, 3.14];
                $value = $floats[array_rand($floats)];
                return [$value, (float) $value !== 0.0];

            case 4:
                // Numeric strings, including padded and zero variants.
                $numeric = ['0', '0.0', ' 0 ', '00', '1', '2', '-3', '10', ' 4 '];
                $value = $numeric[array_rand($numeric)];
                return [$value, (float) trim($value) !== 0.0];

            case 5:
                // Explicit "not present" word tokens (with varied case/whitespace).
                $falsy = ['no', 'No', 'NO', 'false', 'FALSE', 'n', 'N', 'off', 'OFF', ' no ', ''];
                $value = $falsy[array_rand($falsy)];
                return [$value, false];

            case 6:
                // Explicit "present" word tokens (with varied case/whitespace).
                $truthy = ['yes', 'Yes', 'YES', 'true', 'TRUE', 'y', 'Y', 'on', 'ON', ' yes ', 'mismatch'];
                $value = $truthy[array_rand($truthy)];
                return [$value, true];

            case 7:
                // Arrays: non-empty is present, empty is not.
                if (mt_rand(0, 1) === 0) {
                    return [[], false];
                }
                return [['mismatch'], true];

            default:
                // Blank/whitespace-only strings are never a mismatch.
                $blank = ['', ' ', '   ', "\t", "\n"];
                $value = $blank[array_rand($blank)];
                return [$value, false];
        }
    }
}
