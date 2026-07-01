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
 * Property-based tests for the auto-release retention-reason annotation (DB path).
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
 * Property-based tests for quizaccess_proctoring_auto_release_expired_risk_holds() annotation.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_auto_release_expired_risk_holds
 */
final class auto_release_annotation_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 120;

    /** @var int Review window length in days used for the whole test run. */
    private const WINDOW_DAYS = 7;

    /**
     * Feature: proctoring-feedback-improvements, Property 2: Ceiling-blocked holds record their retention reason
     *
     * For any expired active hold whose risk score is at or above the Risk_Ceiling, after the
     * auto-release task runs the hold remains active and its retention annotation is recorded
     * (autoreleaseblockedscore equals the hold's risk score and autoreleaseblockedreason is set to
     * 'riskceiling'); expired active holds whose score is strictly below the ceiling are released;
     * and running the task again does not change the recorded annotation (idempotent -> annotated=0).
     *
     * The test builds fixture holds directly in the database against a single real quiz fixture so
     * the release path can restore grades, sets a random ceiling per iteration, runs the task, and
     * asserts the classification and idempotency across many generated scores/ceilings.
     *
     * Validates: Requirements 1.5
     */
    public function test_ceiling_blocked_holds_record_retention_reason(): void {
        global $DB;

        $this->resetAfterTest();
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240117);

        // Configure the review window and disable student notifications so the release path has no
        // messaging side effects during the generated runs.
        set_config('riskreviewautoreleasedays', self::WINDOW_DAYS, 'quizaccess_proctoring');
        set_config('holddecisionnotify', 0, 'quizaccess_proctoring');

        $this->assertSame(self::WINDOW_DAYS, quizaccess_proctoring_get_risk_review_auto_release_days());

        // A single real quiz fixture backs every generated hold so release_risk_hold() can resolve
        // the course module and quiz instance it requires.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $now = time();
        $window = self::WINDOW_DAYS * DAYSECS;

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Start each iteration from a clean holds table.
            $DB->delete_records('quizaccess_proctoring_risk_holds');

            // Random ceiling in 0..100 (enabled). The accessor clamps, but keep it in range here.
            $ceiling = mt_rand(0, 100);
            set_config('riskreviewceiling', $ceiling, 'quizaccess_proctoring');
            $this->assertSame($ceiling, quizaccess_proctoring_get_risk_review_ceiling());

            // Build a small batch of holds covering the meaningful cases.
            $holdcount = mt_rand(3, 6);
            $expected = [];
            for ($h = 0; $h < $holdcount; $h++) {
                [$holdid, $meta] = $this->insert_generated_hold($course, $cm, $quiz, $student, $now, $window, $ceiling);
                $expected[$holdid] = $meta;
            }

            $context = 'iteration=' . $iteration . ' ceiling=' . $ceiling;

            // First run: annotate ceiling-blocked holds and release below-ceiling expired holds.
            $result = quizaccess_proctoring_auto_release_expired_risk_holds();
            $this->assertArrayHasKey('released', $result, $context);
            $this->assertArrayHasKey('annotated', $result, $context);

            $expectedannotated = 0;
            $expectedreleased = 0;
            foreach ($expected as $holdid => $meta) {
                if ($meta['blocked']) {
                    $expectedannotated++;
                } else if ($meta['released']) {
                    $expectedreleased++;
                }
            }

            $this->assertSame($expectedannotated, (int)$result['annotated'],
                'annotated count mismatch on first run: ' . $context);
            $this->assertSame($expectedreleased, (int)$result['released'],
                'released count mismatch on first run: ' . $context);

            // Assert per-hold state after the first run.
            foreach ($expected as $holdid => $meta) {
                $row = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);

                if ($meta['blocked']) {
                    // Ceiling-blocked expired active hold: stays active, annotated with its score.
                    $this->assertSame(QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE, (int)$row->status,
                        'ceiling-blocked hold should remain active: ' . $context . ' holdid=' . $holdid);
                    $this->assertSame((int)$meta['riskscore'], (int)$row->autoreleaseblockedscore,
                        'annotation score must equal the risk score: ' . $context . ' holdid=' . $holdid);
                    $this->assertSame('riskceiling', (string)$row->autoreleaseblockedreason,
                        'annotation reason must be set to riskceiling: ' . $context . ' holdid=' . $holdid);
                } else if ($meta['released']) {
                    // Below-ceiling expired active hold: released, never annotated.
                    $this->assertSame(QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED, (int)$row->status,
                        'below-ceiling expired hold should be released: ' . $context . ' holdid=' . $holdid);
                    $this->assertSame('', (string)$row->autoreleaseblockedreason,
                        'released hold must not be annotated: ' . $context . ' holdid=' . $holdid);
                } else {
                    // Not expired or not active: untouched by both passes.
                    $this->assertSame((int)$meta['status'], (int)$row->status,
                        'ineligible hold status should be unchanged: ' . $context . ' holdid=' . $holdid);
                    $this->assertSame('', (string)$row->autoreleaseblockedreason,
                        'ineligible hold must not be annotated: ' . $context . ' holdid=' . $holdid);
                }
            }

            // Capture the annotated state to prove the second run leaves it untouched.
            $snapshot = [];
            foreach ($expected as $holdid => $meta) {
                $row = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
                $snapshot[$holdid] = [
                    'status' => (int)$row->status,
                    'score' => (int)$row->autoreleaseblockedscore,
                    'reason' => (string)$row->autoreleaseblockedreason,
                ];
            }

            // Second run: idempotent. Nothing new to annotate and nothing new to release.
            $secondresult = quizaccess_proctoring_auto_release_expired_risk_holds();
            $this->assertSame(0, (int)$secondresult['annotated'],
                'second run must annotate nothing (idempotent): ' . $context);
            $this->assertSame(0, (int)$secondresult['released'],
                'second run must release nothing (idempotent): ' . $context);

            foreach ($snapshot as $holdid => $before) {
                $row = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
                $this->assertSame($before['status'], (int)$row->status,
                    'status changed on idempotent re-run: ' . $context . ' holdid=' . $holdid);
                $this->assertSame($before['score'], (int)$row->autoreleaseblockedscore,
                    'annotation score changed on idempotent re-run: ' . $context . ' holdid=' . $holdid);
                $this->assertSame($before['reason'], (string)$row->autoreleaseblockedreason,
                    'annotation reason changed on idempotent re-run: ' . $context . ' holdid=' . $holdid);
            }
        }
    }

    /**
     * Insert one generated hold and return its id plus the expected classification metadata.
     *
     * The generated hold covers the meaningful cases: expired vs. not-yet-expired, active vs.
     * non-active, and risk score above/below the ceiling.
     *
     * @param \stdClass $course Course fixture.
     * @param \stdClass $cm Quiz course module fixture.
     * @param \stdClass $quiz Quiz instance fixture.
     * @param \stdClass $student Student user fixture.
     * @param int $now Base current timestamp.
     * @param int $window Review window length in seconds.
     * @param int $ceiling Configured risk ceiling for this iteration.
     * @return array{0:int,1:array} [hold id, classification metadata].
     */
    private function insert_generated_hold(
        \stdClass $course,
        \stdClass $cm,
        \stdClass $quiz,
        \stdClass $student,
        int $now,
        int $window,
        int $ceiling
    ): array {
        global $DB;

        // Status: bias towards ACTIVE, but also produce RELEASED and CONFIRMED noise.
        $statuschoices = [
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED,
            QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED,
        ];
        $status = (int)$statuschoices[array_rand($statuschoices)];

        // Expired ~2/3 of the time: timecreated older than the window; otherwise recent.
        $isexpiredchoice = (mt_rand(0, 2) !== 0);
        if ($isexpiredchoice) {
            // Strictly older than the window boundary.
            $timecreated = $now - $window - mt_rand(HOURSECS, 3 * DAYSECS);
        } else {
            // Within the window (not yet expired).
            $timecreated = $now - mt_rand(0, max(1, $window - HOURSECS));
        }

        // Risk score in 0..100, biased to land on both sides of the ceiling.
        $riskscore = mt_rand(0, 100);

        $holdid = (int)$DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'quizinstance' => $quiz->id,
            'userid' => $student->id,
            'attemptid' => 0,
            'reportid' => 0,
            'riskscore' => $riskscore,
            'threshold' => 0,
            'originalgrade' => null,
            'status' => $status,
            'reviewerid' => 0,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
            'timereviewed' => 0,
            'autoreleaseblockedscore' => 0,
            'autoreleaseblockedreason' => null,
        ], true);

        $isactive = ($status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE);
        // The task uses timecreated <= (now - window) as the expiry cutoff.
        $isexpired = ($timecreated > 0) && ($timecreated <= ($now - $window));
        $belowceiling = ($riskscore < $ceiling);

        $blocked = $isactive && $isexpired && !$belowceiling;
        $released = $isactive && $isexpired && $belowceiling;

        $meta = [
            'status' => $status,
            'riskscore' => $riskscore,
            'blocked' => $blocked,
            'released' => $released,
        ];

        return [$holdid, $meta];
    }
}
