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
 * Tests for the webservice rate limit's error contract (CPIT-503).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/external.php');

/**
 * The flood protection answers "slow down and retry", not a permanent refusal.
 *
 * The browser's upload queue retries 'ratelimitexceeded' with backoff; any other errorcode is a
 * permanent rejection that drops the upload for good. The limit used to throw the generic 'error'
 * code, so a burst of context events (a mouse crossing the window edge beside a second window)
 * silently discarded every flooded upload for the rest of the window - including the
 * student-message log entries (CPIT-503).
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring_external
 */
final class rate_limit_errorcode_test extends advanced_testcase {

    /**
     * Invoke the private limiter with seeded rows.
     *
     * @param array $conditions Equality conditions.
     * @param int $limit Window limit.
     */
    private function enforce(array $conditions, int $limit): void {
        $method = new \ReflectionMethod(\quizaccess_proctoring_external::class, 'enforce_recent_record_limit');
        $method->setAccessible(true);
        $method->invoke(null, 'quizaccess_proctoring_events', $conditions, $limit);
    }

    /**
     * A full window answers with the retryable errorcode.
     */
    public function test_rate_limit_throws_the_retryable_errorcode(): void {
        global $DB;

        $this->resetAfterTest();
        $conditions = ['courseid' => 1, 'quizid' => 2, 'userid' => 3];
        for ($i = 0; $i < 3; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)($conditions + [
                'attemptid' => 0,
                'reportid' => 0,
                'eventtype' => 'focus_lost',
                'eventdetail' => '',
                'pagevisibility' => '',
                'currenturl' => '',
                'screenshoturl' => '',
                'timemodified' => time(),
            ]));
        }

        try {
            $this->enforce($conditions, 3);
            $this->fail('the full window must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('ratelimitexceeded', $e->errorcode,
                'the browser queue only retries this exact errorcode');
        }
    }

    /**
     * Under the limit nothing is thrown, and another student's rows do not count.
     */
    public function test_rate_limit_scopes_to_its_conditions(): void {
        global $DB;

        $this->resetAfterTest();
        for ($i = 0; $i < 5; $i++) {
            $DB->insert_record('quizaccess_proctoring_events', (object)[
                'courseid' => 1,
                'quizid' => 2,
                'userid' => 99,
                'attemptid' => 0,
                'reportid' => 0,
                'eventtype' => 'focus_lost',
                'eventdetail' => '',
                'pagevisibility' => '',
                'currenturl' => '',
                'screenshoturl' => '',
                'timemodified' => time(),
            ]);
        }

        $this->enforce(['courseid' => 1, 'quizid' => 2, 'userid' => 3], 3);
        $this->assertTrue(true, 'another student\'s rows must not fill this student\'s window');
    }
}
