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
 * Tests for phone and tablet detection, including tablets that ask for the desktop site (CPIT-495).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\external\report_tablet;
use quizaccess_proctoring\local\device;

/**
 * Device tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\device
 * @covers \quizaccess_proctoring\external\report_tablet
 */
final class device_test extends advanced_testcase {
    /** @var string Chrome on an Android tablet asking for the desktop site. */
    private const ANDROID_DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';

    /** @var string A Windows computer. */
    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';

    /**
     * Use a user agent for this request.
     *
     * @param string $useragent User agent.
     */
    private function use_agent(string $useragent): void {
        \core_useragent::instance(true, $useragent);
    }

    /**
     * A student enrolled in a course with a quiz; returns the quiz context.
     *
     * @return \context_module
     */
    private function student_with_quiz(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        return \context_module::instance($quiz->cmid);
    }

    protected function tearDown(): void {
        \core_useragent::instance(true, '');
        parent::tearDown();
    }

    /**
     * Only a user agent a tablet in desktop mode sends is accepted: never Windows or ChromeOS.
     */
    public function test_desktop_mode_tablet_agents(): void {
        $this->assertTrue(device::desktop_mode_tablet_agent(self::ANDROID_DESKTOP));
        $this->assertTrue(device::desktop_mode_tablet_agent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15'));
        $this->assertFalse(device::desktop_mode_tablet_agent(self::WINDOWS));
        $this->assertFalse(device::desktop_mode_tablet_agent('Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) Chrome/129.0'));
    }

    /**
     * In bypass mode a tablet in desktop mode is taken for a tablet once reported, and it is logged.
     */
    public function test_report_in_bypass_mode(): void {
        $this->resetAfterTest();
        set_config('mobilescreensharemode', 'bypass', 'quizaccess_proctoring');
        $context = $this->student_with_quiz();
        $this->use_agent(self::ANDROID_DESKTOP);
        $this->assertFalse(device::is_phone_or_tablet());

        $sink = $this->redirectEvents();
        $result = report_tablet::execute($context->instanceid);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($result['accepted']);
        $this->assertTrue(device::is_phone_or_tablet());
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\quizaccess_proctoring\event\tablet_reported::class, $events[0]);

        // The report belongs to that browser: another user agent in the session is not a tablet.
        $this->use_agent(self::WINDOWS);
        $this->assertFalse(device::is_phone_or_tablet());
    }

    /**
     * A Windows computer, or any device while the site requires or blocks, cannot report itself.
     */
    public function test_report_refused(): void {
        $this->resetAfterTest();
        set_config('mobilescreensharemode', 'bypass', 'quizaccess_proctoring');
        $context = $this->student_with_quiz();

        $this->use_agent(self::WINDOWS);
        $this->assertFalse(report_tablet::execute($context->instanceid)['accepted']);
        $this->assertFalse(device::is_phone_or_tablet());

        foreach (['require', 'block'] as $mode) {
            set_config('mobilescreensharemode', $mode, 'quizaccess_proctoring');
            $this->use_agent(self::ANDROID_DESKTOP);
            $this->assertFalse(report_tablet::execute($context->instanceid)['accepted']);
            $this->assertFalse(device::is_phone_or_tablet());
        }
    }

    /**
     * Phones and tablets that say so are detected without any report.
     */
    public function test_detected_phone(): void {
        $this->use_agent('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36');
        $this->assertTrue(device::is_phone_or_tablet());
    }

    /**
     * User agents and the browser family each belongs to.
     *
     * @return array
     */
    public static function browser_provider(): array {
        return [
            'chrome' => [self::WINDOWS, device::BROWSER_CHROME],
            'edge' => [self::WINDOWS . ' Edg/129.0.0.0', device::BROWSER_EDGE],
            'edge android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/130.0.0.0 '
                . 'Mobile Safari/537.36 EdgA/130.0.0.0', device::BROWSER_EDGE],
            'firefox' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0',
                device::BROWSER_FIREFOX],
            'safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/18.0 Safari/605.1.15', device::BROWSER_SAFARI],
            'chrome on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) CriOS/130.0.6723.37 Mobile/15E148 Safari/604.1', device::BROWSER_SAFARI],
            'opera' => [self::WINDOWS . ' OPR/114.0.0.0', device::BROWSER_OTHER],
            'samsung' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 SamsungBrowser/26.0 '
                . 'Chrome/122.0.0.0 Mobile Safari/537.36', device::BROWSER_OTHER],
            'empty' => ['', device::BROWSER_OTHER],
        ];
    }

    /**
     * Each user agent is put in its browser family.
     *
     * @dataProvider browser_provider
     * @param string $useragent User agent.
     * @param string $expected Browser family.
     */
    public function test_browser(string $useragent, string $expected): void {
        $this->assertSame($expected, device::browser($useragent));
    }

    /**
     * Each browser policy allows exactly its browsers; an unknown policy allows all.
     */
    public function test_browser_allowed(): void {
        $this->assertTrue(device::browser_allowed(device::BROWSERS_CHROME_EDGE, device::BROWSER_EDGE));
        $this->assertFalse(device::browser_allowed(device::BROWSERS_CHROME_EDGE, device::BROWSER_FIREFOX));
        $this->assertTrue(device::browser_allowed(device::BROWSERS_NO_SAFARI, device::BROWSER_FIREFOX));
        $this->assertFalse(device::browser_allowed(device::BROWSERS_NO_SAFARI, device::BROWSER_SAFARI));
        $this->assertFalse(device::browser_allowed(device::BROWSERS_NO_SAFARI, device::BROWSER_OTHER));
        $this->assertTrue(device::browser_allowed(device::BROWSERS_ANY, device::BROWSER_SAFARI));
        $this->assertTrue(device::browser_allowed('', device::BROWSER_OTHER));
    }
}
