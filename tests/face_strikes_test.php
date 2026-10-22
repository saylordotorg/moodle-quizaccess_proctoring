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
 * Tests for the escalating "face out of frame" warnings that end the attempt (CPIT-510).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\face_strikes;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Face strike tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\face_strikes
 * @covers \quizaccess_proctoring\proctoring_observer
 * @covers \quizaccess_proctoring_external
 */
final class face_strikes_test extends advanced_testcase {
    /** @var \stdClass Course. */
    private $course;

    /** @var \stdClass Quiz, with its cmid. */
    private $quiz;

    /** @var \stdClass Student. */
    private $student;

    /**
     * A proctored quiz on a site that blurs without a face and ends an attempt after 4 strikes.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('blurquizwithoutface', 1, 'quizaccess_proctoring');
        set_config('facestrikes', 4, 'quizaccess_proctoring');
        set_config('holdcreatednotify', 0, 'quizaccess_proctoring');
        set_config('holddecisionnotify', 0, 'quizaccess_proctoring');

        global $DB;

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->quiz = $generator->create_module('quiz', [
            'course' => $this->course->id,
            'proctoringrequired' => 1,
            'grade' => 10,
        ]);
        // Directly inserted attempts carry their own marks, out of 10.
        $DB->set_field('quiz', 'sumgrades', 10, ['id' => $this->quiz->id]);
        $this->quiz->sumgrades = 10;
        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * The quiz's own limit wins over the site's, and a per-student override wins over both.
     */
    public function test_limit_follows_site_quiz_and_student_override(): void {
        global $DB;

        $limit = fn() => face_strikes::limit_for((int)$this->course->id, (int)$this->quiz->id, (int)$this->student->id, time());
        $this->assertSame(4, $limit());

        // Strikes are face pauses: without the blur there is nothing to count.
        set_config('blurquizwithoutface', 0, 'quizaccess_proctoring');
        $this->assertSame(0, $limit());
        set_config('blurquizwithoutface', 1, 'quizaccess_proctoring');

        $this->assertSame(0, face_strikes::clamp_limit(0));
        $this->assertSame(face_strikes::MIN_LIMIT, face_strikes::clamp_limit(1));
        $this->assertSame(face_strikes::MAX_LIMIT, face_strikes::clamp_limit(50));

        $DB->set_field('quizaccess_proctoring', 'facestrikes', 3, ['quizid' => $this->quiz->id]);
        $this->assertSame(3, $limit());
        $DB->set_field('quizaccess_proctoring', 'facestrikes', 0, ['quizid' => $this->quiz->id]);
        $this->assertSame(0, $limit(), 'A quiz can turn the rule off where the site has it on.');

        // Forced on for a student where neither the quiz nor the site sets a limit.
        set_config('facestrikes', 0, 'quizaccess_proctoring');
        $overrideid = $this->override(1);
        $this->assertSame(face_strikes::DEFAULT_LIMIT, $limit());

        // An accommodation waives it where the site has it on.
        set_config('facestrikes', 5, 'quizaccess_proctoring');
        $DB->set_field('quizaccess_proctoring', 'facestrikes', -1, ['quizid' => $this->quiz->id]);
        $DB->set_field('quizaccess_proctoring_overrides', 'revoked', 1, ['id' => $overrideid]);
        $this->assertSame(5, $limit());
        $this->override(0);
        $this->assertSame(0, $limit());
    }

