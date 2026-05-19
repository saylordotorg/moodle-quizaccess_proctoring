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
 * Security regression tests for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\task\send_daily_report_task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Security regression tests for file access hardening.
 */
final class security_test extends advanced_testcase {

    /**
     * Pluginfile record checks must be scoped to the owning module.
     */
    public function test_module_file_record_check_is_scoped_to_module(): void {
        $this->resetAfterTest();

        [$course, $cm, $logid, $filename] = $this->create_picture_file_fixture();
        $othercourse = $this->getDataGenerator()->create_course();
        $otherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $othercourse->id]);

        $this->assertTrue(\quizaccess_proctoring_module_file_has_record(
            (int)$course->id,
            (int)$cm->id,
            'picture',
            $filename
        ));
        $this->assertFalse(\quizaccess_proctoring_module_file_has_record(
            (int)$othercourse->id,
            (int)$otherquiz->cmid,
            'picture',
            $filename
        ));
        $this->assertFalse(\quizaccess_proctoring_module_file_has_record(
            (int)$course->id,
            (int)$cm->id,
            'picture',
            'missing-' . $logid . '.png'
        ));
    }

    /**
     * Internal AI and face-match jobs should read pluginfile bytes without bypassing public pluginfile access checks.
     */
    public function test_pluginfile_url_to_bytes_reads_from_file_storage(): void {
        $this->resetAfterTest();

        [, , , , $url, $content] = $this->create_picture_file_fixture();

        $this->assertSame($content, \quizaccess_proctoring_pluginfile_url_to_bytes($url));
        $this->assertFalse(\quizaccess_proctoring_pluginfile_url_to_bytes('https://example.invalid/not-a-pluginfile.png'));
    }

    /**
     * Module files are available to the owner or a report viewer, but not another enrolled student.
     */
    public function test_pluginfile_access_requires_owner_or_report_capability(): void {
        $this->resetAfterTest();

        [$course, $cm, $logid, $filename, , , $file, $owner] = $this->create_picture_file_fixture();
        $context = \context_module::instance((int)$cm->id);

        $otherstudent = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherstudent->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');

        $this->setUser($otherstudent);
        $this->assertFalse(\quizaccess_proctoring_can_serve_pluginfile(
            $course,
            $cm,
            $context,
            'picture',
            $logid,
            $filename,
            $file
        ));

        $this->setUser($owner);
        $this->assertTrue(\quizaccess_proctoring_can_serve_pluginfile(
            $course,
            $cm,
            $context,
            'picture',
            $logid,
            $filename,
            $file
        ));

        $this->setUser($teacher);
        $this->assertTrue(\quizaccess_proctoring_can_serve_pluginfile(
            $course,
            $cm,
            $context,
            'picture',
            $logid,
            $filename,
            $file
        ));
    }

    /**
     * Configured outbound AI endpoints must not point at localhost or private infrastructure.
     */
    public function test_outbound_endpoint_validation_blocks_private_ranges(): void {
        $this->assertSame(
            'https://8.8.8.8/v1/chat/completions',
            \quizaccess_proctoring_validate_outbound_endpoint('https://8.8.8.8/v1/chat/completions')
        );

        foreach ([
            'http://localhost:8000/verify',
            'http://127.0.0.1/verify',
            'http://169.254.169.254/latest',
            'https://token@example.com/v1/chat/completions',
        ] as $url) {
            try {
                \quizaccess_proctoring_validate_outbound_endpoint($url);
                $this->fail('Private or reserved endpoint was accepted: ' . $url);
            } catch (\moodle_exception $e) {
                $this->assertNotEmpty($e->errorcode);
            }
        }
    }

    /**
     * Browser-submitted images must decode to real image bytes before storage or outbound AI processing.
     */
    public function test_base64_image_decode_rejects_non_images(): void {
        $png = 'data:image/png;base64,' .
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
        $this->assertNotEmpty(\quizaccess_proctoring_decode_base64_image_data($png, 2048));

        $this->expectException(\invalid_parameter_exception::class);
        \quizaccess_proctoring_decode_base64_image_data(base64_encode('not an image'), 2048);
    }

    /**
     * Capabilities that handle biometric or proctoring data should be marked as personal-data risks.
     */
    public function test_sensitive_capabilities_declare_personal_data_risk(): void {
        $capabilities = [];
        require(__DIR__ . '/../db/access.php');

        foreach ([
            'quizaccess/proctoring:sendcamshot',
            'quizaccess/proctoring:viewreport',
            'quizaccess/proctoring:deletecamshots',
            'quizaccess/proctoring:analyzeimages',
            'quizaccess/proctoring:reviewriskholds',
        ] as $capability) {
            $this->assertArrayHasKey($capability, $capabilities);
            $this->assertNotEmpty($capabilities[$capability]['riskbitmask'] & RISK_PERSONAL);
        }

        $this->assertNotEmpty($capabilities['quizaccess/proctoring:deletecamshots']['riskbitmask'] & RISK_DATALOSS);
    }

    /**
     * External daily report addresses should require an explicit privacy-aware admin opt-in.
     */
    public function test_daily_report_external_recipients_require_explicit_opt_in(): void {
        $this->resetAfterTest();

        $teacher = $this->getDataGenerator()->create_user(['email' => 'teacher-security@example.com']);
        $task = new send_daily_report_task();

        set_config('dailyreportallowexternal', 0, 'quizaccess_proctoring');
        ob_start();
        $recipients = $this->invoke_task_method($task, 'get_recipients', [
            $teacher->email . ', external-security@example.com',
        ]);
        $output = ob_get_clean();
        $this->assertCount(1, $recipients);
        $this->assertSame($teacher->id, reset($recipients)->id);
        $this->assertStringContainsString('external recipients are disabled', $output);

        set_config('dailyreportallowexternal', 1, 'quizaccess_proctoring');
        $recipients = $this->invoke_task_method($task, 'get_recipients', [
            $teacher->email . ', external-security@example.com',
        ]);
        $this->assertCount(2, $recipients);

        $external = array_values(array_filter($recipients, static function($recipient): bool {
            return !empty($recipient->quizaccessproctoringexternal);
        }));
        $this->assertCount(1, $external);
        $this->assertSame('external-security@example.com', $external[0]->email);
    }

    /**
     * Moodle-user daily report recipients should only receive rows they can view.
     */
    public function test_daily_report_rows_are_scoped_to_recipient_report_capability(): void {
        $this->resetAfterTest();

        set_config('dailyreportincludeall', 1, 'quizaccess_proctoring');
        $task = new send_daily_report_task();

        [$firstcourse, $firstcm] = $this->create_daily_report_log_fixture('First security course');
        [, $secondcm] = $this->create_daily_report_log_fixture('Second security course');

        $teacher = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $firstcourse->id, 'teacher');

        $start = time() - DAYSECS;
        $end = time() + MINSECS;

        $teacherdata = $this->invoke_task_method($task, 'build_report_data', [$start, $end, $teacher]);
        $this->assertCount(1, $teacherdata['rows']);
        $this->assertSame((int)$firstcm->id, (int)$teacherdata['rows'][0]['cmid']);

        $outsiderdata = $this->invoke_task_method($task, 'build_report_data', [$start, $end, $outsider]);
        $this->assertCount(0, $outsiderdata['rows']);
        $this->assertSame(0, $outsiderdata['summary']['recentattempts']);

        $externalrecipient = (object)['id' => -1, 'quizaccessproctoringexternal' => true];
        $externaldata = $this->invoke_task_method($task, 'build_report_data', [$start, $end, $externalrecipient]);
        $this->assertCount(2, $externaldata['rows']);
        $this->assertContains((int)$firstcm->id, array_map('intval', array_column($externaldata['rows'], 'cmid')));
        $this->assertContains((int)$secondcm->id, array_map('intval', array_column($externaldata['rows'], 'cmid')));
    }

    /**
     * Calls a private task method for focused security regression tests.
     *
     * @param object $object Object under test.
     * @param string $method Method name.
     * @param array $arguments Method arguments.
     * @return mixed
     */
    private function invoke_task_method(object $object, string $method, array $arguments = []) {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $arguments);
    }

    /**
     * Creates one recent proctored attempt log for daily report filtering tests.
     *
     * @param string $coursename Course full name.
     * @return array Course and course module.
     */
    private function create_daily_report_log_fixture(string $coursename): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['fullname' => $coursename]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $student->id,
            'webcampicture' => '',
            'status' => 1,
            'timemodified' => time(),
        ]);

        return [$course, $cm];
    }

    /**
     * Creates a quiz, proctoring log, and matching stored pluginfile record.
     *
     * @return array Fixture values.
     */
    private function create_picture_file_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $owner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($owner->id, $course->id, 'student');

        $log = (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $owner->id,
            'webcampicture' => '',
            'status' => 0,
            'timemodified' => time(),
        ];
        $logid = $DB->insert_record('quizaccess_proctoring_logs', $log);

        $context = \context_module::instance((int)$cm->id);
        $filename = 'webcam-security-test-' . $logid . '.png';
        $content = 'proctoring image fixture';
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'quizaccess_proctoring',
            'filearea' => 'picture',
            'itemid' => $logid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $owner->id,
        ];
        $file = get_file_storage()->create_file_from_string($filerecord, $content);
        $url = \moodle_url::make_pluginfile_url(
            $context->id,
            'quizaccess_proctoring',
            'picture',
            $logid,
            '/',
            $filename,
            false
        )->out(false);

        $DB->set_field('quizaccess_proctoring_logs', 'webcampicture', $url, ['id' => $logid]);

        return [$course, $cm, $logid, $filename, $url, $content, $file, $owner];
    }
}
