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
     * This method calculates the proctoring risk score and applies a grade hold when configured.
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

            $proctoring = $DB->get_record('quizaccess_proctoring', ['quizid' => $cm->id]);
            if (!$proctoring || empty($proctoring->proctoringrequired)) {
                return;
            }

            $settings = \quizaccess_proctoring_get_effective_risk_review_settings((int)$cm->id);
            if (empty($settings['enabled'])) {
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

            if ((int)$risk['score'] < (int)$settings['threshold']) {
                return;
            }

            \quizaccess_proctoring_apply_risk_hold(
                (int)$quiz->course,
                (int)$cm->id,
                (int)$attempt->userid,
                $attemptid,
                (int)$report->id,
                (int)$risk['score'],
                (int)$settings['threshold']
            );
        } catch (\Throwable $e) {
            debugging('Unable to apply Saylor Proctored Quiz risk hold: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

}
