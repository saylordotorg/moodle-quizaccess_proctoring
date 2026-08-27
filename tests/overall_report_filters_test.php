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

    /** @var \stdClass Generated quiz instance. */
    private $quiz;

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
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);

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
        int $riskmax = -1
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
     * With the score cap off a score can exceed 100, and the filters must still reach it.
     *
     * Clamping the upper bound to a flat 100 would drop exactly the attempts a reviewer is looking
     * for: asking for the Critical band on an uncapped site would silently exclude the worst ones.
     */
    public function test_uncapped_scores_above_100_are_still_reachable(): void {
        global $DB;

        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');

        // Pile on evidence across several factors so the sum runs past 100 with no cap to stop it.
        $loud = $this->students['brown'];
        foreach (['multiple_faces_detected', 'possible_ai_tool', 'multiple_monitors_detected',
                  'screen_marker_missing', 'phone_detected', 'audio_detected'] as $eventtype) {
            for ($i = 0; $i < 4; $i++) {
                $DB->insert_record('quizaccess_proctoring_events', (object)[
                    'courseid' => $this->course->id,
                    'quizid' => $this->cm->id,
                    'userid' => $loud->id,
                    'attemptid' => 102,
                    'reportid' => 0,
                    'eventtype' => $eventtype,
                    'eventdetail' => '',
                    'timemodified' => time() - (2 * HOURSECS),
                ]);
            }
        }

        $scores = [];
        foreach ($this->build()['rows'] as $row) {
            $scores[$row['fullname']] = (int)$row['riskscore'];
        }
        $this->assertArrayHasKey(
            'Bo Brown',
            $scores,
            'the uncapped attempt fell out of the unfiltered list, so an upper bound is clamping it'
        );
        $this->assertGreaterThan(100, $scores['Bo Brown'], 'the uncapped score should exceed 100');

        // Pass the bound the page passes, so this exercises the real upper bound rather than the
        // helper's default: the page sends max_possible_score(), which is what a flat 100 broke.
        $max = \quizaccess_proctoring\local\risk_calculator::max_possible_score();
        $this->assertGreaterThan(100, $max, 'with the cap off the reachable maximum exceeds 100');

        // The band the row displays must be the band that finds it.
        $band = '';
        foreach ($this->build()['rows'] as $row) {
            if ($row['fullname'] === 'Bo Brown') {
                $band = $row['levelkey'];
            }
        }
        $this->assertSame('critical', $band);
        $this->assertContains(
            'Bo Brown',
            $this->names($this->build('recent', '', '', '', 'critical', 0, $max)),
            'a >100 attempt must still answer its own band filter'
        );

        // An explicit range whose upper bound is the reachable maximum must include it too.
        $this->assertContains(
            'Bo Brown',
            $this->names($this->build('recent', '', '', '', '', 101, $max))
        );

        // And a range that genuinely excludes it still excludes it.
        $this->assertNotContains(
            'Bo Brown',
            $this->names($this->build('recent', '', '', '', '', 0, 50))
        );
    }

    /**
     * Each row carries the attempt facts the table shows in columns, and the two links staff use to
     * get out of this report: the student's profile and the attempt itself.
     */
    public function test_rows_carry_the_attempt_and_student_columns(): void {
        global $DB;

        // A real quiz attempt, so score and duration have something to report.
        $user = $this->students['clark'];
        $start = time() - (2 * HOURSECS);
        // insert_record ignores an explicit id, so take the one the database assigns and point the
        // seeded proctoring rows at it - otherwise the row and the attempt never match up.
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $this->quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => 7001,
            'layout' => '1,0',
            'state' => 'finished',
            'timestart' => $start,
            'timefinish' => $start + 1800,
            'timemodified' => time(),
            'sumgrades' => 8,
        ], true);
        $DB->set_field(
            'quizaccess_proctoring_logs',
            'status',
            $attemptid,
            ['status' => 103, 'courseid' => $this->course->id]
        );
        $DB->set_field('quizaccess_proctoring_events', 'attemptid', $attemptid, ['attemptid' => 103]);
        $DB->set_field('quiz', 'sumgrades', 10, ['id' => $this->quiz->id]);
        $DB->set_field('quiz', 'grade', 100, ['id' => $this->quiz->id]);

        $row = null;
        foreach ($this->build()['rows'] as $candidate) {
            if ($candidate['fullname'] === 'Cy Clark') {
                $row = $candidate;
            }
        }
        $this->assertNotNull($row);

        // Student identity: name, email and the Moodle id, with a profile link to jump to.
        $this->assertSame((int)$user->id, $row['userid']);
        $this->assertSame($user->email, $row['email']);
        $this->assertStringContainsString('/user/profile.php', $row['profileurl']);
        $this->assertStringContainsString('id=' . $user->id, $row['profileurl']);

        // Attempt facts: 8 of 10 raw rescaled onto a 100-point quiz, and half an hour spent.
        $this->assertSame('80.00 / 100.00', $row['scorelabel']);
        $this->assertSame(format_time(1800), $row['duration']);

        // Account age, and the signup date behind it for the column's tooltip. The generator makes
        // users "now", so backdate this one to something a reviewer would actually be weighing up.
        $DB->set_field('user', 'timecreated', time() - (400 * DAYSECS), ['id' => $user->id]);
        $aged = null;
        foreach ($this->build()['rows'] as $candidate) {
            if ($candidate['fullname'] === 'Cy Clark') {
                $aged = $candidate;
            }
        }
        $this->assertStringContainsString('year', $aged['accountage']);
        $this->assertNotSame('', $aged['accountcreated']);

        // A brand-new account reads as an age rather than as an empty cell. Not pinned to
        // format_time(0) exactly: the generator stamps the account at "now", so a runner that
        // takes a second to get here legitimately renders "1 sec" instead, and that is still the
        // behaviour under test - an age, however small, rather than a blank.
        $this->assertMatchesRegularExpression('/^(now|\d+ secs?)$/', $row['accountage']);

        // Straight into the attempt Moodle recorded.
        $this->assertStringContainsString('/mod/quiz/review.php', $row['attempturl']);
        $this->assertStringContainsString('attempt=' . $attemptid, $row['attempturl']);
    }

    /**
     * Dates match the quiz Grades report, so reading one against the other needs no translating.
     */
    public function test_dates_use_the_quiz_grades_report_format(): void {
        $row = reset($this->build()['rows']);
        // The format is the quiz grades report's, and the zone is the institution's with its name
        // printed - staff read these reports against each other, and a bare wall-clock time that
        // silently followed the server timezone is what made request times read as Central.
        $expected = \quizaccess_proctoring\local\display_time::staff(
            $row === false ? 0 : $this->lastactivity_of($row['fullname'])
        );

        $this->assertSame($expected, $row['lastactivity']);
        // The old format led with a weekday name; the grades report format does not.
        $this->assertStringNotContainsString('day,', $row['lastactivity']);
        // And the zone is named, so nobody has to guess which one it is.
        $this->assertMatchesRegularExpression('/[A-Z]{2,5}$/', $row['lastactivity']);
    }

    /**
     * The newest evidence timestamp for a student, for comparing formatted dates.
     *
     * @param string $fullname Student full name.
     * @return int Timestamp.
     */
    private function lastactivity_of(string $fullname): int {
        global $DB;

        $ids = [
            'Ada Abbott' => 101,
            'Bo Brown' => 102,
            'Cy Clark' => 103,
        ];
        $attemptid = $ids[$fullname] ?? 0;

        return (int)$DB->get_field(
            'quizaccess_proctoring_logs',
            'timemodified',
            ['status' => $attemptid, 'courseid' => $this->course->id]
        );
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
