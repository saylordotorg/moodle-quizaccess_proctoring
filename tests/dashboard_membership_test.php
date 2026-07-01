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
 * Property-based tests for the cross-course held-certificate dashboard membership (DB path).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\overall_report;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Property-based tests for overall_report::held_certificates() membership.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\overall_report::held_certificates
 */
final class dashboard_membership_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 120;

    /**
     * Feature: proctoring-feedback-improvements, Property 11: Cross-course dashboard lists exactly the held certificates
     *
     * For any collection of risk holds spanning multiple courses, the cross-course
     * held-certificate dashboard contains an attempt if and only if that attempt's resolved
     * certificate label is "held" (i.e. it has an ACTIVE hold and the certificate_state resolver
     * returns 'held'); and after any hold status change, re-deriving the dashboard reflects the
     * updated membership.
     *
     * The test builds a fixed pool of real course/quiz/user fixtures once, then per iteration
     * inserts a generated batch of holds (each on a distinct user so its row is identifiable by
     * fullname, each with a unique attemptid so the resolver looks up exactly that hold) with
     * varied statuses (ACTIVE / RELEASED / CONFIRMED) spread across courses. It computes the
     * expected held membership independently from the same certificate-state rule, asserts the
     * dashboard total and the exact set of listed attempts match, then flips a subset of statuses
     * and asserts the membership updates on the next call.
     *
     * Validates: Requirements 17.1, 17.2
     */
    public function test_dashboard_lists_exactly_the_held_certificates(): void {
        global $DB;

        $this->resetAfterTest();
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260117);

        // A fixed pool of real fixtures backs every generated hold so decorate_held_rows() can
        // resolve the course module, quiz name and risk score it needs. Created once, reused
        // across iterations; only the holds table is regenerated each iteration.
        $courses = [];
        $quizzes = [];
        for ($c = 0; $c < 3; $c++) {
            $course = $this->getDataGenerator()->create_course();
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
            $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
            $courses[$c] = $course;
            $quizzes[$c] = (object)['cm' => $cm, 'instance' => $quiz->id];
        }

        // A pool of users, each enrolled in every course. Each generated hold in an iteration is
        // assigned a distinct user so its dashboard row is uniquely identifiable by fullname.
        $users = [];
        for ($u = 0; $u < 14; $u++) {
            $user = $this->getDataGenerator()->create_user();
            foreach ($courses as $course) {
                $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            }
            $users[$u] = $user;
        }

        $statuschoices = [
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED,
            QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED,
        ];

        $attemptseq = 0;
        $now = time();

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Start each iteration from a clean holds table.
            $DB->delete_records('quizaccess_proctoring_risk_holds');

            // Generate a batch of holds on distinct users (fits comfortably on one page of results).
            $holdcount = mt_rand(3, count($users));
            $userorder = range(0, count($users) - 1);
            shuffle($userorder);

            // Per-hold metadata keyed by hold id: the assigned fullname and current status.
            $holds = [];
            for ($h = 0; $h < $holdcount; $h++) {
                $course = $courses[$h % count($courses)];
                $quiz = $quizzes[$h % count($quizzes)];
                $user = $users[$userorder[$h]];
                $status = (int)$statuschoices[array_rand($statuschoices)];
                $attemptseq++;

                $holdid = (int)$DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
                    'courseid' => $course->id,
                    'quizid' => $quiz->cm->id,
                    'quizinstance' => $quiz->instance,
                    'userid' => $user->id,
                    'attemptid' => $attemptseq,
                    'reportid' => 0,
                    'riskscore' => mt_rand(0, 100),
                    'threshold' => 0,
                    'originalgrade' => null,
                    'status' => $status,
                    'reviewerid' => 0,
                    'timecreated' => $now - mt_rand(0, 5 * DAYSECS),
                    'timemodified' => $now,
                    'timereviewed' => 0,
                    'autoreleaseblockedscore' => 0,
                    'autoreleaseblockedreason' => null,
                ], true);

                $holds[$holdid] = [
                    'fullname' => fullname($user),
                    'status' => $status,
                ];
            }

            $context = 'iteration=' . $iteration;

            // Independently derive the expected membership: an attempt is "held" iff its hold is
            // ACTIVE. No quiz grades are created, and each attempt has exactly one hold looked up by
            // its unique attemptid, so certificate_state() returns 'held' exactly for active holds.
            $this->assert_dashboard_matches($holds, $context . ' initial');

            // Requirement 17.2: change a subset of hold statuses and assert the dashboard reflects
            // the updated membership on re-derivation. Active holds may be released/confirmed
            // (removing them); non-active holds may be re-activated (adding them).
            foreach ($holds as $holdid => $meta) {
                if (mt_rand(0, 1) !== 1) {
                    continue;
                }
                if ($meta['status'] === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
                    $newstatus = mt_rand(0, 1) === 0
                        ? QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED
                        : QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED;
                } else {
                    $newstatus = QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
                }
                $DB->set_field('quizaccess_proctoring_risk_holds', 'status', $newstatus, ['id' => $holdid]);
                $holds[$holdid]['status'] = $newstatus;
            }

            $this->assert_dashboard_matches($holds, $context . ' after status change');
        }
    }

    /**
     * Assert that held_certificates() lists exactly the attempts whose hold is currently active.
     *
     * @param array $holds Map of hold id => ['fullname' => string, 'status' => int].
     * @param string $context Failure-context label identifying the iteration/phase.
     */
    private function assert_dashboard_matches(array $holds, string $context): void {
        // Expected held set: the fullnames of attempts whose hold is ACTIVE (resolver -> 'held').
        $expectednames = [];
        foreach ($holds as $meta) {
            if ($meta['status'] === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
                $expectednames[] = $meta['fullname'];
            }
        }
        sort($expectednames);

        $result = overall_report::held_certificates(0);

        // The total count must equal the number of held attempts.
        $this->assertSame(count($expectednames), (int)$result['total'],
            'dashboard total mismatch: ' . $context);

        // Every listed row must carry the held label, and the listed set must match exactly.
        $heldlabel = quizaccess_proctoring_certificate_state_label('held');
        $actualnames = [];
        foreach ($result['rows'] as $row) {
            $actualnames[] = $row['fullname'];
            // Every listed row must carry the resolved "held" label.
            $this->assertSame($heldlabel, $row['holdlabel'],
                'listed row must carry the held label: ' . $context);
        }
        sort($actualnames);

        $this->assertSame($expectednames, $actualnames,
            'dashboard membership set mismatch: ' . $context);

        $this->assertSame(!empty($expectednames), (bool)$result['hasrows'],
            'hasrows must reflect whether any certificate is held: ' . $context);
    }
}
