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

defined('MOODLE_INTERNAL') || die();

/**
 * Security regressions for browser evidence and provider verification responses.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_proctoring_external
 */
final class external_security_audit_test extends \advanced_testcase {
    /** A valid PNG, usable without any external image files. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

    /**
     * Browser captures cannot masquerade as staff/reference face rows.
     */
    public function test_camshot_cannot_create_reference_face_row(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        set_config('continuousfacecheck', 0, 'quizaccess_proctoring');
        $reportid = $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $user->id,
            'webcampicture' => '',
            'status' => 0,
            'timemodified' => time(),
        ]);
        $image = $this->make_image();

        $result = \quizaccess_proctoring_external::send_camshot(
            $course->id,
            $reportid,
            $cm->id,
            $image,
            1,
            'admin_image',
            $image,
            1
        );

        $face = $DB->get_record('quizaccess_proctoring_face_images', ['id' => $result['screenshotid']], '*', MUST_EXIST);
        $this->assertSame('camshot_image', $face->parent_type);
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_face_images', ['parent_type' => 'admin_image']));
    }

    /**
     * Preflight evidence has no attempt id and failed validation revokes an earlier pass.
     */
    public function test_failed_validation_cannot_create_reference_or_keep_a_pass(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $DB->insert_record('quizaccess_proctoring_user_images', (object)[
            'user_id' => $user->id,
            'photo_draft_id' => 0,
        ]);
        \quizaccess_proctoring_set_face_preflight_passed((int)$cm->id);
        $image = $this->make_image();

        $result = \quizaccess_proctoring_external::validate_face(
            $course->id,
            $cm->id,
            '',
            $image,
            'admin_image',
            $image,
            1
        );

        $this->assertSame('photonotuploaded', $result['status']);
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $result['screenshotid']]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_face_images', ['parent_type' => 'admin_image']));
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed((int)$cm->id));
    }

    /**
     * Registration still records server-side preflight evidence for a first-time student.
     */
    public function test_registration_records_preflight_pass(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_fixture();
        $image = $this->make_image();

        $result = \quizaccess_proctoring_external::validate_face(
            $course->id,
            $cm->id,
            '',
            $image,
            'camshot_image',
            $image,
            1
        );

        $this->assertSame('registered', $result['status']);
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed((int)$cm->id));
    }

    /**
     * A first photo is shown to the student and kept only once they choose to use it (CPIT-476).
     */
    public function test_first_photo_waits_for_the_students_confirmation(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        [$course, $cm] = $this->create_fixture();
        $image = $this->make_image();
        set_config('referenceretentiondays', 365, 'quizaccess_proctoring');

        $preview = \quizaccess_proctoring_external::validate_face($course->id, $cm->id, '', $image, 'camshot_image', $image, 1, 0);
        $this->assertSame('confirmreference', $preview['status']);
        $this->assertStringContainsString('365', $preview['message']);
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $USER->id]));
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed((int)$cm->id));

        $used = \quizaccess_proctoring_external::validate_face($course->id, $cm->id, '', $image, 'camshot_image', $image, 1, 1);
        $this->assertSame('registered', $used['status']);
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $USER->id]));
    }

    /**
     * A self-registered reference the provider finds no face in is retired, not reported as a mismatch (CPIT-453).
     */
    public function test_faceless_self_registered_reference_is_retired_and_reregistered(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $image = $this->register_reference($course, $cm);

        $result = $this->validate_with_provider($course, $cm, $image, ['match' => false, 'reason' => 'reference_no_face']);

        $this->assertSame('referencereset', $result['status']);
        $this->assertSame(
            QUIZACCESS_PROCTORING_AWSFLAG_REFERENCE_UNUSABLE,
            (int)$DB->get_field('quizaccess_proctoring_logs', 'awsflag', ['id' => $result['screenshotid']])
        );
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));
        $this->assertFalse(\quizaccess_proctoring_get_image_url($user->id));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_fm_warnings', ['userid' => $user->id]));
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed((int)$cm->id));

        // The next capture registers a fresh reference under the first-registration checks.
        $again = \quizaccess_proctoring_external::validate_face($course->id, $cm->id, '', $image, 'camshot_image', $image, 1);
        $this->assertSame('registered', $again['status']);
    }

    /**
     * A late verdict about an already-replaced photo cannot retire the new one (overlapping checks).
     */
    public function test_stale_no_face_verdict_cannot_retire_a_replacement_reference(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $image = $this->register_reference($course, $cm);
        $oldurl = \quizaccess_proctoring_get_image_url($user->id);

        // Check A finds no face and retires the photo; the student registers a replacement.
        $this->assertSame('referencereset',
            $this->validate_with_provider($course, $cm, $image, ['match' => false, 'reason' => 'reference_no_face'])['status']);
        $again = \quizaccess_proctoring_external::validate_face($course->id, $cm->id, '', $image, 'camshot_image', $image, 1);
        $this->assertSame('registered', $again['status']);

        // Slower check B, which was comparing against the old photo, now returns its verdict.
        $this->assertFalse($this->invoke_external('retire_unusable_reference', [(int)$user->id, (string)$oldurl]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));
        $this->assertNotFalse(\quizaccess_proctoring_get_image_url($user->id));
    }

    /**
     * Retirement runs under the student's reference lock and always releases it.
     *
     * Contention itself needs two requests: in PHPUnit everything shares one process and one
     * database session, where Moodle's lock factories let the holder take the lock again.
     */
    public function test_retirement_releases_the_reference_lock(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $this->register_reference($course, $cm);
        $url = \quizaccess_proctoring_get_image_url($user->id);

        $this->assertTrue($this->invoke_external('retire_unusable_reference', [(int)$user->id, (string)$url]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));

        // A stale verdict on the already-retired photo also releases the lock.
        $this->assertFalse($this->invoke_external('retire_unusable_reference', [(int)$user->id, (string)$url]));

        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring_reference');
        $lock = $factory->get_lock('user' . $user->id, 0);
        $this->assertNotFalse($lock);
        $lock->release();
    }

    /**
     * A staff-uploaded reference is never retired by the student's precheck.
     */
    public function test_faceless_staff_uploaded_reference_is_kept(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $image = $this->register_reference($course, $cm);
        $DB->set_field('quizaccess_proctoring_user_images', 'photo_draft_id', 12345, ['user_id' => $user->id]);

        $result = $this->validate_with_provider($course, $cm, $image, ['match' => false, 'reason' => 'reference_no_face']);

        $this->assertSame('referenceunusable', $result['status']);
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));
        $this->assertNotFalse(\quizaccess_proctoring_get_image_url($user->id));
    }

    /**
     * With replacement switched off, the reference is kept and the student is sent to support.
     */
    public function test_faceless_reference_is_kept_when_replacement_is_off(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $image = $this->register_reference($course, $cm);
        set_config('replaceunusablereference', 0, 'quizaccess_proctoring');

        $result = $this->validate_with_provider($course, $cm, $image, ['match' => false, 'reason' => 'reference_no_face']);

        $this->assertSame('referenceunusable', $result['status']);
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));
    }

    /**
     * A plain mismatch never retires the reference, whatever the setting.
     */
    public function test_plain_mismatch_keeps_the_reference(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $user] = $this->create_fixture();
        $image = $this->register_reference($course, $cm);

        $result = $this->validate_with_provider($course, $cm, $image, ['match' => false, 'message' => 'Face does not match.']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(2, (int)$DB->get_field('quizaccess_proctoring_logs', 'awsflag', ['id' => $result['screenshotid']]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $user->id]));
    }

    /**
     * A string false or arbitrary truthy value cannot synthesize missing scores of 100.
     */
    public function test_false_provider_verdicts_do_not_pass(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        foreach (['false', 'no', 'failed', 2, ['unexpected']] as $verdict) {
            $result = $this->verify_provider_response($user, ['verified' => $verdict]);
            $this->assertSame('failed', $result['status']);
            $this->assertSame(0, $result['facescore']);
        }
    }

    /**
     * A final negative verification verdict cannot be replaced by a positive partial match.
     */
    public function test_negative_verification_takes_precedence_over_partial_match(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $result = $this->verify_provider_response($user, ['verified' => false, 'match' => true]);
        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, $result['facescore']);
    }

    /**
     * Boolean-only provider integrations remain supported.
     */
    public function test_explicit_positive_provider_verdict_passes(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $result = $this->verify_provider_response($user, ['verified' => true]);
        $this->assertSame('pass', $result['status']);
        $this->assertSame(100, $result['facescore']);
    }

    /**
     * Preliminary high scores do not overrule an incomplete or failed provider operation.
     */
    public function test_incomplete_provider_results_do_not_pass(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        foreach (['retry' => 'retry', 'error' => 'error', 'manual' => 'failed'] as $status => $expected) {
            $result = $this->verify_provider_response($user, [
                'status' => $status,
                'face_score' => 99,
                'name_score' => 99,
            ]);
            $this->assertSame($expected, $result['status']);
        }
    }

    /**
     * Unsupported raster types cannot bypass the declared MIME allowlist.
     */
    public function test_mislabeled_gif_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->invoke_external('decode_base64_image_data', [
            'data:image/png;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
        ]);
    }

    /**
     * Even allowed image formats must match the data URI's declaration.
     */
    public function test_mime_mismatch_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->invoke_external('decode_base64_image_data', ['data:image/jpeg;base64,' . self::PNG]);
    }

    /**
     * Malformed data URI metadata cannot be stripped to bypass its MIME validation.
     */
    public function test_malformed_image_prefix_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->invoke_external('decode_base64_image_data', ['data:text/html;ignored,' . self::PNG]);
    }

    /**
     * Whitespace cannot evade the size check and force unbounded preprocessing copies.
     */
    public function test_oversized_encoded_request_is_rejected_before_whitespace_removal(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->invoke_external('decode_base64_image_data', [str_repeat(' ', 2000) . self::PNG, 128]);
    }

    /**
     * Raw base64 and correctly declared PNG input remain valid.
     */
    public function test_valid_png_encodings_are_accepted(): void {
        $expected = base64_decode(self::PNG);
        $this->assertSame($expected, $this->invoke_external('decode_base64_image_data', [self::PNG]));
        $this->assertSame($expected, $this->invoke_external('decode_base64_image_data', ['data:image/png;base64,' . self::PNG]));
    }

    /**
     * Run a provider response through the real decision path without any network request.
     *
     * @param \stdClass $user User whose identity is being checked.
     * @param array $response Provider response.
     * @return array Normalized verification result.
     */
    private function verify_provider_response(\stdClass $user, array $response): array {
        set_config('idverificationendpoint', 'https://8.8.8.8/verify', 'quizaccess_proctoring');
        set_config('idverificationapikey', 'security-test-key', 'quizaccess_proctoring');
        \curl::mock_response(json_encode($response));
        return $this->invoke_external('call_id_verification_endpoint', [base64_decode(self::PNG), base64_decode(self::PNG), $user]);
    }

    /**
     * Register the current user's reference image through the real first-capture path.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Quiz course module.
     * @return string The image used, reusable for later captures.
     */
    private function register_reference(\stdClass $course, \stdClass $cm): string {
        $image = $this->make_image();
        $result = \quizaccess_proctoring_external::validate_face($course->id, $cm->id, '', $image, 'camshot_image', $image, 1);
        $this->assertSame('registered', $result['status']);
        set_config('replaceunusablereference', 1, 'quizaccess_proctoring');
        return $image;
    }

    /**
     * Run a precheck capture against a mocked face-match provider response.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Quiz course module.
     * @param string $image Capture to submit.
     * @param array $response Provider response.
     * @return array validate_face result.
     */
    private function validate_with_provider(\stdClass $course, \stdClass $cm, string $image, array $response): array {
        set_config('fcmethod', 'customapi', 'quizaccess_proctoring');
        set_config('custom_ai_endpoint', 'https://8.8.8.8/verify-face', 'quizaccess_proctoring');
        set_config('custom_api_key', 'security-test-key', 'quizaccess_proctoring');
        \curl::mock_response(json_encode($response));
        return \quizaccess_proctoring_external::validate_face($course->id, $cm->id, '', $image, 'camshot_image', $image, 1);
    }

    /**
     * Invoke an internal parser without broadening its production API.
     *
     * @param string $method Method to call.
     * @param array $arguments Arguments.
     * @return mixed Method return value.
     */
    private function invoke_external(string $method, array $arguments) {
        $reflection = new \ReflectionMethod(\quizaccess_proctoring_external::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $arguments);
    }

    /**
     * Create an enrolled student and accessible quiz.
     *
     * @return array Course, module and student.
     */
    private function create_fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        return [$course, $cm, $user];
    }

    /**
     * Make a well-lit, sharp fixture accepted by the server's reference quality checks.
     *
     * @return string PNG data URI.
     */
    private function make_image(): string {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to store webcam captures.');
        }
        $image = imagecreatetruecolor(80, 80);
        for ($x = 0; $x < 80; $x++) {
            $level = $x % 2 === 0 ? 70 : 190;
            $colour = imagecolorallocate($image, $level, $level, $level);
            imageline($image, $x, 0, $x, 79, $colour);
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}
