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
 * Property-based tests for the auto-release selection helper.
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
 * Property-based tests for quizaccess_proctoring_auto_release_selection().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_auto_release_selection
 */
final class auto_release_selection_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 1: Auto-release ceiling gate
     *
     * A hold is released if and only if it is active, its review window has expired, and its
     * risk score is strictly below the ceiling. When the ceiling is greater than 100 the ceiling
     * is disabled and every expired active hold is released regardless of its risk score.
     *
     * This test generates holds with random status/age/score plus a random ceiling and window,
     * independently recomputes the expected partition, and asserts the helper agrees over many
     * iterations.
     *
     * Validates: Requirements 1.2, 1.3, 1.4
     */
    public function test_auto_release_ceiling_gate_partition(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240116);

        $now = 1700000000;
        $daysecs = DAYSECS;

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Random review window: mostly positive, occasionally 0 to exercise the days guard.
            $days = (mt_rand(0, 9) === 0) ? 0 : mt_rand(1, 30);
            $window = $days * $daysecs;

            // Random ceiling in 0..101, biased to also hit the ">100 disables ceiling" branch.
            $ceiling = (mt_rand(0, 3) === 0) ? mt_rand(101, 150) : mt_rand(0, 100);

            // Build a random batch of holds mixing stdClass and array forms.
            $holdcount = mt_rand(1, 8);
            $holds = [];
            for ($h = 0; $h < $holdcount; $h++) {
                $holds[] = $this->generate_hold($now, $window);
            }

            [$expectedrelease, $expectedretain] =
                $this->reference_partition($holds, $ceiling, $now, $days);

            $result = quizaccess_proctoring_auto_release_selection($holds, $ceiling, $now, $days);

            $context = 'iteration=' . $iteration . ' ceiling=' . $ceiling
                . ' now=' . $now . ' days=' . $days
                . ' holds=' . $this->describe_holds($holds);

            // Same partition sizes.
            $this->assertCount(count($expectedrelease), $result['release'],
                'release bucket size mismatch: ' . $context);
            $this->assertCount(count($expectedretain), $result['retain'],
                'retain bucket size mismatch: ' . $context);

            // Every hold is placed in exactly one bucket (no loss, no duplication).
            $this->assertCount(
                count($holds),
                array_merge($result['release'], $result['retain']),
                'total partitioned holds must equal input holds: ' . $context
            );

            // The released bucket must be exactly the reference-released holds, in order.
            $this->assertSame($expectedrelease, array_values($result['release']),
                'release bucket contents mismatch: ' . $context);
            $this->assertSame($expectedretain, array_values($result['retain']),
                'retain bucket contents mismatch: ' . $context);

            // Cross-check the defining biconditional on every hold independently.
            foreach ($result['release'] as $hold) {
                $this->assertTrue($this->should_release($hold, $ceiling, $now, $days),
                    'released a hold that should have been retained: ' . $context);
            }
            foreach ($result['retain'] as $hold) {
                $this->assertFalse($this->should_release($hold, $ceiling, $now, $days),
                    'retained a hold that should have been released: ' . $context);
            }
        }
    }

    /**
     * Generate a single random hold, alternating between stdClass and array representations.
     *
     * @param int $now Base current timestamp.
     * @param int $window Review window length in seconds (0 when days is 0).
     * @return \stdClass|array Randomly shaped hold record.
     */
    private function generate_hold(int $now, int $window) {
        // Status: bias towards ACTIVE (0) but also include RELEASED (1), CONFIRMED (2) and noise.
        $statuschoices = [
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED,
            2,
            mt_rand(3, 9),
        ];
        $status = $statuschoices[array_rand($statuschoices)];

        // timecreated: occasionally 0 (never expires); otherwise straddle the window boundary.
        if (mt_rand(0, 6) === 0) {
            $timecreated = 0;
        } else {
            // Offset from -2 days to (window + 2 days) so both expired and not-yet-expired occur.
            $offset = mt_rand(0, $window + (2 * DAYSECS));
            $timecreated = $now - $offset;
        }

        // riskscore: 0..100, occasionally above 100 to exercise clamped-like edges.
        $riskscore = (mt_rand(0, 8) === 0) ? mt_rand(101, 130) : mt_rand(0, 100);

        if (mt_rand(0, 1) === 0) {
            $hold = new \stdClass();
            $hold->status = $status;
            $hold->timecreated = $timecreated;
            $hold->riskscore = $riskscore;
            return $hold;
        }

        return [
            'status' => $status,
            'timecreated' => $timecreated,
            'riskscore' => $riskscore,
        ];
    }

    /**
     * Independent reference implementation of the release/retain partition.
     *
     * This intentionally re-derives the expected outcome from the acceptance criteria rather than
     * reusing the helper under test.
     *
     * @param array $holds Hold records.
     * @param int $ceiling Risk ceiling.
     * @param int $now Current timestamp.
     * @param int $days Review window in days.
     * @return array{0: array, 1: array} [release, retain] buckets preserving input order.
     */
    private function reference_partition(array $holds, int $ceiling, int $now, int $days): array {
        $release = [];
        $retain = [];
        foreach ($holds as $hold) {
            if ($this->should_release($hold, $ceiling, $now, $days)) {
                $release[] = $hold;
            } else {
                $retain[] = $hold;
            }
        }
        return [$release, $retain];
    }

    /**
     * Independently decide whether a single hold should be released, from the acceptance criteria.
     *
     * @param \stdClass|array $hold Hold record.
     * @param int $ceiling Risk ceiling.
     * @param int $now Current timestamp.
     * @param int $days Review window in days.
     * @return bool True when the hold qualifies for automatic release.
     */
    private function should_release($hold, int $ceiling, int $now, int $days): bool {
        $status = (int)$this->field($hold, 'status');
        $timecreated = (int)$this->field($hold, 'timecreated');
        $riskscore = (int)$this->field($hold, 'riskscore');

        $isactive = ($status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE);
        $isexpired = ($days > 0) && ($timecreated > 0)
            && (($now - $timecreated) > ($days * DAYSECS));
        $belowceiling = ($ceiling > 100) || ($riskscore < $ceiling);

        return $isactive && $isexpired && $belowceiling;
    }

    /**
     * Read a field from a hold that may be a stdClass or an associative array.
     *
     * @param \stdClass|array $hold Hold record.
     * @param string $field Field name.
     * @return int|mixed Field value or 0 when absent.
     */
    private function field($hold, string $field) {
        if (is_array($hold)) {
            return array_key_exists($field, $hold) ? $hold[$field] : 0;
        }
        return isset($hold->{$field}) ? $hold->{$field} : 0;
    }

    /**
     * Render holds as a compact string for assertion failure messages.
     *
     * @param array $holds Hold records.
     * @return string Human-readable description of the generated holds.
     */
    private function describe_holds(array $holds): string {
        $parts = [];
        foreach ($holds as $hold) {
            $parts[] = '{status=' . $this->field($hold, 'status')
                . ',timecreated=' . $this->field($hold, 'timecreated')
                . ',riskscore=' . $this->field($hold, 'riskscore') . '}';
        }
        return '[' . implode(',', $parts) . ']';
    }
}
