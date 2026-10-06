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
 * Tests that review-only factors never decide an automatic failure (CPIT-468).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\risk_calculator;

/**
 * Review-only factor tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\risk_calculator::score_without_review_only_factors
 */
final class review_only_factors_test extends advanced_testcase {

    /**
     * A second face counts towards the score but not towards the automatic-failure score.
     */
    public function test_multiple_faces_are_left_out_of_the_automatic_failure_score(): void {
        $this->resetAfterTest();
        $this->assertContains('multiplefaces', risk_calculator::REVIEW_ONLY_FACTORS);

        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        $risk = ['factors' => [
            ['key' => 'multiplefaces', 'points' => 30],
            ['key' => 'tabactivity', 'points' => 20],
            ['key' => 'clipboard', 'points' => 8],
        ]];
        $this->assertSame(28, risk_calculator::score_without_review_only_factors($risk));

        // Nothing but a second face: nothing left to fail on.
        $this->assertSame(0, risk_calculator::score_without_review_only_factors(['factors' => [
            ['key' => 'multiplefaces', 'points' => 30],
        ]]));
        $this->assertSame(0, risk_calculator::score_without_review_only_factors([]));
    }

    /**
     * The automatic-failure score is capped exactly like the score it is compared against.
     */
    public function test_the_automatic_failure_score_follows_the_score_cap(): void {
        $this->resetAfterTest();
        $risk = ['factors' => [
            ['key' => 'multiplefaces', 'points' => 30],
            ['key' => 'facemismatch', 'points' => 35],
            ['key' => 'screenshare', 'points' => 36],
            ['key' => 'aitool', 'points' => 30],
            ['key' => 'multimonitor', 'points' => 25],
        ]];

        set_config('riskscorecapenabled', 1, 'quizaccess_proctoring');
        $this->assertSame(100, risk_calculator::score_without_review_only_factors($risk));

        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        $this->assertSame(126, risk_calculator::score_without_review_only_factors($risk));
    }
}
