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
 * Reviewer sign-off on a flagged attempt that has no grade hold to act on.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Records that a human looked at a flagged attempt and found nothing to act on.
 *
 * An attempt with detected signals but no grade hold has nothing to release or confirm, so before
 * this it could be read but never marked: it looked identical the next day, and every reviewer after
 * the first had to redo the same reading. This is the missing decision - deliberately the lightest
 * one available. It changes no grade, sends the student nothing, and can be undone.
 *
 * Records live in the existing reviewer-verdict table under their own verdict and the
 * {@see self::FACTOR_KEY} sentinel, since they are a verdict on the whole attempt rather than on one
 * risk factor. Reusing that table keeps one place where reviewer verdicts are stored, exported for
 * privacy requests and deleted with the course.
 */
final class attempt_review {

    /** @var string Verdict value marking an attempt-level sign-off. */
    const VERDICT = 'attempt_reviewed';

    /**
     * Factor key stored on an attempt-level sign-off.
     *
     * The verdict is about the whole attempt rather than one risk factor, so there is no real
     * factor key to store. A named sentinel is used rather than an empty string: the column is
     * NOT NULL, and Oracle stores '' as NULL - Moodle's driver papers over that by writing a
     * single space, but a row that says what it is beats one that depends on a workaround. It
     * must never collide with a key in {@see risk_calculator::FACTOR_DEFAULTS}.
     *
     * @var string
     */
    const FACTOR_KEY = 'attempt';

    /** @var string Table holding reviewer verdicts. */
    const TABLE = 'quizaccess_proctoring_finding_reviews';

    /**
     * Whether the verdict table is present.
     *
     * @return bool True when sign-offs can be read or written.
     */
    public static function available(): bool {
        global $DB;

        static $exists = null;
        if ($exists === null) {
            $exists = $DB->get_manager()->table_exists(self::TABLE);
        }

        return $exists;
    }

    /**
     * The key an attempt is looked up by: its attempt id, or its report id when it has none.
     *
     * Mirrors the scoping the risk calculator and the hold lifecycle already use, so an attempt
     * without an attempt id is still addressable rather than silently unreviewable.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Quiz attempt id, 0 when unknown.
     * @param int $reportid Proctoring log id.
     * @return string Lookup key.
     */
    public static function key(int $courseid, int $cmid, int $userid, int $attemptid, int $reportid): string {
        $scope = $attemptid > 0 ? ('a' . $attemptid) : ('r' . $reportid);

        return $courseid . ':' . $cmid . ':' . $userid . ':' . $scope;
    }

    /**
     * Load the active sign-offs for a set of attempts, in one query.
     *
     * @param array $attempts Rows carrying 'courseid', 'cmid', 'userid', 'attemptid' and 'reportid'.
     * @return array Sign-off records keyed by {@see self::key()}.
     */
    public static function active_for(array $attempts): array {
        global $DB;

        if (empty($attempts) || !self::available()) {
            return [];
        }

        $attemptids = [];
        $reportids = [];
        foreach ($attempts as $a) {
            if ((int)$a['attemptid'] > 0) {
                $attemptids[(int)$a['attemptid']] = true;
            } else {
                $reportids[(int)$a['reportid']] = true;
            }
        }

        $params = ['verdict' => self::VERDICT];
        $scopes = [];
        if (!empty($attemptids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($attemptids), SQL_PARAMS_NAMED, 'att');
            $scopes[] = "attemptid {$insql}";
            $params += $inparams;
        }
        if (!empty($reportids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($reportids), SQL_PARAMS_NAMED, 'rep');
            $scopes[] = "(attemptid = 0 AND reportid {$insql})";
            $params += $inparams;
        }

        $where = 'verdict = :verdict AND revoked = 0 AND (' . implode(' OR ', $scopes) . ')';
        $records = $DB->get_records_select(self::TABLE, $where, $params, 'timecreated ASC, id ASC');

        // Last write wins, so re-signing off after new evidence replaces the earlier record.
        $signoffs = [];
        foreach ($records as $record) {
            $key = self::key(
                (int)$record->courseid,
                (int)$record->quizid,
                (int)$record->userid,
                (int)$record->attemptid,
                (int)$record->reportid
            );
            $signoffs[$key] = $record;
        }

        return $signoffs;
    }

    /**
     * Whether a sign-off still covers the attempt, or has been overtaken by newer evidence.
     *
     * A sign-off says "I looked at this attempt". It cannot vouch for evidence recorded afterwards,
     * so a later capture or event puts the attempt back in front of a reviewer rather than leaving
     * it filed under a decision that never saw it.
     *
     * @param \stdClass $signoff Sign-off record.
     * @param int $lastactivity Newest evidence timestamp for the attempt.
     * @return bool True when the sign-off still stands.
     */
    public static function is_current(\stdClass $signoff, int $lastactivity): bool {
        return (int)$signoff->timecreated >= $lastactivity;
    }

    /**
     * Record a sign-off, replacing any earlier one for the same attempt.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Quiz attempt id, 0 when unknown.
     * @param int $reportid Proctoring log id.
     * @param int $reviewerid Reviewer recording the decision.
     * @return int The new record id.
     */
    public static function record(
        int $courseid,
        int $cmid,
        int $userid,
        int $attemptid,
        int $reportid,
        int $reviewerid
    ): int {
        global $DB;

        $now = time();

        // Supersede rather than stack: an attempt has one current decision, and an older record kept
        // alongside it would make "who signed this off" ambiguous.
        $existing = self::active_for([[
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
        ]]);
        foreach ($existing as $signoff) {
            self::revoke($signoff, $reviewerid, $now);
        }

        return (int)$DB->insert_record(self::TABLE, (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'factorkey' => self::FACTOR_KEY,
            'verdict' => self::VERDICT,
            'reviewerid' => $reviewerid,
            'timecreated' => $now,
        ], true);
    }

    /**
     * Undo a sign-off, putting the attempt back among the flagged.
     *
     * @param int $id Sign-off record id.
     * @param int $courseid Course the caller was authorised against.
     * @param int $cmid Quiz course-module id the caller was authorised against.
     * @param int $actorid Actor undoing the decision.
     * @return \stdClass The record that was undone.
     */
    public static function undo(int $id, int $courseid, int $cmid, int $actorid): \stdClass {
        global $DB;

        $signoff = $DB->get_record(self::TABLE, ['id' => $id, 'verdict' => self::VERDICT], '*', MUST_EXIST);
        // The capability was checked against a course and quiz; refuse to act outside them.
        if ((int)$signoff->courseid !== $courseid || (int)$signoff->quizid !== $cmid) {
            throw new \moodle_exception('invalidrequest', 'error');
        }
        if ((int)$signoff->revoked === 0) {
            self::revoke($signoff, $actorid, time());
        }

        return $signoff;
    }

    /**
     * Mark one record as no longer standing.
     *
     * @param \stdClass $signoff Sign-off record.
     * @param int $actorid Actor.
     * @param int $when Timestamp.
     */
    private static function revoke(\stdClass $signoff, int $actorid, int $when): void {
        global $DB;

        $DB->update_record(self::TABLE, (object)[
            'id' => $signoff->id,
            'revoked' => 1,
            'revokedby' => $actorid,
            'timerevoked' => $when,
        ]);
    }
}