    /**
     * The attempt ends on the strike that reaches the limit, and only once.
     */
    public function test_strikes_end_the_attempt_at_the_limit_once(): void {
        global $DB;

        [$attemptid, $reportid] = $this->attempt('inprogress');
        for ($i = 1; $i <= 3; $i++) {
            $this->strike($attemptid, $reportid);
            $state = $this->record($attemptid, $reportid);
            $this->assertSame(['strikes' => $i, 'limit' => 4, 'ended' => false], $state);
        }
        $this->assertFalse(face_strikes::ended($attemptid, (int)$this->student->id));

        $this->strike($attemptid, $reportid);
        $this->assertSame(['strikes' => 4, 'limit' => 4, 'ended' => true], $this->record($attemptid, $reportid));
        $ended = face_strikes::ended_event($attemptid, (int)$this->student->id);
        $this->assertNotNull($ended);
        $this->assertSame(
            ['strikes' => 4, 'limit' => 4, 'outcome' => face_strikes::OUTCOME_HOLD],
            json_decode($ended->eventdetail, true)
        );

        // A strike still on its way when the attempt ended changes nothing.
        $this->strike($attemptid, $reportid);
        $this->assertSame(['strikes' => 5, 'limit' => 4, 'ended' => true], $this->record($attemptid, $reportid));
        $this->assertSame(1, $DB->count_records('quizaccess_proctoring_events', [
            'attemptid' => $attemptid,
            'eventtype' => face_strikes::EVENT_ENDED,
        ]));

        $this->assertSame(
            get_string('verdict:facestrikesended', 'quizaccess_proctoring', 4),
            face_strikes::summary((int)$this->student->id, $attemptid)
        );
    }

    /**
     * An attempt keeps the rule it started under: a setting changed meanwhile cannot end it sooner
     * than its warnings said, or change what happens when it ends.
     */
    public function test_attempt_keeps_the_rule_it_started_under(): void {
        [$attemptid, $reportid] = $this->attempt('inprogress');
        $rule = face_strikes::attempt_rule(
            (int)$this->course->id,
            (int)$this->quiz->cmid,
            (int)$this->student->id,
            $attemptid,
            $reportid
        );
        $this->assertSame(['limit' => 4, 'outcome' => face_strikes::OUTCOME_HOLD], $rule);

        set_config('facestrikes', 2, 'quizaccess_proctoring');
        set_config('facestrikesoutcome', face_strikes::OUTCOME_AUTOFAIL, 'quizaccess_proctoring');
        for ($i = 1; $i <= 3; $i++) {
            $this->strike($attemptid, $reportid);
            $this->assertFalse($this->record($attemptid, $reportid)['ended']);
        }
        $this->strike($attemptid, $reportid);
        $this->assertTrue($this->record($attemptid, $reportid)['ended']);
        $this->assertSame(face_strikes::OUTCOME_HOLD, face_strikes::ended_outcome($attemptid, (int)$this->student->id));

        // A new attempt takes the rule as it is now.
        [$next, $nextreport] = $this->attempt('inprogress');
        $this->assertSame(
            ['limit' => 2, 'outcome' => face_strikes::OUTCOME_AUTOFAIL],
            face_strikes::attempt_rule((int)$this->course->id, (int)$this->quiz->cmid, (int)$this->student->id, $next, $nextreport)
        );
    }

    /**
     * Only an open attempt can be ended, and a waived student never is.
     */
    public function test_finished_or_waived_attempts_are_not_ended(): void {
        [$finished, $finishedreport] = $this->attempt('finished');
        for ($i = 0; $i < 4; $i++) {
            $this->strike($finished, $finishedreport);
        }
        $this->assertFalse($this->record($finished, $finishedreport)['ended']);

        $this->override(0);
        [$attemptid, $reportid] = $this->attempt('inprogress');
        for ($i = 0; $i < 4; $i++) {
            $this->strike($attemptid, $reportid);
        }
        $state = $this->record($attemptid, $reportid);
        $this->assertSame(0, $state['limit']);
        $this->assertFalse($state['ended']);
        $this->assertSame(
            get_string('verdict:facestrikes', 'quizaccess_proctoring', 4),
            face_strikes::summary((int)$this->student->id, $attemptid)
        );
    }

