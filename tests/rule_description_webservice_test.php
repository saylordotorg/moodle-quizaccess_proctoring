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

        $this->assertCount(1, $messages);
        foreach ($messages as $message) {
            // Throws invalid_parameter_exception when cleaning changes the value, as core does for return values.
            $this->assertSame($message, validate_param($message, PARAM_TEXT));
        }
        $this->assertStringNotContainsString('<', $messages[0]);
        $this->assertStringContainsString('webcam', $messages[0]);
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
        $this->assertCount(2, $messages);
        $this->assertSame($messages[1], validate_param($messages[1], PARAM_TEXT));
        $this->assertStringContainsStringIgnoringCase('photo ID', $messages[1]);
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
