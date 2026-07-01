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
 * Property-based tests for the AI-review status-label mapping.
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
 * Property-based tests for quizaccess_proctoring_get_ai_review_status_label().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_get_ai_review_status_label
 */
final class ai_review_status_label_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Feature: proctoring-feedback-improvements, Property 4: AI review status is never presented as "Not queued" once a row exists
     *
     * For any AI review status value (every defined status QUEUED/PROCESSING/COMPLETE/FAILED,
     * plus random/unknown integers standing in for corrupt or future statuses), the status label
     * returned by the helper is:
     *   - the pending variant for QUEUED or PROCESSING (and any unknown status),
     *   - the completed variant for COMPLETE,
     *   - the tool-failure variant for FAILED,
     * and is NEVER the "Not queued" string. The "Not queued" presentation is reserved for
     * attempts with no AI review row and is therefore never produced by this function, which
     * only runs once a row (with a status) exists.
     *
     * This test independently recomputes the expected label variant and asserts the helper
     * agrees, while also asserting the "Not queued" string never appears, over many iterations.
     *
     * Validates: Requirements 3.6
     */
    public function test_status_label_never_notqueued_once_row_exists(): void {
        // Uses get_string(), so reset language/config state after the test.
        $this->resetAfterTest();

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240118);

        $pending = get_string('aireview:statuspending', 'quizaccess_proctoring');
        $complete = get_string('aireview:statuscomplete', 'quizaccess_proctoring');
        $toolfailure = get_string('aireview:statustoolfailure', 'quizaccess_proctoring');
        $notqueued = get_string('aireview:notqueued', 'quizaccess_proctoring');

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $status = $this->generate_status();

            $expected = $this->reference_label($status, $pending, $complete, $toolfailure);

            $actual = quizaccess_proctoring_get_ai_review_status_label($status);

            $context = 'iteration=' . $iteration . ' status=' . $status;

            // The label must match the expected variant for the status.
            $this->assertSame($expected, $actual,
                'status label mismatch: ' . $context);

            // Core property: a row exists (we always pass a status), so the label is
            // never the "Not queued" presentation.
            $this->assertNotSame($notqueued, $actual,
                '"Not queued" must never be returned once a row exists: ' . $context);

            // Reinforce the individual status branches directly.
            switch ($status) {
                case QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE:
                    $this->assertSame($complete, $actual,
                        'COMPLETE must render the completed variant: ' . $context);
                    break;
                case QUIZACCESS_PROCTORING_AI_REVIEW_FAILED:
                    $this->assertSame($toolfailure, $actual,
                        'FAILED must render the tool-failure variant: ' . $context);
                    break;
                case QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED:
                case QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING:
                    $this->assertSame($pending, $actual,
                        'QUEUED/PROCESSING must render the pending variant: ' . $context);
                    break;
                default:
                    // Unknown/corrupt statuses fall back to pending, never "Not queued".
                    $this->assertSame($pending, $actual,
                        'unknown status must fall back to the pending variant: ' . $context);
                    break;
            }
        }
    }

    /**
     * Generate a status value, covering every defined status plus random/unknown integers.
     *
     * @return int The generated status value.
     */
    private function generate_status(): int {
        // Roughly a third of the time, pick a defined status so all four are well covered;
        // otherwise pick an arbitrary integer (including negatives and large values) to
        // represent corrupt or future statuses that must still avoid "Not queued".
        if (mt_rand(0, 2) !== 0) {
            $defined = [
                QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED,
                QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING,
                QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
                QUIZACCESS_PROCTORING_AI_REVIEW_FAILED,
            ];
            return $defined[array_rand($defined)];
        }
        return mt_rand(-50, 200);
    }

    /**
     * Independent reference implementation of the status-label mapping from the design.
     *
     * This intentionally re-derives the expected label rather than reusing the helper under test.
     *
     * @param int $status The AI review status.
     * @param string $pending The pending-variant label.
     * @param string $complete The completed-variant label.
     * @param string $toolfailure The tool-failure-variant label.
     * @return string Expected label.
     */
    private function reference_label(
        int $status,
        string $pending,
        string $complete,
        string $toolfailure
    ): string {
        if ($status === QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE) {
            return $complete;
        }
        if ($status === QUIZACCESS_PROCTORING_AI_REVIEW_FAILED) {
            return $toolfailure;
        }
        // QUEUED, PROCESSING, and any unknown status map to pending.
        return $pending;
    }
}
