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

use quizaccess_proctoring\local\identity_recheck_policy as policy;

/**
 * Identity reuse controls and preflight consumption regressions.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_proctoring\local\identity_recheck_policy
 */
final class identity_recheck_policy_test extends \advanced_testcase {
    /** @var string Valid PNG fixture. */
    private const PNG = 'data:image/png;base64,' .
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

    /**
     * Set up isolated configuration for each policy scenario.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Old passes keep working until an administrator enables a recheck rule.
     */
    public function test_defaults_preserve_legacy_reuse(): void {
        $record = (object)['id' => 7, 'status' => 'pass', 'timecreated' => 1, 'attemptid' => 42];
        $result = policy::evaluate(
            $record,
            ['profilehash' => 'current', 'policyhash' => 'current'],
            policy::config(),
            0,
            0,
            1000000
        );
        $this->assertTrue($result['passed']);
        $this->assertSame('none', $result['reason']);
        $this->assertSame(0, $result['expiresat']);
    }

    /**
     * Expiration uses successful completion, and legacy edits cannot extend old evidence.
     */
    public function test_age_boundary_and_legacy_creation_time(): void {
        $record = (object)['id' => 7, 'status' => 'pass', 'verifiedat' => 1000, 'timecreated' => 900];
        $config = ['maxage' => 100];
        $this->assertTrue(policy::evaluate($record, [], $config, 0, 0, 1099)['passed']);
        $this->assertSame('expired', policy::evaluate($record, [], $config, 0, 0, 1100)['reason']);
        $this->assertSame('expired', policy::evaluate($record, [], $config, 0, 0, 999)['reason']);
        $record->verifiedat = 0;
        $record->timemodified = 100000;
        $this->assertSame('expired', policy::evaluate($record, [], $config, 0, 0, 1000)['reason']);
    }

    /**
     * Each optional fingerprint control independently invalidates unmatched or legacy snapshots.
     */
    public function test_name_and_policy_changes_are_independent(): void {
        $record = (object)['id' => 7, 'status' => 'pass', 'profilehash' => 'oldname', 'policyhash' => 'oldpolicy'];
        $snapshot = ['profilehash' => 'newname', 'policyhash' => 'newpolicy'];
        $this->assertTrue(policy::evaluate($record, $snapshot, [], 0, 0, 1000)['passed']);
        $this->assertSame('namechanged', policy::evaluate($record, $snapshot, ['namechange' => true], 0, 0, 1000)['reason']);
        $this->assertSame('policychanged', policy::evaluate($record, $snapshot, ['policychange' => true], 0, 0, 1000)['reason']);
        $record->profilehash = '';
        $record->policyhash = '';
        $this->assertFalse(policy::evaluate($record, $snapshot, ['namechange' => true], 0, 0, 1000)['passed']);
        $this->assertFalse(policy::evaluate($record, $snapshot, ['policychange' => true], 0, 0, 1000)['passed']);
    }

    /**
     * A new attempt requires unused current-session evidence; a resume requires its own bound pass.
     */
    public function test_each_attempt_rejects_consumed_and_foreign_passes(): void {
        $record = (object)['id' => 7, 'status' => 'pass', 'attemptid' => 0];
        $config = ['eachattempt' => true];
        $this->assertSame('newattempt', policy::evaluate($record, [], $config, 0, 0, 1000)['reason']);
        $this->assertTrue(policy::evaluate($record, [], $config, 0, 7, 1000)['passed']);
        $record->attemptid = 42;
        $this->assertFalse(policy::evaluate($record, [], $config, 0, 7, 1000)['passed']);
        $this->assertTrue(policy::evaluate($record, [], $config, 42, 0, 1000)['passed']);
        $this->assertFalse(policy::evaluate($record, [], $config, 43, 0, 1000)['passed']);
    }

    /**
     * Provider settings affect the snapshot; credential rotation and retention do not.
     */
    public function test_policy_fingerprint_tracks_effective_identity_rules(): void {
        $user = (object)['firstname' => 'Alex', 'lastname' => 'Example'];
        $initial = policy::snapshot($user);
        set_config('idverificationapikey', 'rotated-secret', 'quizaccess_proctoring');
        set_config('idverificationretentiondays', 7, 'quizaccess_proctoring');
        $this->assertSame($initial['policyhash'], policy::snapshot($user)['policyhash']);
        set_config('idverificationfacethreshold', 95, 'quizaccess_proctoring');
        $this->assertNotSame($initial['policyhash'], policy::snapshot($user)['policyhash']);
        set_config('idverificationfacethreshold', 80, 'quizaccess_proctoring');
        set_config('idverificationrequireback', 1, 'quizaccess_proctoring');
        $this->assertNotSame($initial['policyhash'], policy::snapshot($user)['policyhash']);
        set_config('idverificationrequireback', 0, 'quizaccess_proctoring');
        set_config('idverificationendpoint', 'https://8.8.8.8/verify', 'quizaccess_proctoring');
        $this->assertNotSame($initial['policyhash'], policy::snapshot($user)['policyhash']);
    }

