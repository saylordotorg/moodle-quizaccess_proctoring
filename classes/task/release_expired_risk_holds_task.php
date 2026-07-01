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

namespace quizaccess_proctoring\task;

/**
 * Releases active risk review holds after the configured review window.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class release_expired_risk_holds_task extends \core\task\scheduled_task {
    /**
     * Return task name.
     *
     * @return string Task name.
     */
    public function get_name() {
        return get_string('task:release_expired_risk_holds', 'quizaccess_proctoring');
    }

    /**
     * Release expired active risk holds.
     *
     * @return void
     */
    public function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
        $result = quizaccess_proctoring_auto_release_expired_risk_holds();
        $released = (int)($result['released'] ?? 0);
        $annotated = (int)($result['annotated'] ?? 0);
        mtrace("Released {$released} expired Saylor Proctored Quiz risk hold(s).");
        mtrace("Retained and annotated {$annotated} ceiling-blocked Saylor Proctored Quiz risk hold(s) for review.");
    }
}
