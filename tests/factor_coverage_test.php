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
 * Tests that a risk factor only reads as passed when its check ran (CPIT-467).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\factor_coverage;
use quizaccess_proctoring\local\monitoring_coverage;
use quizaccess_proctoring\local\risk_calculator;

/**
 * Factor coverage tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\factor_coverage
 * @covers \quizaccess_proctoring\local\monitoring_coverage::record_monitors
 * @covers \quizaccess_proctoring\local\monitoring_coverage::describe_browser
 */
final class factor_coverage_test extends advanced_testcase {

    /** A Chrome-on-Windows user agent. */
    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    /**
     * Factors nothing detects are never passed, whatever else is known about the attempt.
     */
    public function test_factors_without_a_detector_are_never_passed(): void {
        $monitors = ['activity' => true, 'clipboard' => true, 'screen' => true, 'multimonitor' => true, 'phone' => true];
        // The only one, audio, was removed rather than built (CPIT-486).
        $this->assertSame([], factor_coverage::FACTORS_WITHOUT_DETECTOR);
        foreach (factor_coverage::FACTORS_WITHOUT_DETECTOR as $factorkey) {
            $this->assertSame(
                factor_coverage::REASON_NOT_BUILT,
                factor_coverage::reason($factorkey, $monitors, true, false, 5, 5)
            );
        }
    }

    /**
     * Browser-side factors need their monitor on, the browser to have reported in, and browser support.
     */
    public function test_browser_factors_need_their_monitor_and_browser_data(): void {
        $all = ['activity' => true, 'clipboard' => true, 'screen' => true, 'multimonitor' => true, 'phone' => true,
            'multiplefaces' => true];
        $started = ['phonedetected' => true, 'multiplefaces' => true];

        // Everything on and the browser reported in: passed.
        foreach (['aitool', 'aitoolscreenshot', 'clipboard', 'tabactivity', 'f12', 'shortcut', 'screenshare',
                'multimonitor', 'phonedetected', 'multiplefaces'] as $factorkey) {
            $this->assertNull(factor_coverage::reason($factorkey, $all, true, false, 1, 1, $started), $factorkey);
        }

        // A model-based detector switched on is not enough: the browser has to say it started.
        foreach (['phonedetected', 'multiplefaces'] as $factorkey) {
            $this->assertSame(
                factor_coverage::REASON_DETECTOR_FAILED,
                factor_coverage::reason($factorkey, $all, true, false, 1, 1, [$factorkey => false] + $started)
            );
        }
        // Attempts recorded before multiple-face detection existed have no such monitor: switched off.
        $this->assertSame(
            factor_coverage::REASON_SETTING_OFF,
            factor_coverage::reason('multiplefaces', array_diff_key($all, ['multiplefaces' => 1]), true, false, 1, 1, $started)
        );

        // The attempt predates monitor recording.
        $this->assertSame(factor_coverage::REASON_NOT_RECORDED, factor_coverage::reason('tabactivity', null, true, false, 1, 1));

        // A monitor that was off.
        $noscreen = ['screen' => false] + $all;
        $this->assertSame(factor_coverage::REASON_SETTING_OFF, factor_coverage::reason('screenshare', $noscreen, true, false, 1, 1));
        $this->assertSame(
            factor_coverage::REASON_SETTING_OFF,
            factor_coverage::reason('aitoolscreenshot', $noscreen, true, false, 1, 1)
        );
        $this->assertNull(factor_coverage::reason('aitool', $noscreen, true, false, 1, 1));

        // Activity monitoring off still leaves clipboard checks when clipboard blocking logs them.
        $noactivity = ['activity' => false] + $all;
        $this->assertSame(factor_coverage::REASON_SETTING_OFF, factor_coverage::reason('f12', $noactivity, true, false, 1, 1));
        $this->assertNull(factor_coverage::reason('clipboard', $noactivity, true, false, 1, 1));

        // The proctoring script never reported in.
        $this->assertSame(factor_coverage::REASON_NO_BROWSER_DATA, factor_coverage::reason('tabactivity', $all, false, false, 1, 1));

        // A browser that cannot count monitors.
        $this->assertSame(
            factor_coverage::REASON_BROWSER_UNSUPPORTED,
            factor_coverage::reason('multimonitor', $all, true, true, 1, 1)
        );
        $this->assertNull(factor_coverage::reason('tabactivity', $all, true, true, 1, 1));
    }

