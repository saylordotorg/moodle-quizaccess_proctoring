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
 * Tests for the rules that list an attempt as Flagged.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\form\profile_form;
use quizaccess_proctoring\local\attempt_review;
use quizaccess_proctoring\local\flag_rules;
use quizaccess_proctoring\local\overall_report;
use quizaccess_proctoring\local\profile;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * An unheld attempt is flagged by any detected event until flag rules are switched on; then only
 * a factor reaching its count, or the risk score reaching the flag score, flags it. A proctoring
 * profile can set all of it for its own quizzes.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\flag_rules
 * @covers \quizaccess_proctoring\local\overall_report
 * @covers \quizaccess_proctoring\local\profile
 * @covers \quizaccess_proctoring\form\profile_form
 */
final class flag_rules_test extends advanced_testcase {
    /** @var \stdClass Course. */
    private $course;

    /** @var int Unique id for the next generated attempt. */
    private $uniqueid = 7000;

    /**
     * Shipped scoring on the site, no score cap, no speed factor and no holds, so every attempt
     * here is either flagged or clean.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        set_config('speedreviewenabled', 0, 'quizaccess_proctoring');
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
     * One finished attempt with a number of tab switches, with or without a webcam capture.
     *
     * @param \stdClass $quiz Quiz with cmid.
     * @param int $tabswitches How many tab switches to record.
     * @param bool $webcam Whether the attempt has a webcam capture.
     * @return \stdClass The attempt: userid, attemptid, reportid, cmid.
     */
    private function attempt(\stdClass $quiz, int $tabswitches, bool $webcam = true): \stdClass {
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
        // Without a webcam the attempt still has its report row, holding no picture.
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $this->course->id,
            'quizid' => $quiz->cmid,
            'userid' => $user->id,
            'webcampicture' => $webcam ? 'data:image/png;base64,AAAA' : '',
            'status' => $attemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => $start + 60,
        ]);
        for ($i = 0; $i < $tabswitches; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $this->course->id,
                'quizid' => $quiz->cmid,
                'userid' => $user->id,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
                'eventtype' => 'tab_hidden',
                'eventdetail' => '',
                'screenshoturl' => '',
                'timemodified' => $start + 120 + $i,
            ]);
        }
        return (object)[
            'userid' => (int)$user->id,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'cmid' => (int)$quiz->cmid,
        ];
    }

    /**
     * The Attempts report over every attempt in the course.
     *
     * @return array Report data.
     */
    private function report(): array {
        return overall_report::build((int)$this->course->id, 'all', 0, 'recent', 0, 'all');
    }

    /**
     * One attempt's row on the report.
     *
     * @param array $data Report data.
     * @param \stdClass $attempt Attempt from {@see self::attempt()}.
     * @return array Row data.
     */
    private function row(array $data, \stdClass $attempt): array {
        foreach ($data['rows'] as $row) {
            if ((int)$row['userid'] === $attempt->userid) {
                return $row;
            }
        }
        $this->fail('The attempt is missing from the report.');
    }

    /**
     * A risk result holding one factor, as the risk calculator shapes it.
     *
     * @param string $key Factor key.
     * @param int $count Evidence count.
     * @param int $score Attempt risk score.
     * @param bool $falsepositive Whether a reviewer marked the factor a false positive.
     * @return array Risk result.
     */
    private static function risk(string $key, int $count, int $score = 0, bool $falsepositive = false): array {
        $factor = ['key' => $key, 'label' => 'Factor ' . $key, 'count' => $count, 'points' => 0];
        if ($falsepositive) {
            $factor['falsepositive'] = true;
        }
        return ['score' => $score, 'factors' => [$factor]];
    }

    /**
     * Nothing changes on upgrade: any event flags, and the shipped rules are the proposed ones.
     */
    public function test_shipped_defaults(): void {
        $this->assertSame(flag_rules::MODE_ANY, flag_rules::mode());
        $this->assertFalse(flag_rules::used_anywhere());
        $this->assertSame(1, flag_rules::threshold('facemismatch'));
        $this->assertSame(1, flag_rules::threshold('phonedetected'));
        $this->assertSame(1, flag_rules::threshold('automation'));
        $this->assertSame(2, flag_rules::threshold('screenshare'));
        $this->assertSame(3, flag_rules::threshold('noface'));
        $this->assertSame(5, flag_rules::threshold('tabactivity'));
        $this->assertSame(0, flag_rules::threshold('clipboard'));
        $this->assertSame(60, flag_rules::min_score());
    }

    /**
     * A factor flags at its count, never at 0, and never once marked a false positive; the
     * score flags at the flag score unless that rule is off.
     */
    public function test_reasons(): void {
        $this->assertSame([], flag_rules::reasons(self::risk('noface', 2)));

        $reasons = flag_rules::reasons(self::risk('noface', 3));
        $this->assertCount(1, $reasons);
        $this->assertSame('noface', $reasons[0]['key']);
        $this->assertSame(3, $reasons[0]['count']);
        $this->assertSame(3, $reasons[0]['threshold']);
        $this->assertSame('Factor noface (3)', flag_rules::describe($reasons));

        $this->assertSame([], flag_rules::reasons(self::risk('noface', 9, 0, true)));
        $this->assertSame([], flag_rules::reasons(self::risk('clipboard', 40)));

        set_config(flag_rules::factor_setting('noface'), 0, 'quizaccess_proctoring');
        $this->assertSame([], flag_rules::reasons(self::risk('noface', 9)));

        $reasons = flag_rules::reasons(self::risk('clipboard', 1, 60));
        $this->assertSame(['score'], array_column($reasons, 'key'));
        $this->assertSame(60, $reasons[0]['count']);
        $this->assertSame([], flag_rules::reasons(self::risk('clipboard', 1, 59)));

        set_config(flag_rules::SCORE_SETTING, 0, 'quizaccess_proctoring');
        $this->assertSame([], flag_rules::reasons(self::risk('clipboard', 1, 500)));
    }

    /**
     * Until the rules are switched on, one tab switch flags the attempt and no reason is shown.
     */
    public function test_any_event_flags_by_default(): void {
        $attempt = $this->attempt($this->quiz(), 1);

        $data = $this->report();
        $row = $this->row($data, $attempt);
        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertSame('', $row['flaglabel']);
        $this->assertSame(1, $data['summary']['flagged']);
    }

    /**
     * On rules, four tab switches are below the count of five and the attempt is clean; five
     * flag it, and the row says which rule it met.
     */
    public function test_rules_flag_at_the_count(): void {
        set_config(flag_rules::MODE_SETTING, flag_rules::MODE_RULES, 'quizaccess_proctoring');
        $this->assertTrue(flag_rules::used_anywhere());
        $quiz = $this->quiz();
        $below = $this->attempt($quiz, 4);
        $at = $this->attempt($quiz, 5);

        $data = $this->report();
        $this->assertSame('clean', $this->row($data, $below)['reviewstate']);
        $this->assertSame('', $this->row($data, $below)['flaglabel']);

        $row = $this->row($data, $at);
        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertStringContainsString(get_string('riskscore:tabactivity', 'quizaccess_proctoring') . ' (5)', $row['flaglabel']);

        $this->assertSame(1, $data['summary']['flagged']);
        $this->assertSame(1, $data['summary']['clean']);
    }

    /**
     * The flag score flags an attempt none of whose factors reached its count.
     */
    public function test_rules_flag_at_the_score(): void {
        set_config(flag_rules::MODE_SETTING, flag_rules::MODE_RULES, 'quizaccess_proctoring');
        // Four tab switches score 20 at the shipped 5 points each.
        set_config(flag_rules::SCORE_SETTING, 20, 'quizaccess_proctoring');
        $attempt = $this->attempt($this->quiz(), 4);

        $row = $this->row($this->report(), $attempt);
        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertStringContainsString(get_string('riskscore:attemptscore', 'quizaccess_proctoring') . ' 20', $row['flaglabel']);
    }

    /**
     * An attempt with no webcam capture is flagged on rules, even with a single event.
     */
    public function test_rules_flag_a_missing_webcam(): void {
        set_config(flag_rules::MODE_SETTING, flag_rules::MODE_RULES, 'quizaccess_proctoring');
        $attempt = $this->attempt($this->quiz(), 1, false);

        $row = $this->row($this->report(), $attempt);
        $this->assertSame('flagged', $row['reviewstate']);
        $this->assertStringContainsString(get_string('riskscore:webcammissing', 'quizaccess_proctoring'), $row['flaglabel']);
    }

    /**
     * A profile puts its own quizzes on rules, with its own counts, while the site stays on any
     * detected event; another profile takes its quizzes off the site's rules.
     */
    public function test_profile_sets_rules_for_its_quizzes(): void {
        $ace = profile::save(0, 'ACE credit', '', [
            flag_rules::MODE_SETTING => flag_rules::MODE_RULES,
            flag_rules::factor_setting('tabactivity') => '2',
        ]);
        $this->assertTrue(flag_rules::used_anywhere());
        $acequiz = $this->quiz($ace);
        $sitequiz = $this->quiz();
        $this->assertSame(flag_rules::MODE_RULES, flag_rules::mode((int)$acequiz->id));
        $this->assertSame(2, flag_rules::threshold('tabactivity', (int)$acequiz->id));
        $this->assertSame(flag_rules::MODE_ANY, flag_rules::mode((int)$sitequiz->id));

        $aceone = $this->attempt($acequiz, 1);
        $acetwo = $this->attempt($acequiz, 2);
        $siteone = $this->attempt($sitequiz, 1);

        $data = $this->report();
        $this->assertSame('clean', $this->row($data, $aceone)['reviewstate']);
        $this->assertSame('flagged', $this->row($data, $acetwo)['reviewstate']);
        $this->assertSame('flagged', $this->row($data, $siteone)['reviewstate']);
        $this->assertSame('', $this->row($data, $siteone)['flaglabel']);

        // The other way round: the site on rules, a profile back on any detected event.
        set_config(flag_rules::MODE_SETTING, flag_rules::MODE_RULES, 'quizaccess_proctoring');
        $certificate = profile::save(0, 'Certificate', '', [flag_rules::MODE_SETTING => flag_rules::MODE_ANY]);
        $certone = $this->attempt($this->quiz($certificate), 1);

        $data = $this->report();
        $this->assertSame('flagged', $this->row($data, $certone)['reviewstate']);
        $this->assertSame('clean', $this->row($data, $siteone)['reviewstate']);
    }

    /**
     * A sign-off recorded while the attempt had signals still stands once the rules stop
     * listing it: the row stays Reviewed rather than dropping to Clean.
     */
    public function test_signed_off_attempt_stays_reviewed(): void {
        $quiz = $this->quiz();
        $attempt = $this->attempt($quiz, 1);
        attempt_review::record(
            (int)$this->course->id,
            $attempt->cmid,
            $attempt->userid,
            $attempt->attemptid,
            $attempt->reportid,
            (int)get_admin()->id
        );
        $this->assertSame('reviewed', $this->row($this->report(), $attempt)['reviewstate']);

        set_config(flag_rules::MODE_SETTING, flag_rules::MODE_RULES, 'quizaccess_proctoring');
        $this->assertSame('reviewed', $this->row($this->report(), $attempt)['reviewstate']);
    }

    /**
     * The same holds for an attempt a rule flagged without a single detected event, such as one
     * with no webcam capture: signed off, it stays Reviewed when that rule is switched off.
     */
    public function test_signed_off_attempt_without_events_stays_reviewed(): void {
        set_config(flag_rules::MODE_SETTING, flag_rules::MODE_RULES, 'quizaccess_proctoring');
        $attempt = $this->attempt($this->quiz(), 0, false);
        $this->assertSame('flagged', $this->row($this->report(), $attempt)['reviewstate']);

        attempt_review::record(
            (int)$this->course->id,
            $attempt->cmid,
            $attempt->userid,
            $attempt->attemptid,
            $attempt->reportid,
            (int)get_admin()->id
        );
        $this->assertSame('reviewed', $this->row($this->report(), $attempt)['reviewstate']);

        set_config(flag_rules::factor_setting('webcammissing'), 0, 'quizaccess_proctoring');
        $this->assertSame('reviewed', $this->row($this->report(), $attempt)['reviewstate']);
    }

    /**
     * A profile keeps flag settings within their ranges, names them in its summary, and its
     * form refuses a count out of range.
     */
    public function test_profile_accepts_flag_settings(): void {
        $this->assertSame(
            [
                flag_rules::MODE_SETTING => flag_rules::MODE_RULES,
                flag_rules::factor_setting('noface') => '0',
                flag_rules::SCORE_SETTING => '45',
            ],
            profile::clean([
                flag_rules::MODE_SETTING => flag_rules::MODE_RULES,
                flag_rules::factor_setting('noface') => '0',
                flag_rules::factor_setting('tabactivity') => '101',
                flag_rules::factor_setting('clipboard') => '',
                flag_rules::SCORE_SETTING => '45',
            ])
        );

        $lines = profile::describe([flag_rules::factor_setting('noface') => '2']);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString(get_string('riskscore:noface', 'quizaccess_proctoring'), $lines[0]);
        $this->assertStringContainsString('2', $lines[0]);

        $form = new profile_form();
        $base = ['id' => 0, 'name' => 'ACE credit'];
        $errors = $form->validation($base + [flag_rules::factor_setting('tabactivity') => '101'], []);
        $this->assertArrayHasKey(flag_rules::factor_setting('tabactivity'), $errors);
        $errors = $form->validation($base + [
            flag_rules::MODE_SETTING => flag_rules::MODE_RULES,
            flag_rules::factor_setting('tabactivity') => '3',
            flag_rules::SCORE_SETTING => '0',
        ], []);
        $this->assertSame([], $errors);
    }
}
