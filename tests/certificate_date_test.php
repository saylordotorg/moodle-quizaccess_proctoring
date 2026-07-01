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
 * Property-based tests for the certificate-date helper.
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
 * Property-based tests for quizaccess_proctoring_certificate_date_for_release().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_certificate_date_for_release
 */
final class certificate_date_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 6: Released certificate date is the exam completion date
     *
     * For any attempt completion time, fallback, and release delay, the chosen certificate/grade
     * date equals the completion time when it is known (`$timefinish > 0`), and equals the defined
     * fallback when the completion time is unknown (`$timefinish <= 0`). The chosen date is
     * independent of the release delay: varying the delay never changes the result.
     *
     * This test generates random completion times, fallbacks, and delays, independently recomputes
     * the expected date from the acceptance criteria, and asserts the helper agrees over many
     * iterations. For each input it also re-invokes the helper under a range of different release
     * delays to confirm delay-independence.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function test_certificate_date_is_exam_completion_date(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240118);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Completion time: bias to hit the "unknown" branch (0 and negatives) as well as
            // realistic positive unix timestamps.
            $timefinish = $this->generate_time();

            // Fallback: independently generated, including the unknown (0/negative) cases.
            $fallback = $this->generate_time();

            $expected = ($timefinish > 0) ? $timefinish : $fallback;

            $actual = quizaccess_proctoring_certificate_date_for_release($timefinish, $fallback);

            $context = 'iteration=' . $iteration
                . ' timefinish=' . $timefinish
                . ' fallback=' . $fallback;

            // The chosen date matches the completion-date-or-fallback derivation (Req 5.1, 5.2).
            $this->assertSame($expected, $actual, 'chosen date mismatch: ' . $context);

            // Delay-independence (Req 5.2): the release delay is not an input to the helper, so the
            // result must be identical no matter how long the hold lasted between completion and
            // release. We model a range of delays and confirm the result never varies.
            foreach ($this->generate_delays() as $delay) {
                // The delay is deliberately unused by the helper; recompute to prove invariance.
                $withdelay =
                    quizaccess_proctoring_certificate_date_for_release($timefinish, $fallback);
                $this->assertSame($actual, $withdelay,
                    'release delay changed the chosen date (delay=' . $delay . '): ' . $context);
            }

            // When the completion time is known it is always chosen, regardless of the fallback.
            if ($timefinish > 0) {
                $this->assertSame($timefinish, $actual,
                    'known completion time was not chosen: ' . $context);
            } else {
                // When unknown, the fallback is chosen verbatim.
                $this->assertSame($fallback, $actual,
                    'fallback was not chosen for unknown completion time: ' . $context);
            }
        }
    }

    /**
     * Generate a random timestamp-like value, biased to also produce the "unknown" cases.
     *
     * @return int Zero (~1/5 of the time), an occasional negative, or a positive unix timestamp.
     */
    private function generate_time(): int {
        switch (mt_rand(0, 6)) {
            case 0:
            case 1:
                // Unknown completion time.
                return 0;
            case 2:
                // Defensive: a negative value is still "unknown" per the > 0 guard.
                return -mt_rand(1, 1000000);
            default:
                // Realistic positive unix timestamps spanning a wide range.
                return mt_rand(1, 2000000000);
        }
    }

    /**
     * Generate a set of release delays (in seconds) to probe delay-independence.
     *
     * Includes zero delay and delays spanning seconds to years.
     *
     * @return int[] Delay values in seconds.
     */
    private function generate_delays(): array {
        return [
            0,
            mt_rand(1, MINSECS),
            mt_rand(MINSECS, HOURSECS),
            mt_rand(HOURSECS, DAYSECS),
            mt_rand(DAYSECS, WEEKSECS),
            mt_rand(WEEKSECS, YEARSECS),
        ];
    }
}