    /**
     * Alternate names are checked even when the displayed name is unchanged.
     */
    public function test_name_fingerprint_covers_provider_name_variants(): void {
        $user = (object)['firstname' => 'Alex', 'lastname' => 'Example', 'alternatename' => 'Alexandra'];
        $initial = policy::snapshot($user);
        $user->firstname = '  ALEX  ';
        $this->assertSame($initial['profilehash'], policy::snapshot($user)['profilehash']);
        $user->alternatename = 'Sasha';
        $this->assertNotSame($initial['profilehash'], policy::snapshot($user)['profilehash']);
    }

    /**
     * Persisted profile changes override the stale user record in a long-lived login session.
     */
    public function test_status_reads_current_profile_and_explains_staleness(): void {
        global $DB, $USER;
        [$course, $cm, $user] = $this->fixture();
        $this->pass($course, $cm, $user);
        set_config('idverificationnamechange', 1, 'quizaccess_proctoring');
        $this->assertTrue(policy::status($course->id, $cm->id, $user->id)['passed']);
        $DB->set_field('user', 'lastname', 'Changed', ['id' => $user->id]);
        $this->assertNotSame('Changed', $USER->lastname);
        $result = policy::status($course->id, $cm->id, $user->id);
        $this->assertFalse($result['passed']);
        $this->assertSame('namechanged', $result['reason']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * A staff reset blocks earlier passes, but not a new check made in the same second (CPIT-487, PR #57 review).
     */
    public function test_reset_blocks_earlier_passes_only(): void {
        global $DB;
        [$course, $cm, $user] = $this->fixture();
        $this->pass($course, $cm, $user);
        $this->assertTrue(policy::status($course->id, $cm->id, $user->id)['passed']);

        policy::reset($course->id, $cm->id, $user->id);
        $result = policy::status($course->id, $cm->id, $user->id);
        $this->assertFalse($result['passed']);
        $this->assertSame('reset', $result['reason']);

        $resetat = (int)$DB->get_field('quizaccess_proctoring_events', 'timemodified',
            ['userid' => $user->id, 'eventtype' => 'id_verification_reset']);
        $new = $this->pass($course, $cm, $user);
        $DB->set_field('quizaccess_proctoring_idv', 'timemodified', $resetat, ['id' => $new->id]);
        $this->assertTrue(policy::status($course->id, $cm->id, $user->id)['passed']);
    }

    /**
     * A consumed preflight cannot start a second attempt, and its binding cannot be overwritten.
     */
    public function test_prepare_consumes_evidence_and_start_event_binds_it_once(): void {
        global $DB, $SESSION;
        [$course, $cm, $user] = $this->fixture();
        set_config('idverificationeachattempt', 1, 'quizaccess_proctoring');
        $record = $this->pass($course, $cm, $user);
        policy::record_success($record);
        $this->assertTrue(policy::prepare_attempt($course->id, $cm->id, $user->id));
        $pending = $SESSION->quizaccess_proctoring_pendingid[$cm->id];
        $this->assertFalse(policy::prepare_attempt($course->id, $cm->id, $user->id));
        $first = $this->attempt_event($course, $cm, $user);
        $first->trigger();
        $this->assertSame((int)$first->objectid, (int)$DB->get_field('quizaccess_proctoring_idv', 'attemptid', ['id' => $record->id]));
        $this->assertArrayNotHasKey($cm->id, $SESSION->quizaccess_proctoring_pendingid);
        $this->assertTrue(policy::status($course->id, $cm->id, $user->id, $first->objectid)['passed']);
        $SESSION->quizaccess_proctoring_pendingid[$cm->id] = $pending;
        $second = $this->attempt_event($course, $cm, $user, 2);
        $second->trigger();
        $this->assertSame((int)$first->objectid, (int)$DB->get_field('quizaccess_proctoring_idv', 'attemptid', ['id' => $record->id]));
        $this->assertFalse(policy::status($course->id, $cm->id, $user->id, $second->objectid)['passed']);
    }

    /**
     * Old session evidence and mismatched users cannot reserve or bind a verification.
     */
    public function test_expired_and_foreign_session_evidence_is_rejected(): void {
        global $DB, $SESSION;
        [$course, $cm, $user] = $this->fixture();
        set_config('idverificationeachattempt', 1, 'quizaccess_proctoring');
        $record = $this->pass($course, $cm, $user);
        policy::record_success($record);
        $SESSION->quizaccess_proctoring_idchecks[$cm->id]['timecreated'] = time() - policy::PREFLIGHT_TTL;
        $this->assertFalse(policy::prepare_attempt($course->id, $cm->id, $user->id));
        policy::record_success($record);
        $SESSION->quizaccess_proctoring_idchecks[$cm->id]['userid'] = $user->id + 1;
        $this->assertFalse(policy::prepare_attempt($course->id, $cm->id, $user->id));
        policy::record_success($record);
        $this->assertTrue(policy::prepare_attempt($course->id, $cm->id, $user->id));
        $SESSION->quizaccess_proctoring_pendingid[$cm->id]['timecreated'] = time() - policy::PREFLIGHT_TTL;
        $this->attempt_event($course, $cm, $user)->trigger();
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_idv', 'attemptid', ['id' => $record->id]));
    }

    /**
     * The real verification API records the exact identity and policy used by the provider.
     */
    public function test_verify_api_snapshots_success_and_records_session_evidence(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/external.php');
        [$course, $cm, $user] = $this->fixture();
        set_config('idverificationenabled', 1, 'quizaccess_proctoring');
        set_config('idverificationeachattempt', 1, 'quizaccess_proctoring');
        set_config('idverificationendpoint', 'https://8.8.8.8/verify', 'quizaccess_proctoring');
        set_config('idverificationapikey', 'mock-key', 'quizaccess_proctoring');
        $DB->set_field('user', 'lastname', 'Current', ['id' => $user->id]);
        \curl::mock_response(json_encode(['verified' => true, 'face_score' => 100, 'name_score' => 100]));
        $result = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, self::PNG, self::PNG, 1);
        $this->assertSame('pass', $result['status']);
        $record = $DB->get_record('quizaccess_proctoring_idv', ['id' => $result['verificationid']], '*', MUST_EXIST);
        $current = $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
        $snapshot = policy::snapshot($current);
        $this->assertSame($snapshot['profilehash'], $record->profilehash);
        $this->assertSame($snapshot['policyhash'], $record->policyhash);
        $this->assertGreaterThan(0, (int)$record->verifiedat);
        $this->assertTrue(policy::status($course->id, $cm->id, $user->id)['passed']);
    }

    /**
     * A forced student requirement is completable while the site-wide ID check is disabled.
     */
    public function test_forced_student_requirement_enables_verification_api(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/external.php');
        [$course, $cm, $user] = $this->fixture();
        set_config('idverificationenabled', 0, 'quizaccess_proctoring');
        set_config('idverificationendpoint', 'https://8.8.8.8/verify', 'quizaccess_proctoring');
        set_config('idverificationapikey', 'mock-key', 'quizaccess_proctoring');
        $disabled = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, self::PNG, self::PNG, 1);
        $this->assertSame('disabled', $disabled['status']);
        $overrideid = $DB->insert_record('quizaccess_proctoring_overrides', [
            'courseid' => $course->id, 'quizid' => $cm->instance, 'userid' => $user->id,
            'idverificationstate' => 1, 'justification' => 'Identity required for this student',
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        \curl::mock_response(json_encode(['verified' => true, 'face_score' => 100, 'name_score' => 100]));
        $result = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, self::PNG, self::PNG, 1);
        $this->assertSame('pass', $result['status']);
        $this->assertGreaterThan(0, $result['verificationid']);
        $DB->set_field('quizaccess_proctoring_overrides', 'revoked', 1, ['id' => $overrideid]);
        $disabled = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, self::PNG, self::PNG, 1);
        $this->assertSame('disabled', $disabled['status']);
    }

    /**
     * Create an enrolled user and a quiz.
     *
     * @return array
     */
    private function fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return [$course, $cm, $user];
    }

