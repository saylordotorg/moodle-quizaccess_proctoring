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
 * Tests for the student and risk filters on the site-wide attempts report.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\overall_report;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the attempts-report filters that narrow or order the list by student and by risk.
 *
 * The report aggregates its rows in PHP, so search, the initials bars and the name/email sorts all
 * have to see the whole set before pagination. These tests assert that: the filters act on the set
 * rather than the visible page, the counters above the list agree with the filtered rows, and each
 * sort produces the order its label promises.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overall_report_filters_test extends advanced_testcase {

    /** @var \stdClass Generated course. */
    private $course;

    /** @var \stdClass Generated quiz course module. */
    private $cm;

    /** @var array<string, \stdClass> Generated students keyed by a short handle. */
    private array $students = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();

        global $DB;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $quiz->id);

        // Three students whose names and emails sort differently from one another, so a sort that
        // silently falls back to another key cannot pass by coincidence.
        $this->students['abbott'] = $generator->create_user([
            'firstname' => 'Ada',
            'lastname' => 'Abbott',
            'email' => 'zoe.last@example.com',
        ]);
        $this->students['brown'] = $generator->create_user([
            'firstname' => 'Bo',
            'lastname' => 'Brown',
            'email' => 'aaron.first@example.com',
        ]);
        $this->students['clark'] = $generator->create_user([
            'firstname' => 'Cy',
            'lastname' => 'Clark',
            'email' => 'mid.middle@example.com',
        ]);

        // One attempt each, ordered in time: Abbott oldest, Clark newest.
        $now = time();
        $this->seed_attempt($this->students['abbott'], 101, $now - (3 * HOURSECS), 0);
        $this->seed_attempt($this->students['brown'], 102, $now - (2 * HOURSECS), 3);
        $this->seed_attempt($this->students['clark'], 103, $now - HOURSECS, 1);

        // Risk holds are irrelevant here, so every row lands in 'flagged' or 'clean' and the tests
        // read the 'all' queue.
        set_config('riskreviewenabled', 0, 'quizaccess_proctoring');
    }

    /**
     * Seed one proctored attempt: a capture row plus a number of suspicious browser events.
     *
     * @param \stdClass $user Student.
     * @param int $attemptid Synthetic quiz attempt id (the logs table stores it in 'status').
     * @param int $when Timestamp for the capture and the events.
     * @param int $events How many suspicious events to record.
     * @return int The proctoring log id.
     */
    private function seed_attempt(\stdClass $user, int $attemptid, int $when, int $events): int {
        global $DB;

        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->cm->id,
            'userid' => $user->id,
            'webcampicture' => 'data:image/png;base64,AAAA',
            'status' => $attemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => $when,
        ], true);

        for ($i = 0; $i < $events; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $this->course->id,
                'quizid' => $this->cm->id,
                'userid' => $user->id,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
                'eventtype' => 'tab_hidden',
                'eventdetail' => '',
                'timemodified' => $when,
            ]);
        }

        return $reportid;
    }

    /**
     * Build the report over all attempts in the seeded course.
     *
     * @param string $sort Sort key.
     * @param string $search Student search string.
     * @param string $tifirst First-name initial.
     * @param string $tilast Surname initial.
     * @param string $risklevel Risk band key.
     * @param int $riskmin Lowest risk score.
     * @param int $riskmax Highest risk score.
     * @return array Report data.
     */
    private function build(
        string $sort = 'recent',
        string $search = '',
        string $tifirst = '',
        string $tilast = '',
        string $risklevel = '',
        int $riskmin = 0,
        int $riskmax = 100
    ): array {
        return overall_report::build(
            (int)$this->course->id,
            'all',
            0,
            $sort,
            0,
            'all',
            $search,
            $tifirst,
            $tilast,
            $risklevel,
            $riskmin,
            $riskmax
        );
    }

    /**
     * Extract the student names from a report's rows, in row order.
     *
     * @param array $data Report data.
     * @return string[] Full names.
     */
    private function names(array $data): array {
        return array_map(function ($row) {
            return $row['fullname'];
        }, $data['rows']);
    }

    /**
     * The unfiltered report lists every seeded attempt, newest activity first.
     */
    public function test_default_sort_is_newest_first(): void {
        $data = $this->build();

        $this->assertSame(['Cy Clark', 'Bo Brown', 'Ada Abbott'], $this->names($data));
        $this->assertSame(3, $data['summary']['totalattempts']);
    }

    /**
     * 'oldest' reverses that order.
     */
    public function test_oldest_sort_reverses_the_order(): void {
        $this->assertSame(['Ada Abbott', 'Bo Brown', 'Cy Clark'], $this->names($this->build('oldest')));
    }

    /**
     * Sorting by student name orders by the displayed name, not by activity or event count.
     */
    public function test_student_sort_orders_by_displayed_name(): void {
        $this->assertSame(['Ada Abbott', 'Bo Brown', 'Cy Clark'], $this->names($this->build('student')));
    }

    /**
     * Sorting by email orders by address, which here is a different order from the names.
     */
    public function test_email_sort_orders_by_address(): void {
        $data = $this->build('email');

        $this->assertSame(['Bo Brown', 'Cy Clark', 'Ada Abbott'], $this->names($data));
        $this->assertSame(
            ['aaron.first@example.com', 'mid.middle@example.com', 'zoe.last@example.com'],
            array_map(function ($row) {
                return $row['email'];
            }, $data['rows'])
        );
    }

    /**
     * Most detected events first, which is the count the list now shows on each row.
     */
    public function test_violations_sort_orders_by_detected_events(): void {
        $data = $this->build('violations');

        $this->assertSame(['Bo Brown', 'Cy Clark', 'Ada Abbott'], $this->names($data));
        $this->assertSame(3, $data['rows'][0]['violations']);
    }

    /**
     * A search matches part of a name, case-insensitively, and the counters follow it.
     */
    public function test_search_matches_partial_name(): void {
        $data = $this->build('recent', 'brow');

        $this->assertSame(['Bo Brown'], $this->names($data));
        $this->assertSame(1, $data['summary']['totalattempts']);
    }

    /**
     * A search also matches the email address, so an address pasted from a ticket works.
     */
    public function test_search_matches_email_address(): void {
        $this->assertSame(['Ada Abbott'], $this->names($this->build('recent', 'ZOE.LAST@example.com')));
    }

    /**
     * A search that matches nobody returns an empty report rather than everything.
     */
    public function test_search_with_no_match_returns_nothing(): void {
        $data = $this->build('recent', 'nobody-by-this-name');

        $this->assertSame([], $data['rows']);
        $this->assertFalse($data['hasrows']);
        $this->assertSame(0, $data['summary']['totalattempts']);
    }

    /**
     * The initials bars filter on first name and surname, and combine with each other.
     */
    public function test_initials_bars_filter_by_first_name_and_surname(): void {
        $this->assertSame(['Bo Brown'], $this->names($this->build('recent', '', 'B')));
        $this->assertSame(['Cy Clark'], $this->names($this->build('recent', '', '', 'C')));
        $this->assertSame([], $this->names($this->build('recent', '', 'B', 'C')));
        $this->assertSame(['Bo Brown'], $this->names($this->build('recent', '', 'b', 'b')));
    }

    /**
     * The search and the initials bars intersect rather than override one another.
     */
    public function test_search_and_initials_combine(): void {
        $this->assertSame(['Bo Brown'], $this->names($this->build('recent', 'brown', 'B')));
        $this->assertSame([], $this->names($this->build('recent', 'brown', 'A')));
    }

    /**
     * A risk score range keeps only attempts whose recomputed score falls inside it, and the row
     * carries the same score the filter judged it on.
     */
    public function test_risk_range_filters_on_the_recomputed_score(): void {
        $unfiltered = $this->build();
        $scores = [];
        foreach ($unfiltered['rows'] as $row) {
            $scores[$row['fullname']] = (int)$row['riskscore'];
        }
        $this->assertGreaterThan($scores['Ada Abbott'], $scores['Bo Brown']);

        // A range that excludes the zero-score attempt keeps the two that scored.
        $data = $this->build('recent', '', '', '', '', 1, 100);
        $names = $this->names($data);
        $this->assertNotContains('Ada Abbott', $names);
        $this->assertContains('Bo Brown', $names);
        $this->assertSame(count($names), $data['summary']['totalattempts']);
        foreach ($data['rows'] as $row) {
            $this->assertGreaterThanOrEqual(1, (int)$row['riskscore']);
        }

        // A range that only the zero-score attempt satisfies keeps just that one.
        $this->assertSame(['Ada Abbott'], $this->names($this->build('recent', '', '', '', '', 0, 0)));
    }

    /**
     * A band filter keeps only attempts whose level matches, using the same band the row displays.
     */
    public function test_risk_band_filter_matches_the_displayed_band(): void {
        $data = $this->build('recent', '', '', '', 'low');

        $this->assertNotEmpty($data['rows']);
        foreach ($data['rows'] as $row) {
            $this->assertSame('low', $row['levelkey']);
        }

        // Nothing seeded here reaches critical, so that band is empty.
        $this->assertSame([], $this->build('recent', '', '', '', 'critical')['rows']);
    }

    /**
     * The score a risk filter judged a row on is the score the row then displays: the filter and the
     * list read one bulk scoring pass, so they cannot disagree.
     */
    public function test_filtered_rows_display_the_score_they_were_judged_on(): void {
        $unfiltered = [];
        foreach ($this->build()['rows'] as $row) {
            $unfiltered[$row['fullname']] = [(int)$row['riskscore'], $row['levelkey']];
        }

        foreach ($this->build('recent', '', '', '', '', 1, 100)['rows'] as $row) {
            $this->assertSame($unfiltered[$row['fullname']], [(int)$row['riskscore'], $row['levelkey']]);
        }
    }
}
