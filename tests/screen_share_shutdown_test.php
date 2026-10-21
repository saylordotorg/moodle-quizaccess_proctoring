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
 * Tests that screen sharing ends when the exam does (CPIT-508).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * The persistent helper window holds the screen share across quiz pages, so nothing in the
 * browser ends it when the attempt is submitted: students had to find the browser's own "stop
 * sharing" control themselves (CPIT-508). The finished-attempt page must tell the helper to
 * stop, and the helper must honour that message.
 *
 * The wiring lives in page JavaScript, so these tests pin the source the way
 * report_display_fixes_test does: cheap, and a refactor that drops the handoff fails loudly.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring
 */
final class screen_share_shutdown_test extends advanced_testcase {

    /**
     * Read one plugin file.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private function source(string $relative): string {
        global $CFG;
        return (string)file_get_contents($CFG->dirroot . '/mod/quiz/accessrule/proctoring/' . $relative);
    }

    /**
     * The finished-attempt page sends the helper a shutdown over the attempt's own channel.
     */
    public function test_finished_attempt_page_signals_the_helper(): void {
        $rule = $this->source('rule.php');
        $this->assertStringContainsString("'shutdown'", $rule,
            'the finished-attempt path must call the shutdown handoff');
        $this->assertStringContainsString("maybe_show_finished_attempt_risk_hold_notice((int)\$cmid, (int)\$attempt)", $rule,
            'the shutdown must ride the finished-attempt early return');

        $client = $this->source('amd/src/screenMonitorClient.js');
        $this->assertStringContainsString("shutdown: function(key, attemptid, attemptagems)", $client,
            'the client module must export the shutdown call');
        $this->assertStringContainsString("type: 'shutdown'", $client,
            'the shutdown must be broadcast to the helper window');
        $this->assertStringContainsString("attemptid: claimattempt", $client,
            'every status poll must claim the helper for its own attempt');
        // The built bundle carries it too, or the page serves stale behaviour.
        $this->assertStringContainsString('"shutdown"', $this->source('amd/build/screenMonitorClient.min.js'),
            'the built bundle must include the shutdown message');
    }

    /**
     * The helper stops the share, says the exam is over, and closes itself.
     */
    public function test_helper_honours_the_shutdown(): void {
        $monitor = $this->source('screenmonitor.php');
        $this->assertStringContainsString("message.type === 'shutdown'", $monitor,
            'the helper must handle the shutdown message');
        $this->assertStringContainsString('stopStream();', $monitor,
            'the helper must stop the share');
        $this->assertStringContainsString('window.close()', $monitor,
            'the helper must close itself after the share ends');
        $this->assertStringContainsString('screenmonitor:finished', $monitor,
            'the helper must tell the student the exam has been submitted');
        $this->assertStringContainsString('claimedattempt', $monitor,
            'the helper must leave a share claimed by another attempt (or its preflight) alone');
        // The student-facing wording exists and says sharing ended.
        $finished = get_string('screenmonitor:finished', 'quizaccess_proctoring');
        $this->assertStringContainsString('screen sharing has ended', $finished);
    }
}
