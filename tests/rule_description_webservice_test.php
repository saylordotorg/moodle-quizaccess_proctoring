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
 * Tests that the rule description survives the quiz web service's return validation.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');

/**
 * Tests for the description returned to the Moodle app.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring::description
 */
final class rule_description_webservice_test extends advanced_testcase {
    /**
     * Build the quiz settings object for a new proctored quiz.
     *
     * @return \mod_quiz\quiz_settings
     */
    private function create_quizobj(): \mod_quiz\quiz_settings {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'proctoringrequired' => 1]);
        return \mod_quiz\quiz_settings::create($quiz->id);
    }

    /**
     * Every message must pass the PARAM_TEXT check mod_quiz_get_quiz_access_information applies to accessrules,
     * including for a teacher, whose web description carries the report button.
     */
    public function test_webservice_description_passes_param_text_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // WS_SERVER cannot be defined inside a test run, so report a web service request by override.
        $rule = new class ($this->create_quizobj(), time()) extends \quizaccess_proctoring {
            protected function is_web_service_request(): bool {
                return true;
            }
        };
        $messages = $rule->description();

        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            // Throws invalid_parameter_exception when cleaning changes the value, as core does for return values.
            $this->assertSame($message, validate_param($message, PARAM_TEXT));
            $this->assertStringNotContainsString('<', $message);
        }
        // First, that the exam is taken in the browser and how (CPIT-454); then the webcam notice.
        $this->assertSame(get_string('appusebrowser', 'quizaccess_proctoring'), $messages[0]);
        $this->assertStringContainsString('Open in browser', $messages[0]);
        $this->assertStringContainsString('webcam', $messages[1]);
    }

    /**
     * Where phones must share a screen, the app sends the student to a computer, not to a phone
     * browser that cannot take the exam either (Codex review).
     */
    public function test_app_notice_follows_the_phone_screen_share_policy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $quizobj = $this->create_quizobj();
        $notice = function () use ($quizobj): string {
            $rule = new class ($quizobj, time()) extends \quizaccess_proctoring {
                /**
                 * Report a web service request, as the Moodle app makes.
                 *
                 * @return bool
                 */
                protected function is_web_service_request(): bool {
                    return true;
                }
            };
            return $rule->description()[0];
        };

        foreach (['require', 'block'] as $mode) {
            set_config('mobilescreensharemode', $mode, 'quizaccess_proctoring');
            set_config('requireentirescreen', 1, 'quizaccess_proctoring');
            $this->assertSame(get_string('appusecomputer', 'quizaccess_proctoring'), $notice(), $mode);
            $this->assertSame($notice(), validate_param($notice(), PARAM_TEXT));

            // An exam that never shares the screen runs in the phone's browser.
            set_config('requireentirescreen', 0, 'quizaccess_proctoring');
            set_config('captureviolationdesktop', 0, 'quizaccess_proctoring');
            $this->assertSame(get_string('appusebrowser', 'quizaccess_proctoring'), $notice(), $mode);
            set_config('captureviolationdesktop', 1, 'quizaccess_proctoring');
        }

        set_config('mobilescreensharemode', 'bypass', 'quizaccess_proctoring');
        set_config('requireentirescreen', 1, 'quizaccess_proctoring');
        $this->assertSame(get_string('appusebrowser', 'quizaccess_proctoring'), $notice());

        // A per-student override is the student's real requirement, as the preflight applies it.
        global $DB, $USER;
        $override = function (int $state) use ($DB, $USER, $quizobj): void {
            $DB->delete_records('quizaccess_proctoring_overrides', ['userid' => $USER->id]);
            $DB->insert_record('quizaccess_proctoring_overrides', (object)[
                'courseid' => (int)$quizobj->get_quiz()->course, 'quizid' => 0, 'userid' => (int)$USER->id,
                'captchastate' => -1, 'webcamstate' => -1, 'idverificationstate' => -1, 'screensharestate' => $state,
                'multimonitorstate' => -1, 'phonedetectionstate' => -1, 'justification' => 'Accommodation',
                'expiry' => null, 'revoked' => 0, 'grantedby' => 2, 'timecreated' => time() - 60, 'timemodified' => time() - 60,
            ]);
        };
        $override(1);
        $this->assertSame(get_string('appusecomputer', 'quizaccess_proctoring'), $notice(), 'Forced even where phones skip it.');
        set_config('mobilescreensharemode', 'require', 'quizaccess_proctoring');
        set_config('captureviolationdesktop', 0, 'quizaccess_proctoring');
        set_config('requireentirescreen', 0, 'quizaccess_proctoring');
        $this->assertSame(get_string('appusecomputer', 'quizaccess_proctoring'), $notice(), 'Forced on a share-free quiz.');
        $override(0);
        set_config('requireentirescreen', 1, 'quizaccess_proctoring');
        $this->assertSame(get_string('appusebrowser', 'quizaccess_proctoring'), $notice(), 'Waived for this student.');
    }

    /**
     * The browser's quiz page does not tell the student to leave the app it is not in.
     */
    public function test_web_description_has_no_app_notice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $messages = (new \quizaccess_proctoring($this->create_quizobj(), time()))->description();
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('Open in browser', $message);
        }
    }

    /**
     * The quiz view page keeps the formatted header and the report button.
     */
    public function test_web_description_keeps_html(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $rule = new \quizaccess_proctoring($this->create_quizobj(), time());
        $messages = $rule->description();

        $this->assertSame(get_string('proctoringheader', 'quizaccess_proctoring'), $messages[0]);
        $this->assertStringContainsString('<form', $messages[1]);
    }

    /**
     * The site's launch instructions appear above the start button, and as plain text in the app (CPIT-478).
     */
    public function test_launch_instructions_are_shown(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('launchinstructions', '<p>Have your <strong>photo ID</strong> ready.</p>', 'quizaccess_proctoring');
        $quizobj = $this->create_quizobj();

        $messages = (new \quizaccess_proctoring($quizobj, time()))->description();
        $this->assertStringContainsString('<strong>photo ID</strong>', $messages[1]);
        $this->assertStringContainsString('<form', $messages[2]);

        $rule = new class ($quizobj, time()) extends \quizaccess_proctoring {
            protected function is_web_service_request(): bool {
                return true;
            }
        };
        $messages = $rule->description();
        $this->assertCount(3, $messages);
        $this->assertSame($messages[2], validate_param($messages[2], PARAM_TEXT));
        $this->assertStringContainsStringIgnoringCase('photo ID', $messages[2]);
    }

    /**
     * Each step links its own help page and the general support page when they are set (CPIT-478).
     */
    public function test_step_help_links(): void {
        $this->resetAfterTest();
        $heading = new \ReflectionMethod(\quizaccess_proctoring::class, 'make_preflight_step_heading');
        $heading->setAccessible(true);

        $html = $heading->invoke(null, 'Photo ID', 'Upload it.', 'idrequirementsurl', 'help:idrequirements');
        $this->assertStringNotContainsString('<a ', $html);

        set_config('idrequirementsurl', 'https://support.example.org/photo-id', 'quizaccess_proctoring');
        set_config('supporturl', 'https://support.example.org/', 'quizaccess_proctoring');
        $html = $heading->invoke(null, 'Photo ID', 'Upload it.', 'idrequirementsurl', 'help:idrequirements');
        $this->assertStringContainsString('https://support.example.org/photo-id', $html);
        $this->assertStringContainsString(get_string('help:support', 'quizaccess_proctoring'), $html);

        $html = $heading->invoke(null, 'Webcam', 'Look at the camera.');
        $this->assertStringNotContainsString('photo-id', $html);
        $this->assertStringContainsString(get_string('help:support', 'quizaccess_proctoring'), $html);
    }
}
