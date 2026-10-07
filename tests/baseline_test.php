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
 * Tests for the proctoring baseline and the pilot profile (CPIT-489).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\baseline;
use quizaccess_proctoring\local\risk_calculator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Baseline tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\baseline
 */
final class baseline_test extends advanced_testcase {

    /**
     * The page has the three plain-language sections, quiz overrides, and never a credential.
     */
    public function test_markdown_sections_and_no_secrets(): void {
        $this->resetAfterTest();
        set_config('custom_api_key', 'do-not-print-this', 'quizaccess_proctoring');
        set_config('idverificationendpoint', 'https://private.example.org/verify-id', 'quizaccess_proctoring');
        set_config('imageretentiondays', 180, 'quizaccess_proctoring');
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CS101']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Final exam']);
        \quizaccess_proctoring::save_settings((object)[
            'id' => $quiz->id, 'proctoringrequired' => 1, 'riskreviewthreshold' => 60,
        ]);

        $page = baseline::markdown();

        $this->assertStringContainsString('## What stops a student from starting', $page);
        $this->assertStringContainsString('## What only flags an attempt for review', $page);
        $this->assertStringContainsString('## What holds a grade or certificate', $page);
        $this->assertStringContainsString('Attempt evidence: 180 days', $page);
        $this->assertStringContainsString('CS101, Final exam: riskreviewthreshold 60', $page);
        $this->assertStringNotContainsString('do-not-print-this', $page);
        $this->assertStringNotContainsString('private.example.org', $page);
    }

    /**
     * The pilot profile holds only Critical attempts and never fails one; applying it twice changes nothing.
     */
    public function test_pilot_profile(): void {
        $this->resetAfterTest();
        set_config('riskreviewenabled', QUIZACCESS_PROCTORING_RISK_ACTION_AUTO_FAIL, 'quizaccess_proctoring');
        set_config('riskreviewthreshold', 50, 'quizaccess_proctoring');

        $changes = baseline::apply_pilot_profile();

        $critical = risk_calculator::get_level_boundaries()['critical'];
        $this->assertSame(QUIZACCESS_PROCTORING_RISK_ACTION_HOLD, (int)get_config('quizaccess_proctoring', 'riskreviewenabled'));
        $this->assertSame($critical, (int)get_config('quizaccess_proctoring', 'riskreviewthreshold'));
        $this->assertCount(2, $changes);
        $this->assertSame([], baseline::apply_pilot_profile());
    }
}