    /**
     * The log_event answer tells the page the count, the limit and whether the attempt ended, also
     * for a retried strike whose first answer was lost.
     */
    public function test_log_event_answers_with_the_strike_state(): void {
        $this->setUser($this->student);
        [$attemptid, $reportid] = $this->attempt('inprogress');
        $send = function (string $requestid) use ($attemptid, $reportid) {
            $result = \quizaccess_proctoring_external::log_event(
                $this->course->id,
                $this->quiz->cmid,
                $attemptid,
                $reportid,
                face_strikes::EVENT_STRIKE,
                '{"strike":1}',
                'visible',
                '',
                '',
                time(),
                $requestid
            );
            return \core_external\external_api::clean_returnvalue(\quizaccess_proctoring_external::log_event_returns(), $result);
        };

        $first = $send('strike-1');
        $this->assertSame(1, $first['facestrikes']);
        $this->assertSame(4, $first['facestrikelimit']);
        $this->assertFalse($first['attemptended']);

        $retry = $send('strike-1');
        $this->assertSame($first['eventid'], $retry['eventid']);
        $this->assertSame(1, $retry['facestrikes']);

        $send('strike-2');
        $send('strike-3');
        $last = $send('strike-4');
        $this->assertSame(4, $last['facestrikes']);
        $this->assertTrue($last['attemptended']);

        // The page cannot write the ending event itself.
        $forged = \quizaccess_proctoring_external::log_event(
            $this->course->id,
            $this->quiz->cmid,
            $attemptid,
            $reportid,
            face_strikes::EVENT_ENDED
        );
        $this->assertArrayNotHasKey('attemptended', $forged);
        $this->assertSame(1, $this->count_events($attemptid, face_strikes::EVENT_ENDED));

        // Other events carry no strike fields.
        $other = \quizaccess_proctoring_external::log_event(
            $this->course->id,
            $this->quiz->cmid,
            $attemptid,
            $reportid,
            'tab_hidden'
        );
        $this->assertArrayNotHasKey('facestrikes', $other);
    }

