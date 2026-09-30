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
 * Bounded, idempotent evidence recovery helpers.
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Validates advisory capture timing without extending the normal attempt authorization boundary.
 */
final class evidence_ingestion {
    /** Maximum age of a recoverable browser capture. */
    public const MAX_CAPTURE_AGE = 300;

    /** Maximum time after submission during which a previously captured image can arrive. */
    public const FINISHED_GRACE = 120;

    /**
     * Validate the optional metadata supplied by clients supporting recovery.
     *
     * @param int $capturedat Browser capture time, or zero for legacy clients.
     * @param string $requestid Stable request token, or an empty string for legacy clients.
     * @param int|null $now Server time.
     * @return array Validated storage values.
     */
    public static function metadata(int $capturedat, string $requestid, ?int $now = null): array {
        $now = $now ?? time();
        if ($requestid !== '' && !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $requestid)) {
            throw new \invalid_parameter_exception('Invalid evidence request token.');
        }
        if ($capturedat < 0 || ($capturedat > 0 && ($capturedat < $now - self::MAX_CAPTURE_AGE || $capturedat > $now + 30))) {
            throw new \invalid_parameter_exception('Capture time is outside the recovery window.');
        }
        if ($requestid !== '' && $capturedat === 0) {
            throw new \invalid_parameter_exception('A capture time is required with a recovery token.');
        }
        return ['capturedat' => $capturedat, 'requestid' => $requestid !== '' ? $requestid : null];
    }

    /**
     * Serialize retries of one capture so parallel delivery cannot store duplicates.
     *
     * @param int $userid Owner.
     * @param int $cmid Course module.
     * @param string $requestid Recovery token.
     * @return \core\lock\lock|null Lock to release in a finally block, or null for a legacy client.
     */
    public static function lock(int $userid, int $cmid, string $requestid): ?\core\lock\lock {
        if ($requestid === '') {
            return null;
        }
        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring');
        $lock = $factory->get_lock('evidence:' . $userid . ':' . $cmid . ':' . hash('sha256', $requestid), 5);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }
        return $lock;
    }

    /**
     * Find an already stored capture without returning a record from another attempt.
     *
     * @param string $table Evidence table.
     * @param int $userid Owner.
     * @param int $cmid Course module.
     * @param int $attemptid Attempt.
     * @param string $requestid Recovery token.
     * @return \stdClass|false Stored record.
     */
    public static function duplicate(string $table, int $userid, int $cmid, int $attemptid, string $requestid) {
        global $DB;
        if ($requestid === '') {
            return false;
        }
        if (!in_array($table, ['quizaccess_proctoring_logs', 'quizaccess_proctoring_events'], true)) {
            throw new \coding_exception('Unsupported evidence table.');
        }
        $record = $DB->get_record($table, ['userid' => $userid, 'quizid' => $cmid, 'requestid' => $requestid]);
        $field = $table === 'quizaccess_proctoring_logs' ? 'status' : 'attemptid';
        if ($record && (int)$record->$field !== $attemptid) {
            throw new \invalid_parameter_exception('Evidence token belongs to another attempt.');
        }
        return $record;
    }

    /**
     * Authorize a normal capture or a bounded recovery of evidence captured before submission.
     *
     * @param int $attemptid Attempt ID.
     * @param int $quizid Quiz instance ID.
     * @param int $userid Owner.
     * @param int $capturedat Browser capture time, or zero for legacy clients.
     * @param string $requestid Stable recovery token.
     * @param int|null $now Server time.
     * @return \stdClass Validated attempt.
     */
    public static function attempt(
        int $attemptid,
        int $quizid,
        int $userid,
        int $capturedat,
        string $requestid,
        ?int $now = null
    ): \stdClass {
        global $DB;
        $now = $now ?? time();
        $attempt = $DB->get_record(
            'quiz_attempts',
            ['id' => $attemptid],
            'id, quiz, userid, state, timestart, timefinish',
            MUST_EXIST
        );
        if ((int)$attempt->quiz !== $quizid || (int)$attempt->userid !== $userid) {
            throw new \invalid_parameter_exception('Invalid quiz attempt.');
        }
        if ($capturedat > 0 && $capturedat < (int)$attempt->timestart) {
            throw new \invalid_parameter_exception('Evidence was captured before the quiz attempt.');
        }
        if (in_array((string)$attempt->state, ['inprogress', 'overdue'], true)) {
            return $attempt;
        }
        if (
            (string)$attempt->state === 'finished' && $requestid !== '' && $capturedat > 0 &&
                (int)$attempt->timefinish > 0 && $capturedat <= (int)$attempt->timefinish &&
                $now <= (int)$attempt->timefinish + self::FINISHED_GRACE
        ) {
            return $attempt;
        }
        throw new \invalid_parameter_exception('Quiz attempt is not active.');
    }
}
