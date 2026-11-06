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
 * Tests for risk scoring through proctoring profiles.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\form\profile_form;
use quizaccess_proctoring\local\overall_report;
use quizaccess_proctoring\local\profile;
use quizaccess_proctoring\local\risk_calculator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * A Certificate profile scores the same evidence differently from a Degree or ACE quiz on the
 * site settings: its own factor points, caps and switches, and its own risk levels.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\profile
 * @covers \quizaccess_proctoring\local\risk_calculator
 * @covers \quizaccess_proctoring\form\profile_form
 * @covers \quizaccess_proctoring\local\overall_report
 */
final class profile_scoring_test extends advanced_testcase {
    /** @var \stdClass Course. */
    private $course;

    /** @var int Unique id for the next generated attempt. */
    private $uniqueid = 5000;

    /**
     * Shipped scoring defaults on the site, no score cap.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        set_config('speedreviewenabled', 0, 'quizaccess_proctoring');
        $this->course = $this->getDataGenerator()->create_course();
        profile::reset_cache();
    }

    /**
     * A Certificate profile: tab activity worth 2 a time up to 6, clipboard not scored, and
     * risk levels that start low.
     *
     * @return int Profile id.
     */
    private function certificate_profile(): int {
        return profile::save(0, 'Certificate', 'Certificate final exams', [
            'riskfactor_tabactivity_points' => '2',
            'riskfactor_tabactivity_cap' => '6',
            'riskfactor_clipboard_enabled' => '0',
            'risklevelmoderate' => '3',
            'risklevelhigh' => '5',
            'risklevelcritical' => '50',
        ]);
    }

