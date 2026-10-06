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
 * Tests that Critical holds wait for a reviewer, and that the backlog is visible (CPIT-465).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\overall_report;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Auto-release and review backlog tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_risk_hold_auto_release_time
 * @covers ::quizaccess_proctoring_get_risk_review_ceiling
 * @covers \quizaccess_proctoring\local\overall_report::held_certificates
 */
final class risk_hold_auto_release_test extends advanced_testcase {

    /**
     * With the shipped settings a Critical hold is never released automatically; lower holds are,
     * once their review window ends.
     */
    public function test_critical_holds_wait_for_a_reviewer_by_default(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('holddecisionnotify', 0, 'quizaccess_proctoring');
        $created = time() - 8 * DAYSECS;
        $critical = $this->hold(85, $created);
        $high = $this->hold(60, $created);

        $this->assertSame(0, quizaccess_proctoring_risk_hold_auto_release_time($critical));
        $this->assertSame($created + 7 * DAYSECS, quizaccess_proctoring_risk_hold_auto_release_time($high));

        $result = quizaccess_proctoring_auto_release_expired_risk_holds();
        $this->assertSame(1, (int)$result['released']);
        $this->assertSame(
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            (int)$DB->get_field('quizaccess_proctoring_risk_holds', 'status', ['id' => $critical->id])
        );
        $this->assertSame(
            QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED,
            (int)$DB->get_field('quizaccess_proctoring_risk_holds', 'status', ['id' => $high->id])
        );
    }

    /**
     * An administrator can still turn the ceiling off, or turn auto-release off altogether.
     */
    public function test_auto_release_time_follows_the_settings(): void {
        $this->resetAfterTest();
        $created = time() - DAYSECS;
        $critical = $this->hold(85, $created);

        set_config('riskscorecapenabled', 1, 'quizaccess_proctoring');
        set_config('riskreviewceiling', 101, 'quizaccess_proctoring');
        $this->assertSame($created + 7 * DAYSECS, quizaccess_proctoring_risk_hold_auto_release_time($critical));

        set_config('riskreviewautoreleasedays', 0, 'quizaccess_proctoring');
        $this->assertSame(0, quizaccess_proctoring_risk_hold_auto_release_time($critical));

        $critical->status = QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED;
        set_config('riskreviewautoreleasedays', 7, 'quizaccess_proctoring');
        $this->assertSame(0, quizaccess_proctoring_risk_hold_auto_release_time($critical));
    }

    /**
     * The held-certificates dashboard reports the backlog by band, its oldest hold, and how many
     * holds will be released unreviewed within 48 hours.
     */
    public function test_dashboard_reports_the_review_backlog(): void {
        $this->resetAfterTest();
        $now = time();
        $this->hold(90, $now - 3 * DAYSECS);
        $this->hold(85, $now - DAYSECS);
        $this->hold(60, $now - 6 * DAYSECS);
        $this->hold(30, $now - HOURSECS);

        $backlog = overall_report::held_certificates(0)['backlog'];

        $this->assertSame(2, $backlog['critical']);
        $this->assertSame(1, $backlog['high']);
        $this->assertSame(1, $backlog['lower']);
        $this->assertSame($now - 6 * DAYSECS, $backlog['oldest']);
        // Only the High hold created six days ago auto-releases within two days; Critical holds wait.
        $this->assertSame(1, $backlog['expiringsoon']);
    }

    /**
     * Insert an active hold on a fresh course, quiz and student.
     *
     * @param int $score Risk score.
     * @param int $created Hold creation time.
     * @return \stdClass Hold record.
     */
    private function hold(int $score, int $created): \stdClass {
        global $DB;

        static $attempt = 0;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id, 'student');

        $record = (object)[
            'courseid' => $course->id,
            'quizid' => $quiz->cmid,
            'quizinstance' => $quiz->id,
            'userid' => $user->id,
            'attemptid' => 900000 + (++$attempt),
            'reportid' => 0,
            'riskscore' => $score,
            'threshold' => 80,
            'originalgrade' => null,
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'reviewerid' => 0,
            'timecreated' => $created,
            'timemodified' => $created,
            'timereviewed' => 0,
            'autoreleaseblockedscore' => 0,
            'autoreleaseblockedreason' => null,
        ];
        $record->id = (int)$DB->insert_record('quizaccess_proctoring_risk_holds', $record);
        return $record;
    }
}
