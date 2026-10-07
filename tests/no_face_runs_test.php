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
 * Tests that "no face" scores sustained absences only, never a single missed capture (CPIT-469).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\risk_calculator;

/**
 * No-face run tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\risk_calculator::count_no_face_runs
 * @covers \quizaccess_proctoring\local\risk_calculator::calculate_attempt
 * @covers \quizaccess_proctoring\local\risk_calculator::calculate_many
 */
final class no_face_runs_test extends advanced_testcase {

    /**
     * Only runs of enough captures in a row, lasting at least 10 seconds, count; each run counts once.
     */
    public function test_runs_need_enough_consecutive_captures(): void {
        // Interval 30 s: captures up to 50 s apart are consecutive.
        $this->assertSame(0, risk_calculator::count_no_face_runs([], 30, 3));
        $this->assertSame(0, risk_calculator::count_no_face_runs([1000], 30, 3));
        $this->assertSame(0, risk_calculator::count_no_face_runs([1000, 1030], 30, 3));
        $this->assertSame(1, risk_calculator::count_no_face_runs([1000, 1030, 1060], 30, 3));
        // A long absence is still one event.
        $this->assertSame(1, risk_calculator::count_no_face_runs(range(1000, 1600, 30), 30, 3));
        // A capture with a face in between (a 60 s gap) splits the run, and neither half is enough.
        $this->assertSame(0, risk_calculator::count_no_face_runs([1000, 1030, 1090, 1120], 30, 3));
        // Two separate absences are two events; input order does not matter.
        $this->assertSame(2, risk_calculator::count_no_face_runs([2060, 1000, 1030, 1060, 2000, 2030], 30, 3));
        // Retries of one capture a second apart never make a sustained absence.
        $this->assertSame(0, risk_calculator::count_no_face_runs([1000, 1001, 1002, 1003], 30, 3));
        // The required length is configurable.
        $this->assertSame(1, risk_calculator::count_no_face_runs([1000, 1030], 30, 2));
    }

    /**
     * The required run length comes from the site setting, clamped to 2 to 10.
     */
    public function test_required_captures_setting(): void {
        $this->resetAfterTest();
        $this->assertSame(3, risk_calculator::noface_required_captures());
        set_config('nofacerequiredcaptures', 1, 'quizaccess_proctoring');
        $this->assertSame(2, risk_calculator::noface_required_captures());
        set_config('nofacerequiredcaptures', 50, 'quizaccess_proctoring');
        $this->assertSame(10, risk_calculator::noface_required_captures());
    }

    /**
     * Scoring counts sustained runs from browser misses and face-match misses alike, ignores
     * captures that were not checked and reference photos, and agrees between single and bulk.
     */
    public function test_attempt_scoring_counts_runs(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('autoreconfigurecamshotdelay', 30, 'quizaccess_proctoring');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);

        // Student A: one browser miss, then a three-capture run reported by the face-match service.
        $a = $this->attempt($course->id, $quiz, [
            [0, 1, 0], [30, 0, 0], [60, 1, 0], [90, null, 3], [120, null, 3], [150, null, 3], [180, 1, 0],
        ]);
        // Student B: single misses only, and a run of captures nobody could check.
        $b = $this->attempt($course->id, $quiz, [
            [0, 0, 0], [30, 1, 0], [60, 0, 0], [90, 2, 0], [120, 2, 0], [150, 2, 0],
        ]);

        $this->assertSame(1, $this->noface_count($course->id, $quiz->cmid, $a));
        $this->assertSame(0, $this->noface_count($course->id, $quiz->cmid, $b));

        // A reference photo without a face, whose id happens to equal a capture's, is never a miss.
        $DB->insert_record('quizaccess_proctoring_face_images', (object)[
            'parent_type' => 'admin_image', 'parentid' => $b['logids'][1], 'faceimage' => '',
            'facefound' => 0, 'timemodified' => time(),
        ]);
        $this->assertSame(0, $this->noface_count($course->id, $quiz->cmid, $b));

        $requests = [];
        foreach (['a' => $a, 'b' => $b] as $key => $attempt) {
            $requests[$key] = ['courseid' => (int)$course->id, 'cmid' => (int)$quiz->cmid,
                'userid' => $attempt['userid'], 'reportid' => $attempt['logids'][0]];
        }
        foreach (risk_calculator::calculate_many($requests) as $key => $result) {
            $request = $requests[$key];
            $this->assertEquals(
                risk_calculator::calculate_attempt($request['courseid'], $request['cmid'], $request['userid'], $request['reportid']),
                $result,
                "attempt {$key} scored differently in bulk"
            );
        }
    }

    /**
     * The "no face" evidence count for one attempt.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param array $attempt Result of {@see self::attempt()}.
     * @return int Evidence count of the no-face factor.
     */
    private function noface_count(int $courseid, int $cmid, array $attempt): int {
        $risk = risk_calculator::calculate_attempt($courseid, $cmid, $attempt['userid'], $attempt['logids'][0]);
        foreach ($risk['factors'] as $factor) {
            if ($factor['key'] === 'noface') {
                return (int)$factor['count'];
            }
        }
        $this->fail('no "noface" factor in the result');
    }

    /**
     * Create a finished attempt with webcam captures.
     *
     * @param int $courseid Course ID.
     * @param \stdClass $quiz Quiz module.
     * @param array $captures Each [seconds after start, browser facefound or null for none, awsflag].
     * @return array 'userid' and 'logids' (capture log ids, in order).
     */
    private function attempt(int $courseid, \stdClass $quiz, array $captures): array {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $start = time() - 3600;
        static $unique = 5000;
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id, 'userid' => $user->id, 'attempt' => 1, 'uniqueid' => ++$unique,
            'layout' => '1,0', 'state' => 'finished', 'timestart' => $start, 'timefinish' => $start + 600,
            'timemodified' => time(), 'sumgrades' => 1,
        ]);
        $logids = [];
        foreach ($captures as [$offset, $facefound, $awsflag]) {
            $logid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
                'courseid' => $courseid, 'quizid' => $quiz->cmid, 'userid' => $user->id,
                'webcampicture' => 'https://example.com/c.png', 'status' => $attemptid,
                'awsflag' => $awsflag, 'awsscore' => 0, 'deletionprogress' => 0,
                'capturedat' => $start + $offset, 'timemodified' => $start + $offset + 2,
            ]);
            if ($facefound !== null) {
                $DB->insert_record('quizaccess_proctoring_face_images', (object)[
                    'parent_type' => 'camshot_image', 'parentid' => $logid, 'faceimage' => '',
                    'facefound' => $facefound, 'timemodified' => $start + $offset,
                ]);
            }
            $logids[] = $logid;
        }
        return ['userid' => (int)$user->id, 'logids' => $logids];
    }
}
