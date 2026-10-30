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
 * Tests for proctoring profiles: named sets of site settings a quiz can use.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\device;
use quizaccess_proctoring\local\face_strikes;
use quizaccess_proctoring\local\profile;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');

/**
 * Profile tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\profile
 * @covers \quizaccess_proctoring\local\face_strikes
 * @covers \quizaccess_proctoring\local\device
 * @covers ::quizaccess_proctoring_get_effective_risk_review_settings
 * @covers ::quizaccess_proctoring_risk_hold_reachability
 * @covers \quizaccess_proctoring
 */
final class profile_test extends advanced_testcase {
    /** @var \stdClass Course. */
    private $course;

    /**
     * A site that proctors lightly: no ID check, no hold, threshold 80.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('idverificationenabled', 0, 'quizaccess_proctoring');
        set_config('fcheckstartchk', 1, 'quizaccess_proctoring');
        set_config('mobilescreensharemode', 'bypass', 'quizaccess_proctoring');
        set_config('riskreviewenabled', 0, 'quizaccess_proctoring');
        set_config('riskreviewthreshold', 80, 'quizaccess_proctoring');
        set_config('blurquizwithoutface', 1, 'quizaccess_proctoring');
        set_config('facestrikes', 0, 'quizaccess_proctoring');
        set_config('cheatinglockoutenabled', 0, 'quizaccess_proctoring');
        $this->course = $this->getDataGenerator()->create_course();
        profile::reset_cache();
    }

    /**
     * An ACE credit profile: ID, desktop only, hold at 60, end after 3 face strikes.
     *
     * @return int Profile id.
     */
    private function ace_profile(): int {
        return profile::save(0, 'ACE credit', 'ACE credit final exams', [
            'idverificationenabled' => '1',
            'mobilescreensharemode' => 'block',
            'riskreviewenabled' => '1',
            'riskreviewthreshold' => '60',
            'facestrikes' => '3',
        ]);
    }

    /**
     * A proctored quiz using a profile.
     *
     * @param int $profileid Profile id, 0 for none.
     * @param array $extra Other quiz settings.
     * @return \stdClass Quiz with cmid.
     */
    private function quiz(int $profileid, array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_module('quiz', array_merge([
            'course' => $this->course->id,
            'proctoringrequired' => 1,
            'profileid' => $profileid,
        ], $extra));
    }

    /**
     * Only allowed settings and values are kept; blank means "use the site setting".
     */
    public function test_clean_keeps_only_allowed_values(): void {
        $this->assertSame(
            ['idverificationenabled' => '1', 'multimonitormode' => 'block', 'facestrikes' => '5', 'riskreviewthreshold' => '70'],
            profile::clean([
                'riskreviewthreshold' => '70',
                'idverificationenabled' => '1',
                'fcheckstartchk' => '',
                'multimonitormode' => 'block',
                'detectphone' => 'maybe',
                'facestrikes' => '5',
                'captchabeforeattemptenabled' => null,
                'imageretentiondays' => '1',
            ])
        );
        $this->assertSame([], profile::clean(['riskreviewthreshold' => '101', 'facestrikes' => '1', 'riskreviewenabled' => '3']));
        $this->assertSame([], profile::decode('not json'));
    }

    /**
     * A quiz reads its profile's values, and the site's for anything the profile leaves alone.
     */
    public function test_config_layers_profile_over_site(): void {
        $ace = $this->ace_profile();
        $acequiz = $this->quiz($ace);
        $plainquiz = $this->quiz(0);

        $this->assertSame('1', profile::config((int)$acequiz->id, 'idverificationenabled'));
        $this->assertSame('block', profile::config((int)$acequiz->id, 'mobilescreensharemode'));
        // Left alone by the profile: the site value.
        $this->assertEquals(1, profile::config((int)$acequiz->id, 'fcheckstartchk'));
        $this->assertEquals(0, profile::config((int)$plainquiz->id, 'idverificationenabled'));
        // A setting no profile may change always reads the site.
        set_config('imageretentiondays', 30, 'quizaccess_proctoring');
        $this->assertEquals(30, profile::config((int)$acequiz->id, 'imageretentiondays'));
        $this->assertSame('ACE credit', profile::for_quiz((int)$acequiz->id)->name);
        $this->assertNull(profile::for_quiz((int)$plainquiz->id));
    }

    /**
     * Risk action and threshold come from the profile unless the quiz sets its own.
     */
    public function test_risk_settings_follow_profile_then_quiz(): void {
        $ace = $this->ace_profile();
        $inherits = $this->quiz($ace);
        $own = $this->quiz($ace, ['riskreviewmode' => 2, 'riskreviewthreshold' => 90]);
        $plain = $this->quiz(0);

        $settings = quizaccess_proctoring_get_effective_risk_review_settings((int)$inherits->cmid);
        $this->assertSame(QUIZACCESS_PROCTORING_RISK_ACTION_HOLD, $settings['action']);
        $this->assertSame(60, $settings['threshold']);

        $settings = quizaccess_proctoring_get_effective_risk_review_settings((int)$own->cmid);
        $this->assertSame(QUIZACCESS_PROCTORING_RISK_ACTION_AUTO_FAIL, $settings['action']);
        $this->assertSame(90, $settings['threshold']);

        $settings = quizaccess_proctoring_get_effective_risk_review_settings((int)$plain->cmid);
        $this->assertFalse($settings['enabled']);
        $this->assertSame(80, $settings['threshold']);
    }

