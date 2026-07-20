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
 * Observer for the quizaccess_proctoring plugin.
 *
 * This class listens for Moodle quiz events related to proctored quiz attempts.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

/**
 * proctoring_observer class.
 *
 * This class defines observer methods that handle quiz submission risk review logic.
 *
 * @package    quizaccess_proctoring
 * @copyright  2020 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proctoring_observer {
    /**
     * Handle the event when a quiz attempt is started.
     *
     * This method is retained for compatibility with older event mappings.
     *
     * @param \mod_quiz\event\attempt_started $event The event object representing the quiz attempt start.
     * @return void
     */
    public static function handle_quiz_attempt_started(\mod_quiz\event\attempt_started $event) {
    }

    /**
     * Handle the event when a quiz attempt is submitted.
     *
     * This method calculates the proctoring risk score and applies the configured high-risk action.
     *
     * @param \mod_quiz\event\attempt_submitted $event The event object representing the quiz attempt submission.
     * @return void
     */
    public static function handle_quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event) {
        global $CFG, $DB;

        try {
            require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

            $attemptid = (int)$event->objectid;
            if ($attemptid <= 0) {
                return;
            }

            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
            if (!$attempt || empty($attempt->userid) || empty($attempt->quiz)) {
                return;
            }

            $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz]);
            if (!$quiz) {
                return;
            }

            $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
            if (!$cm) {
                return;
            }

            $proctoring = $DB->get_record('quizaccess_proctoring', ['quizid' => $quiz->id]);
            if (!$proctoring || empty($proctoring->proctoringrequired)) {
                return;
            }

            $risksettings = \quizaccess_proctoring_get_effective_risk_review_settings((int)$cm->id);
            $riskaction = (int)$risksettings['action'];
            $aireviewsettings = \quizaccess_proctoring_get_ai_review_settings();
            $aireviewenabled = \quizaccess_proctoring_ai_review_configured($aireviewsettings);
            $risklockoutenabled = \quizaccess_proctoring_get_cheating_lockout_days() > 0 &&
                (int)$risksettings['mode'] !== \QUIZACCESS_PROCTORING_RISK_ACTION_DISABLED;
            if (empty($risksettings['enabled']) && !$risklockoutenabled && !$aireviewenabled) {
                return;
            }

            $reports = $DB->get_records(
                'quizaccess_proctoring_logs',
                [
                    'courseid' => $quiz->course,
                    'quizid' => $cm->id,
                    'userid' => $attempt->userid,
                    'status' => $attemptid,
                    'deletionprogress' => 0,
                ],
                'id ASC',
                '*',
                0,
                1
            );
            if (!$reports) {
                return;
            }

            $report = reset($reports);
            $risk = \quizaccess_proctoring_calculate_attempt_risk(
                (int)$quiz->course,
                (int)$cm->id,
                (int)$attempt->userid,
                (int)$report->id
            );

            $holdid = 0;
            if (
                (!empty($risksettings['enabled']) || $risklockoutenabled) &&
                    (int)$risk['score'] >= (int)$risksettings['threshold']
            ) {
                if ($riskaction === \QUIZACCESS_PROCTORING_RISK_ACTION_AUTO_FAIL) {
                    $holdid = \quizaccess_proctoring_fail_high_risk_attempt(
                        (int)$quiz->course,
                        (int)$cm->id,
                        (int)$attempt->userid,
                        $attemptid,
                        (int)$report->id,
                        (int)$risk['score'],
                        (int)$risksettings['threshold']
                    );
                } else {
                    $holdid = \quizaccess_proctoring_apply_risk_hold(
                        (int)$quiz->course,
                        (int)$cm->id,
                        (int)$attempt->userid,
                        $attemptid,
                        (int)$report->id,
                        (int)$risk['score'],
                        (int)$risksettings['threshold']
                    );
                }
            }

            if ($aireviewenabled) {
                // The configured trigger mode selects when AI image review is enqueued at
                // submission: 'everyattempt' forces enqueue regardless of the risk score
                // (Requirement 3.2), while 'threshold' keeps the score gate (Requirements 3.3, 3.4).
                // Both remain gated by AI review being configured and enabled ($aireviewenabled).
                $forceaireview = \quizaccess_proctoring_get_ai_review_trigger_mode() === 'everyattempt';
                \quizaccess_proctoring_queue_ai_review(
                    (int)$quiz->course,
                    (int)$cm->id,
                    (int)$attempt->userid,
                    $attemptid,
                    (int)$report->id,
                    $holdid,
                    (int)$risk['score'],
                    (int)$aireviewsettings['triggerthreshold'],
                    $forceaireview
                );
            }
        } catch (\Throwable $e) {
            debugging('Unable to process Saylor Proctored Quiz submission risk review: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
