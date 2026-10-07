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
 * Tests for the messages-shown-to-student timeline (CPIT-481).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\monitoring_coverage;
use quizaccess_proctoring\local\student_messages;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Student message timeline tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\student_messages
 */
final class student_messages_test extends advanced_testcase {

    /**
     * Insert an event.
     *
     * @param string $type Event type.
     * @param array $detail Event detail.
     * @param int $time Event time.
     */
    private function event(string $type, array $detail, int $time): void {
        global $DB;
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => 2, 'quizid' => 3, 'userid' => 4, 'attemptid' => 5, 'reportid' => 0,
            'eventtype' => $type, 'eventdetail' => json_encode($detail), 'timemodified' => $time,
        ]);
    }

    /**
     * Warnings and the face pause are listed in order, with how long each stayed up.
     */
    public function test_timeline_lists_each_warning_with_its_duration(): void {
        $this->resetAfterTest();
        $start = time() - 600;
        $this->event('warning_shown', ['key' => 'quiznotinview'], $start);
        $this->event('focus_lost', [], $start);
        $this->event('warning_cleared', ['key' => 'quiznotinview', 'seconds' => 12], $start + 12);
        $this->event('face_missing_start', ['reason' => 'no_face_in_view'], $start + 60);
        $this->event('face_missing_end', [], $start + 90);
        $this->event('warning_shown', ['key' => 'wrongscreen'], $start + 120);

        $messages = student_messages::for_attempt(2, 3, 4, 5);

        $this->assertCount(3, $messages);
        $this->assertSame(strip_tags(get_string('attemptwarning:quiznotinview', 'quizaccess_proctoring')),
            $messages[0]['message']);
        $this->assertSame(format_time(12), $messages[0]['duration']);
        $this->assertSame(format_time(30), $messages[1]['duration']);
        $this->assertSame('', $messages[2]['duration'], 'a warning still up when the page closed has no end');
        $this->assertSame([], student_messages::for_attempt(2, 3, 4, 6));
    }

    /**
     * The wording logged with a warning is shown, and a resumed face pause stays one message.
     */
    public function test_logged_wording_and_pause_continuation(): void {
        $this->resetAfterTest();
        $start = time() - 600;
        $this->event('warning_shown', ['key' => 'wrongscreen', 'message' => 'Wording at the time'], $start);
        $this->event('face_missing_start', ['reason' => 'no_face_in_view'], $start + 10);
        $this->event('face_missing_end', ['durationseconds' => 20], $start + 30);
        $this->event('face_missing_start', ['continued' => 1], $start + 31);
        $this->event('face_missing_end', ['durationseconds' => 15], $start + 46);

        $messages = student_messages::for_attempt(2, 3, 4, 5);

        $this->assertCount(2, $messages);
        $this->assertSame('Wording at the time', $messages[0]['message']);
        $this->assertSame(format_time(35), $messages[1]['duration']);
    }

    /**
     * Warning events are neutral: never counted as suspicious activity.
     */
    public function test_warning_events_are_neutral(): void {
        $this->assertContains('warning_shown', monitoring_coverage::NEUTRAL_EVENTS);
        $this->assertContains('warning_cleared', monitoring_coverage::NEUTRAL_EVENTS);
        $this->assertNotContains('warning_shown', \quizaccess_proctoring\local\overall_report::SUSPICIOUS_EVENT_TYPES);
    }
}
