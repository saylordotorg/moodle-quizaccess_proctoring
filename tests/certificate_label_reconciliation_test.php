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
 * Example tests for the certificate-label resolver reconciliation edge cases.
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
 * Example tests for quizaccess_proctoring_resolve_certificate_label().
 *
 * Feature: proctoring-feedback-improvements
 *
 * These are worked examples (not properties) covering the label-reconciliation edge cases from the
 * design's Testing Strategy: a grade present alongside a stale released hold must resolve to a
 * released/issued certificate and never mislabel as held.
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_resolve_certificate_label
 */
final class certificate_label_reconciliation_test extends advanced_testcase {

    /**
     * A stale released hold with a grade present resolves to 'released' (never 'held').
     *
     * Scenario: the reviewer already released the hold (so a RELEASED row remains on record) and
     * the gradebook now carries a grade for the attempt. The resolver must reconcile the live hold
     * and gradebook state to a released certificate; it must never report the certificate as held.
     *
     * Validates: Requirements 2.2
     */
    public function test_stale_released_hold_with_grade_resolves_released(): void {
        global $DB;

        $this->resetAfterTest();

        // Build a real course + quiz + enrolled user fixture.
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $attemptid = 4321;
        $reportid = 8765;

        // A grade is present in the gradebook for this attempt (certificate is issuable).
        $DB->insert_record('quiz_grades', (object)[
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'grade' => 82.5,
            'timemodified' => time(),
        ]);

        // A stale RELEASED hold row remains on record for the same attempt.
        $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'quizinstance' => $quiz->id,
            'userid' => $student->id,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'riskscore' => 40,
            'threshold' => 0,
            'originalgrade' => null,
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED,
            'reviewerid' => 0,
            'timecreated' => time() - (30 * DAYSECS),
            'timemodified' => time() - (29 * DAYSECS),
            'timereviewed' => time() - (29 * DAYSECS),
            'autoreleaseblockedscore' => 0,
            'autoreleaseblockedreason' => null,
        ]);

        $resolved = quizaccess_proctoring_resolve_certificate_label(
            (int)$course->id, (int)$cm->id, (int)$student->id, $attemptid, $reportid);

        // The reconciled state is released, and it is emphatically never held.
        $this->assertSame('released', $resolved['state'],
            'a grade with a stale released hold must resolve to released');
        $this->assertNotSame('held', $resolved['state'],
            'a released certificate must never be mislabelled as held');
        $this->assertNotSame('withheld', $resolved['state'],
            'a released certificate must never be mislabelled as withheld');
        $this->assertSame(quizaccess_proctoring_certificate_state_label('released'), $resolved['label']);
        $this->assertSame(quizaccess_proctoring_certificate_state_class('released'), $resolved['class']);
    }

    /**
     * A held failing attempt says "no certificate at stake"; a passing one keeps the held label.
     *
     * Scenario (CPIT-463, Stephanie): an attempt below the quiz's pass grade earns no certificate
     * whatever the review decides, so "Review required - grade/certificate held" overstates what
     * hangs on the decision. The resolver reads the attempt's own grade (the hold only empties
     * the gradebook) against the quiz's pass grade.
     */
    public function test_failing_attempt_reads_no_certificate_at_stake(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'grade' => 10, 'sumgrades' => 10,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        // Pass grade 8/10, the Saylor convention Stephanie tested with (80%).
        $DB->set_field('grade_items', 'gradepass', 8.0, [
            'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'courseid' => $course->id,
        ]);

        $now = time();
        $makeattempt = function (int $attemptnumber, int $uniqueid, float $sumgrades) use ($DB, $quiz, $student, $now): int {
            return (int)$DB->insert_record('quiz_attempts', (object)[
                'quiz' => $quiz->id,
                'userid' => $student->id,
                'attempt' => $attemptnumber,
                'uniqueid' => $uniqueid,
                'layout' => '1,0',
                'currentpage' => 0,
                'preview' => 0,
                'state' => 'finished',
                'timestart' => $now - 600,
                'timefinish' => $now - 60,
                'timemodified' => $now,
                'timemodifiedoffline' => 0,
                'timecheckstate' => null,
                'sumgrades' => $sumgrades,
            ]);
        };
        $makehold = function (int $attemptid) use ($DB, $course, $cm, $quiz, $student, $now): void {
            $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
                'courseid' => $course->id,
                'quizid' => $cm->id,
                'quizinstance' => $quiz->id,
                'userid' => $student->id,
                'attemptid' => $attemptid,
                'reportid' => 0,
                'riskscore' => 60,
                'threshold' => 40,
                'originalgrade' => null,
                'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
                'reviewerid' => 0,
                'timecreated' => $now - 100,
                'timemodified' => $now - 100,
                'timereviewed' => null,
                'autoreleaseblockedscore' => 0,
                'autoreleaseblockedreason' => null,
            ]);
        };

        // A failing attempt (6/10 against a pass grade of 8): held, but nothing certifiable.
        $failing = $makeattempt(1, 990001, 6);
        $makehold($failing);
        $resolved = quizaccess_proctoring_resolve_certificate_label(
            (int)$course->id, (int)$cm->id, (int)$student->id, $failing, 0);
        $this->assertSame('held', $resolved['state']);
        $this->assertFalse($resolved['atstake']);
        $this->assertSame(get_string('certificatestate:nocertatstake', 'quizaccess_proctoring'), $resolved['label'],
            'a held failing attempt must say no certificate is at stake');

        // A passing attempt (9/10) keeps the certificate-held label.
        $DB->delete_records('quizaccess_proctoring_risk_holds');
        $passing = $makeattempt(2, 990002, 9);
        $makehold($passing);
        $resolved = quizaccess_proctoring_resolve_certificate_label(
            (int)$course->id, (int)$cm->id, (int)$student->id, $passing, 0);
        $this->assertSame('held', $resolved['state']);
        $this->assertTrue($resolved['atstake']);
        $this->assertSame(quizaccess_proctoring_certificate_state_label('held'), $resolved['label']);

        // No pass grade configured: unknown beats wrong, so the plain label stands.
        $DB->set_field('grade_items', 'gradepass', 0, [
            'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'courseid' => $course->id,
        ]);
        $DB->delete_records('quizaccess_proctoring_risk_holds');
        $makehold($failing);
        $resolved = quizaccess_proctoring_resolve_certificate_label(
            (int)$course->id, (int)$cm->id, (int)$student->id, $failing, 0);
        $this->assertTrue($resolved['atstake']);
        $this->assertSame(quizaccess_proctoring_certificate_state_label('held'), $resolved['label']);
    }

    /**
     * A grade present with no hold on record resolves to 'issued'.
     *
     * Scenario: the attempt was graded and there was never a proctoring hold. The resolver must
     * report the certificate as issued.
     *
     * Validates: Requirements 2.2
     */
    public function test_grade_only_resolves_issued(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $attemptid = 1111;
        $reportid = 2222;

        // Only a grade exists; no risk-hold row is inserted.
        $DB->insert_record('quiz_grades', (object)[
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'grade' => 95.0,
            'timemodified' => time(),
        ]);

        $resolved = quizaccess_proctoring_resolve_certificate_label(
            (int)$course->id, (int)$cm->id, (int)$student->id, $attemptid, $reportid);

        $this->assertSame('issued', $resolved['state'],
            'a grade with no hold on record must resolve to issued');
        $this->assertNotSame('held', $resolved['state'],
            'an issued certificate must never be mislabelled as held');
        $this->assertSame(quizaccess_proctoring_certificate_state_label('issued'), $resolved['label']);
        $this->assertSame(quizaccess_proctoring_certificate_state_class('issued'), $resolved['class']);
    }
}
