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
 * Tests for the student-facing risk hold notices.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Tests that every non-terminal and terminal risk hold state has a student-facing notice.
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_get_student_risk_confirmed_notice_html
 * @covers ::quizaccess_proctoring_get_student_risk_failure_notice_html
 * @covers ::quizaccess_proctoring_get_student_risk_hold_notice_html
 */
final class student_risk_notice_test extends advanced_testcase {
    /**
     * A confirmed violation renders its own notice naming the grade and certificate outcome.
     */
    public function test_confirmed_notice_states_the_outcome(): void {
        $this->resetAfterTest(true);

        $hold = (object)[
            'riskscore' => 85,
            'threshold' => 70,
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED,
            'timecreated' => time(),
        ];

        $html = quizaccess_proctoring_get_student_risk_confirmed_notice_html($hold);

        $this->assertStringContainsString('alert-danger', $html);
        $this->assertStringContainsString('quizaccess-proctoring-risk-confirmed-notice', $html);
        $this->assertStringContainsString(
            get_string('riskreview:confirmedstudenttitle', 'quizaccess_proctoring'),
            $html
        );

        // The score, the threshold, and both consequences are spelled out for the student.
        $this->assertStringContainsString('85/100', $html);
        $this->assertStringContainsString('70/100', $html);
        $this->assertStringContainsString('grade is zero', $html);
        $this->assertStringContainsString('certificate eligibility is withheld', $html);
    }

    /**
     * The confirmed notice is distinct from the interim hold notice it replaces, so a student
     * revisiting the quiz page after review never sees "review in progress" again.
     */
    public function test_confirmed_notice_replaces_the_in_progress_notice(): void {
        $this->resetAfterTest(true);

        $hold = (object)[
            'riskscore' => 91,
            'threshold' => 70,
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'timecreated' => time(),
        ];

        $active = quizaccess_proctoring_get_student_risk_hold_notice_html($hold);

        $hold->status = QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED;
        $confirmed = quizaccess_proctoring_get_student_risk_confirmed_notice_html($hold);

        $this->assertNotSame($active, $confirmed);
        $this->assertStringNotContainsString(
            get_string('riskreview:studentnoticetitle', 'quizaccess_proctoring'),
            $confirmed
        );
        // The interim notice promises a review; the confirmed one must not.
        $this->assertStringNotContainsString('Automatic release date', $confirmed);
    }

    /**
     * Every terminal hold state that withholds a certificate has a student notice builder, so no
     * state can silently fall through the quiz-page dispatch.
     */
    public function test_all_withholding_states_have_a_notice(): void {
        $this->resetAfterTest(true);

        $builders = [
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE =>
                'quizaccess_proctoring_get_student_risk_hold_notice_html',
            QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED =>
                'quizaccess_proctoring_get_student_risk_confirmed_notice_html',
            QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED =>
                'quizaccess_proctoring_get_student_risk_failure_notice_html',
        ];

        foreach ($builders as $status => $builder) {
            $hold = (object)[
                'riskscore' => 80,
                'threshold' => 70,
                'status' => $status,
                'timecreated' => time(),
            ];

            $html = $builder($hold);

            $this->assertNotEmpty($html, 'no notice rendered for status ' . $status);
            $this->assertStringContainsString(
                'role="alert"',
                $html,
                'notice for status ' . $status . ' is not an alert'
            );
            $this->assertStringContainsString(
                'certificate',
                $html,
                'notice for status ' . $status . ' does not mention the certificate'
            );
        }
    }
}
