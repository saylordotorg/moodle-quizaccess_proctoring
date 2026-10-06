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
 * One-off task that applies the gradebook rules to every existing risk hold.
 *
 * Holds created before 1.11.3 only zeroed the gradebook once, and a regrade may since have put
 * the real grade back. Queued by the upgrade so the grade work runs in cron, not in the upgrade.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enforce_risk_holds_task extends \core\task\adhoc_task {
    /**
     * Apply every active, confirmed and automatically failed hold to its student's grade.
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        [$insql, $inparams] = $DB->get_in_or_equal(
            \quizaccess_proctoring\local\risk_hold_enforcer::enforced_statuses(),
            SQL_PARAMS_NAMED
        );
        $pairs = $DB->get_recordset_sql(
            "SELECT DISTINCT quizinstance, userid
               FROM {quizaccess_proctoring_risk_holds}
              WHERE status $insql AND quizinstance > 0 AND userid > 0
           ORDER BY quizinstance, userid",
            $inparams
        );

        $done = 0;
        $failed = 0;
        foreach ($pairs as $pair) {
            try {
                \quizaccess_proctoring\local\risk_hold_enforcer::enforce((int)$pair->quizinstance, (int)$pair->userid);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                quizaccess_proctoring_log_failure(
                    'applying risk holds for quiz ' . $pair->quizinstance . ' user ' . $pair->userid,
                    $e
                );
            }
        }
        $pairs->close();

        mtrace("Applied Saylor Proctored Quiz risk holds to {$done} student grade(s); {$failed} failed.");
    }
}