    /**
     * A hold reached only through a profile still counts as a route to the review queue.
     */
    public function test_risk_hold_reachable_through_profile(): void {
        $this->quiz(0);
        $this->assertSame('off', quizaccess_proctoring_risk_hold_reachability());

        $this->quiz($this->ace_profile());
        $this->assertSame('reachable', quizaccess_proctoring_risk_hold_reachability());
    }

    /**
     * The face strike limit comes from the profile when the quiz inherits it.
     */
    public function test_face_strike_limit_follows_profile(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $acequiz = $this->quiz($this->ace_profile());
        $plainquiz = $this->quiz(0);

        $limit = fn($quiz) => face_strikes::limit_for((int)$this->course->id, (int)$quiz->id, (int)$student->id, time());
        $this->assertSame(3, $limit($acequiz));
        $this->assertSame(0, $limit($plainquiz));
    }

    /**
     * Profile names are unique, ignoring case.
     */
    public function test_names_are_unique(): void {
        $this->ace_profile();
        $this->assertTrue(profile::name_taken('ace CREDIT'));
        $this->expectException(\invalid_parameter_exception::class);
        profile::save(0, 'Ace Credit', '', []);
    }

    /**
     * Editing a profile reaches its quizzes; deleting it sends them back to the site settings.
     */
    public function test_edit_and_delete(): void {
        global $DB;

        $ace = $this->ace_profile();
        $quiz = $this->quiz($ace);
        $this->assertSame([$ace => 1], profile::usage_counts());

        profile::save($ace, 'ACE credit', '', ['idverificationenabled' => '0']);
        $this->assertSame('0', profile::config((int)$quiz->id, 'idverificationenabled'));
        $this->assertEquals(0, profile::config((int)$quiz->id, 'riskreviewenabled'));

        profile::delete($ace);
        $this->assertFalse($DB->record_exists(profile::TABLE, ['id' => $ace]));
        $this->assertEquals(0, $DB->get_field('quizaccess_proctoring', 'profileid', ['quizid' => $quiz->id]));
        $this->assertEquals(0, profile::config((int)$quiz->id, 'idverificationenabled'));
    }

    /**
     * Saving a quiz with a profile that does not exist stores no profile.
     */
    public function test_quiz_save_ignores_unknown_profile(): void {
        global $DB;

        $quiz = $this->quiz(9999);
        $this->assertEquals(0, $DB->get_field('quizaccess_proctoring', 'profileid', ['quizid' => $quiz->id]));
    }

    /**
     * The summary names each changed setting and its value.
     */
    public function test_describe(): void {
        $lines = profile::describe(['idverificationenabled' => '1', 'riskreviewthreshold' => '60']);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(get_string('setting:idverificationenabled', 'quizaccess_proctoring'), $lines[0]);
        $this->assertStringContainsString('60', $lines[1]);
    }

    /**
     * A Chrome-or-Edge profile turns Firefox away at the start of a new attempt, naming the
     * browsers to use; a quiz without the profile lets it start.
     */
    public function test_browser_policy_blocks_new_attempt(): void {
        $ace = profile::save(0, 'ACE credit', '', ['allowedbrowsers' => 'chromeedge']);
        $acequiz = $this->quiz($ace);
        $plainquiz = $this->quiz(0);
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        \core_useragent::instance(true, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0');

        $rule = fn($quiz) => \quizaccess_proctoring::make(\mod_quiz\quiz_settings::create((int)$quiz->id), time(), false);
        $message = $rule($acequiz)->prevent_new_attempt(0, null);
        $this->assertStringContainsString(get_string('browsers:allowed_chromeedge', 'quizaccess_proctoring'), $message);
        $this->assertStringContainsString(get_string('browser:firefox', 'quizaccess_proctoring'), $message);
        $this->assertFalse($rule($plainquiz)->prevent_new_attempt(0, null));

        \core_useragent::instance(true, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0');
        $this->assertFalse($rule($acequiz)->prevent_new_attempt(0, null));
    }

    /**
     * A tablet asking for the desktop site may skip the screen share on a quiz whose profile lets
     * phones and tablets skip it, even where the site blocks them.
     */
    public function test_tablet_report_follows_profile(): void {
        set_config('mobilescreensharemode', 'block', 'quizaccess_proctoring');
        $bypass = profile::save(0, 'Tablets welcome', '', ['mobilescreensharemode' => 'bypass']);
        $quiz = $this->quiz($bypass);
        $plain = $this->quiz(0);
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        \core_useragent::instance(true, 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/129.0.0.0 Safari/537.36');

        $this->assertSame('bypass', device::mobile_mode((int)$quiz->id));
        $this->assertSame('block', device::mobile_mode((int)$plain->id));
        $this->assertFalse(device::report_tablet(\context_module::instance((int)$plain->cmid)));
        $this->assertTrue(device::report_tablet(\context_module::instance((int)$quiz->cmid)));
    }
}
