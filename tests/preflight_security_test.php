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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Security regression tests for server-side preflight enforcement.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Exercises direct starts, forged browser flags, and requirement overrides.
 *
 * @covers \quizaccess_proctoring
 */
final class preflight_security_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private $course;
    /** @var \stdClass Quiz. */
    private $quiz;
    /** @var \stdClass Student. */
    private $student;
    /** @var \quizaccess_proctoring Access rule. */
    private $rule;

    /**
     * Set up a proctored quiz with optional preflight controls disabled.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'proctoringrequired' => 1,
        ]);
        foreach (
            [
            'honorstatementrequired', 'privacynoticerequired', 'captchabeforeattemptenabled',
            'idverificationenabled', 'requireentirescreen', 'fcheckstartchk',
            ] as $setting
        ) {
            set_config($setting, 0, 'quizaccess_proctoring');
        }
        set_config('multimonitormode', 'off', 'quizaccess_proctoring');
        $this->setUser($this->student);
        $quizsettings = \mod_quiz\quiz_settings::create($this->quiz->id, $this->student->id);
        $this->rule = new \quizaccess_proctoring($quizsettings, time());
    }

    /**
     * A new attempt must pass the gate even outside view.php (including web services).
     */
    public function test_new_attempt_always_requires_preflight(): void {
        $this->assertTrue($this->rule->is_preflight_check_required(null));
        $this->assertTrue($this->rule->is_preflight_check_required(0));
        // Once in the attempt, ordinary requests must not keep redirecting to the preflight form.
        $this->assertFalse($this->rule->is_preflight_check_required(123));
    }

    /**
     * Moodle's real access manager must validate the plugin on a direct start.
     */
    public function test_access_manager_enforces_privacy_without_view_page(): void {
        set_config('privacynoticerequired', 1, 'quizaccess_proctoring');
        $quizsettings = \mod_quiz\quiz_settings::create($this->quiz->id, $this->student->id);
        $manager = $quizsettings->get_access_manager(time());
        $this->assertTrue($manager->is_preflight_check_required(null));
        $this->assertArrayHasKey('proctoringprivacy', $manager->validate_preflight_check([], [], null));
    }

    /**
     * Client-side flags alone cannot prove that face validation took place.
     */
    public function test_forged_face_flags_cannot_bypass_validation(): void {
        set_config('fcheckstartchk', 1, 'quizaccess_proctoring');
        $errors = $this->rule->validate_preflight_check([
            'facevalidation' => 1,
            'faceregistrationneeded' => 0,
            'faceverified' => 1,
        ], [], [], null);
        $this->assertArrayHasKey('facevalidation', $errors);
        \quizaccess_proctoring_set_face_preflight_passed($this->quiz->cmid);
        $this->assertSame([], $this->rule->validate_preflight_check([], [], [], null));
        $this->rule->notify_preflight_check_passed(null);
        $this->assertArrayHasKey('facevalidation', $this->rule->validate_preflight_check([], [], [], null));
    }

    /**
     * Evidence cannot be reused for another student, quiz, or after its expiry.
     */
    public function test_face_evidence_is_scoped_and_expires(): void {
        global $SESSION;

        \quizaccess_proctoring_set_face_preflight_passed($this->quiz->cmid);
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed($this->quiz->cmid));
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed($this->quiz->cmid + 1));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed($this->quiz->cmid));
        $this->setUser($this->student);
        $SESSION->quizaccess_proctoring_facechecks[$this->quiz->cmid]['timecreated'] = time() - 601;
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed($this->quiz->cmid));
        $SESSION->quizaccess_proctoring_facechecks[$this->quiz->cmid]['timecreated'] = time() + 60;
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed($this->quiz->cmid));
    }

    /**
     * Server validation must enforce forced requirements when site defaults are disabled.
     */
    public function test_forced_requirements_cannot_be_bypassed_with_site_defaults(): void {
        $this->create_override(override_resolver::STATE_ENABLED);
        $errors = $this->rule->validate_preflight_check([], [], [], null);
        $this->assertArrayHasKey('facevalidation', $errors);
        $this->assertArrayHasKey('idverificationconfirmed', $errors);
        $this->assertArrayHasKey('entirescreenconfirmed', $errors);
        $this->assertArrayHasKey('multimonitorconfirmed', $errors);
        $this->assertArrayHasKey('proctoringcaptchaunavailable', $errors);
    }

    /**
     * Approved waivers must agree with the UI instead of blocking students at submission.
     */
    public function test_waivers_are_honoured_by_server_validation(): void {
        foreach (
            [
            'captchabeforeattemptenabled', 'idverificationenabled', 'requireentirescreen', 'fcheckstartchk',
            ] as $setting
        ) {
            set_config($setting, 1, 'quizaccess_proctoring');
        }
        set_config('multimonitormode', 'block', 'quizaccess_proctoring');
        $this->create_override(override_resolver::STATE_DISABLED);
        $this->assertSame([], $this->rule->validate_preflight_check([], [], [], null));
    }

    /**
     * Apply the same approved override to all supported requirement fields.
     *
     * @param int $state Override value.
     */
    private function create_override(int $state): void {
        $this->setAdminUser();
        $data = (object)[
            'quizid' => $this->quiz->id,
            'userid' => $this->student->id,
            'justification' => 'Security regression fixture',
            'expiry' => null,
        ];
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $data->$column = $state;
        }
        override_manager::create(\context_module::instance($this->quiz->cmid), $data);
        $this->setUser($this->student);
    }
}
