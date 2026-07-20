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
 * Example tests for rule.php per-student override resolution and in-progress snapshotting.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_module;
use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_resolver.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_manager.php');

/**
 * Example tests for the rule.php preflight override-resolution seam.
 *
 * Feature: per-student-proctoring-overrides
 *
 * `rule.php::add_preflight_check_form_fields()` is the single point where the plugin turns the
 * site/quiz proctoring settings into the boolean config flags handed to `startAttempt.js`. Task
 * 6.1 wired `override_resolver::resolve_all()` into that method at the new-attempt gate
 * (`if (empty($attemptid))`), layering per-student overrides on top of the base states computed by
 * the existing private helpers (`requires_entire_screen`, `should_require_captcha`,
 * `should_require_id_verification`, `multi_monitor_mode`).
 *
 * The full method renders a moodleform and schedules AMD JS, so it cannot be invoked directly in a
 * unit test. These example tests instead exercise the exact integration seam the method uses:
 * they build the base states from the rule's real private helpers (via reflection, mirroring the
 * five assignments in the method), then run `override_resolver::resolve_all()` exactly as the
 * method does, and assert the observable outcome. In-progress snapshotting (R7.4) is verified
 * through the attempt-id gating that protects a started attempt from re-resolution.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring::add_preflight_check_form_fields
 */
final class rule_override_resolution_test extends advanced_testcase {

    /** @var \stdClass Generated course. */
    private $course;

    /** @var \stdClass Enrolled target student. */
    private $student;

    /** @var \stdClass Acting reviewer (editingteacher holds manageoverrides). */
    private $teacher;

    /** @var \stdClass Generated quiz module. */
    private $quiz;

    /** @var context_module The quiz module context. */
    private $context;

    /**
     * Build a course + enrolled student + editingteacher reviewer + quiz module, and set the
     * baseline site proctoring configuration used by the base-state helpers. The baseline turns
     * every requirement ON except ID verification, so each requirement's base value is
     * unambiguous and an override that disables one is clearly observable.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $this->context = context_module::instance($this->quiz->cmid);

        // Baseline site settings: captcha on, ID verification off, entire screen on, multi-monitor
        // warn (not off), webcam face check on. The per-quiz tri-states are left at inherit so the
        // base states come from these site settings.
        set_config('captchabeforeattemptenabled', '1', 'quizaccess_proctoring');
        set_config('idverificationenabled', '0', 'quizaccess_proctoring');
        set_config('requireentirescreen', '1', 'quizaccess_proctoring');
        set_config('multimonitormode', 'warn', 'quizaccess_proctoring');
        set_config('fcheckstartchk', '1', 'quizaccess_proctoring');
    }

    /**
     * Build a proctoring access rule bound to the fixture quiz.
     *
     * @return \quizaccess_proctoring The rule under test.
     */
    private function make_rule(): \quizaccess_proctoring {
        $quizsettings = \mod_quiz\quiz_settings::create($this->quiz->id, $this->student->id);
        return new \quizaccess_proctoring($quizsettings, time());
    }

