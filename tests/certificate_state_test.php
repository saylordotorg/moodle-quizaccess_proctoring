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
 * Property-based tests for the certificate-state helper.
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
 * Property-based tests for quizaccess_proctoring_certificate_state().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_certificate_state
 */
final class certificate_state_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 3: Certificate label reflects live state and never mislabels an issued certificate
     *
     * For any combination of hold state (none / active / released / confirmed) and grade presence,
     * the certificate-state helper returns the state dictated by the derivation table. In
     * particular, whenever a grade is present and no active or confirmed hold exists, the helper
     * never returns a "held" or "withheld" label. Re-resolving after any state transition yields
     * the label for the new state.
     *
     * This test exhaustively checks all 16 flag combinations against an independent reference of
     * the derivation table, then runs randomized iterations to reinforce the never-mislabel
     * invariant and the transition property over many inputs.
     *
     * Validates: Requirements 2.1, 2.2, 2.3
     */
    public function test_certificate_state_reflects_live_state(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240117);

        // 1. Exhaustively verify all 16 combinations of the four boolean flags.
        for ($mask = 0; $mask < 16; $mask++) {
            $hasactive = (bool)($mask & 1);
            $hasreleased = (bool)($mask & 2);
            $hasconfirmed = (bool)($mask & 4);
            $hasgrade = (bool)($mask & 8);

            $this->assert_combination($hasactive, $hasreleased, $hasconfirmed, $hasgrade);
        }

        // 2. Randomized iterations (>= 100) over the same flag space, plus transition checks.
        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $hasactive = (bool)mt_rand(0, 1);
            $hasreleased = (bool)mt_rand(0, 1);
            $hasconfirmed = (bool)mt_rand(0, 1);
            $hasgrade = (bool)mt_rand(0, 1);

            $state = $this->assert_combination($hasactive, $hasreleased, $hasconfirmed, $hasgrade);

            // Transition property: re-resolving after flipping one flag yields the new state, which
            // must match the reference derivation for the transitioned inputs.
            $nextactive = $hasactive;
            $nextreleased = $hasreleased;
            $nextconfirmed = $hasconfirmed;
            $nextgrade = $hasgrade;

            switch (mt_rand(0, 3)) {
                case 0:
                    $nextactive = !$hasactive;
                    break;
                case 1:
                    $nextreleased = !$hasreleased;
                    break;
                case 2:
                    $nextconfirmed = !$hasconfirmed;
                    break;
                default:
                    $nextgrade = !$hasgrade;
                    break;
            }

            $nextstate =
                $this->assert_combination($nextactive, $nextreleased, $nextconfirmed, $nextgrade);

            // The helper is a pure function of its inputs: identical inputs give identical output,
            // and the transitioned inputs give exactly the reference-derived new state.
            $this->assertSame(
                $this->reference_state($nextactive, $nextreleased, $nextconfirmed, $nextgrade),
                $nextstate,
                'transition did not yield the new state: from '
                    . $this->describe($hasactive, $hasreleased, $hasconfirmed, $hasgrade)
                    . ' (' . $state . ') to '
                    . $this->describe($nextactive, $nextreleased, $nextconfirmed, $nextgrade)
            );
        }
    }

    /**
     * Assert the helper agrees with the reference derivation and the never-mislabel invariant.
     *
     * @param bool $hasactive Whether an active hold exists.
     * @param bool $hasreleased Whether a released hold exists.
     * @param bool $hasconfirmed Whether a confirmed (withheld) hold exists.
     * @param bool $hasgrade Whether a non-null quiz grade exists.
     * @return string The state returned by the helper under test.
     */
    private function assert_combination(
        bool $hasactive,
        bool $hasreleased,
        bool $hasconfirmed,
        bool $hasgrade
    ): string {
        $expected = $this->reference_state($hasactive, $hasreleased, $hasconfirmed, $hasgrade);
        $actual = quizaccess_proctoring_certificate_state(
            $hasactive, $hasreleased, $hasconfirmed, $hasgrade);

        $context = $this->describe($hasactive, $hasreleased, $hasconfirmed, $hasgrade);

        // The helper matches the derivation table (Requirements 2.1, 2.3).
        $this->assertSame($expected, $actual, 'state mismatch for ' . $context);

        // The result is always one of the five defined states.
        $this->assertContains(
            $actual,
            ['held', 'withheld', 'released', 'issued', 'none'],
            'unexpected state value for ' . $context
        );

        // Invariant (Requirement 2.2): a present grade with no active/confirmed hold is NEVER
        // labelled as a held or withheld certificate.
        if ($hasgrade && !$hasactive && !$hasconfirmed) {
            $this->assertNotSame('held', $actual,
                'issued certificate mislabelled as held for ' . $context);
            $this->assertNotSame('withheld', $actual,
                'issued certificate mislabelled as withheld for ' . $context);
        }

        return $actual;
    }

    /**
     * Independent reference implementation of the certificate-state derivation table.
     *
     * This re-derives the expected outcome directly from the acceptance criteria / derivation
     * order rather than reusing the helper under test.
     *
     * @param bool $hasactive Whether an active hold exists.
     * @param bool $hasreleased Whether a released hold exists.
     * @param bool $hasconfirmed Whether a confirmed (withheld) hold exists.
     * @param bool $hasgrade Whether a non-null quiz grade exists.
     * @return string One of 'held', 'withheld', 'released', 'issued' or 'none'.
     */
    private function reference_state(
        bool $hasactive,
        bool $hasreleased,
        bool $hasconfirmed,
        bool $hasgrade
    ): string {
        if ($hasactive) {
            return 'held';
        }
        if ($hasconfirmed) {
            return 'withheld';
        }
        if ($hasreleased) {
            return 'released';
        }
        if ($hasgrade) {
            return 'issued';
        }
        return 'none';
    }

    /**
     * Render a flag combination as a compact string for assertion failure messages.
     *
     * @param bool $hasactive Whether an active hold exists.
     * @param bool $hasreleased Whether a released hold exists.
     * @param bool $hasconfirmed Whether a confirmed (withheld) hold exists.
     * @param bool $hasgrade Whether a non-null quiz grade exists.
     * @return string Human-readable description of the flags.
     */
    private function describe(
        bool $hasactive,
        bool $hasreleased,
        bool $hasconfirmed,
        bool $hasgrade
    ): string {
        return '{active=' . ($hasactive ? '1' : '0')
            . ',released=' . ($hasreleased ? '1' : '0')
            . ',confirmed=' . ($hasconfirmed ? '1' : '0')
            . ',grade=' . ($hasgrade ? '1' : '0') . '}';
    }
}
