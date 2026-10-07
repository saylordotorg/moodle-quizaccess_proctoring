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
 * Tests for the no-face pause totals (CPIT-470).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\face_pauses;

/**
 * Face pause tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\face_pauses
 */
final class face_pauses_test extends advanced_testcase {

    /**
     * Every pause is counted, however many there were, with the total of their recorded durations.
     */
    public function test_every_pause_is_counted_with_its_duration(): void {
        $this->resetAfterTest();
        [$courseid, $cmid, $userid, $attemptid] = [11, 22, 33, 44];

        $this->assertSame(
            ['count' => 0, 'seconds' => 0, 'open' => 0],
            face_pauses::for_attempt($courseid, $cmid, $userid, $attemptid)
        );
        $this->assertSame('', face_pauses::summary($courseid, $cmid, $userid, $attemptid));

        // Ten pauses: more than the "no face" score cap would ever show.
        for ($i = 0; $i < 10; $i++) {
            $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_start', ['reason' => 'no_face_in_view']);
            $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_end', ['durationseconds' => 12]);
        }
        // One more still open when the attempt ended, and a nonsense duration that is bounded.
        $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_start', []);
        $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_start', []);
        $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_end', ['durationseconds' => -50]);

        $totals = face_pauses::for_attempt($courseid, $cmid, $userid, $attemptid);
        $this->assertSame(['count' => 12, 'seconds' => 120, 'open' => 1], $totals);

        // A continuation after a cancelled page leave adds time to its pause, not another pause.
        $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_start', ['continued' => true]);
        $this->event($courseid, $cmid, $userid, $attemptid, 'face_missing_end', ['durationseconds' => 30]);
        $totals = face_pauses::for_attempt($courseid, $cmid, $userid, $attemptid);
        $this->assertSame(['count' => 12, 'seconds' => 150, 'open' => 1], $totals);

        $summary = face_pauses::summary($courseid, $cmid, $userid, $attemptid);
        $this->assertStringContainsString('12', $summary);
        $this->assertStringContainsString(format_time(120), $summary);

        // Another student's, or another attempt's, pauses are not this attempt's.
        $this->assertSame(0, face_pauses::for_attempt($courseid, $cmid, $userid + 1, $attemptid)['count']);
        $this->assertSame(0, face_pauses::for_attempt($courseid, $cmid, $userid, $attemptid + 1)['count']);
    }

    /**
     * Insert one browser event.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @param string $eventtype Event type.
     * @param array $detail Event detail.
     */
    private function event(int $courseid, int $cmid, int $userid, int $attemptid, string $eventtype, array $detail): void {
        global $DB;
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
            'eventtype' => $eventtype, 'eventdetail' => json_encode($detail), 'timemodified' => time(),
        ]);
    }
}