    /**
     * Invoke a private/protected method on the rule (or a private static on the class) via
     * reflection.
     *
     * @param \quizaccess_proctoring|null $rule Instance, or null for a static call.
     * @param string $method Method name.
     * @param array $args Positional arguments.
     * @return mixed The method's return value.
     */
    private function invoke_rule_method($rule, string $method, array $args = []) {
        $reflection = new \ReflectionMethod('quizaccess_proctoring', $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($rule, ...$args);
    }

    /**
     * Read a private class constant on the rule via reflection.
     *
     * @param string $name Constant name.
     * @return mixed The constant value.
     */
    private function rule_constant(string $name) {
        return (new \ReflectionClass('quizaccess_proctoring'))->getConstant($name);
    }

    /**
     * Reproduce, from the rule's real private helpers, the exact five base states that
     * add_preflight_check_form_fields() feeds into override_resolver::resolve_all().
     *
     * This mirrors the method's own assignments:
     *   $requireentirescreen = $this->requires_entire_screen() ? 1 : 0;
     *   $captcharequired     = $this->should_require_captcha($attemptid);
     *   $idverificationreq   = $this->should_require_id_verification($attemptid);
     *   $multimonitormode    = self::multi_monitor_mode();
     *   $faceidcheck         = <config_plugins fcheckstartchk>;
     * then the REQ_* => bool map built from them.
     *
     * @param \quizaccess_proctoring $rule Rule instance.
     * @param int|null $attemptid Attempt id passed to the attempt-gated helpers (empty = new attempt).
     * @return array<string, bool> Map of REQ_* => base boolean state.
     */
    private function compute_base_states(\quizaccess_proctoring $rule, $attemptid): array {
        $faceidcheck = (string)get_config('quizaccess_proctoring', 'fcheckstartchk');
        $multimonitoroff = $this->rule_constant('MULTI_MONITOR_OFF');

        $requireentirescreen = $this->invoke_rule_method($rule, 'requires_entire_screen') ? 1 : 0;
        $captcharequired = $this->invoke_rule_method($rule, 'should_require_captcha', [$attemptid]);
        $idverificationrequired = $this->invoke_rule_method($rule, 'should_require_id_verification', [$attemptid]);
        $multimonitormode = $this->invoke_rule_method(null, 'multi_monitor_mode');

        return [
            override_resolver::REQ_CAPTCHA => (bool)$captcharequired,
            override_resolver::REQ_WEBCAM => ((string)$faceidcheck === '1'),
            override_resolver::REQ_IDVERIFICATION => (bool)$idverificationrequired,
            override_resolver::REQ_SCREENSHARE => ((int)$requireentirescreen === 1),
            override_resolver::REQ_MULTIMONITOR => ($multimonitormode !== $multimonitoroff),
        ];
    }

    /**
     * Create a per-student override on the fixture course/quiz via the real write path, acting as
     * the editingteacher reviewer (who holds quizaccess/proctoring:manageoverrides).
     *
     * @param array<string, int> $states Map of state column => tri-state value.
     * @param int $quizid 0 for course-scoped, or the quiz id for quiz-scoped.
     * @return int The new override id.
     */
    private function create_override(array $states, int $quizid = 0): int {
        $this->setUser($this->teacher);

        $data = new \stdClass();
        $data->quizid = $quizid;
        $data->userid = $this->student->id;
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $data->$column = $states[$column] ?? override_resolver::STATE_INHERIT;
        }
        $data->justification = 'Example test override';
        $data->expiry = null;

        $overrideid = override_manager::create($this->context, $data);

        // Resolution and the attempt gate run as the student in production; switch back so the
        // rule helpers evaluate against the target student.
        $this->setUser($this->student);
        return $overrideid;
    }

    /**
     * With no override present, the config flags produced at the new-attempt gate equal the base
     * (site/quiz) resolution — i.e. today's behaviour is unchanged. This asserts both that the
     * base states match the configured settings and that resolve_all() passes them through
     * untouched when no applicable override exists.
     *
     * Validates: Requirements 3.1, 3.3
     */
    public function test_no_override_config_flags_match_base_behavior(): void {
        $this->setUser($this->student);
        $rule = $this->make_rule();

        // New-attempt gate: attemptid empty.
        $basestates = $this->compute_base_states($rule, 0);

        // The base states must reflect the configured site settings (today's behaviour).
        $expectedbase = [
            override_resolver::REQ_CAPTCHA => true,          // captchabeforeattemptenabled = 1
            override_resolver::REQ_WEBCAM => true,           // fcheckstartchk = 1
            override_resolver::REQ_IDVERIFICATION => false,  // idverificationenabled = 0
            override_resolver::REQ_SCREENSHARE => true,      // requireentirescreen = 1
            override_resolver::REQ_MULTIMONITOR => true,     // multimonitormode = warn (!= off)
        ];
        $this->assertSame($expectedbase, $basestates,
            'Base states should match the configured site proctoring settings.');

        // No overrides exist, so resolve_all() must return the base states unchanged. The
        // preflight gate resolves five requirements; phone detection is resolved separately on
        // the attempt page, so resolve_all() reports it as false when no base state is passed.
        $resolved = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );

        $expectedresolved = $basestates;
        $expectedresolved[override_resolver::REQ_PHONEDETECTION] = false;
        $this->assertSame($expectedresolved, $resolved,
            'With no override, the resolved config flags must equal the base resolution.');
    }

    /**
     * A non-inherit override changes exactly its own config flag while leaving every other
     * requirement at its base value. Here a course-scoped override disables captcha for the target
     * student: the resolved captcha flag flips off, and webcam/id/screenshare/multimonitor keep
     * their base states.
     *
     * Validates: Requirements 3.1, 3.3
     */
    public function test_noninherit_override_changes_only_its_config_flag(): void {
        $this->setUser($this->student);
        $rule = $this->make_rule();
        $basestates = $this->compute_base_states($rule, 0);

        // Sanity: captcha is on at base, so disabling it is an observable change.
        $this->assertTrue($basestates[override_resolver::REQ_CAPTCHA],
            'Precondition: captcha should be required at base.');

        // Course-scoped override that disables captcha only; everything else inherits.
        $this->create_override([
            override_resolver::STATE_COLUMNS[override_resolver::REQ_CAPTCHA] => override_resolver::STATE_DISABLED,
        ]);

        $resolved = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );

        // The overridden requirement flips off.
        $this->assertFalse($resolved[override_resolver::REQ_CAPTCHA],
            'A disabling override must turn the captcha config flag off.');

        // Every other requirement keeps its base value.
        foreach ([
            override_resolver::REQ_WEBCAM,
            override_resolver::REQ_IDVERIFICATION,
            override_resolver::REQ_SCREENSHARE,
            override_resolver::REQ_MULTIMONITOR,
        ] as $requirement) {
            $this->assertSame($basestates[$requirement], $resolved[$requirement],
                'Unaffected requirement ' . $requirement . ' must keep its base value.');
        }
    }

    /**
     * A forcing (enabled) override turns a base-disabled requirement on. ID verification is off at
     * base; an override that enables it flips the resolved flag on, confirming the tri-state
     * enabled path also flows through the rule seam.
     *
     * Validates: Requirements 3.1, 3.3
     */
    public function test_enabling_override_forces_requirement_on(): void {
        $this->setUser($this->student);
        $rule = $this->make_rule();
        $basestates = $this->compute_base_states($rule, 0);

        $this->assertFalse($basestates[override_resolver::REQ_IDVERIFICATION],
            'Precondition: ID verification should be off at base.');

        $this->create_override([
            override_resolver::STATE_COLUMNS[override_resolver::REQ_IDVERIFICATION] => override_resolver::STATE_ENABLED,
        ]);

        $resolved = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );

        $this->assertTrue($resolved[override_resolver::REQ_IDVERIFICATION],
            'An enabling override must force the ID-verification flag on.');
    }

    /**
     * In-progress snapshotting (R7.4): an attempt keeps the requirement state resolved at its
     * start, so a later revoke does not re-resolve the started attempt.
     *
     * This is protected by two layers the rule relies on:
     *  1. The `if (empty($attemptid))` gate in add_preflight_check_form_fields() means
     *     resolve_all() runs ONLY for a new attempt; once an attempt exists it is never re-run.
     *  2. The attempt-gated base helpers themselves already collapse to "not required" once an
     *     attempt id is present (should_require_captcha()/should_require_id_verification() return
     *     false for a non-empty attemptid), so no override is layered mid-attempt.
     *
     * The test shows: (a) at the new-attempt gate the override is applied; (b) after revoking, a
     * NEW attempt sees the base state again (revocation applies only to attempts begun after); and
     * (c) with a non-empty attemptid the attempt-gated helpers report "not required", the signal
     * the rule uses to skip resolution for an in-progress attempt.
     *
     * Validates: Requirements 7.4
     */
    public function test_in_progress_attempt_not_re_resolved(): void {
        $this->setUser($this->student);
        $rule = $this->make_rule();

        // (a) Resolve at attempt start with an active captcha-disabling override.
        $overrideid = $this->create_override([
            override_resolver::STATE_COLUMNS[override_resolver::REQ_CAPTCHA] => override_resolver::STATE_DISABLED,
        ]);

        $startbase = $this->compute_base_states($rule, 0);
        $startstate = time();
        $resolvedatstart = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            $startstate,
            $startbase
        );
        $this->assertFalse($resolvedatstart[override_resolver::REQ_CAPTCHA],
            'At the new-attempt gate the active override should disable captcha.');

        // (b) Revoke the override, then a NEW attempt must see the base state again.
        $this->setUser($this->teacher);
        override_manager::revoke($this->context, $overrideid);
        $this->setUser($this->student);

        $resolvedafterrevoke = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $startbase
        );
        $this->assertTrue($resolvedafterrevoke[override_resolver::REQ_CAPTCHA],
            'After revocation, a new attempt should fall back to the base captcha state.');

        // (c) The rule's in-progress guard: with a non-empty attemptid the attempt-gated base
        // helpers report "not required", which is exactly why add_preflight_check_form_fields()
        // wraps resolve_all() in `if (empty($attemptid))` and never re-resolves a started attempt.
        $inprogressattemptid = 4242;
        $this->assertFalse(
            (bool)$this->invoke_rule_method($rule, 'should_require_captcha', [$inprogressattemptid]),
            'should_require_captcha() must return false for an in-progress attempt.');
        $this->assertFalse(
            (bool)$this->invoke_rule_method($rule, 'should_require_id_verification', [$inprogressattemptid]),
            'should_require_id_verification() must return false for an in-progress attempt.');

        // And at the new-attempt gate (empty attemptid) the same helper reflects the base config.
        $this->assertTrue(
            (bool)$this->invoke_rule_method($rule, 'should_require_captcha', [0]),
            'should_require_captcha() must reflect the base config at the new-attempt gate.');
    }
}