    /**
     * An attempt the strikes ended is submitted server-side when its page never did it.
     */
    public function test_ended_attempt_left_open_is_submitted(): void {
        global $DB;

        $this->redirectMessages();
        $this->redirectEmails();
        set_config('facestrikesoutcome', face_strikes::OUTCOME_NONE, 'quizaccess_proctoring');
        $attemptid = $this->real_attempt();
        $this->assertFalse(face_strikes::finish_ended_attempt($attemptid, false), 'Not ended: nothing to submit.');
        $this->assertSame(0, face_strikes::finish_abandoned_attempts(time()));

        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->cmid,
            'userid' => $this->student->id,
            'attemptid' => $attemptid,
            'eventtype' => face_strikes::EVENT_ENDED,
            'eventdetail' => json_encode(['strikes' => 4, 'limit' => 4, 'outcome' => face_strikes::OUTCOME_NONE]),
            'timemodified' => time(),
        ]);
        // The page has its grace period to submit the attempt itself, with its answers.
        $this->assertSame(0, face_strikes::finish_abandoned_attempts(time()));
        $this->assertSame(1, face_strikes::finish_abandoned_attempts(time() + face_strikes::FINISH_GRACE_SECONDS + 1));
        $this->assertSame('finished', $DB->get_field('quiz_attempts', 'state', ['id' => $attemptid]));
        $this->assertFalse(face_strikes::finish_ended_attempt($attemptid, false), 'Already submitted.');
    }

    /**
     * The submission applies the configured outcome to an attempt the strikes ended, whatever the
     * risk score, and leaves every other attempt alone.
     *
     * @dataProvider outcome_provider
     * @param string $outcome Configured outcome.
     * @param int|null $status Expected hold status, or null for no hold.
     */
    public function test_submission_applies_the_configured_outcome(string $outcome, ?int $status): void {
        global $DB;

        $this->redirectMessages();
        set_config('facestrikesoutcome', $outcome, 'quizaccess_proctoring');
        [$attemptid, $reportid] = $this->attempt('finished');
        [$untouched, $untouchedreport] = $this->attempt('finished');
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->cmid,
            'userid' => $this->student->id,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'eventtype' => face_strikes::EVENT_ENDED,
            'eventdetail' => json_encode(['strikes' => 4, 'limit' => 4, 'outcome' => $outcome]),
            'timemodified' => time(),
        ]);

        foreach ([$attemptid, $untouched] as $id) {
            proctoring_observer::handle_quiz_attempt_submitted(\mod_quiz\event\attempt_submitted::create([
                'objectid' => $id,
                'relateduserid' => $this->student->id,
                'courseid' => $this->course->id,
                'context' => \context_module::instance($this->quiz->cmid),
                'other' => ['submitterid' => $this->student->id, 'quizid' => $this->quiz->id],
            ]));
        }

        $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['attemptid' => $attemptid]);
        if ($status === null) {
            $this->assertFalse($hold);
        } else {
            $this->assertSame($status, (int)$hold->status);
        }
        $this->assertFalse(
            $DB->record_exists('quizaccess_proctoring_risk_holds', ['attemptid' => $untouched]),
            'With high-risk review off, an attempt the strikes did not end is not held.'
        );
    }

    /**
     * Moodle 5.0 submits before it grades: the submission quietly holds the grade, and the attempt
     * is failed once graded.
     */
    public function test_automatic_failure_waits_for_grading_where_moodle_grades_after_submitting(): void {
        global $DB;

        set_config('facestrikesoutcome', face_strikes::OUTCOME_AUTOFAIL, 'quizaccess_proctoring');
        set_config('holdcreatednotify', 1, 'quizaccess_proctoring');
        $sink = $this->redirectMessages();
        [$attemptid, $reportid] = $this->attempt('finished');
        $DB->set_field('quiz_attempts', 'state', 'submitted', ['id' => $attemptid]);
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->cmid,
            'userid' => $this->student->id,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'eventtype' => face_strikes::EVENT_ENDED,
            'eventdetail' => json_encode(['strikes' => 4, 'limit' => 4, 'outcome' => face_strikes::OUTCOME_AUTOFAIL]),
            'timemodified' => time(),
        ]);
        $event = \mod_quiz\event\attempt_submitted::create([
            'objectid' => $attemptid,
            'relateduserid' => $this->student->id,
            'courseid' => $this->course->id,
            'context' => \context_module::instance($this->quiz->cmid),
            'other' => ['submitterid' => $this->student->id, 'quizid' => $this->quiz->id],
        ]);

        proctoring_observer::handle_quiz_attempt_submitted($event);
        $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertSame(QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE, (int)$hold->status);
        $this->assertSame(0, $sink->count(), 'No "held for review" notice for an attempt about to be failed.');

        // Not graded yet: nothing more happens.
        proctoring_observer::handle_quiz_attempt_graded($event);
        $this->assertSame(
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            (int)$DB->get_field('quizaccess_proctoring_risk_holds', 'status', ['id' => $hold->id])
        );

        // The outcome recorded when the attempt ended stands, whatever the setting says now.
        set_config('facestrikesoutcome', face_strikes::OUTCOME_NONE, 'quizaccess_proctoring');
        $DB->set_field('quiz_attempts', 'state', 'finished', ['id' => $attemptid]);
        proctoring_observer::handle_quiz_attempt_graded($event);
        $this->assertSame(
            QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED,
            (int)$DB->get_field('quizaccess_proctoring_risk_holds', 'status', ['id' => $hold->id])
        );
    }

    /**
     * Outcomes and the hold status each leaves.
     *
     * @return array Cases.
     */
    public static function outcome_provider(): array {
        return [
            'hold for review' => [face_strikes::OUTCOME_HOLD, QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE],
            'fail automatically' => [face_strikes::OUTCOME_AUTOFAIL, QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED],
            'only submit' => [face_strikes::OUTCOME_NONE, null],
        ];
    }

    /**
     * The attempt page learns the limit and the strikes so far, so a reload resets nothing.
     */
    public function test_page_state_carries_the_count_across_pages(): void {
        [$attemptid, $reportid] = $this->attempt('inprogress');
        $this->strike($attemptid, $reportid);
        $this->strike($attemptid, $reportid);
        $state = face_strikes::page_state(
            (int)$this->course->id,
            (int)$this->quiz->cmid,
            (int)$this->student->id,
            $attemptid,
            $reportid
        );
        $this->assertSame([
            'limit' => 4,
            'strikes' => 2,
            'minseconds' => face_strikes::DEFAULT_MIN_SECONDS,
            'outcome' => face_strikes::OUTCOME_HOLD,
        ], $state);

        set_config('facestrikeminseconds', 500, 'quizaccess_proctoring');
        $this->assertSame(60, face_strikes::min_seconds());
        set_config('facestrikesoutcome', 'nonsense', 'quizaccess_proctoring');
        $this->assertSame(face_strikes::OUTCOME_HOLD, face_strikes::outcome());
    }

    /**
     * A directly inserted attempt with its first proctoring report row.
     *
     * @param string $state Attempt state.
     * @return int[] Attempt id and report id.
     */
    private function attempt(string $state): array {
        global $DB;

        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', \context_module::instance($this->quiz->cmid));
        $usage->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($usage);
        $number = $DB->count_records('quiz_attempts', ['quiz' => $this->quiz->id, 'userid' => $this->student->id]) + 1;
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $this->quiz->id,
            'userid' => $this->student->id,
            'attempt' => $number,
            'uniqueid' => $usage->get_id(),
            'layout' => '',
            'state' => $state,
            'timestart' => time() - 300,
            'timefinish' => $state === 'finished' ? time() - 10 : 0,
            'timemodified' => time(),
            'sumgrades' => $state === 'finished' ? 5 : null,
        ]);
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->cmid,
            'userid' => $this->student->id,
            'webcampicture' => '',
            'status' => $attemptid,
            'timemodified' => time(),
        ]);
        return [$attemptid, $reportid];
    }

    /**
     * A real attempt, started through the quiz, on a quiz with one question.
     *
     * @return int Attempt id.
     */
    private function real_attempt(): int {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $this->quiz, 0, 1);

        $this->setUser($this->student);
        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')
            ->create_attempt($this->quiz->id, $this->student->id);
        $this->setAdminUser();
        return (int)$attempt->id;
    }

    /**
     * Log a strike as the page does.
     *
     * @param int $attemptid Attempt id.
     * @param int $reportid Report id.
     */
    private function strike(int $attemptid, int $reportid): void {
        global $DB;

        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $this->quiz->cmid,
            'userid' => $this->student->id,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'eventtype' => face_strikes::EVENT_STRIKE,
            'eventdetail' => '{}',
            'timemodified' => time(),
        ]);
    }

    /**
     * Count the strike just logged.
     *
     * @param int $attemptid Attempt id.
     * @param int $reportid Report id.
     * @return array The strike state.
     */
    private function record(int $attemptid, int $reportid): array {
        return face_strikes::record_strike(
            (int)$this->course->id,
            (int)$this->quiz->cmid,
            (int)$this->student->id,
            $attemptid,
            $reportid
        );
    }

    /**
     * Events of one type for an attempt.
     *
     * @param int $attemptid Attempt id.
     * @param string $eventtype Event type.
     * @return int Count.
     */
    private function count_events(int $attemptid, string $eventtype): int {
        global $DB;

        return $DB->count_records('quizaccess_proctoring_events', ['attemptid' => $attemptid, 'eventtype' => $eventtype]);
    }

    /**
     * Give the student a course-wide override of the face-strike rule.
     *
     * @param int $state 0 waives it, 1 forces it on.
     * @return int Override id.
     */
    private function override(int $state): int {
        global $DB;

        return (int)$DB->insert_record('quizaccess_proctoring_overrides', (object)[
            'courseid' => $this->course->id,
            'quizid' => 0,
            'userid' => $this->student->id,
            'captchastate' => -1,
            'webcamstate' => -1,
            'idverificationstate' => -1,
            'screensharestate' => -1,
            'multimonitorstate' => -1,
            'phonedetectionstate' => -1,
            'facestrikesstate' => $state,
            'justification' => 'Accommodation',
            'expiry' => null,
            'revoked' => 0,
            'grantedby' => 2,
            'timecreated' => time() - 600,
            'timemodified' => time() - 600,
        ]);
    }
}