    /**
     * Face factors are judged by whether any capture was actually compared or checked.
     */
    public function test_face_factors_need_a_check_that_ran(): void {
        $this->assertSame(factor_coverage::REASON_NOT_COMPARED, factor_coverage::reason('facemismatch', null, true, false, 0, 4));
        $this->assertNull(factor_coverage::reason('facemismatch', null, true, false, 1, 1));
        $this->assertSame(factor_coverage::REASON_NO_FACE_CHECK, factor_coverage::reason('noface', null, true, false, 0, 0));
        $this->assertNull(factor_coverage::reason('noface', null, true, false, 0, 1));
        $this->assertSame(factor_coverage::REASON_NOT_RECORDED, factor_coverage::reason('facemismatch', null, true, false, -1, -1));
        $this->assertSame(factor_coverage::REASON_NOT_RECORDED, factor_coverage::reason('noface', null, true, false, -1, -1));

        // Server-side checks always ran.
        $this->assertNull(factor_coverage::reason('webcammissing', null, false, false, -1, -1));
        $this->assertNull(factor_coverage::reason('speed', null, false, false, -1, -1));
    }

    /**
     * The attempt page records its monitors once, and the report reads them back with the attempt's evidence.
     */
    public function test_recorded_monitors_drive_the_attempt_coverage(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, true, false, 30);