    /**
     * Save a server-success fixture with current fingerprints.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Module.
     * @param \stdClass $user Student.
     * @return \stdClass
     */
    private function pass(\stdClass $course, \stdClass $cm, \stdClass $user): \stdClass {
        global $DB;
        $record = (object)([
            'courseid' => $course->id, 'quizid' => $cm->id, 'userid' => $user->id,
            'attemptid' => 0, 'status' => 'pass', 'verifiedat' => time(), 'timecreated' => time(),
        ] + policy::snapshot($user));
        $record->id = $DB->insert_record('quizaccess_proctoring_idv', $record);
        return $record;
    }

    /**
     * Create a real attempt record and its start event without question fixtures.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Module.
     * @param \stdClass $user Student.
     * @param int $number Attempt number.
     * @return \mod_quiz\event\attempt_started
     */
    private function attempt_event(\stdClass $course, \stdClass $cm, \stdClass $user, int $number = 1): \mod_quiz\event\attempt_started {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $context = \context_module::instance($cm->id);
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $context);
        $usage->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($usage);
        $attemptid = $DB->insert_record('quiz_attempts', [
            'quiz' => $cm->instance, 'userid' => $user->id, 'attempt' => $number,
            'uniqueid' => $usage->get_id(), 'layout' => '', 'state' => 'inprogress',
            'timestart' => time(), 'timemodified' => time(), 'timecheckstate' => 0,
        ]);
        return \mod_quiz\event\attempt_started::create([
            'objectid' => $attemptid, 'relateduserid' => $user->id,
            'courseid' => $course->id, 'context' => $context,
        ]);
    }
}
