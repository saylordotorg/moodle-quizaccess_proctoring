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
 * Property-based tests for the plain-language session-summary generator.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Property-based tests for quizaccess_proctoring_build_session_summary().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_build_session_summary
 * @covers \quizaccess_proctoring\local\session_summary
 */
final class session_summary_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /**
     * Absolute upper bound (in bytes) the summary must never exceed. The generator mentions at most
     * three factors and one AI sentence, each phrased from a bounded vocabulary, so a few hundred
     * bytes is generous. If the summary ever listed individual occurrences (instead of collapsing
     * them into a count phrase) this bound would be blown out by large counts.
     *
     * @var int
     */
    private const MAX_BYTES = 1024;

    /**
     * Raw telemetry key tokens that must never leak into the human-readable summary. These are the
     * kinds of low-level keys captured in raw event payloads; the summary is required to speak in
     * plain language only. Matched case-insensitively as substrings.
     *
     * @var string[]
     */
    private const FORBIDDEN_TOKENS = [
        'viewportheight', 'viewportwidth', 'clientx', 'clienty', 'screenx', 'screeny',
        'devicepixelratio', 'useragent', 'innerwidth', 'innerheight', 'pagex', 'pagey',
        'offsetx', 'offsety', 'scrollx', 'scrolly', 'srcelement', 'timestampms',
    ];

    /**
     * Human-readable factor labels, mirroring the plain-language labels the risk calculator emits.
     * Deliberately contain NO sentence terminators so sentence counting stays unambiguous, and NO
     * raw telemetry tokens (the summary must speak in plain language).
     *
     * @var string[]
     */
    private const LABELS = [
        'Left the exam window',
        'Switched to another application',
        'Multiple faces detected',
        'No face detected',
        'Copy or paste activity',
        'Entered full-screen late',
        'Lost network connection',
        'Second screen detected',
    ];

    /**
     * Very large evidence counts used to prove the summary size does not scale with the count.
     *
     * @var int[]
     */
    private const HUGE_COUNTS = [
        1000000, 5000000, 123456789, 999999999, 2147483647, PHP_INT_MAX,
    ];

    /**
     * Feature: proctoring-feedback-improvements, Property 9: Plain-language summary is bounded and telemetry-free
     *
     * For any risk-factor input and AI outcome, the generated session summary:
     *   1. contains at most three sentences;
     *   2. never contains a raw telemetry key token (for example "viewportheight"); and
     *   3. stays bounded in size even as an individual evidence count grows arbitrarily large,
     *      because repeated flags are collapsed into a single count phrase rather than listed.
     *
     * The third claim is checked two ways: an absolute byte bound, and a paired comparison of the
     * summary built from small counts against one built from the same factors with enormous counts,
     * asserting the length grows only by the extra digits (never linearly with the count).
     *
     * Risk-factor inputs are generated with random factor sets (including very large counts) and
     * random AI outcomes (false, or a stdClass with random status/score/decision) over at least
     * 100 iterations.
     *
     * Validates: Requirements 15.1, 15.2, 15.3
     */
    public function test_summary_is_bounded_and_telemetry_free(): void {
        $this->resetAfterTest();

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260119);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Shared factor "shape" (labels/points/haspoints) reused for the small- and huge-count
            // variants so only the counts differ between them.
            $shape = $this->generate_factor_shape();
            $aireview = $this->generate_ai_outcome();

            $risksmall = ['factors' => $this->apply_counts($shape, false)];
            $riskhuge = ['factors' => $this->apply_counts($shape, true)];

            $summarysmall = quizaccess_proctoring_build_session_summary($risksmall, $aireview);
            $summaryhuge = quizaccess_proctoring_build_session_summary($riskhuge, $this->clone_ai($aireview));

            $context = 'iteration=' . $iteration
                . ' summarysmall=' . var_export($summarysmall, true)
                . ' summaryhuge=' . var_export($summaryhuge, true);

            // (1) At most three sentences, for both variants.
            $this->assertLessThanOrEqual(3, $this->count_sentences($summarysmall),
                'small-count summary exceeded three sentences: ' . $context);
            $this->assertLessThanOrEqual(3, $this->count_sentences($summaryhuge),
                'huge-count summary exceeded three sentences: ' . $context);

            // (2) No raw telemetry key tokens in either variant.
            $this->assert_telemetry_free($summarysmall, $context);
            $this->assert_telemetry_free($summaryhuge, $context);

            // (3a) Absolute byte bound regardless of count magnitude.
            $this->assertLessThanOrEqual(self::MAX_BYTES, strlen($summaryhuge),
                'summary exceeded the absolute byte bound: ' . $context);

            // (3b) Growing the counts by many orders of magnitude only adds digits, never scales
            // the summary. The generator mentions at most three factors, so the extra length is
            // bounded by 3 * (digits of PHP_INT_MAX) with slack; a summary that listed each
            // occurrence would blow past this immediately.
            $maxdigitgrowth = 3 * strlen((string) PHP_INT_MAX);
            $growth = strlen($summaryhuge) - strlen($summarysmall);
            $this->assertGreaterThanOrEqual(0, $growth,
                'huge-count summary was shorter than the small-count summary: ' . $context);
            $this->assertLessThanOrEqual($maxdigitgrowth, $growth,
                'summary length scaled with the evidence count instead of collapsing it: ' . $context);
        }
    }

    /**
     * Assert a summary contains none of the forbidden raw telemetry tokens (case-insensitively).
     *
     * @param string $summary Generated summary.
     * @param string $context Iteration context for failure messages.
     */
    private function assert_telemetry_free(string $summary, string $context): void {
        $haystack = \core_text::strtolower($summary);
        foreach (self::FORBIDDEN_TOKENS as $token) {
            $this->assertFalse(strpos($haystack, $token) !== false,
                'summary leaked raw telemetry token "' . $token . '": ' . $context);
        }
    }

    /**
     * Count the number of sentences in the summary.
     *
     * Sentences are terminated by a period; the generator joins them with a single space. Splitting
     * on a period followed by whitespace or end-of-string and dropping empty segments yields the
     * sentence count. The generated labels contain no periods, so this is unambiguous.
     *
     * @param string $summary Generated summary.
     * @return int Number of non-empty sentences.
     */
    private function count_sentences(string $summary): int {
        $trimmed = trim($summary);
        if ($trimmed === '') {
            return 0;
        }
        $segments = preg_split('/\.(\s+|$)/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        return count($segments);
    }

    /**
     * Generate a random set of factor "shapes" (label, points, haspoints) without counts.
     *
     * Includes zero-factor sets, factors with and without points, and duplicate labels, so the
     * generator's top-factor selection is exercised across the input space.
     *
     * @return array<int, array{label:string, points:int, haspoints:bool}> Factor shapes.
     */
    private function generate_factor_shape(): array {
        $shape = [];
        $numfactors = mt_rand(0, 6);
        for ($i = 0; $i < $numfactors; $i++) {
            $haspoints = (bool) mt_rand(0, 1);
            $shape[] = [
                'label' => self::LABELS[mt_rand(0, count(self::LABELS) - 1)],
                'points' => $haspoints ? mt_rand(1, 60) : 0,
                'haspoints' => $haspoints,
            ];
        }
        return $shape;
    }

    /**
     * Attach counts to a factor shape, either small counts or enormous ones.
     *
     * @param array<int, array{label:string, points:int, haspoints:bool}> $shape Factor shapes.
     * @param bool $huge When true, use very large counts; otherwise small counts.
     * @return array<int, array{label:string, count:int, points:int, haspoints:bool}> Factors.
     */
    private function apply_counts(array $shape, bool $huge): array {
        $factors = [];
        foreach ($shape as $factor) {
            if ($huge) {
                $count = self::HUGE_COUNTS[mt_rand(0, count(self::HUGE_COUNTS) - 1)];
            } else {
                $count = mt_rand(1, 5);
            }
            $factors[] = [
                'label' => $factor['label'],
                'count' => $count,
                'points' => $factor['points'],
                'haspoints' => $factor['haspoints'],
            ];
        }
        return $factors;
    }

    /**
     * Generate a random AI outcome: false, or a stdClass with random status/score/decision.
     *
     * @return \stdClass|false AI review row or false.
     */
    private function generate_ai_outcome() {
        if (mt_rand(0, 2) === 0) {
            return false;
        }

        $statuses = [
            QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED,
            QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING,
            QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            QUIZACCESS_PROCTORING_AI_REVIEW_FAILED,
        ];
        $decisions = [
            '', 'clear', 'inconclusive', 'suspicious', 'highly_suspicious', 'unknown_decision',
        ];

        $aireview = new stdClass();
        $aireview->status = $statuses[mt_rand(0, count($statuses) - 1)];
        $aireview->reviewscore = mt_rand(0, 100);
        $aireview->decision = $decisions[mt_rand(0, count($decisions) - 1)];
        return $aireview;
    }

    /**
     * Clone an AI outcome so the small- and huge-count summaries receive equivalent inputs.
     *
     * @param \stdClass|false $aireview AI review row or false.
     * @return \stdClass|false An equivalent AI outcome.
     */
    private function clone_ai($aireview) {
        if (!is_object($aireview)) {
            return $aireview;
        }
        return clone $aireview;
    }
}
