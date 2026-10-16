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

namespace quizaccess_proctoring\local;

/**
 * Removes what a deleted quiz attempt leaves behind (CPIT-464, second round).
 *
 * Deleting an attempt removed its grades but not its proctoring records: the captures stayed
 * stored, and the per-quiz report showed the student with an attempt link that goes nowhere.
 * The capture rows are queued for the image deletion task, which removes the files and every
 * dependent record (events and their screenshots, face crops, match warnings); an active hold
 * on the deleted attempt is dropped, or it would keep the gradebook empty and locked forever
 * with nothing left to review. Decided holds stay, as the audit trail of a past decision.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_cleanup {
    /** @var int Most rows queued per sweep run. */
    private const BATCH = 500;

    /**
     * Clean up after one deleted attempt.
     *
     * @param \mod_quiz\event\attempt_deleted $event The deletion event.
     * @return void
     */
    public static function attempt_deleted(\mod_quiz\event\attempt_deleted $event): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        try {
            $attemptid = (int)$event->objectid;
            if ($attemptid <= 0) {
                return;
            }
            $userid = (int)$event->relateduserid;
            $quizinstance = (int)($event->other['quizid'] ?? 0);

            $DB->set_field_select(
                'quizaccess_proctoring_logs',
                'deletionprogress',
                1,
                'status = :attemptid AND deletionprogress = 0',
                ['attemptid' => $attemptid]
            );
            $hadactivehold = $DB->record_exists('quizaccess_proctoring_risk_holds', [
                'attemptid' => $attemptid,
                'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            ]);
            if ($hadactivehold) {
                $DB->delete_records('quizaccess_proctoring_risk_holds', [
                    'attemptid' => $attemptid,
                    'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
                ]);
                if ($quizinstance > 0 && $userid > 0) {
                    // Restore the gradebook from whatever holds and attempts remain. Only when
                    // a hold was actually removed: clearfeedback bypasses the no-holds guard,
                    // and running it for every deleted attempt on the site would unlock grades
                    // and erase feedback this plugin never touched (PR review).
                    risk_hold_enforcer::enforce($quizinstance, $userid, true);
                }
            }
        } catch (\Throwable $e) {
            quizaccess_proctoring_log_failure('cleaning up after a deleted attempt', $e);
        }
    }

    /**
     * Queue capture rows whose attempt no longer exists, for attempts deleted before this
     * cleanup existed (and as the safety net behind the event).
     *
     * @return int Number of rows queued.
     */
    public static function queue_orphaned_attempt_rows(): int {
        global $DB;

        $orphans = $DB->get_records_sql(
            "SELECT l.id
               FROM {quizaccess_proctoring_logs} l
          LEFT JOIN {quiz_attempts} qa ON qa.id = l.status
              WHERE l.status > 0 AND l.deletionprogress = 0 AND qa.id IS NULL
           ORDER BY l.id",
            [],
            0,
            self::BATCH
        );
        if (!$orphans) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($orphans));
        $DB->set_field_select('quizaccess_proctoring_logs', 'deletionprogress', 1, "id $insql", $params);
        return count($orphans);
    }
}
