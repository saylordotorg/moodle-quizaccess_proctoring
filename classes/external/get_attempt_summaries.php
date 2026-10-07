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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Read-only proctoring summaries for the Saylor SIS (SIS-204).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use quizaccess_proctoring\local\sis_export;

/**
 * Pages proctored attempt summaries for the SIS, or refreshes named attempts.
 *
 * A web-service function for the SIS token, not an AJAX one: it reads every student's attempts,
 * so it needs the system capability quizaccess/proctoring:exportsummaries, which no archetype holds,
 * and it refuses outright unless the site has switched sharing on (`sisexportenabled`).
 */
final class get_attempt_summaries extends external_api {
    /**
     * Keyset position, page size, or a list of attempt ids to refresh.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'quiz_attempts.timemodified of the last row read', VALUE_DEFAULT, 0),
            'since_id' => new external_value(PARAM_INT, 'quiz_attempts.id of the last row read', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Page size, at most ' . sis_export::MAX_LIMIT, VALUE_DEFAULT, 200),
            'attemptids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Attempt id to refresh'),
                'Refresh these attempts instead of paging; at most ' . sis_export::MAX_IDS,
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Return one page of summaries, or the named attempts.
     *
     * @param int $since Keyset timestamp.
     * @param int $sinceid Keyset id.
     * @param int $limit Page size.
     * @param array $attemptids Attempts to refresh.
     * @return array
     */
    public static function execute(int $since = 0, int $sinceid = 0, int $limit = 200, array $attemptids = []): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'since' => $since,
            'since_id' => $sinceid,
            'limit' => $limit,
            'attemptids' => $attemptids,
        ]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('quizaccess/proctoring:exportsummaries', $context);
        if (!sis_export::enabled()) {
            throw new \moodle_exception('sisexportdisabled', 'quizaccess_proctoring');
        }

        if (!empty($params['attemptids'])) {
            return sis_export::by_ids($params['attemptids']);
        }
        return sis_export::page((int)$params['since'], (int)$params['since_id'], (int)$params['limit']);
    }

    /**
     * Summaries only: counts, statuses and scores. No images, names from IDs, notes or AI text.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'attempts' => new external_multiple_structure(new external_single_structure([
                'attemptid' => new external_value(PARAM_INT, 'quiz_attempts id'),
                'userid' => new external_value(PARAM_INT, 'Student user id'),
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'course_shortname' => new external_value(PARAM_RAW, 'Course short name'),
                'course_idnumber' => new external_value(PARAM_RAW, 'Course id number'),
                'cmid' => new external_value(PARAM_INT, 'Quiz course module id'),
                'quizid' => new external_value(PARAM_INT, 'Quiz instance id'),
                'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
                'attempt' => new external_value(PARAM_INT, 'Attempt number'),
                'state' => new external_value(PARAM_ALPHAEXT, 'inprogress, overdue, finished or abandoned'),
                'timestart' => new external_value(PARAM_INT, 'Attempt start'),
                'timefinish' => new external_value(PARAM_INT, 'Attempt submission, 0 if not submitted'),
                'timemodified' => new external_value(PARAM_INT, 'Attempt last modified; the keyset timestamp'),
                'risk_score' => new external_value(PARAM_INT, 'Proctoring risk score'),
                'risk_level' => new external_value(PARAM_ALPHA, 'low, moderate, high or critical'),
                'capture_count' => new external_value(PARAM_INT, 'Webcam captures stored'),
                'face_mismatch_count' => new external_value(PARAM_INT, 'Captures below the face-match threshold'),
                'violation_count' => new external_value(PARAM_INT, 'Suspicious browser events'),
                'idv_status' => new external_value(PARAM_ALPHAEXT, 'none, pass, failed, retry, error or reused_pass'),
                'idv_face_score' => new external_value(PARAM_INT, 'ID portrait vs live face score; null when there is no ID check', VALUE_OPTIONAL, null, NULL_ALLOWED),
                'idv_name_score' => new external_value(PARAM_INT, 'ID name vs profile name score; null when there is no ID check', VALUE_OPTIONAL, null, NULL_ALLOWED),
                'idv_verified_at' => new external_value(PARAM_INT, 'When the governing ID check ran, 0 if none'),
                'hold_status' => new external_value(PARAM_ALPHAEXT, 'none, active, released, confirmed or auto_failed'),
                'hold_reviewed_at' => new external_value(PARAM_INT, 'When the hold was decided, 0 if not'),
                'ai_review_status' => new external_value(PARAM_ALPHA, 'none, queued, processing, complete or failed'),
                'ai_review_decision' => new external_value(PARAM_ALPHANUMEXT, 'The AI review decision key, empty if none'),
                'reviewed' => new external_value(PARAM_BOOL, 'A reviewer signed the attempt off'),
                'reviewed_at' => new external_value(PARAM_INT, 'When it was signed off, 0 if not'),
                'review_current' => new external_value(PARAM_BOOL, 'The sign-off still covers all recorded evidence'),
                'overrides' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'requirement:on or requirement:off'),
                    'Requirements a per-student override changed for this attempt'
                ),
                'last_activity' => new external_value(PARAM_INT, 'Newest proctoring evidence timestamp'),
                'report_url' => new external_value(PARAM_URL, 'The plugin report for this attempt, empty if none'),
            ])),
            'next_since' => new external_value(PARAM_INT, 'Keyset timestamp to resume from'),
            'next_since_id' => new external_value(PARAM_INT, 'Keyset id to resume from'),
            'truncated' => new external_value(PARAM_BOOL, 'More rows follow this page'),
        ]);
    }
}
