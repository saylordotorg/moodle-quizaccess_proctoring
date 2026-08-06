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
 * Tests for reviewer sign-off on flagged attempts.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\attempt_review;
use quizaccess_proctoring\local\overall_report;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the decision available on a flagged attempt: a reviewer sign-off.
 *
 * A flagged attempt has detected signals but no grade hold, so there is nothing to release or
 * confirm. These tests cover the sign-off that fills that gap: it moves the attempt out of the
 * flagged set without touching the grade, it is undoable, it names who made the decision, and it
 * stops standing once evidence arrives that it never saw.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_review_test extends advanced_testcase {

    /** @var \stdClass Generated course. */
    private $course;

    /** @var \stdClass Generated quiz course module. */
    private $cm;

    /** @var \stdClass Generated student. */
    private $student;

    /** @var \stdClass Generated reviewer. */
    private $reviewer;

    /** @var int Synthetic attempt id. */
    private int $attemptid = 4242;

    /** @var int Proctoring log id for the attempt. */
    private int $reportid = 0;

    /** @var int Newest evidence timestamp for the attempt. */
    private int $lastactivity = 0;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $quiz->id);
        $this->student = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $this->reviewer = $generator->create_user(['firstname' => 'Rae', 'lastname' => 'Reviewer']);

        // Holds off, so the attempt lands in 'flagged' rather than the actionable queue.
        set_config('riskreviewenabled', 0, 'quizaccess_proctoring');

        $this->lastactivity = time() - HOURSECS;
        $this->reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $this->student->id,
            'webcampicture' => 'data:image/png;base64,AAAA',
            'status' => $this->attemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => $this->lastactivity,
        ], true);

        // Two suspicious events, so the attempt has signals and is therefore flagged.
        for ($i = 0; $i < 2; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $this->course->id,
                'quizid' => $this->cm->id,
                'userid' => $this->student->id,
                'attemptid' => $this->attemptid,
                'reportid' => $this->reportid,
                'eventtype' => 'tab_hidden',
                'eventdetail' => '',
                'timemodified' => $this->lastactivity,
            ]);
        }
    }

    /**
     * Build the report over every attempt in the seeded course.
     *
     * @param string $queue Queue filter.
     * @return array Report data.
     */
    private function build(string $queue = 'all'): array {
        return overall_report::build((int)$this->course->id, 'all', 0, 'recent', 0, $queue);
    }

    /**
     * The single row from a report build.
     *
     * @param string $queue Queue filter.
     * @return array|null Row data, or null when the queue is empty.
     */
    private function row(string $queue = 'all'): ?array {
        $rows = $this->build($queue)['rows'];

        return $rows ? reset($rows) : null;
    }

    /**
     * Record a sign-off for the seeded attempt.
     *
     * @return int Sign-off record id.
     */
    private function sign_off(): int {
        return attempt_review::record(
            (int)$this->course->id,
            (int)$this->cm->id,
            (int)$this->student->id,
            $this->attemptid,
            $this->reportid,
            (int)$this->reviewer->id
        );
    }

    /**
     * Without a sign-off the attempt is flagged, offers the sign-off action, and sits outside both
     * the actionable queue and the reviewed queue - the gap this decision closes.
     */
    public function test_flagged_attempt_starts_unreviewed_and_offers_signoff(): void {
        $row = $this->row();

        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertTrue($row['cansignoff']);
        $this->assertNotSame('', $row['signoffurl']);
        $this->assertSame('', $row['signofflabel']);
        $this->assertSame('', $row['undosignoffurl']);

        // Nothing to release or confirm, which is why the sign-off has to exist.
        $this->assertFalse($row['canact']);

        $this->assertSame([], $this->build('needs')['rows']);
        $this->assertSame([], $this->build('reviewed')['rows']);
    }

    /**
     * Signing off moves the attempt to Reviewed, records who and when, and offers the undo.
     */
    public function test_signoff_moves_the_attempt_to_reviewed(): void {
        $this->sign_off();

        $row = $this->row();
        $this->assertSame('reviewed', $row['reviewstate']);
        $this->assertStringContainsString('Rae Reviewer', $row['signofflabel']);
        $this->assertNotSame('', $row['undosignoffurl']);
        $this->assertFalse($row['cansignoff']);

        // It now answers the Reviewed view, and the counters agree.
        $reviewed = $this->build('reviewed');
        $this->assertCount(1, $reviewed['rows']);
        $this->assertSame(1, $reviewed['summary']['reviewed']);
        $this->assertSame(0, $reviewed['summary']['needsreview']);
    }

    /**
     * The Flagged view is the worklist of attempts still awaiting a sign-off, and a signed-off
     * attempt leaves it. Without this view the only way to find them would be scanning All attempts.
     */
    public function test_flagged_view_lists_only_attempts_awaiting_signoff(): void {
        $flagged = $this->build('flagged');
        $this->assertCount(1, $flagged['rows']);
        $this->assertSame(1, $flagged['summary']['flagged']);

        $this->sign_off();

        $after = $this->build('flagged');
        $this->assertSame([], $after['rows']);
        $this->assertSame(0, $after['summary']['flagged']);
    }

    /**
     * A sign-off changes no grade and opens no hold: it is a record of a reading, nothing more.
     */
    public function test_signoff_does_not_touch_grades_or_holds(): void {
        global $DB;

        $this->sign_off();

        $this->assertFalse($DB->record_exists('quizaccess_proctoring_risk_holds', [
            'courseid' => $this->course->id,
            'userid' => $this->student->id,
        ]));
        $this->assertFalse($DB->record_exists('quiz_grades', ['userid' => $this->student->id]));
    }

    /**
     * Undoing a sign-off puts the attempt back among the flagged.
     */
    public function test_undo_returns_the_attempt_to_flagged(): void {
        $id = $this->sign_off();

        attempt_review::undo($id, (int)$this->course->id, (int)$this->cm->id, (int)$this->reviewer->id);

        $row = $this->row();
        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertTrue($row['cansignoff']);
        $this->assertSame('', $row['signofflabel']);
    }

    /**
     * Evidence recorded after a sign-off overtakes it: the attempt is flagged again, because the
     * decision on record never saw that evidence.
     */
    public function test_newer_evidence_supersedes_a_signoff(): void {
        global $DB;

        $this->sign_off();
        $this->assertSame('reviewed', $this->row()['reviewstate']);

        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $this->student->id,
            'attemptid' => $this->attemptid,
            'reportid' => $this->reportid,
            'eventtype' => 'possible_ai_tool',
            'eventdetail' => '',
            'timemodified' => time() + MINSECS,
        ]);

        $row = $this->row();
        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertTrue($row['cansignoff'], 'a superseded sign-off must be re-signable');
        $this->assertSame('', $row['signofflabel']);
    }

    /**
     * Signing off again after new evidence replaces the old record rather than stacking, so exactly
     * one decision is current and it is the latest one.
     */
    public function test_signing_off_again_supersedes_the_previous_record(): void {
        global $DB;

        $first = $this->sign_off();
        $second = attempt_review::record(
            (int)$this->course->id,
            (int)$this->cm->id,
            (int)$this->student->id,
            $this->attemptid,
            $this->reportid,
            (int)$this->reviewer->id
        );

        $this->assertNotSame($first, $second);
        $this->assertSame(1, (int)$DB->get_field(attempt_review::TABLE, 'revoked', ['id' => $first]));
        $this->assertSame(0, (int)$DB->get_field(attempt_review::TABLE, 'revoked', ['id' => $second]));

        $active = attempt_review::active_for([[
            'courseid' => (int)$this->course->id,
            'cmid' => (int)$this->cm->id,
            'userid' => (int)$this->student->id,
            'attemptid' => $this->attemptid,
            'reportid' => $this->reportid,
        ]]);
        $this->assertCount(1, $active);
        $this->assertSame($second, (int)reset($active)->id);
    }

    /**
     * A sign-off is an attempt-level verdict, so it must not be mistaken for a per-factor
     * false-positive mark and must not change the risk score.
     */
    public function test_signoff_is_not_a_false_positive_mark(): void {
        $before = $this->row()['riskscore'];

        $this->sign_off();

        $this->assertSame($before, $this->row()['riskscore']);
        $this->assertSame([], \quizaccess_proctoring\local\risk_calculator::get_false_positive_marks(
            (int)$this->course->id,
            (int)$this->cm->id,
            (int)$this->student->id,
            $this->attemptid,
            $this->reportid
        ));
    }

    /**
     * The stored factor key is a named sentinel, never an empty string, and never one of the real
     * risk factor keys. Empty strings are the one value Oracle cannot store in a NOT NULL column
     * without a driver workaround, and a row that names itself is readable in the table besides.
     */
    public function test_signoff_stores_a_nonempty_factor_key(): void {
        global $DB;

        $id = $this->sign_off();
        $stored = $DB->get_field(attempt_review::TABLE, 'factorkey', ['id' => $id]);

        $this->assertSame(attempt_review::FACTOR_KEY, $stored);
        $this->assertNotSame('', trim((string)$stored));
        $this->assertArrayNotHasKey(
            attempt_review::FACTOR_KEY,
            \quizaccess_proctoring\local\risk_calculator::FACTOR_DEFAULTS,
            'the sentinel must not collide with a real risk factor key'
        );
    }

    /**
     * A clean attempt has nothing to sign off, so it is offered no such action.
     */
    public function test_clean_attempts_are_not_offered_a_signoff(): void {
        global $DB;

        $DB->delete_records('quizaccess_proctoring_events', ['reportid' => $this->reportid]);

        $row = $this->row();
        $this->assertSame('clean', $row['reviewstate']);
        $this->assertFalse($row['cansignoff']);
        $this->assertSame('', $row['signoffurl']);
    }

    /**
     * A sign-off on one attempt says nothing about another attempt by the same student.
     */
    public function test_signoff_is_scoped_to_one_attempt(): void {
        global $DB;

        $otherattemptid = $this->attemptid + 1;
        $otherreportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $this->student->id,
            'webcampicture' => 'data:image/png;base64,AAAA',
            'status' => $otherattemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => $this->lastactivity,
        ], true);
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $this->student->id,
            'attemptid' => $otherattemptid,
            'reportid' => $otherreportid,
            'eventtype' => 'tab_hidden',
            'eventdetail' => '',
            'timemodified' => $this->lastactivity,
        ]);

        $this->sign_off();

        $states = [];
        foreach ($this->build()['rows'] as $row) {
            $states[] = $row['reviewstate'];
        }
        sort($states);
        $this->assertSame(['flagged', 'reviewed'], $states);
    }
}
