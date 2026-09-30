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
 * Authorization and privacy boundaries of optional readiness checks.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use quizaccess_proctoring\external\readiness as readiness_external;
use quizaccess_proctoring\local\readiness;

/**
 * Exercises real Moodle access checks and offline provider responses.
 *
 * @covers \quizaccess_proctoring\local\readiness
 * @covers \quizaccess_proctoring\external\readiness
 */
final class readiness_test extends \advanced_testcase {
    /**
     * Create an enrolled student and an enabled proctored quiz.
     *
     * @return \stdClass Quiz instance with course module ID.
     */
    private function fixture(): \stdClass {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'proctoringrequired' => 1]);
        $this->setUser($student);
        return $quiz;
    }

    /**
     * The connectivity test cannot start a timer, retain synthetic data or expose service secrets.
     */
    public function test_connectivity_does_not_create_attempt_or_evidence(): void {
        global $DB;
        $quiz = $this->fixture();
        $response = readiness_external::execute($quiz->cmid, false, str_repeat('x', 32768));
        $this->assertSame(['receivedbytes' => 32768, 'providers' => []], $response);
        $this->assertSame(0, $DB->count_records('quiz_attempts', ['quiz' => $quiz->id]));
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_logs'));
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_idv'));
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_events'));
    }

    /**
     * The actual page supplies cm_info rather than the raw module database record used by AJAX.
     */
    public function test_page_module_info_is_accepted(): void {
        global $USER;
        $quiz = $this->fixture();
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $this->assertInstanceOf(\cm_info::class, $cm);
        $requirements = readiness::requirements($cm, readiness::require_access($quiz->cmid), (int)$USER->id);
        $this->assertArrayHasKey('screenshare', $requirements);
        $this->assertArrayHasKey('idverification', $requirements);
    }

    /**
     * The readiness hint must not require screen sharing when preflight applies mobile bypass.
     */
    public function test_screen_hint_respects_mobile_bypass(): void {
        global $USER;
        $quiz = $this->fixture();
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $settings = readiness::require_access($quiz->cmid);
        $settings->requireentirescreen = 1;
        \core_useragent::instance(true, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)');
        try {
            foreach (['bypass' => false, 'require' => true, 'block' => true, 'invalid' => false] as $mode => $expected) {
                set_config('mobilescreensharemode', $mode, 'quizaccess_proctoring');
                $requirements = readiness::requirements($cm, $settings, (int)$USER->id);
                $this->assertSame($expected, $requirements['screenshare']);
            }
        } finally {
            \core_useragent::instance(true);
        }
    }

    /**
     * A module-level prohibition also applies to provider checks.
     */
    public function test_module_capability_is_required(): void {
        global $DB;
        $quiz = $this->fixture();
        $role = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability(
            'quizaccess/proctoring:sendcamshot',
            CAP_PROHIBIT,
            $role->id,
            \context_module::instance($quiz->cmid)->id
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->expectException(\required_capability_exception::class);
        readiness_external::execute($quiz->cmid, true);
    }

    /**
     * Generated uploads are bounded before processing.
     */
    public function test_oversized_payload_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        readiness_external::execute(0, false, str_repeat('x', 65537));
    }

    /**
     * Turning the optional page off also disables its AJAX function.
     */
    public function test_disabled_readiness_cannot_be_called_directly(): void {
        $quiz = $this->fixture();
        set_config('readinessenabled', 0, 'quizaccess_proctoring');
        $this->expectException(\invalid_parameter_exception::class);
        readiness_external::execute($quiz->cmid);
    }

    /**
     * Blocked destinations cannot receive credentials even through a diagnostic probe.
     */
    public function test_probe_enforces_outbound_security_before_transport(): void {
        $this->resetAfterTest();
        $calls = 0;
        $transport = static function () use (&$calls): array {
            $calls++;
            return [200, 0];
        };
        $this->assertSame('unavailable', readiness::probe('http://1.1.1.1/verify', 'secret', $transport));
        $this->assertSame('unavailable', readiness::probe('https://127.0.0.1/verify', 'secret', $transport));
        $this->assertSame(0, $calls);
    }

    /**
     * Reachability does not invent an identity verdict; TLS, redirects and timeouts remain bounded.
     */
    public function test_probe_reports_safe_statuses_and_keeps_secure_options(): void {
        $this->resetAfterTest();
        foreach (
            [200 => 'reachable', 405 => 'reachable', 401 => 'authentication',
                403 => 'authentication', 429 => 'busy', 503 => 'busy', 302 => 'unavailable', 500 => 'unavailable'] as $code => $status
        ) {
            $transport = function (string $endpoint, array $options) use ($code): array {
                $this->assertSame('https://1.1.1.1/verify', $endpoint);
                $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
                $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
                $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
                $this->assertSame(5, $options['CURLOPT_TIMEOUT']);
                return [$code, 0];
            };
            $this->assertSame($status, readiness::probe('https://1.1.1.1/verify', 'secret', $transport));
        }
        $this->assertSame('unavailable', readiness::probe('https://1.1.1.1/verify', '', static function (): array {
            return [200, 28];
        }));
    }
}
