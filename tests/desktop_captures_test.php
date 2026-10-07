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
 * Tests for desktop capture totals and absence coverage (CPIT-471).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\desktop_captures;

/**
 * Desktop capture tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\desktop_captures
 */
final class desktop_captures_test extends advanced_testcase {

    /** Scope used by every event in these tests. */
    private const SCOPE = [11, 22, 33, 44];

    /**
     * An absence is its leave events plus the captures taken while away; it is covered when any of
     * them carries a desktop capture. Webcam frames are not desktop captures.
     */
    public function test_absences_and_their_coverage(): void {
        $this->resetAfterTest();
        [$courseid, $cmid, $userid, $attemptid] = self::SCOPE;
        $this->assertSame(
            ['captures' => 0, 'periods' => 0, 'covered' => 0],
            desktop_captures::for_attempt($courseid, $cmid, $userid, $attemptid)
        );

        // Absence A: both leave events, no frame on them, two captures while away.
        $this->event('tab_hidden', ['awaykey' => 'a'], '');
        $this->event('focus_lost', ['awaykey' => 'a'], '');
        $this->event('away_capture', ['awaykey' => 'a', 'sequence' => 1], 'https://example.com/a1.png');
        $this->event('away_capture', ['awaykey' => 'a', 'sequence' => 2], 'https://example.com/a2.png');
        // Absence B: no capture at all, with the reason recorded.
        $this->event('focus_lost', ['awaykey' => 'b'], '');
        $this->event('away_capture', ['awaykey' => 'b', 'sequence' => 1, 'capturemissing' => 'helper_closed'], '');
        // An older leave event without a key is its own absence.
        $this->event('focus_lost', [], 'https://example.com/old.png');
        // Other desktop evidence, and a webcam frame that is not a desktop capture.
        $this->event('possible_ai_tool', [], 'https://example.com/ai.png');
        $this->event('phone_detected', [], 'https://example.com/webcam.png');

        $this->assertSame(
            ['captures' => 4, 'periods' => 3, 'covered' => 2],
            desktop_captures::for_attempt($courseid, $cmid, $userid, $attemptid)
        );
        $summary = desktop_captures::summary($courseid, $cmid, $userid, $attemptid);
        $this->assertStringContainsString('4', $summary);
        $this->assertStringContainsString('2 of 3', $summary);

        // Another attempt's events are not this attempt's.
        $this->assertSame(0, desktop_captures::for_attempt($courseid, $cmid, $userid, $attemptid + 1)['periods']);
    }

    /**
     * With desktop capture recorded as off for the attempt, there is no summary to show.
     */
    public function test_no_summary_when_desktop_capture_was_off(): void {
        $this->resetAfterTest();
        [$courseid, $cmid, $userid, $attemptid] = self::SCOPE;
        $this->event('focus_lost', ['awaykey' => 'a'], '');
        $this->assertNotSame('', desktop_captures::summary($courseid, $cmid, $userid, $attemptid));

        $this->event('monitoring_started', [
            'version' => 1, 'webcam' => true, 'screen' => false, 'interval' => 30,
            'monitors' => ['activity' => true, 'screen' => false],
        ], '');
        $this->assertSame('', desktop_captures::summary($courseid, $cmid, $userid, $attemptid));
    }

    /**
     * Insert one event in the test scope.
     *
     * @param string $eventtype Event type.
     * @param array $detail Event detail.
     * @param string $screenshoturl Attached capture URL, or ''.
     */
    private function event(string $eventtype, array $detail, string $screenshoturl): void {
        global $DB;
        [$courseid, $cmid, $userid, $attemptid] = self::SCOPE;
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
            'eventtype' => $eventtype, 'eventdetail' => json_encode($detail), 'screenshoturl' => $screenshoturl,
            'timemodified' => time(),
        ]);
    }
}
