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
 * Tests for the per-quiz report rows (CPIT-473).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\report_list;
use quizaccess_proctoring\local\risk_calculator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Report row tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\report_list
 */
final class report_list_test extends advanced_testcase {

    /** @var int Attempts in the large fixture, the size named in the ticket. */
    private const ATTEMPTS = 2000;

    /** @var \stdClass Course. */
    private $course;

    /** @var \stdClass Quiz module record. */
    private $quiz;

    /**
     * A quiz with one capture per student, as the report query returns it.
     *
     * @param int $count Students.
     * @return \stdClass[] Report query rows.
     */
    private function fixture(int $count): array {
        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $now = time();
        $logs = [];
        for ($i = 0; $i < $count; $i++) {
            $logs[] = [
                'courseid' => $this->course->id,
                'quizid' => $this->quiz->cmid,
                'userid' => 100000 + $i,
                'webcampicture' => '',
                'status' => 500000 + $i,
                'timemodified' => $now - $i,
            ];
        }
        $DB->insert_records('quizaccess_proctoring_logs', $logs);

        $records = [];
        foreach ($DB->get_records('quizaccess_proctoring_logs', ['quizid' => $this->quiz->cmid], 'id') as $log) {
            $i = (int)$log->userid - 100000;
            $records[(int)$log->userid] = (object)[
                'studentid' => (int)$log->userid,
                'firstname' => 'Student',
                'lastname' => sprintf('L%05d', $i),
                'email' => 'student' . $i . '@example.com',
                'warningid' => null,
                'reportid' => (int)$log->id,
                'timemodified' => (int)$log->timemodified,
            ];
        }
        return $records;
    }

    /**
     * Build the rows with the report's options.
     *
     * @param array $records Report query rows.
     * @param string $sort Sort key.
     * @param string $dir Direction.
     * @return array report_list::build() result
     */
    private function build(array $records, string $sort = '', string $dir = ''): array {
        return report_list::build($records, [
            'courseid' => (int)$this->course->id,
            'cmid' => (int)$this->quiz->cmid,
            'quiz' => $this->quiz,
            'context' => \context_module::instance($this->quiz->cmid),
            'baseurl' => new \moodle_url('/mod/quiz/accessrule/proctoring/report.php'),
            'islistview' => true,
            'firstnameinitial' => '',
            'lastnameinitial' => '',
            'sort' => $sort,
            'dir' => $dir,
            'offset' => 0,
            'perpage' => 30,
        ]);
    }

    /**
     * A quiz with 2,000 attempts is paged before anything is scored: the page costs a bounded number
     * of queries, where scoring every row one at a time cost about twenty each.
     */
    public function test_large_quiz_scores_only_the_page(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $records = $this->fixture(self::ATTEMPTS);

        $before = $DB->perf_get_reads();
        $list = $this->build($records);
        $reads = $DB->perf_get_reads() - $before;

        $this->assertSame(self::ATTEMPTS, $list['total']);
        $this->assertCount(30, $list['rows']);
        // Newest first by default.
        $this->assertSame('L00000', explode(' ', $list['rows'][0]['fullname'])[1]);
        $this->assertLessThan(400, $reads, "{$reads} reads for one page of a " . self::ATTEMPTS . '-attempt quiz');
    }

    /**
     * Sorting by risk needs every row's score; those are scored in bulk, not one attempt at a time.
     */
    public function test_risk_sort_scores_every_row_in_bulk(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $records = $this->fixture(self::ATTEMPTS);

        $before = $DB->perf_get_reads();
        $list = $this->build($records, 'risk', 'desc');
        $reads = $DB->perf_get_reads() - $before;

        $this->assertSame(self::ATTEMPTS, $list['total']);
        $this->assertCount(30, $list['rows']);
        $this->assertArrayHasKey('riskscore', $list['rows'][0]);
        $this->assertLessThan(1000, $reads, "{$reads} reads to sort a " . self::ATTEMPTS . '-attempt quiz by risk');
    }

    /**
     * The rows carry the same scores, event counts and links the per-row path produced.
     */
    public function test_rows_match_the_single_attempt_path(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $records = $this->fixture(5);
        $target = reset($records);
        foreach (['focus_lost', 'focus_lost', 'clipboard_paste', 'monitoring_started'] as $type) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $this->course->id, 'quizid' => $this->quiz->cmid, 'userid' => $target->studentid,
                'attemptid' => 500000, 'reportid' => $target->reportid, 'eventtype' => $type,
                'eventdetail' => '{}', 'timemodified' => time(),
            ]);
        }

        $list = $this->build($records, 'name', 'asc');
        $this->assertSame(5, $list['total']);
        foreach ($list['rows'] as $row) {
            $single = risk_calculator::calculate_attempt(
                (int)$this->course->id,
                (int)$this->quiz->cmid,
                (int)$row['studentid'],
                (int)$row['reportid']
            );
            $this->assertSame($single['score'], $row['riskscore']);
            $this->assertSame((int)$single['attemptid'], $row['attemptid']);
            $this->assertStringContainsString('attempt=' . $single['attemptid'], $row['attempturl']);
            $this->assertStringContainsString('studentid=' . $row['studentid'], $row['actionmenu']);
        }
        $first = $list['rows'][0];
        $this->assertSame((int)$target->studentid, $first['studentid']);
        // Monitoring markers are not suspicious activity and are not counted.
        $this->assertSame(3, $first['eventcount']);
        $this->assertSame(0, $list['rows'][1]['eventcount']);
    }

    /**
     * The name-initial filter and the page offset apply before the total is counted.
     */
    public function test_initial_filter_and_offset(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $records = $this->fixture(40);

        $options = [
            'courseid' => (int)$this->course->id,
            'cmid' => (int)$this->quiz->cmid,
            'quiz' => $this->quiz,
            'context' => \context_module::instance($this->quiz->cmid),
            'baseurl' => new \moodle_url('/mod/quiz/accessrule/proctoring/report.php'),
            'islistview' => true,
            'firstnameinitial' => '',
            'lastnameinitial' => '',
            'sort' => 'name',
            'dir' => 'asc',
            'offset' => 30,
            'perpage' => 30,
        ];
        $list = report_list::build($records, $options);
        $this->assertSame(40, $list['total']);
        $this->assertCount(10, $list['rows']);
        $this->assertSame('Student L00030', $list['rows'][0]['fullname']);

        $options['offset'] = 0;
        $options['firstnameinitial'] = 'Z';
        $this->assertSame(['rows' => [], 'total' => 0], report_list::build($records, $options));
    }
}
