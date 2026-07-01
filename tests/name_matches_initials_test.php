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
 * Property-based tests for the name-initial filter helper.
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
 * Property-based tests for quizaccess_proctoring_name_matches_initials().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_name_matches_initials
 */
final class name_matches_initials_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 8: Name-initial filter matches by initial
     *
     * For any first name, last name, first-initial filter and last-initial filter, the helper
     * returns true iff BOTH parts match: a name part matches when its initial filter is blank
     * ("all") OR the first character of the name equals the first character of the filter,
     * compared case-insensitively and multibyte-safely. The expectation is derived independently
     * from the acceptance criteria using \core_text::strtolower/substr rather than from the
     * implementation.
     *
     * Names and filters are generated to include non-Latin (accented and CJK) and mixed-case
     * inputs, plus blank filters, over at least 100 iterations.
     *
     * Validates: Requirements 13.3
     */
    public function test_name_matches_initials_matches_by_initial(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240119);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $firstname = $this->generate_name();
            $lastname = $this->generate_name();
            $firstinitial = $this->generate_initial();
            $lastinitial = $this->generate_initial();

            $actual = quizaccess_proctoring_name_matches_initials(
                $firstname, $lastname, $firstinitial, $lastinitial);

            // Independently derive the expected biconditional from the acceptance criteria.
            $expected = $this->part_matches($firstname, $firstinitial)
                && $this->part_matches($lastname, $lastinitial);

            $context = 'iteration=' . $iteration
                . ' firstname=' . var_export($firstname, true)
                . ' lastname=' . var_export($lastname, true)
                . ' firstinitial=' . var_export($firstinitial, true)
                . ' lastinitial=' . var_export($lastinitial, true);

            $this->assertSame($expected, $actual,
                'name-initial match did not follow the biconditional: ' . $context);
        }
    }

    /**
     * Independent reference for a single name part matching an initial filter.
     *
     * A blank initial (after trimming) means "all" and always matches. Otherwise the first
     * character of the name and of the initial are compared, lower-cased via \core_text so that
     * non-Latin and mixed-case characters are handled correctly.
     *
     * @param string $name The name part (first or last name).
     * @param string $initial The requested initial filter.
     * @return bool True when the name part matches the initial filter.
     */
    private function part_matches(string $name, string $initial): bool {
        if (trim($initial) === '') {
            return true;
        }
        $nameinitial = \core_text::strtolower(\core_text::substr($name, 0, 1));
        $wanted = \core_text::strtolower(\core_text::substr($initial, 0, 1));
        return $nameinitial === $wanted;
    }

    /**
     * Generate a name, including empty, Latin mixed-case, accented and CJK values.
     *
     * @return string A generated name.
     */
    private function generate_name(): string {
        $names = [
            '', 'Ann', 'bob', 'CARA', 'dan', 'Eve', 'Álvarez', 'álvarez', 'Étienne',
            'Øyvind', 'Zoë', 'çela', 'Ćatić', '张伟', '李娜', 'あきら', 'Мария', 'naïve',
            'ANNA', 'anna', ' spaced', 'ß-street',
        ];
        return $names[mt_rand(0, count($names) - 1)];
    }

    /**
     * Generate an initial filter, biased towards single characters and blanks, but also including
     * mixed-case, accented, CJK and multi-character inputs (only the first character is used).
     *
     * @return string A generated initial filter.
     */
    private function generate_initial(): string {
        $initials = [
            '', '   ', 'A', 'a', 'B', 'c', 'D', 'e', 'Z', 'z',
            'Á', 'á', 'É', 'Ø', 'ç', 'Ć', '张', '李', 'あ', 'М', 'ß',
            'An', 'álpha', '张三', 'ANNA',
        ];
        return $initials[mt_rand(0, count($initials) - 1)];
    }
}
