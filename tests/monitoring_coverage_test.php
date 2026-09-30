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

namespace quizaccess_proctoring;

use quizaccess_proctoring\local\activity_grouper;
use quizaccess_proctoring\local\evidence_ingestion;
use quizaccess_proctoring\local\monitoring_coverage;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Coverage expectations, gap semantics and safe recovery of buffered evidence.
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_proctoring\local\monitoring_coverage
 * @covers \quizaccess_proctoring\local\evidence_ingestion
 * @covers \quizaccess_proctoring_external
 */
final class monitoring_coverage_test extends \advanced_testcase {
    /** A CRC-valid 80-pixel striped PNG that can be decoded by GD. */
    private const PNG = 'data:image/png;base64,' .
        'iVBORw0KGgoAAAANSUhEUgAAAFAAAABQCAIAAAABc2X6AAAAgklEQVR4nO3PsQkAMBDEsIzz+5MBU6X93qDr' .
        'D6xz18264vcUo4GBgYFT0cDAwMCpaGBgYOBUNDAwMHAqGhgYGDgVDQwMDJyKBgYGBk5FAwMDA6eigYGBgVPR' .
        'wMDAwKloYGBg4FQ0MDAwcCoaGBgYOBUNDAwMnIoGBgYGTkUDAwPPBz8qfTvh+jXSxAAAAABJRU5ErkJggg==';

    /**
     * Delayed captures fill their original period, while a genuinely missing interval remains visible.
     */
    public function test_delayed_captures_use_capture_time_for_gap_calculation(): void {
        $summary = monitoring_coverage::summarize(1000, 1180, 30, [
            (object)['capturedat' => 1030, 'timemodified' => 1120],
            (object)['capturedat' => 1060, 'timemodified' => 1130],
            (object)['capturedat' => 1180, 'timemodified' => 1182],
        ]);
        $this->assertSame(3, $summary['capturecount']);
        $this->assertSame(2, $summary['delayedcount']);
        $this->assertSame([['start' => 1090, 'end' => 1150, 'seconds' => 60]], $summary['gaps']);
        $this->assertSame(60, $summary['missingseconds']);
    }

    /**
     * A short startup wait and legacy receipt timestamps are explicitly distinguished.
     */
    public function test_startup_grace_and_legacy_capture_times(): void {
        $this->assertSame(0, monitoring_coverage::summarize(1000, 1020, 30, [])['gapcount']);
        $this->assertSame(70, monitoring_coverage::summarize(1000, 1100, 30, [])['missingseconds']);
        $legacy = monitoring_coverage::summarize(1000, 1100, 30, [
            (object)['capturedat' => 0, 'timemodified' => 1030],
            (object)['capturedat' => 0, 'timemodified' => 1090],
        ]);
        $this->assertSame(2, $legacy['legacycount']);
        $this->assertSame(0, $legacy['delayedcount']);
        $this->assertSame(0, $legacy['gapcount']);
    }

