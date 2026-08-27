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
 * Smoke tests for the risk-ceiling and AI-review-trigger-mode settings accessors.
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
 * Smoke tests for quizaccess_proctoring_get_risk_review_ceiling() and
 * quizaccess_proctoring_get_ai_review_trigger_mode().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_get_risk_review_ceiling
 * @covers ::quizaccess_proctoring_get_ai_review_trigger_mode
 */
final class settings_accessors_test extends advanced_testcase {

    /**
     * The risk review ceiling defaults to "one above the highest reachable score", meaning disabled.
     *
     * The sentinel is not a fixed 101: it is one above whatever the highest reachable score is, and
     * that follows the score cap. With the cap on the highest score is 100 and the sentinel is the
     * historical 101; with the cap off - which is the shipped default since 1.8.0 - the highest
     * score is the sum of the enabled factors' caps and the sentinel follows it up. Both are the
     * same statement: nothing can reach the ceiling, so no expired hold is retained.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_defaults_to_one_above_the_maximum(): void {
        $this->resetAfterTest();

        // settings.php ships this setting with a default of 101, and installing the plugin stores
        // it - so it is never actually absent on a real site or in a test run. Clearing it is the
        // only way to exercise the "nothing configured" branch rather than reading that stored 101
        // back and calling it the default.
        unset_config('riskreviewceiling', 'quizaccess_proctoring');

        set_config('riskscorecapenabled', 1, 'quizaccess_proctoring');
        $this->assertSame(101, quizaccess_proctoring_get_risk_review_ceiling());

        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        $this->assertSame(
            \quizaccess_proctoring\local\risk_calculator::max_possible_score() + 1,
            quizaccess_proctoring_get_risk_review_ceiling()
        );
    }

    /**
     * The risk review ceiling accessor clamps configured values into the 0-101 range.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_clamps_out_of_range_values(): void {
        $this->resetAfterTest();

        // The upper bound is one above the highest reachable score, which depends on the score cap,
        // so the cap is set explicitly here rather than inherited from whatever the shipped default
        // happens to be. With the cap on that bound is the historical 101.
        set_config('riskscorecapenabled', 1, 'quizaccess_proctoring');

        // Below the lower bound clamps to 0.
        set_config('riskreviewceiling', '-5', 'quizaccess_proctoring');
        $this->assertSame(0, quizaccess_proctoring_get_risk_review_ceiling());

        set_config('riskreviewceiling', '-100', 'quizaccess_proctoring');
        $this->assertSame(0, quizaccess_proctoring_get_risk_review_ceiling());

        // Above the upper bound clamps to 101.
        set_config('riskreviewceiling', '200', 'quizaccess_proctoring');
        $this->assertSame(101, quizaccess_proctoring_get_risk_review_ceiling());

        set_config('riskreviewceiling', '102', 'quizaccess_proctoring');
        $this->assertSame(101, quizaccess_proctoring_get_risk_review_ceiling());
    }

    /**
     * With the score cap off, the ceiling clamps to one above the uncapped maximum instead of 101.
     *
     * This is the case that matters for a site running the shipped default: a ceiling of 101 is a
     * genuine ceiling once scores can pass 100, not a way of switching the ceiling off.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_follows_the_uncapped_maximum(): void {
        $this->resetAfterTest();

        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        $max = \quizaccess_proctoring\local\risk_calculator::max_possible_score();
        $this->assertGreaterThan(
            100,
            $max,
            'the uncapped maximum should exceed 100, or this test is not exercising anything'
        );

        // A value inside the uncapped range is preserved, where the capped rule would clamp it.
        set_config('riskreviewceiling', (string)($max - 1), 'quizaccess_proctoring');
        $this->assertSame($max - 1, quizaccess_proctoring_get_risk_review_ceiling());

        // Beyond the uncapped maximum it clamps to the sentinel, whatever that now is.
        set_config('riskreviewceiling', (string)($max + 500), 'quizaccess_proctoring');
        $this->assertSame($max + 1, quizaccess_proctoring_get_risk_review_ceiling());
    }

    /**
     * The risk review ceiling accessor preserves in-range values, including the boundaries.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_preserves_in_range_values(): void {
        $this->resetAfterTest();

        // These values are in range relative to the capped maximum, so pin the cap on.
        set_config('riskscorecapenabled', 1, 'quizaccess_proctoring');

        foreach ([0, 1, 50, 80, 100, 101] as $value) {
            set_config('riskreviewceiling', (string)$value, 'quizaccess_proctoring');
            $this->assertSame($value, quizaccess_proctoring_get_risk_review_ceiling());
        }
    }

    /**
     * The AI review trigger mode defaults to "threshold" when nothing is configured.
     *
     * Validates: Requirements 3.1
     */
    public function test_ai_review_trigger_mode_defaults_to_threshold(): void {
        $this->resetAfterTest();

        // No aireviewtriggermode config set -> default of 'threshold'.
        $this->assertSame('threshold', quizaccess_proctoring_get_ai_review_trigger_mode());
    }

    /**
     * The AI review trigger mode accessor accepts only the two valid modes.
     *
     * Validates: Requirements 3.1
     */
    public function test_ai_review_trigger_mode_accepts_only_valid_modes(): void {
        $this->resetAfterTest();

        // Both valid modes are returned as-is.
        set_config('aireviewtriggermode', 'everyattempt', 'quizaccess_proctoring');
        $this->assertSame('everyattempt', quizaccess_proctoring_get_ai_review_trigger_mode());

        set_config('aireviewtriggermode', 'threshold', 'quizaccess_proctoring');
        $this->assertSame('threshold', quizaccess_proctoring_get_ai_review_trigger_mode());
    }

    /**
     * Any invalid AI review trigger mode falls back to the safe default of "threshold".
     *
     * Validates: Requirements 3.1
     */
    public function test_ai_review_trigger_mode_falls_back_for_invalid_values(): void {
        $this->resetAfterTest();

        foreach (['', 'bogus', 'EveryAttempt', 'always', '0'] as $invalid) {
            set_config('aireviewtriggermode', $invalid, 'quizaccess_proctoring');
            $this->assertSame('threshold', quizaccess_proctoring_get_ai_review_trigger_mode());
        }
    }
}
