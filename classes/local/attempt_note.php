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
 * Staff-only reviewer notes on a proctored attempt.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Reads and writes the notes reviewers leave each other on an attempt.
 *
 * Reviewing a proctored attempt means reading evidence and reaching a judgement, and until now the
 * judgement was the only part that survived: a sign-off records that someone looked, never what
 * they concluded. The next reviewer therefore repeated the reading. A note is the missing half -
 * "the second face is the student's child, confirmed by email on 3 August" - and it is worth
 * exactly as much as it is durable, so it lives in the database next to the attempt rather than in
 * a support ticket nobody opening the report would think to search.
 *
 * Notes are staff-only. They are never rendered on any student-facing surface, and they are scoped
 * the same way {@see attempt_review} scopes a sign-off, so a note follows the attempt it is about.
 */
final class attempt_note {

    /** @var string Table holding reviewer notes. */
    const TABLE = 'quizaccess_proctoring_notes';

    /** @var int Longest note accepted, in characters. Long enough for a paragraph of reasoning. */
    const MAX_LENGTH = 2000;

    /**
     * Whether the notes table is present.
     *
     * Upgrades that have not run yet must not break the report, so every read returns nothing and
     * every write is refused rather than raising a database error mid-page.
     *
     * @return bool True when notes can be read or written.
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
     * The notes on one attempt, oldest first.
     *
     * Oldest first because the notes are a conversation: read in order, they say how the
     * understanding of the attempt developed.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student the notes are about.
     * @param int $attemptid Quiz attempt id, 0 when unknown.
     * @param int $reportid Proctoring log id, used when there is no attempt id.
     * @return \stdClass[] Note records, oldest first.
     */
    public static function for_attempt(
        int $courseid,
        int $cmid,
        int $userid,
        int $attemptid,
        int $reportid
    ): array {
        global $DB;

        if (!self::available()) {
            return [];
        }

        $conditions = [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
        ];
        // Scoped exactly as a sign-off is: by attempt id where there is one, by report id where
        // there is not. Mixing the two would show a note filed against one attempt on another.
        if ($attemptid > 0) {
            $conditions['attemptid'] = $attemptid;
        } else {
            $conditions['attemptid'] = 0;
            $conditions['reportid'] = $reportid;
        }

        return array_values($DB->get_records(self::TABLE, $conditions, 'timecreated ASC, id ASC'));
    }

    /**
     * How many notes each of a set of attempts carries, in one query.
     *
     * Used by the site-wide list, so a reviewer can see there is context to read before deciding
     * whether to open the report.
     *
     * @param array $attempts Rows carrying 'courseid', 'cmid', 'userid', 'attemptid' and 'reportid'.
     * @return array<string, int> Note counts keyed by {@see attempt_review::key()}.
     */
    public static function counts_for(array $attempts): array {
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

        $scopes = [];
        $params = [];
        if (!empty($attemptids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($attemptids), SQL_PARAMS_NAMED, 'natt');
            $scopes[] = "attemptid {$insql}";
            $params += $inparams;
        }
        if (!empty($reportids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($reportids), SQL_PARAMS_NAMED, 'nrep');
            $scopes[] = "(attemptid = 0 AND reportid {$insql})";
            $params += $inparams;
        }
        if (empty($scopes)) {
            return [];
        }

        $sql = 'SELECT courseid, quizid, userid, attemptid, reportid, COUNT(id) AS notecount
                  FROM {' . self::TABLE . '}
                 WHERE ' . implode(' OR ', $scopes) . '
              GROUP BY courseid, quizid, userid, attemptid, reportid';
        $rows = $DB->get_records_sql($sql, $params);

        $counts = [];
        foreach ($rows as $row) {
            $key = attempt_review::key(
                (int)$row->courseid,
                (int)$row->quizid,
                (int)$row->userid,
                (int)$row->attemptid,
                (int)$row->reportid
            );
            $counts[$key] = ($counts[$key] ?? 0) + (int)$row->notecount;
        }

        return $counts;
    }

    /**
     * Add a note.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student the note is about.
     * @param int $attemptid Quiz attempt id, 0 when unknown.
     * @param int $reportid Proctoring log id.
     * @param int $authorid Staff member writing the note.
     * @param string $text The note.
     * @return int The new record id.
     * @throws \moodle_exception When the note is blank or over {@see self::MAX_LENGTH}.
     */
    public static function add(
        int $courseid,
        int $cmid,
        int $userid,
        int $attemptid,
        int $reportid,
        int $authorid,
        string $text
    ): int {
        global $DB;

        if (!self::available()) {
            throw new \moodle_exception('error:notesunavailable', 'quizaccess_proctoring');
        }

        $text = trim($text);
        // A blank note is not a note. Refusing it here rather than at the form keeps the same rule
        // whichever route writes one.
        if ($text === '' || \core_text::strlen($text) > self::MAX_LENGTH) {
            throw new \moodle_exception('error:invalidnote', 'quizaccess_proctoring');
        }

        $now = time();

        return (int)$DB->insert_record(self::TABLE, (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'notetext' => $text,
            'authorid' => $authorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ], true);
    }

    /**
     * Delete a note.
     *
     * The caller has already been checked for the review capability on the course; this refuses to
     * act outside the course and quiz it was checked against, and - unless the caller may manage
     * other people's notes - refuses to delete a note somebody else wrote. A reviewer retracting
     * their own reasoning is housekeeping; overwriting a colleague's is not.
     *
     * @param int $id Note id.
     * @param int $courseid Course the caller was authorised against.
     * @param int $cmid Quiz course-module id the caller was authorised against.
     * @param int $actorid Actor deleting the note.
     * @param bool $canmanageothers Whether the actor may delete notes they did not write.
     * @return \stdClass The record that was deleted.
     * @throws \moodle_exception When the note is out of scope, or belongs to someone else.
     */
    public static function delete(
        int $id,
        int $courseid,
        int $cmid,
        int $actorid,
        bool $canmanageothers = false
    ): \stdClass {
        global $DB;

        $note = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        if ((int)$note->courseid !== $courseid || (int)$note->quizid !== $cmid) {
            throw new \moodle_exception('invalidrequest', 'error');
        }
        if ((int)$note->authorid !== $actorid && !$canmanageothers) {
            throw new \moodle_exception('error:notenotyours', 'quizaccess_proctoring');
        }

        $DB->delete_records(self::TABLE, ['id' => $id]);

        return $note;
    }
}
