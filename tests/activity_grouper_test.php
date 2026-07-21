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
 * Tests for the suspicious-activity episode grouper.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\activity_grouper;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for \quizaccess_proctoring\local\activity_grouper::group().
 *
 * The grouper folds the raw browser-event stream from the attempt page into
 * away-from-exam episodes, standalone flagged events, and routine noise, so the
 * report's Suspicious activity tab can lead with "the student left the exam N
 * times" instead of a flat table.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\activity_grouper
 */
final class activity_grouper_test extends advanced_testcase {

    /**
     * Builds a minimal event record.
     *
     * @param int $id Record id.
     * @param string $type Event type.
     * @param int $time Event timestamp.
     * @param string $screenshot Optional desktop capture URL.
     * @return \stdClass Event record.
     */
    private static function event(int $id, string $type, int $time, string $screenshot = ''): \stdClass {
        return (object)[
            'id' => $id,
            'eventtype' => $type,
            'eventdetail' => '',
            'pagevisibility' => '',
            'currenturl' => '',
            'screenshoturl' => $screenshot,
            'timemodified' => $time,
        ];
    }

    /**
     * A blur/focus pair becomes one closed away episode with the elapsed duration.
     */
    public function test_blur_focus_pair_forms_single_away_episode(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'focus_lost', 100),
            self::event(2, 'focus_returned', 122),
        ]);

        $this->assertCount(1, $grouped['episodes']);
        $episode = $grouped['episodes'][0];
        $this->assertSame('away', $episode['type']);
        $this->assertSame(100, $episode['start']);
        $this->assertSame(122, $episode['end']);
        $this->assertSame(22, $episode['duration']);
        $this->assertCount(2, $episode['events']);
        $this->assertSame(1, $grouped['awaycount']);
        $this->assertSame(22, $grouped['awayseconds']);
    }

    /**
     * A second departure signal (tab_hidden alongside focus_lost) does not split the episode,
     * and events during the gap - including captures - attach to it.
     */
    public function test_events_during_gap_attach_to_episode(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'focus_lost', 100),
            self::event(2, 'tab_hidden', 100),
            self::event(3, 'possible_ai_tool', 121, 'https://example.com/shot.png'),
            self::event(4, 'focus_returned', 148),
            self::event(5, 'tab_visible', 149),
        ]);

        $this->assertCount(1, $grouped['episodes']);
        $episode = $grouped['episodes'][0];
        $this->assertCount(5, $episode['events']);
        $this->assertTrue($episode['hascapture']);
        $this->assertCount(1, $episode['captures']);
        $this->assertSame(48, $episode['duration']);
        $this->assertSame(1, $grouped['capturecount']);
    }

    /**
     * A paste within the grace window after returning belongs to the gap it followed;
     * a paste later than the grace window is its own standalone entry.
     */
    public function test_return_followup_grace_window(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'focus_lost', 100),
            self::event(2, 'focus_returned', 120),
            self::event(3, 'clipboard_paste', 122),
            self::event(4, 'clipboard_paste', 200),
        ]);

        $this->assertCount(2, $grouped['episodes']);
        $this->assertSame('away', $grouped['episodes'][0]['type']);
        $this->assertCount(3, $grouped['episodes'][0]['events']);
        $this->assertSame('signal', $grouped['episodes'][1]['type']);
        $this->assertSame(200, $grouped['episodes'][1]['start']);
    }

    /**
     * Mouse-edge noise and unmatched return events stay out of the episode list.
     */
    public function test_routine_and_unmatched_events_go_to_routine(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'mouse_left_window', 90),
            self::event(2, 'mouse_returned_window', 92),
            self::event(3, 'tab_visible', 300),
        ]);

        $this->assertSame([], $grouped['episodes']);
        $this->assertCount(3, $grouped['routine']);
        $this->assertSame(0, $grouped['awaycount']);
        $this->assertSame(0, $grouped['rawcount']);
    }

    /**
     * A departure with no recorded return stays open: null duration, not counted in away seconds.
     */
    public function test_open_episode_has_null_duration(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'page_exit', 500),
        ]);

        $this->assertCount(1, $grouped['episodes']);
        $this->assertSame('away', $grouped['episodes'][0]['type']);
        $this->assertNull($grouped['episodes'][0]['duration']);
        $this->assertSame(1, $grouped['awaycount']);
        $this->assertSame(0, $grouped['awayseconds']);
    }

    /**
     * A flagged event outside any gap is a standalone signal episode.
     */
    public function test_standalone_signal_episode(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'audio_detected', 100),
        ]);

        $this->assertCount(1, $grouped['episodes']);
        $this->assertSame('signal', $grouped['episodes'][0]['type']);
        $this->assertSame(0, $grouped['awaycount']);
        $this->assertSame(1, $grouped['rawcount']);
    }

    /**
     * Input order does not matter: records are sorted by time before grouping.
     */
    public function test_out_of_order_input_is_sorted(): void {
        $grouped = activity_grouper::group([
            self::event(2, 'focus_returned', 130),
            self::event(3, 'shortcut', 115),
            self::event(1, 'focus_lost', 110),
        ]);

        $this->assertCount(1, $grouped['episodes']);
        $episode = $grouped['episodes'][0];
        $this->assertSame('away', $episode['type']);
        $this->assertSame(20, $episode['duration']);
        $this->assertSame(
            ['focus_lost', 'shortcut', 'focus_returned'],
            array_map(static fn(\stdClass $event): string => $event->eventtype, $episode['events'])
        );
    }

    /**
     * Two separate gaps produce two episodes and the away seconds add up.
     */
    public function test_multiple_gaps_accumulate(): void {
        $grouped = activity_grouper::group([
            self::event(1, 'tab_hidden', 100),
            self::event(2, 'tab_visible', 110),
            self::event(3, 'focus_lost', 200),
            self::event(4, 'focus_returned', 230),
        ]);

        $this->assertSame(2, $grouped['awaycount']);
        $this->assertSame(40, $grouped['awayseconds']);
        $this->assertSame(4, $grouped['rawcount']);
    }
}
