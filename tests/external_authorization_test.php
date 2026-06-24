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
 * Authorization tests for quizaccess_proctoring external services.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use advanced_testcase;
use invalid_parameter_exception;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Authorization tests for quizaccess_proctoring external services.
 *
 * @covers \quizaccess_proctoring_external
 * @runTestsInSeparateProcesses
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class external_authorization_test extends advanced_testcase {
    /** A small valid PNG data URI for image payload validation. */
    private const PNG_DATA_URI = 'data:image/png;base64,' .
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

    /**
     * Users without the webcam-submission capability must not log browser events.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_log_event_requires_sendcamshot_capability(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, , $cm] = $this->create_quiz_fixture();
        $user = $this->create_enrolled_user($course);

        // Students, teachers, and managers all hold this capability by default, so prohibit it for
        // the enrolled role in this course to exercise the access check on log_event().
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability(
            'quizaccess/proctoring:sendcamshot',
            CAP_PROHIBIT,
            $studentroleid,
            \context_course::instance($course->id)->id,
            true
        );

        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        \quizaccess_proctoring_external::log_event(
            (int)$course->id,
            (int)$cm->id,
            0,
            0,
            'tab_hidden'
        );
    }

    /**
     * Webcam captures must not be accepted against another student's proctoring report.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_send_camshot_rejects_report_owned_by_another_student(): void {
        $this->resetAfterTest();

        [$course, , $cm] = $this->create_quiz_fixture();
        $owner = $this->create_enrolled_user($course);
        $otherstudent = $this->create_enrolled_user($course);
        $reportid = $this->create_proctoring_report($course, $cm, $owner);

        $this->setUser($otherstudent);

        $this->expectException(invalid_parameter_exception::class);
        \quizaccess_proctoring_external::send_camshot(
            (int)$course->id,
            $reportid,
            (int)$cm->id,
            self::PNG_DATA_URI,
            1,
            'camshot_image',
            '',
            1
        );
    }

    /**
     * Browser event logs must not be attached to another student's proctoring report.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_log_event_rejects_report_owned_by_another_student(): void {
        $this->resetAfterTest();

        [$course, , $cm] = $this->create_quiz_fixture();
        $owner = $this->create_enrolled_user($course);
        $otherstudent = $this->create_enrolled_user($course);
        $reportid = $this->create_proctoring_report($course, $cm, $owner);

        $this->setUser($otherstudent);

        $this->expectException(invalid_parameter_exception::class);
        \quizaccess_proctoring_external::log_event(
            (int)$course->id,
            (int)$cm->id,
            0,
            $reportid,
            'tab_hidden'
        );
    }

    /**
     * Desktop screenshot events need a report id so screenshots are tied to an owned proctoring record.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_log_event_rejects_screenshot_without_report(): void {
        $this->resetAfterTest();

        [$course, , $cm] = $this->create_quiz_fixture();
        $student = $this->create_enrolled_user($course);
        $this->setUser($student);

        $this->expectException(invalid_parameter_exception::class);
        \quizaccess_proctoring_external::log_event(
            (int)$course->id,
            (int)$cm->id,
            0,
            0,
            'screen_share_stopped',
            '',
            'visible',
            '',
            self::PNG_DATA_URI
        );
    }

    /**
     * Mouse activity event types must be stored without falling back to a generic shortcut event.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_log_event_preserves_mouse_activity_event_type(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, , $cm] = $this->create_quiz_fixture();
        $student = $this->create_enrolled_user($course);
        $this->setUser($student);

        $result = \quizaccess_proctoring_external::log_event(
            (int)$course->id,
            (int)$cm->id,
            0,
            0,
            'mouse_left_window',
            '{"reason":"mouseout"}'
        );

        $eventtype = $DB->get_field('quizaccess_proctoring_events', 'eventtype', ['id' => $result['eventid']]);
        $this->assertSame('mouse_left_window', $eventtype);
    }

    /**
     * ID verification must reject attempts owned by another student before accepting any evidence.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_verify_id_rejects_attempt_owned_by_another_student(): void {
        $this->resetAfterTest();

        [$course, $quiz, $cm] = $this->create_quiz_fixture();
        $owner = $this->create_enrolled_user($course);
        $otherstudent = $this->create_enrolled_user($course);
        $attemptid = $this->create_quiz_attempt($quiz, $cm, $owner);

        $this->setUser($otherstudent);

        $this->expectException(invalid_parameter_exception::class);
        \quizaccess_proctoring_external::verify_id(
            (int)$course->id,
            (int)$cm->id,
            $attemptid,
            'not-used',
            'not-used',
            1
        );
    }

    /**
     * ID verification must reject attempts from another quiz even when the current user owns them.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_verify_id_rejects_attempt_from_another_quiz(): void {
        $this->resetAfterTest();

        [$course, , $cm] = $this->create_quiz_fixture();
        [, $otherquiz, $othercm] = $this->create_quiz_fixture($course);
        $student = $this->create_enrolled_user($course);
        $attemptid = $this->create_quiz_attempt($otherquiz, $othercm, $student);

        $this->setUser($student);

        $this->expectException(invalid_parameter_exception::class);
        \quizaccess_proctoring_external::verify_id(
            (int)$course->id,
            (int)$cm->id,
            $attemptid,
            'not-used',
            'not-used',
            1
        );
    }

    /**
     * Moodle-side name fallback should allow full legal names, romanized names, and common short names.
     *
     * @covers \quizaccess_proctoring_external
     */
    public function test_id_name_fuzzy_fallback_handles_legal_names_romanization_and_aliases(): void {
        $method = new \ReflectionMethod(\quizaccess_proctoring_external::class, 'get_fuzzy_profile_name_match');
        $method->setAccessible(true);

        $david = (object)[
            'firstname' => 'David',
            'lastname' => 'Ta',
            'firstnamephonetic' => '',
            'lastnamephonetic' => '',
            'middlename' => '',
            'alternatename' => '',
        ];
        $legalname = $method->invoke(null, $david, ['Vinam David Nguyen Ta']);
        $this->assertGreaterThanOrEqual(80, $legalname['score']);
        $this->assertSame('David Ta', $legalname['matchedprofile']);

        $romanized = $method->invoke(null, $david, ['张伟', 'David Ta']);
        $this->assertGreaterThanOrEqual(80, $romanized['score']);
        $this->assertSame('David Ta', $romanized['matchedprofile']);

        $joseph = (object)[
            'firstname' => 'Joseph',
            'lastname' => 'Smith',
            'firstnamephonetic' => '',
            'lastnamephonetic' => '',
            'middlename' => '',
            'alternatename' => '',
        ];
        $alias = $method->invoke(null, $joseph, ['Joe Smith']);
        $this->assertGreaterThanOrEqual(80, $alias['score']);
        $this->assertSame('Joe Smith', $alias['matchedprofile']);
    }

    /**
     * Creates a course, quiz, and course-module fixture.
     *
     * @param \stdClass|null $course Existing course, or null to create one.
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
     */
    private function create_quiz_fixture(?\stdClass $course = null): array {
        $course = $course ?? $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);

        return [$course, $quiz, $cm];
    }

    /**
     * Creates and enrols a user in the supplied course.
     *
     * @param \stdClass $course Course record.
     * @param string $role Role shortname.
     * @return \stdClass User record.
     */
    private function create_enrolled_user(\stdClass $course, string $role = 'student'): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);

        return $user;
    }

    /**
     * Creates a proctoring report row for ownership checks.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $cm Course module.
     * @param \stdClass $student Student user.
     * @return int Proctoring report id.
     */
    private function create_proctoring_report(\stdClass $course, \stdClass $cm, \stdClass $student): int {
        global $DB;

        return (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $student->id,
            'webcampicture' => '',
            'status' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Creates a minimal in-progress quiz attempt for ownership checks.
     *
     * @param \stdClass $quiz Quiz instance.
     * @param \stdClass $cm Course module.
     * @param \stdClass $student Student user.
     * @return int Quiz attempt id.
     */
    private function create_quiz_attempt(\stdClass $quiz, \stdClass $cm, \stdClass $student): int {
        global $DB;

        return (int)$DB->insert_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $student->id,
            'attempt' => 1,
            'uniqueid' => $this->create_question_usage_id($cm),
            'layout' => '',
            'state' => 'inprogress',
            'timestart' => time(),
            'timemodified' => time(),
            'timecheckstate' => 0,
        ]);
    }

    /**
     * Creates an empty question usage for a quiz attempt fixture.
     *
     * @param \stdClass $cm Course module.
     * @return int Question usage id.
     */
    private function create_question_usage_id(\stdClass $cm): int {
        $quba = \question_engine::make_questions_usage_by_activity(
            'mod_quiz',
            \context_module::instance((int)$cm->id)
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);

        return (int)$quba->get_id();
    }
}
