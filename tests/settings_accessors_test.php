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
     * The risk review ceiling defaults to 101 (ceiling disabled) when nothing is configured.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_defaults_to_101(): void {
        $this->resetAfterTest();

        // No riskreviewceiling config set -> default sentinel of 101 (ceiling disabled).
        $this->assertSame(101, quizaccess_proctoring_get_risk_review_ceiling());
    }

    /**
     * The risk review ceiling accessor clamps configured values into the 0-101 range.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_clamps_out_of_range_values(): void {
        $this->resetAfterTest();

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
     * The risk review ceiling accessor preserves in-range values, including the boundaries.
     *
     * Validates: Requirements 1.1
     */
    public function test_risk_review_ceiling_preserves_in_range_values(): void {
        $this->resetAfterTest();

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
