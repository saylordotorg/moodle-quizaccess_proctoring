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
 * Exercise the real quiz preflight form submission rather than its rule in isolation.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use mod_quiz\form\preflight_check_form;
use mod_quiz\quiz_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * Covers accepted submissions and recoverable failures with fresh server-side face evidence.
 *
 * @covers \quizaccess_proctoring
 */
final class preflight_form_integration_test extends \advanced_testcase {
    /** @var \stdClass Quiz fixture. */
    private $quiz;

    /** @var \stdClass Course fixture. */
    private $course;

    /**
     * Reproduce the laptop's effective requirements without contacting a real provider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'proctoringrequired' => 1,
            'requireentirescreen' => 1,
            'captchamode' => 1,
            'password' => '',
            'browsersecurity' => '-',
            'allowofflineattempts' => 0,
        ]);
        foreach (
            ['privacynoticerequired', 'honorstatementrequired', 'captchabeforeattemptenabled',
            'fcheckstartchk', 'requireentirescreen'] as $setting
        ) {
            set_config($setting, 1, 'quizaccess_proctoring');
        }
        set_config('idverificationenabled', 0, 'quizaccess_proctoring');
        set_config('multimonitormode', 'off', 'quizaccess_proctoring');
        set_config('captchaprovider', 'turnstile', 'quizaccess_proctoring');
        set_config('turnstilesitekey', 'test-site-key', 'quizaccess_proctoring');
        set_config('turnstilesecretkey', 'test-secret-key', 'quizaccess_proctoring');
    }

    /**
     * Prevent an intentionally unused fallback mock from affecting another test.
     */
    protected function tearDown(): void {
        (new \ReflectionProperty(\curl::class, 'mockresponses'))->setValue(null, []);
        parent::tearDown();
    }

    /**
     * Browser confirmation writes must reach the actual hidden controls used by the POST.
     */
    public function test_rendered_confirmation_ids_allow_browser_updates_to_submit(): void {
        \quizaccess_proctoring_set_face_preflight_passed((int)$this->quiz->cmid);
        \curl::mock_response('{"success":true}');
        [$form] = $this->submitted_form(['entirescreenconfirmed' => 0]);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($form->render());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $confirmed = [];
        foreach (['entirescreenconfirmed', 'multimonitorconfirmed', 'idverificationconfirmed'] as $name) {
            $inputs = $xpath->query('//input[@id="id_' . $name . '"]');
            $this->assertSame(1, $inputs->length, 'The browser selects confirmation by id_' . $name);
            $input = $inputs->item(0);
            $this->assertSame($name, $input->getAttribute('name'));
            $this->assertSame('hidden', $input->getAttribute('type'));
            // Mirror document.getElementById(...).value = '1' in startAttempt.js.
            $input->setAttribute('value', '1');
            $confirmed[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        \curl::mock_response('{"success":true}');
        [$submitted, , $quickform] = $this->submitted_form($confirmed);
        $this->assertNotNull($submitted->get_data(), json_encode($quickform->_errors));
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed((int)$this->quiz->cmid));
    }

    /**
     * Both student attempts and administrator previews submit the same form successfully.
     *
     * @dataProvider user_kinds
     * @param bool $admin Whether to exercise the preview account.
     */
    public function test_complete_form_accepts_student_and_preview(bool $admin): void {
        if (!$admin) {
            $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        }
        \quizaccess_proctoring_set_face_preflight_passed((int)$this->quiz->cmid);
        // Curl's mock queue is LIFO. A second verification must fail instead of reaching the network.
        \curl::mock_response('{"success":false,"error-codes":["timeout-or-duplicate"]}');
        \curl::mock_response('{"success":true}');
        [$form, $manager, $quickform] = $this->submitted_form();

        $data = $form->get_data();
        $this->assertNotNull($data, json_encode($quickform->_errors));
        $this->assertSame([], $quickform->_errors);
        $this->assertSame(1, (int)$data->proctoringprivacy);
        $this->assertSame(1, (int)$data->proctoring);
        $this->assertSame(1, (int)$data->entirescreenconfirmed);
        $this->assertSame(1, (int)$data->multimonitorconfirmed);
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed((int)$this->quiz->cmid));
        $this->assertCount(1, (new \ReflectionProperty(\curl::class, 'mockresponses'))->getValue());
        $this->assertEquals($data, $form->get_data());
        $this->assertCount(1, (new \ReflectionProperty(\curl::class, 'mockresponses'))->getValue());

        $manager->notify_preflight_check_passed(null);
        $this->assertFalse(\quizaccess_proctoring_has_face_preflight_passed((int)$this->quiz->cmid));
    }

    /**
     * Provide both relevant roles.
     *
     * @return array
     */
    public static function user_kinds(): array {
        return ['student' => [false], 'administrator preview' => [true]];
    }

    /**
     * A rejected CAPTCHA attaches its own error and does not discard a valid face check.
     */
    public function test_rejected_captcha_keeps_face_evidence(): void {
        \quizaccess_proctoring_set_face_preflight_passed((int)$this->quiz->cmid);
        \curl::mock_response('{"success":false,"error-codes":["timeout-or-duplicate"]}');
        [$form, , $quickform] = $this->submitted_form();

        $this->assertNull($form->get_data());
        $this->assertSame(['proctoringcaptcha'], array_keys($quickform->_errors));
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed((int)$this->quiz->cmid));
    }

    /**
     * The raw provider token remains available through POST even though QuickForm does not export it.
     */
    public function test_missing_captcha_is_reported_on_captcha(): void {
        \quizaccess_proctoring_set_face_preflight_passed((int)$this->quiz->cmid);
        [$form, , $quickform] = $this->submitted_form(['cf-turnstile-response' => '']);

        $this->assertNull($form->get_data());
        $this->assertSame(['proctoringcaptcha'], array_keys($quickform->_errors));
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed((int)$this->quiz->cmid));
    }

    /**
     * An unconfirmed screen is rejected even if all other checks passed.
     */
    public function test_screen_confirmation_is_not_replaced_by_the_render_default(): void {
        \quizaccess_proctoring_set_face_preflight_passed((int)$this->quiz->cmid);
        \curl::mock_response('{"success":true}');
        [$form, , $quickform] = $this->submitted_form(['entirescreenconfirmed' => 0]);

        $this->assertNull($form->get_data());
        $this->assertSame(['proctoring'], array_keys($quickform->_errors));
        $this->assertTrue(\quizaccess_proctoring_has_face_preflight_passed((int)$this->quiz->cmid));
    }

    /**
     * Submit the real Moodle form with the same fields as the browser's Start quiz action.
     *
     * @param array $overrides Submitted values to change.
     * @return array Form, access manager and underlying QuickForm.
     */
    private function submitted_form(array $overrides = []): array {
        global $PAGE;

        $quizobj = quiz_settings::create((int)$this->quiz->id);
        $PAGE = new \moodle_page();
        $PAGE->set_context($quizobj->get_context());
        $PAGE->set_url($quizobj->start_attempt_url());
        preflight_check_form::mock_submit(array_replace([
            'cmid' => $this->quiz->cmid,
            'proctoringprivacy' => 1,
            'proctoring' => 1,
            'entirescreenconfirmed' => 1,
            'multimonitorconfirmed' => 1,
            'idverificationconfirmed' => 1,
            'cf-turnstile-response' => 'test-single-use-token',
            'submitbutton' => 'Start attempt',
        ], $overrides));
        $manager = $quizobj->get_access_manager(time());
        $form = $manager->get_preflight_check_form($quizobj->start_attempt_url(), null);
        $quickform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
        return [$form, $manager, $quickform];
    }
}
