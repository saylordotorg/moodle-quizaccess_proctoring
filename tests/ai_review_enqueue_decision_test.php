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
 * Property-based tests for the AI-review enqueue-decision helper.
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
 * Property-based tests for quizaccess_proctoring_should_enqueue_ai_review().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_should_enqueue_ai_review
 */
final class ai_review_enqueue_decision_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 13: AI-review enqueue decision follows the configured trigger mode
     *
     * For any configured-state, any trigger mode ('everyattempt' or 'threshold', plus the
     * occasional invalid mode), any risk score, and any AI-review trigger threshold, the
     * submission-time enqueue decision is true if and only if AI review is configured AND
     * (the mode is 'everyattempt' OR the risk score is at or above the trigger threshold).
     *
     * This test generates random inputs, independently recomputes the expected biconditional,
     * and asserts the helper agrees over many iterations.
     *
     * Validates: Requirements 3.2, 3.3, 3.4
     */
    public function test_enqueue_decision_follows_trigger_mode(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240117);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $configured = (mt_rand(0, 1) === 1);
            $mode = $this->generate_mode();
            $score = $this->generate_score();
            $triggerthreshold = $this->generate_score();

            $expected = $this->reference_decision($configured, $mode, $score, $triggerthreshold);

            $actual = quizaccess_proctoring_should_enqueue_ai_review(
                $configured, $mode, $score, $triggerthreshold);

            $context = 'iteration=' . $iteration
                . ' configured=' . ($configured ? 'true' : 'false')
                . " mode='" . $mode . "'"
                . ' score=' . $score
                . ' threshold=' . $triggerthreshold;

            $this->assertSame($expected, $actual,
                'enqueue decision mismatch: ' . $context);

            // Reinforce the individual acceptance-criteria branches directly.
            if (!$configured) {
                // Not configured: never enqueue (precondition of Req 3.2/3.3).
                $this->assertFalse($actual,
                    'unconfigured AI review must never enqueue: ' . $context);
            } else if ($mode === 'everyattempt') {
                // Configured + everyattempt: always enqueue regardless of score (Req 3.2).
                $this->assertTrue($actual,
                    'everyattempt mode must always enqueue when configured: ' . $context);
            } else if ($mode === 'threshold') {
                // Configured + threshold: enqueue iff score >= threshold (Req 3.3, 3.4).
                $this->assertSame($score >= $triggerthreshold, $actual,
                    'threshold mode must enqueue iff score >= threshold: ' . $context);
            } else {
                // Configured + any other (invalid) mode behaves like threshold gating.
                $this->assertSame($score >= $triggerthreshold, $actual,
                    'non-everyattempt mode must gate on the threshold: ' . $context);
            }
        }
    }

    /**
     * Generate a random trigger mode, biased to the two supported values but occasionally invalid.
     *
     * @return string The generated mode value.
     */
    private function generate_mode(): string {
        $choices = ['everyattempt', 'threshold', 'threshold', 'everyattempt', 'bogus', ''];
        return $choices[array_rand($choices)];
    }

    /**
     * Generate a random score/threshold value across and slightly beyond the 0-100 range.
     *
     * @return int The generated value.
     */
    private function generate_score(): int {
        // Mostly 0..100, occasionally out-of-range to exercise boundary comparisons.
        if (mt_rand(0, 6) === 0) {
            return mt_rand(-20, 150);
        }
        return mt_rand(0, 100);
    }

    /**
     * Independent reference implementation of the enqueue decision from the acceptance criteria.
     *
     * This intentionally re-derives the expected outcome rather than reusing the helper under test.
     *
     * @param bool $configured Whether AI image review is configured and enabled.
     * @param string $mode The configured trigger mode.
     * @param int $score The attempt's computed risk score.
     * @param int $triggerthreshold The AI-review trigger threshold.
     * @return bool Expected enqueue decision.
     */
    private function reference_decision(
        bool $configured,
        string $mode,
        int $score,
        int $triggerthreshold
    ): bool {
        if (!$configured) {
            return false;
        }
        if ($mode === 'everyattempt') {
            return true;
        }
        return $score >= $triggerthreshold;
    }
}
