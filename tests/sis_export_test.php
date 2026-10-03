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
 * Tests for the SIS attempt-summary web service (SIS-204).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use core_external\external_api;
use quizaccess_proctoring\external\get_attempt_summaries;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the gate (setting and capability), the summary contents and the keyset paging.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_proctoring\external\get_attempt_summaries
 * @covers \quizaccess_proctoring\local\sis_export
 */
final class sis_export_test extends advanced_testcase {

    /** @var \stdClass Course. */
    private $course;

    /** @var \stdClass Proctored quiz. */
    private $quiz;

    /** @var \stdClass Proctored quiz course module. */
    private $cm;

    /** @var \stdClass Unproctored quiz. */
    private $plainquiz;

    /** @var \stdClass Student. */
    private $student;

    /** @var \stdClass The SIS web-service user. */
    private $sisuser;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        global $DB;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['shortname' => 'BUS101', 'idnumber' => 'BUS101-D']);
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id, 'name' => 'Final Exam']);
        $this->cm = get_coursemodule_from_instance('quiz', $this->quiz->id);
        $DB->insert_record('quizaccess_proctoring', (object)[
            'quizid' => $this->quiz->id,
            'proctoringrequired' => 1,
        ]);
        $this->plainquiz = $generator->create_module('quiz', ['course' => $this->course->id]);
        $this->student = $generator->create_user();

        // The SIS token's user: the capability at system context, nothing else.
        $this->sisuser = $generator->create_user();
        $roleid = create_role('SIS integration', 'sisintegration', '');
        assign_capability('quizaccess/proctoring:exportsummaries', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $this->sisuser->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Insert a quiz attempt.
     *
     * @param \stdClass $quiz Quiz.
     * @param int $attempt Attempt number.
     * @param int $modified timemodified.
     * @param int $preview Preview flag.
     * @return int Attempt id.
     */
    private function attempt(\stdClass $quiz, int $attempt, int $modified, int $preview = 0): int {
        global $DB;
        static $uniqueid = 9000;
        return (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $this->student->id,
            'attempt' => $attempt,
            'uniqueid' => ++$uniqueid,
            'layout' => '1,0',
            'state' => 'finished',
            'timestart' => $modified - 1800,
            'timefinish' => $modified,
            'timemodified' => $modified,
            'preview' => $preview,
            'sumgrades' => 5,
        ]);
    }

    /**
     * Call the web service as the SIS user and validate the response against its declared shape.
     *
     * @param array $args Arguments.
     * @return array
     */
    private function call(array $args = []): array {
        $this->setUser($this->sisuser);
        $result = get_attempt_summaries::execute(
            $args['since'] ?? 0,
            $args['since_id'] ?? 0,
            $args['limit'] ?? 200,
            $args['attemptids'] ?? []
        );
        return external_api::clean_returnvalue(get_attempt_summaries::execute_returns(), $result);
    }

    public function test_refuses_while_sharing_is_off(): void {
        $this->attempt($this->quiz, 1, time());
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('sisexportdisabled', 'quizaccess_proctoring'));
        $this->call();
    }

    public function test_refuses_without_the_capability(): void {
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        // A site manager holds every archetype capability, and still not this one.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $this->expectException(\required_capability_exception::class);
        get_attempt_summaries::execute();
    }

    public function test_summarises_only_real_proctored_attempts(): void {
        global $DB;
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        set_config('threshold', 80, 'quizaccess_proctoring');
        $now = time();

        $attemptid = $this->attempt($this->quiz, 1, $now - 100);
        $this->attempt($this->quiz, 2, $now - 50, 1);       // A preview: never exported.
        $this->attempt($this->plainquiz, 1, $now - 10);      // Not proctored: never exported.

        // Two captures, one of them a face mismatch below the threshold.
        foreach ([[2, 40], [2, 95]] as [$flag, $score]) {
            $DB->insert_record('quizaccess_proctoring_logs', (object)[
                'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
                'webcampicture' => 'https://example.invalid/capture.png', 'status' => $attemptid,
                'awsscore' => $score, 'awsflag' => $flag, 'deletionprogress' => 0, 'timemodified' => $now - 120,
            ]);
        }
        // One suspicious event and one routine event that is not a violation.
        foreach (['tab_hidden', 'tab_visible'] as $type) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
                'attemptid' => $attemptid, 'reportid' => 0, 'eventtype' => $type, 'eventdetail' => '{}',
                'timemodified' => $now - 110,
            ]);
        }
        // An active hold later released: the decision wins over the lingering active row.
        foreach ([\QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE, \QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED] as $status) {
            $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
                'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
                'attemptid' => $attemptid, 'status' => $status, 'timereviewed' => $status ? $now - 5 : 0,
                'timecreated' => $now - 90, 'timemodified' => $now - 5,
            ]);
        }
        $DB->insert_record('quizaccess_proctoring_ai_reviews', (object)[
            'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
            'attemptid' => $attemptid, 'reviewtype' => 'attempt', 'status' => \QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            'decision' => 'highly_suspicious', 'summary' => 'Private reviewer-facing text',
            'timecreated' => $now - 80, 'timemodified' => $now - 80,
        ]);
        $DB->insert_record('quizaccess_proctoring_idv', (object)[
            'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
            'attemptid' => $attemptid, 'status' => 'pass', 'facescore' => 97, 'namescore' => 100,
            'extractedname' => 'PRIVATE NAME', 'idimageurl' => 'https://example.invalid/id.png',
            'timecreated' => $now - 1900, 'timemodified' => $now - 1900, 'verifiedat' => $now - 1900,
            'profilehash' => str_repeat('a', 64), 'policyhash' => str_repeat('b', 64),
        ]);
        $DB->insert_record('quizaccess_proctoring_overrides', (object)[
            'courseid' => $this->course->id, 'quizid' => 0, 'userid' => $this->student->id,
            'captchastate' => -1, 'webcamstate' => 0, 'idverificationstate' => -1, 'screensharestate' => -1,
            'multimonitorstate' => -1, 'phonedetectionstate' => -1, 'justification' => 'Private accommodation note',
            'expiry' => null, 'revoked' => 0, 'grantedby' => 2, 'timecreated' => $now - 5000, 'timemodified' => $now - 5000,
        ]);

        $result = $this->call();
        $this->assertCount(1, $result['attempts']);
        $row = $result['attempts'][0];

        $this->assertSame($attemptid, $row['attemptid']);
        $this->assertSame('BUS101', $row['course_shortname']);
        $this->assertSame('BUS101-D', $row['course_idnumber']);
        $this->assertSame('Final Exam', $row['quizname']);
        $this->assertSame((int)$this->cm->id, $row['cmid']);
        $this->assertSame(2, $row['capture_count']);
        $this->assertSame(1, $row['face_mismatch_count']);
        $this->assertSame(1, $row['violation_count']);
        $this->assertSame('pass', $row['idv_status']);
        $this->assertSame(97, $row['idv_face_score']);
        $this->assertSame('released', $row['hold_status']);
        $this->assertSame($now - 5, $row['hold_reviewed_at']);
        $this->assertSame('complete', $row['ai_review_status']);
        $this->assertSame('highly_suspicious', $row['ai_review_decision']);
        $this->assertSame(['webcam:off'], $row['overrides']);
        $this->assertGreaterThan(0, $row['risk_score']);
        $this->assertStringContainsString('/mod/quiz/accessrule/proctoring/report.php', $row['report_url']);
        $this->assertFalse($row['reviewed']);

        // Summaries, not evidence: nothing private crosses, whatever the field it would hide in.
        $wire = json_encode($result);
        foreach (['example.invalid', 'PRIVATE NAME', 'Private reviewer-facing text', 'Private accommodation note'] as $secret) {
            $this->assertStringNotContainsString($secret, $wire);
        }
    }

    public function test_reports_an_earlier_pass_as_reused(): void {
        global $DB;
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        $now = time();
        $first = $this->attempt($this->quiz, 1, $now - 5000);
        $second = $this->attempt($this->quiz, 2, $now - 100);
        $DB->insert_record('quizaccess_proctoring_idv', (object)[
            'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
            'attemptid' => $first, 'status' => 'pass', 'facescore' => 91, 'namescore' => 100,
            'timecreated' => $now - 6800, 'timemodified' => $now - 6800, 'verifiedat' => $now - 6800,
            'profilehash' => str_repeat('a', 64), 'policyhash' => str_repeat('b', 64),
        ]);

        $rows = array_column($this->call()['attempts'], null, 'attemptid');
        $this->assertSame('pass', $rows[$first]['idv_status']);
        $this->assertSame('reused_pass', $rows[$second]['idv_status']);
        $this->assertSame($now - 6800, $rows[$second]['idv_verified_at']);
    }

    public function test_a_pass_recorded_after_the_attempt_started_is_not_reused(): void {
        global $DB;
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        $now = time();
        // Attempt 1 runs from now-5000 to now-100; a pass recorded mid-way, for some later attempt,
        // cannot have governed it.
        $first = $this->attempt($this->quiz, 1, $now - 100);
        $DB->set_field('quiz_attempts', 'timestart', $now - 5000, ['id' => $first]);
        $DB->insert_record('quizaccess_proctoring_idv', (object)[
            'courseid' => $this->course->id, 'quizid' => $this->cm->id, 'userid' => $this->student->id,
            'attemptid' => 0, 'status' => 'pass', 'facescore' => 91, 'namescore' => 100,
            'timecreated' => $now - 3000, 'timemodified' => $now - 3000, 'verifiedat' => $now - 3000,
            'profilehash' => str_repeat('a', 64), 'policyhash' => str_repeat('b', 64),
        ]);

        $row = $this->call()['attempts'][0];
        $this->assertSame('none', $row['idv_status']);
        // No ID check means null scores, and the declared shape accepts them.
        $this->assertNull($row['idv_face_score']);
        $this->assertNull($row['idv_name_score']);
    }

    public function test_overrides_are_read_as_they_stood_when_the_attempt_started(): void {
        global $DB;
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        $now = time();
        $attemptid = $this->attempt($this->quiz, 1, $now - 100);
        $start = $now - 1900;
        $override = function (array $over) use ($DB) {
            $DB->insert_record('quizaccess_proctoring_overrides', (object)($over + [
                'courseid' => $this->course->id, 'quizid' => 0, 'userid' => $this->student->id,
                'captchastate' => -1, 'webcamstate' => -1, 'idverificationstate' => -1, 'screensharestate' => -1,
                'multimonitorstate' => -1, 'phonedetectionstate' => -1, 'justification' => 'x',
                'expiry' => null, 'revoked' => 0, 'timerevoked' => 0, 'grantedby' => 2,
            ]));
        };
        // Active at the start, revoked afterwards: it covered this attempt.
        $override(['webcamstate' => 0, 'timecreated' => $start - 500, 'timemodified' => $start - 500,
            'revoked' => 1, 'timerevoked' => $start + 60]);
        // Granted after the start: it did not.
        $override(['captchastate' => 0, 'timecreated' => $start + 30, 'timemodified' => $start + 30]);
        // Revoked before the start: it did not.
        $override(['screensharestate' => 0, 'timecreated' => $start - 900, 'timemodified' => $start - 900,
            'revoked' => 1, 'timerevoked' => $start - 10]);
        // Expired before the start: it did not.
        $override(['multimonitorstate' => 0, 'timecreated' => $start - 900, 'timemodified' => $start - 900,
            'expiry' => $start - 1]);

        $rows = array_column($this->call()['attempts'], null, 'attemptid');
        $this->assertSame(['webcam:off'], $rows[$attemptid]['overrides']);
    }

    public function test_a_full_page_costs_a_bounded_number_of_queries(): void {
        global $DB;
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        $now = time();
        $this->attempt($this->quiz, 1, $now - 500);
        $this->setUser($this->sisuser);
        get_attempt_summaries::execute(0, 0, 200);
        $small = $DB->perf_get_reads();
        get_attempt_summaries::execute(0, 0, 200);
        $one = $DB->perf_get_reads() - $small;

        for ($i = 2; $i <= 40; $i++) {
            $this->attempt($this->quiz, $i, $now - 500 + $i);
        }
        $before = $DB->perf_get_reads();
        $result = get_attempt_summaries::execute(0, 0, 200);
        $forty = $DB->perf_get_reads() - $before;

        $this->assertCount(40, $result['attempts']);
        // Grouped queries, not one per attempt: forty attempts may not cost forty times one.
        $this->assertLessThan($one + 40, $forty, "1 attempt cost {$one} reads, 40 cost {$forty}");
    }

    public function test_pages_on_the_keyset_without_gaps_or_repeats(): void {
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        $now = time();
        // Two attempts share a second, so the id half of the keyset has to do the work.
        $a = $this->attempt($this->quiz, 1, $now - 300);
        $b = $this->attempt($this->quiz, 2, $now - 200);
        $c = $this->attempt($this->quiz, 3, $now - 200);

        $seen = [];
        $since = 0;
        $sinceid = 0;
        for ($i = 0; $i < 5; $i++) {
            $page = $this->call(['since' => $since, 'since_id' => $sinceid, 'limit' => 1]);
            foreach ($page['attempts'] as $row) {
                $seen[] = $row['attemptid'];
            }
            $since = $page['next_since'];
            $sinceid = $page['next_since_id'];
            if (!$page['truncated']) {
                break;
            }
        }
        $this->assertSame([$a, $b, $c], $seen);

        // Drained: resuming from the last position returns nothing and keeps the position.
        $page = $this->call(['since' => $since, 'since_id' => $sinceid]);
        $this->assertSame([], $page['attempts']);
        $this->assertSame($since, $page['next_since']);
        $this->assertSame($sinceid, $page['next_since_id']);
    }

    public function test_refreshes_named_attempts_only(): void {
        set_config('sisexportenabled', 1, 'quizaccess_proctoring');
        $now = time();
        $this->attempt($this->quiz, 1, $now - 300);
        $wanted = $this->attempt($this->quiz, 2, $now - 200);
        $plain = $this->attempt($this->plainquiz, 1, $now - 100);

        $result = $this->call(['attemptids' => [$wanted, $plain, 999999]]);
        $this->assertSame([$wanted], array_column($result['attempts'], 'attemptid'));
        $this->assertFalse($result['truncated']);
    }
}