    /**
     * A proctored quiz, with its course module id.
     *
     * @param int $profileid Profile id, 0 for the site settings.
     * @return \stdClass Quiz with cmid.
     */
    private function quiz(int $profileid): \stdClass {
        return $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'proctoringrequired' => 1,
            'profileid' => $profileid,
        ]);
    }

    /**
     * One finished attempt with a webcam capture, four tab switches and one copy.
     *
     * @param \stdClass $quiz Quiz with cmid.
     * @return array Scoring request: courseid, cmid, userid, reportid, attemptid.
     */
    private function attempt(\stdClass $quiz): array {
        global $DB;

        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $start = time() - 3600;
        $attemptid = (int)$DB->insert_record('quiz_attempts', (object)[
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempt' => 1,
            'uniqueid' => $this->uniqueid++,
            'layout' => '1,0',
            'state' => 'finished',
            'timestart' => $start,
            'timefinish' => $start + 1800,
            'timemodified' => time(),
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
            'timemodified' => time(),
        ]);
        foreach (['tab_hidden', 'tab_hidden', 'focus_lost', 'page_exit', 'clipboard_copy'] as $eventtype) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => $this->course->id,
                'quizid' => $quiz->cmid,
                'userid' => $user->id,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
                'eventtype' => $eventtype,
                'eventdetail' => '',
                'screenshoturl' => '',
                'timemodified' => time(),
            ]);
        }
        return [
            'courseid' => (int)$this->course->id,
            'cmid' => (int)$quiz->cmid,
            'userid' => (int)$user->id,
            'reportid' => $reportid,
            'attemptid' => $attemptid,
        ];
    }

    /**
     * Score one attempt the way the attempt page does.
     *
     * @param array $request Scoring request.
     * @return array Risk result.
     */
    private function score(array $request): array {
        return risk_calculator::calculate_attempt(
            $request['courseid'],
            $request['cmid'],
            $request['userid'],
            $request['reportid']
        );
    }

    /**
     * Factor keys a result scored.
     *
     * @param array $risk Risk result.
     * @return array<string, int> Factor key => points.
     */
    private static function points(array $risk): array {
        $points = [];
        foreach ($risk['factors'] as $factor) {
            $points[$factor['key']] = (int)$factor['points'];
        }
        return $points;
    }

    /**
     * Scoring settings are kept only within their ranges; blank follows the site.
     */
    public function test_clean_accepts_scoring_settings(): void {
        $this->assertSame(
            [
                'riskfactor_noface_enabled' => '0',
                'riskfactor_tabactivity_points' => '0',
                'riskfactor_tabactivity_cap' => '100',
                'risklevelhigh' => '40',
            ],
            profile::clean([
                'risklevelhigh' => '40',
                'riskfactor_tabactivity_cap' => '100',
                'riskfactor_tabactivity_points' => '0',
                'riskfactor_noface_enabled' => '0',
                'riskfactor_noface_points' => '',
            ])
        );
        $this->assertSame([], profile::clean([
            'riskfactor_tabactivity_points' => '101',
            'riskfactor_tabactivity_cap' => '-1',
            'riskfactor_noface_enabled' => '2',
            'riskfactor_unknown_points' => '5',
            'risklevelmoderate' => '0',
            'riskscorecapenabled' => '1',
        ]));
    }

    /**
     * The same evidence scores and bands by the quiz's profile, on the attempt page and in bulk.
     */
    public function test_score_and_level_follow_profile(): void {
        $certificate = $this->attempt($this->quiz($this->certificate_profile()));
        $degree = $this->attempt($this->quiz(0));

        $degreerisk = $this->score($degree);
        $this->assertSame(['clipboard' => 8, 'tabactivity' => 20], array_intersect_key(
            self::points($degreerisk),
            ['tabactivity' => 0, 'clipboard' => 0]
        ));
        $this->assertSame(28, $degreerisk['score']);
        $this->assertSame('moderate', $degreerisk['levelkey']);

        $certrisk = $this->score($certificate);
        $this->assertSame(6, self::points($certrisk)['tabactivity']);
        $this->assertArrayNotHasKey('clipboard', self::points($certrisk));
        $this->assertSame(6, $certrisk['score']);
        $this->assertSame('high', $certrisk['levelkey']);

        // The reports' bulk path agrees with the attempt page for both quizzes at once.
        $bulk = risk_calculator::calculate_many(['cert' => $certificate, 'degree' => $degree]);
        $this->assertSame($certrisk, $bulk['cert']);
        $this->assertSame($degreerisk, $bulk['degree']);
    }

    /**
     * A factor the site switches off still scores on a profile that switches it on, and
     * the other way round.
     */
    public function test_profile_switches_a_factor_both_ways(): void {
        set_config('riskfactor_clipboard_enabled', 0, 'quizaccess_proctoring');
        $on = profile::save(0, 'Degree', '', ['riskfactor_clipboard_enabled' => '1']);
        $profiled = $this->attempt($this->quiz($on));
        $plain = $this->attempt($this->quiz(0));

        $this->assertSame(8, self::points($this->score($profiled))['clipboard']);
        $this->assertArrayNotHasKey('clipboard', self::points($this->score($plain)));
        $bulk = risk_calculator::calculate_many([$profiled, $plain]);
        $this->assertSame(8, self::points($bulk[0])['clipboard']);
        $this->assertArrayNotHasKey('clipboard', self::points($bulk[1]));

        $this->assertTrue(risk_calculator::factor_enabled_anywhere('clipboard'));
        $this->assertFalse(risk_calculator::factor_enabled('clipboard'));
        profile::save($on, 'Degree', '', []);
        $this->assertFalse(risk_calculator::factor_enabled_anywhere('clipboard'));
    }

    /**
     * The highest reachable score counts every profile, so a site-wide filter or ceiling never
     * cuts off a score one profile's quizzes can reach.
     */
    public function test_max_possible_score_counts_profiles(): void {
        $plain = $this->quiz(0);
        $sitemax = risk_calculator::max_possible_score();
        $this->assertSame($sitemax, risk_calculator::max_possible_score((int)$plain->cmid));

        $raised = profile::save(0, 'ACE credit', '', ['riskfactor_f12_cap' => '100']);
        $acequiz = $this->quiz($raised);
        $this->assertSame($sitemax + 85, risk_calculator::max_possible_score());
        $this->assertSame($sitemax + 85, risk_calculator::max_possible_score((int)$acequiz->cmid));
        $this->assertSame($sitemax, risk_calculator::max_possible_score((int)$plain->cmid));

        set_config('riskscorecapenabled', 1, 'quizaccess_proctoring');
        $this->assertSame(100, risk_calculator::max_possible_score());
    }

    /**
     * The review backlog bands each hold by its own quiz's levels once a profile moves them.
     */
    public function test_review_backlog_uses_each_quizs_levels(): void {
        global $DB;

        $certquiz = $this->quiz($this->certificate_profile());
        $plainquiz = $this->quiz(0);
        $this->assertTrue(profile::levels_vary());
        foreach ([[$certquiz, 60], [$certquiz, 10], [$plainquiz, 60], [$plainquiz, 10]] as [$quiz, $score]) {
            $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
                'courseid' => $this->course->id,
                'quizid' => $quiz->cmid,
                'quizinstance' => $quiz->id,
                'userid' => 2,
                'attemptid' => $this->uniqueid++,
                'riskscore' => $score,
                'threshold' => 5,
                'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $backlog = overall_report::review_backlog(time());
        // Certificate: 60 is Critical (from 50), 10 is High (from 5). Site: 60 is High, 10 is Low.
        $this->assertSame(1, $backlog['critical']);
        $this->assertSame(2, $backlog['high']);
        $this->assertSame(1, $backlog['lower']);
    }

    /**
     * The edit form refuses out-of-range scoring values and levels that fall once the site's
     * values fill in the blanks.
     */
    public function test_form_validation(): void {
        $form = new profile_form();
        $base = ['id' => 0, 'name' => 'Certificate'];

        $errors = $form->validation($base + ['riskfactor_tabactivity_points' => '150'], []);
        $this->assertArrayHasKey('riskfactorgroup_tabactivity', $errors);

        // Site High starts at 50: a Critical of 40 would fall below it.
        $errors = $form->validation($base + ['risklevelcritical' => '40'], []);
        $this->assertArrayHasKey('risklevelcritical', $errors);

        $errors = $form->validation($base + ['risklevelmoderate' => '5', 'risklevelhigh' => '10', 'risklevelcritical' => '40'], []);
        $this->assertSame([], $errors);
    }

    /**
     * The profile summary names each factor and level it changes.
     */
    public function test_describe_names_scoring_settings(): void {
        $lines = profile::describe(['riskfactor_tabactivity_points' => '2', 'risklevelhigh' => '30']);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(get_string('riskscore:tabactivity', 'quizaccess_proctoring'), $lines[0]);
        $this->assertStringContainsString('2', $lines[0]);
        $this->assertStringContainsString(get_string('setting:risklevelhigh', 'quizaccess_proctoring'), $lines[1]);
    }
}