    /**
     * Legacy attempts stay unknown and later settings cannot rewrite the original channel expectations.
     */
    public function test_snapshot_is_scoped_and_preserved_after_policy_changes(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        $unknown = monitoring_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid);
        $this->assertFalse($unknown['available']);
        $this->assertTrue($unknown['legacy']);
        $original = monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, true, false, 30);
        $reloaded = monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, false, true, 5);
        $this->assertSame(['version' => 1, 'webcam' => true, 'screen' => false, 'interval' => 30], $original);
        $this->assertSame($original, $reloaded);
        $this->assertSame(1, $DB->count_records('quizaccess_proctoring_events', ['eventtype' => 'monitoring_started']));
        $snapshot = $DB->get_record('quizaccess_proctoring_events', ['eventtype' => 'monitoring_started']);
        $DB->set_field('quizaccess_proctoring_events', 'timemodified', time() - 100, ['id' => $snapshot->id]);
        $coverage = monitoring_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid);
        $this->assertTrue($coverage['available']);
        $this->assertSame(30, $coverage['interval']);
        $this->assertTrue($coverage['channels'][0]['required']);
        $this->assertFalse($coverage['channels'][1]['required']);
        $this->assertGreaterThan(0, $coverage['channels'][0]['missingseconds']);
        $other = $this->getDataGenerator()->create_user();
        $this->assertFalse(monitoring_coverage::for_attempt($course->id, $quiz->cmid, $other->id, $attemptid)['available']);
    }

    /**
     * The registered Moodle event captures policy before a first page load can observe changed settings.
     */
    public function test_new_attempt_event_records_the_original_policy(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        set_config('autoreconfigurecamshotdelay', 25, 'quizaccess_proctoring');
        $this->attempt_event($course, $quiz, $student->id, $attemptid)->trigger();
        $snapshot = $DB->get_record('quizaccess_proctoring_events', [
            'attemptid' => $attemptid, 'eventtype' => 'monitoring_started',
        ], '*', MUST_EXIST);
        $policy = json_decode($snapshot->eventdetail, true);
        $this->assertTrue($policy['screen']);
        $this->assertSame(25, $policy['interval']);
        set_config('monitoringcoveragescreens', 0, 'quizaccess_proctoring');
        $this->assertSame($policy, monitoring_coverage::start_attempt(
            $course->id,
            $quiz->cmid,
            $student->id,
            $attemptid,
            true,
            false,
            10
        ));
    }

    /**
     * Loading an older attempt cannot newly opt it into periodic screen collection.
     */
    public function test_legacy_attempt_page_fallback_keeps_periodic_screens_disabled(): void {
        global $COURSE, $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        $savedget = $_GET;
        $savedpost = $_POST;
        $savedcourse = $COURSE;
        try {
            $_GET['cmid'] = $quiz->cmid;
            $_GET['attempt'] = $attemptid;
            unset($_POST['cmid'], $_POST['attempt']);
            $COURSE = $course;
            $page = new \moodle_page();
            $page->set_context(\context_module::instance($quiz->cmid));
            $page->set_course($course);
            $page->set_url('/mod/quiz/attempt.php', ['attempt' => $attemptid]);
            $quizsettings = \mod_quiz\quiz_settings::create($quiz->id, $student->id);
            $rule = new \quizaccess_proctoring($quizsettings, time());
            $rule->setup_attempt_page($page);
            $snapshot = $DB->get_record('quizaccess_proctoring_events', [
                'attemptid' => $attemptid, 'eventtype' => 'monitoring_started',
            ], '*', MUST_EXIST);
            $policy = json_decode($snapshot->eventdetail, true);
            $this->assertTrue($policy['webcam']);
            $this->assertFalse($policy['screen']);
        } finally {
            $_GET = $savedget;
            $_POST = $savedpost;
            $COURSE = $savedcourse;
        }
    }

    /**
     * A wrong owner, preview or unproctored quiz must not create monitoring records.
     */
    public function test_attempt_observer_requires_a_real_proctored_owner_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        $other = $this->getDataGenerator()->create_user();
        monitoring_coverage::attempt_started($this->attempt_event($course, $quiz, $other->id, $attemptid));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_events', ['eventtype' => 'monitoring_started']));
        $event = $this->attempt_event($course, $quiz, $student->id, $attemptid);
        $DB->set_field('quiz_attempts', 'preview', 1, ['id' => $attemptid]);
        monitoring_coverage::attempt_started($event);
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_events', ['eventtype' => 'monitoring_started']));
        $DB->set_field('quiz_attempts', 'preview', 0, ['id' => $attemptid]);
        $DB->set_field('quizaccess_proctoring', 'proctoringrequired', 0, ['quizid' => $quiz->id]);
        monitoring_coverage::attempt_started($event);
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_events', ['eventtype' => 'monitoring_started']));
    }

    /**
     * The start event follows the same mobile bypass and invalid-setting fallback as the attempt page.
     */
    public function test_attempt_observer_respects_effective_mobile_desktop_policy(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        $event = $this->attempt_event($course, $quiz, $student->id, $attemptid);
        \core_useragent::instance(true, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');
        try {
            foreach (['bypass' => false, 'require' => true, 'block' => true, 'invalid' => false] as $mode => $expected) {
                set_config('mobilescreensharemode', $mode, 'quizaccess_proctoring');
                monitoring_coverage::attempt_started($event);
                $snapshot = $DB->get_record('quizaccess_proctoring_events', ['eventtype' => 'monitoring_started'], '*', MUST_EXIST);
                $this->assertSame($expected, json_decode($snapshot->eventdetail, true)['screen']);
                $DB->delete_records('quizaccess_proctoring_events', ['id' => $snapshot->id]);
            }
        } finally {
            \core_useragent::instance(true);
        }
    }

    /**
     * Stable tokens acknowledge a retried webcam/screen upload without creating duplicate files or events.
     */
    public function test_retry_tokens_deduplicate_webcam_and_screen_evidence(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid, $reportid] = $this->fixture();
        $capturedat = time();
        $webcam = [];
        $screen = [];
        for ($i = 0; $i < 2; $i++) {
            $webcam[] = \quizaccess_proctoring_external::send_camshot(
                $course->id,
                $reportid,
                $quiz->cmid,
                self::PNG,
                1,
                'camshot_image',
                '',
                1,
                $capturedat,
                'webcam-test-token'
            );
            $screen[] = \quizaccess_proctoring_external::log_event(
                $course->id,
                $quiz->cmid,
                $attemptid,
                $reportid,
                'screen_capture',
                '',
                '',
                '',
                self::PNG,
                $capturedat,
                'screen-test-token'
            );
        }
        $this->assertSame($webcam[0], $webcam[1]);
        $this->assertSame($screen[0], $screen[1]);
        $this->assertSame(1, $DB->count_records('quizaccess_proctoring_logs', ['requestid' => 'webcam-test-token']));
        $this->assertSame(1, $DB->count_records('quizaccess_proctoring_events', ['requestid' => 'screen-test-token']));
        $event = $DB->get_record('quizaccess_proctoring_events', ['id' => $screen[0]['eventid']], '*', MUST_EXIST);
        $this->assertSame($capturedat, (int)$event->capturedat);
        $this->assertNotEmpty($event->screenshoturl);
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_ai_reviews'));
        $this->assertSame(2, $DB->count_records_select(
            'files',
            'component = :component AND userid = :userid AND filename <> :directory',
            [
                'component' => 'quizaccess_proctoring', 'userid' => $student->id, 'directory' => '.',
            ]
        ));
    }

    /**
     * A normal new upload after submission stays blocked; a bounded captured-before-submission retry can arrive.
     */
    public function test_finished_attempt_accepts_only_recent_pre_submission_recovery(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid, $reportid] = $this->fixture();
        $now = time();
        $DB->update_record('quiz_attempts', (object)['id' => $attemptid, 'state' => 'finished', 'timefinish' => $now - 10]);
        $result = \quizaccess_proctoring_external::log_event(
            $course->id,
            $quiz->cmid,
            $attemptid,
            $reportid,
            'screen_capture',
            '',
            '',
            '',
            self::PNG,
            $now - 20,
            'late-screen'
        );
        $this->assertGreaterThan(0, $result['eventid']);
        foreach ([[0, ''], [$now, 'after-finish']] as [$capturedat, $token]) {
            try {
                evidence_ingestion::attempt($attemptid, $quiz->id, $student->id, $capturedat, $token, $now);
                $this->fail('Post-submission evidence was accepted without a valid capture window.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->expectException(\invalid_parameter_exception::class);
        evidence_ingestion::attempt($attemptid, $quiz->id, $student->id, $now - 20, 'too-late', $now + 121);
    }

    /**
     * Periodic screenshots require an explicit opt-in without disabling existing event screenshot collection.
     */
    public function test_periodic_screen_capture_requires_explicit_opt_in(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid, $reportid] = $this->fixture();
        unset_config('monitoringcoveragescreens', 'quizaccess_proctoring');
        foreach ([0, 1] as $enabled) {
            if ($enabled) {
                set_config('monitoringcoveragescreens', 1, 'quizaccess_proctoring');
                set_config('captureviolationdesktop', 0, 'quizaccess_proctoring');
            }
            try {
                \quizaccess_proctoring_external::log_event(
                    $course->id,
                    $quiz->cmid,
                    $attemptid,
                    $reportid,
                    'screen_capture',
                    '',
                    '',
                    '',
                    self::PNG,
                    time(),
                    'blocked-periodic-' . $enabled
                );
                $this->fail('Periodic screenshots were accepted without the required opt-in.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_events', ['eventtype' => 'screen_capture']));
        $result = \quizaccess_proctoring_external::log_event(
            $course->id,
            $quiz->cmid,
            $attemptid,
            $reportid,
            'tab_visible',
            '',
            '',
            '',
            self::PNG,
            time(),
            'existing-event'
        );
        $this->assertGreaterThan(0, $result['eventid']);
    }

    /**
     * Recovery metadata cannot introduce unbounded backdating, malformed tokens or cross-attempt reuse.
     */
    public function test_recovery_metadata_is_bounded_and_scoped(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        $this->assertSame(['capturedat' => 0, 'requestid' => null], evidence_ingestion::metadata(0, ''));
        foreach ([[699, 'token'], [1031, 'token'], [1000, 'invalid token'], [0, 'token']] as [$time, $token]) {
            try {
                evidence_ingestion::metadata($time, $token, 1000);
                $this->fail('Invalid recovery metadata was accepted.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $student->id,
            'attemptid' => $attemptid, 'eventtype' => 'screen_capture', 'requestid' => 'existing',
        ]);
        $this->expectException(\invalid_parameter_exception::class);
        evidence_ingestion::duplicate('quizaccess_proctoring_events', $student->id, $quiz->cmid, $attemptid + 1, 'existing');
    }

    /**
     * Coverage rows cannot become suspicious episodes or increase the risk score.
     */
    public function test_coverage_events_do_not_score_or_create_activity_episodes(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid, $reportid] = $this->fixture();
        $before = quizaccess_proctoring_calculate_attempt_risk($course->id, $quiz->cmid, $student->id, $reportid);
        monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, true, true, 30);
        $eventid = $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $student->id,
            'attemptid' => $attemptid, 'reportid' => $reportid, 'eventtype' => 'screen_capture',
            'screenshoturl' => self::PNG, 'timemodified' => time(),
        ]);
        $after = quizaccess_proctoring_calculate_attempt_risk($course->id, $quiz->cmid, $student->id, $reportid);
        $this->assertSame($before['score'], $after['score']);
        $grouped = activity_grouper::group($DB->get_records('quizaccess_proctoring_events'));
        $this->assertSame([], $grouped['episodes']);
        $this->assertSame(0, $grouped['rawcount']);
        $event = $DB->get_record('quizaccess_proctoring_events', ['id' => $eventid], '*', MUST_EXIST);
        $settings = ['desktopmode' => 'all'];
        $this->assertFalse(quizaccess_proctoring_should_queue_event_ai_review($event, $settings));
        $review = (object)['courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $student->id,
            'attemptid' => $attemptid, 'reportid' => $reportid];
        $this->assertSame([], quizaccess_proctoring_collect_ai_review_images($review, 12, $settings));
        $review->eventid = $eventid;
        $review->reviewtype = 'event';
        $this->assertSame([], quizaccess_proctoring_collect_ai_review_images($review, 12, $settings));
    }

    /**
     * Create an authorized active attempt with an initial empty proctoring log.
     *
     * @return array Fixture records and IDs.
     */
    private function fixture(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'proctoringrequired' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        set_config('continuousfacecheck', 0, 'quizaccess_proctoring');
        set_config('monitoringcoveragescreens', 1, 'quizaccess_proctoring');
        set_config('captureviolationdesktop', 1, 'quizaccess_proctoring');
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', \context_module::instance($quiz->cmid));
        $usage->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($usage);
        $attemptid = $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id, 'userid' => $student->id, 'attempt' => 1, 'uniqueid' => $usage->get_id(),
            'layout' => '', 'state' => 'inprogress', 'timestart' => time() - 300, 'timemodified' => time(),
        ]);
        $reportid = $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $student->id,
            'webcampicture' => '', 'status' => $attemptid, 'timemodified' => time(),
        ]);
        return [$course, $quiz, $student, (int)$attemptid, (int)$reportid];
    }

    /**
     * Build the real Moodle event for an existing test attempt.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $quiz Quiz.
     * @param int $userid Attempt owner.
     * @param int $attemptid Attempt ID.
     * @return \mod_quiz\event\attempt_started Start event.
     */
    private function attempt_event(
        \stdClass $course,
        \stdClass $quiz,
        int $userid,
        int $attemptid
    ): \mod_quiz\event\attempt_started {
        return \mod_quiz\event\attempt_started::create([
            'objectid' => $attemptid, 'relateduserid' => $userid,
            'courseid' => $course->id, 'context' => \context_module::instance($quiz->cmid),
        ]);
    }
}