        // Nothing recorded yet: browser-side factors are unknown, never passed.
        $coverage = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid);
        $this->assertSame(factor_coverage::REASON_NOT_RECORDED, $coverage['reasons']['tabactivity']);
        $this->assertSame(factor_coverage::REASON_NOT_COMPARED, $coverage['reasons']['facemismatch']);
        $this->assertSame('', $coverage['browser']);

        $monitors = ['activity' => true, 'clipboard' => true, 'screen' => false, 'multimonitor' => true, 'phone' => false];
        monitoring_coverage::record_monitors($course->id, $quiz->cmid, $student->id, $attemptid, $monitors, self::CHROME_WINDOWS);
        // A later page load with different settings does not rewrite what the attempt started with.
        monitoring_coverage::record_monitors(
            $course->id,
            $quiz->cmid,
            $student->id,
            $attemptid,
            ['activity' => false] + $monitors,
            'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'
        );
        $policy = monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, true, false, 30);
        $this->assertSame($monitors, $policy['monitors']);
        $this->assertSame(30, $policy['interval']);

        // Monitors recorded, but the browser has not sent anything yet.
        $coverage = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid);
        $this->assertSame(factor_coverage::REASON_NO_BROWSER_DATA, $coverage['reasons']['tabactivity']);
        $this->assertSame('Chrome 129', $coverage['browser']);
        $this->assertSame('Windows', $coverage['os']);

        // A webcam capture that was compared with the reference photo, and an "unsupported" notice.
        $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $student->id,
            'webcampicture' => 'https://example.com/capture.png', 'status' => $attemptid,
            'awsflag' => 2, 'awsscore' => 95, 'timemodified' => time(),
        ]);
        $this->event($course->id, $quiz->cmid, $student->id, $attemptid, 'monitor_detection_unavailable');

        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        foreach (['facemismatch', 'noface', 'webcammissing', 'speed', 'aitool', 'clipboard', 'tabactivity', 'f12',
                'shortcut'] as $factorkey) {
            $this->assertArrayNotHasKey($factorkey, $reasons, $factorkey . ' ran and should read as passed');
        }
        $this->assertSame(factor_coverage::REASON_SETTING_OFF, $reasons['multiplefaces']);
        // The audio factor was removed (CPIT-486), not reported as unmonitored.
        $this->assertArrayNotHasKey('audio', $reasons);
        $this->assertSame(factor_coverage::REASON_SETTING_OFF, $reasons['screenshare']);
        $this->assertSame(factor_coverage::REASON_SETTING_OFF, $reasons['aitoolscreenshot']);
        $this->assertSame(factor_coverage::REASON_SETTING_OFF, $reasons['phonedetected']);
        $this->assertSame(factor_coverage::REASON_BROWSER_UNSUPPORTED, $reasons['multimonitor']);

        // Phone detection that was on but never started is not a pass either; once the browser
        // reports it started, it is.
        $phoneon = ['phone' => true] + $monitors;
        $DB->delete_records('quizaccess_proctoring_events', ['attemptid' => $attemptid, 'eventtype' => 'monitoring_started']);
        monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, true, false, 30);
        monitoring_coverage::record_monitors($course->id, $quiz->cmid, $student->id, $attemptid, $phoneon, self::CHROME_WINDOWS);
        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        $this->assertSame(factor_coverage::REASON_DETECTOR_FAILED, $reasons['phonedetected']);
        $this->event($course->id, $quiz->cmid, $student->id, $attemptid, 'phone_detection_started');
        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        $this->assertArrayNotHasKey('phonedetected', $reasons);

        // Detection that later worked shows the check did run.
        $this->event($course->id, $quiz->cmid, $student->id, $attemptid, 'multiple_monitors_detected');
        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        $this->assertArrayNotHasKey('multimonitor', $reasons);

        // Every factor the calculator knows has an answer one way or the other.
        $this->assertEmpty(array_diff(array_keys($reasons), array_keys(risk_calculator::FACTOR_DEFAULTS)));
    }

    /**
     * Another student's evidence never vouches for this attempt.
     */
    public function test_coverage_is_scoped_to_the_attempt_owner(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        monitoring_coverage::start_attempt($course->id, $quiz->cmid, $student->id, $attemptid, true, false, 30);
        monitoring_coverage::record_monitors(
            $course->id,
            $quiz->cmid,
            $student->id,
            $attemptid,
            ['activity' => true, 'clipboard' => true, 'screen' => true, 'multimonitor' => true, 'phone' => false],
            self::CHROME_WINDOWS
        );
        $other = $this->getDataGenerator()->create_user();
        $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $other->id,
            'webcampicture' => 'https://example.com/other.png', 'status' => $attemptid,
            'awsflag' => 2, 'awsscore' => 95, 'timemodified' => time(),
        ]);

        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        $this->assertSame(factor_coverage::REASON_NO_BROWSER_DATA, $reasons['tabactivity']);
        $this->assertSame(factor_coverage::REASON_NOT_COMPARED, $reasons['facemismatch']);
    }

    /**
     * A reference photo's face check never counts as a face check of an attempt.
     *
     * Reference-photo face images point at user_images rows, whose ids can equal an attempt log's id.
     */
    public function test_reference_photo_face_images_do_not_count_as_attempt_checks(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, $student, $attemptid] = $this->fixture();
        $logid = $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $student->id,
            'webcampicture' => 'https://example.com/capture.png', 'status' => $attemptid, 'timemodified' => time(),
        ]);
        $DB->insert_record('quizaccess_proctoring_face_images', (object)[
            'parent_type' => 'admin_image', 'parentid' => $logid, 'faceimage' => 'https://example.com/ref.png',
            'facefound' => 1, 'timemodified' => time(),
        ]);
        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        $this->assertSame(factor_coverage::REASON_NO_FACE_CHECK, $reasons['noface']);

        $DB->insert_record('quizaccess_proctoring_face_images', (object)[
            'parent_type' => 'camshot_image', 'parentid' => $logid, 'faceimage' => 'https://example.com/face.png',
            'facefound' => 1, 'timemodified' => time(),
        ]);
        $reasons = factor_coverage::for_attempt($course->id, $quiz->cmid, $student->id, $attemptid)['reasons'];
        $this->assertArrayNotHasKey('noface', $reasons);
    }

    /**
     * Only a browser name, major version and OS are kept from the user agent.
     */
    public function test_describe_browser(): void {
        $cases = [
            self::CHROME_WINDOWS => ['Chrome 129', 'Windows'],
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 '
                . 'Safari/537.36 Edg/129.0.2792.65' => ['Edge 129', 'Windows'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 '
                . 'Safari/605.1.15' => ['Safari 17', 'macOS'],
            'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0' => ['Firefox 131', 'Linux'],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/17.6 Mobile/15E148 Safari/604.1' => ['Safari 17', 'iOS'],
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 '
                . 'Mobile Safari/537.36' => ['Chrome 129', 'Android'],
            'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 '
                . 'Safari/537.36' => ['Chrome 129', 'ChromeOS'],
            '' => ['', ''],
        ];
        foreach ($cases as $useragent => [$browser, $os]) {
            $this->assertSame(['browser' => $browser, 'os' => $os], monitoring_coverage::describe_browser($useragent), $useragent);
        }
    }

    /**
     * Insert one browser event for the attempt.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @param string $eventtype Event type.
     */
    private function event(int $courseid, int $cmid, int $userid, int $attemptid, string $eventtype): void {
        global $DB;
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid,
            'eventtype' => $eventtype, 'eventdetail' => '{}', 'timemodified' => time(),
        ]);
    }

    /**
     * A proctored quiz with one in-progress attempt.
     *
     * @return array [course, quiz, student, attempt id]
     */
    private function fixture(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'proctoringrequired' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', \context_module::instance($quiz->cmid));
        $usage->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($usage);
        $attemptid = $DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id, 'userid' => $student->id, 'attempt' => 1, 'uniqueid' => $usage->get_id(),
            'layout' => '', 'state' => 'inprogress', 'timestart' => time() - 300, 'timemodified' => time(),
        ]);
        return [$course, $quiz, $student, (int)$attemptid];
    }
}
