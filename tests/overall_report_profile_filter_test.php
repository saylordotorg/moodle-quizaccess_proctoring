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
 * Tests for the proctoring profile filter on the site-wide attempts report.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\overall_report;
use quizaccess_proctoring\local\profile;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * The Attempts report can list only the attempts on quizzes that use one proctoring profile,
 * or only those on no profile, and names each row's profile.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\overall_report
 */
final class overall_report_profile_filter_test extends advanced_testcase {
    /** @var \stdClass Course. */
    private $course;

    /** @var int Unique id for the next generated attempt. */
    private $uniqueid = 8000;

    /**
     * No holds, so the rows are only ever flagged or clean.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('riskreviewenabled', 0, 'quizaccess_proctoring');
        $this->course = $this->getDataGenerator()->create_course();
        profile::reset_cache();
    }

    /**
     * A proctored quiz, with its course module id.
     *
     * @param int $profileid Profile id, 0 for the site settings.
     * @return \stdClass Quiz with cmid.
     */
    private function quiz(int $profileid = 0): \stdClass {
        return $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'proctoringrequired' => 1,
            'profileid' => $profileid,
        ]);
    }

    /**
     * One finished attempt with a webcam capture and a tab switch.
     *
     * @param \stdClass $quiz Quiz with cmid.
     * @return int The student's user id.
     */
    private function attempt(\stdClass $quiz): int {
        global $DB;

        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $start = time() - 7200;
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => $this->uniqueid++,
            'layout' => '1,0',
            'state' => 'finished',
            'timestart' => $start,
            'timefinish' => $start + 1800,
            'timemodified' => $start + 1800,
            'sumgrades' => 1,
        ]);
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $quiz->cmid,
            'userid' => $user->id,
            'webcampicture' => 'data:image/png;base64,AAAA',
            'status' => $attemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => $start + 60,
        ]);
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $this->course->id,
            'quizid' => $quiz->cmid,
            'userid' => $user->id,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'eventtype' => 'tab_hidden',
            'eventdetail' => '',
            'screenshoturl' => '',
            'timemodified' => $start + 120,
        ]);
        return (int)$user->id;
    }

    /**
     * The report over the course, optionally for one profile.
     *
     * @param int $profilefilter Profile id, 0 for no profile, -1 for all.
     * @return array Report data.
     */
    private function report(int $profilefilter = -1): array {
        return overall_report::build(
            (int)$this->course->id,
            'all',
            0,
            'recent',
            0,
            'all',
            '',
            '',
            '',
            '',
            0,
            -1,
            null,
            false,
            $profilefilter
        );
    }

    /**
     * The students listed on a report, lowest id first.
     *
     * @param array $data Report data.
     * @return int[] User ids.
     */
    private static function students(array $data): array {
        $ids = array_map(fn($row) => (int)$row['userid'], $data['rows']);
        sort($ids);
        return $ids;
    }

    /**
     * Each profile lists its own quizzes' attempts, "no profile" lists the rest, and the
     * counters above the list follow the filter.
     */
    public function test_filter_lists_one_profiles_attempts(): void {
        $ace = profile::save(0, 'ACE credit', '', ['riskreviewthreshold' => '40']);
        $certificate = profile::save(0, 'Certificate', '', ['riskreviewthreshold' => '80']);
        $onace = $this->attempt($this->quiz($ace));
        $oncertificate = $this->attempt($this->quiz($certificate));
        $onsite = $this->attempt($this->quiz());

        $all = [$onace, $oncertificate, $onsite];
        sort($all);
        $this->assertSame($all, self::students($this->report()));

        $data = $this->report($ace);
        $this->assertSame([$onace], self::students($data));
        $this->assertSame(1, $data['summary']['totalattempts']);

        $this->assertSame([$oncertificate], self::students($this->report($certificate)));
        $this->assertSame([$onsite], self::students($this->report(0)));

        // A profile nothing uses, or one that does not exist, lists nothing.
        $unused = profile::save(0, 'Degree', '', []);
        $this->assertSame([], self::students($this->report($unused)));
        $this->assertSame([], self::students($this->report($unused + 1000)));
    }

    /**
     * A quiz whose profile was deleted is back on the site settings, so "no profile" lists it.
     */
    public function test_deleted_profile_returns_attempts_to_no_profile(): void {
        $ace = profile::save(0, 'ACE credit', '', []);
        $student = $this->attempt($this->quiz($ace));
        $this->assertSame([], self::students($this->report(0)));

        profile::delete($ace);
        $this->assertSame([$student], self::students($this->report(0)));
    }

    /**
     * A row names its quiz's profile; a row on the site settings names none.
     */
    public function test_rows_name_their_profile(): void {
        $ace = profile::save(0, 'ACE credit', '', []);
        $onace = $this->attempt($this->quiz($ace));
        $onsite = $this->attempt($this->quiz());

        $names = [];
        $labels = [];
        foreach ($this->report()['rows'] as $row) {
            $names[(int)$row['userid']] = $row['profilename'];
            $labels[(int)$row['userid']] = $row['profilelabel'];
        }
        $this->assertSame('ACE credit', $names[$onace]);
        $this->assertStringContainsString('ACE credit', $labels[$onace]);
        $this->assertSame('', $names[$onsite]);
        $this->assertSame('', $labels[$onsite]);
    }

    /**
     * The filter's options: all, each profile by name, then no profile; none while the site
     * has no profiles, so the page leaves the filter out.
     */
    public function test_profile_options(): void {
        $this->assertSame([], overall_report::profile_options(-1));

        $ace = profile::save(0, 'ACE credit', '', []);
        $certificate = profile::save(0, 'Certificate', '', []);

        $options = overall_report::profile_options($certificate);
        $this->assertSame([-1, $ace, $certificate, 0], array_column($options, 'value'));
        $this->assertSame([false, false, true, false], array_column($options, 'selected'));
        $this->assertSame('ACE credit', $options[1]['label']);

        $this->assertSame([true, false, false, false], array_column(overall_report::profile_options(-1), 'selected'));
        $this->assertSame([false, false, false, true], array_column(overall_report::profile_options(0), 'selected'));
    }
}
