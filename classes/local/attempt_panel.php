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
 * Embeddable per-attempt proctoring panel builder.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Builds a template-ready, embeddable proctoring fragment for a single quiz attempt.
 *
 * This extracts the per-attempt panel that the per-quiz report (report.php) renders in its
 * per-student detail view into a reusable, read-only builder keyed by attempt id. The fragment
 * bundles the four review-critical signals a reviewer needs at a glance:
 *
 *  - the attempt risk score (score / level / badge and the contributing factor breakdown),
 *  - the resolved certificate label reconciled against live hold + grade state (C2),
 *  - the AI image-review status with its compact status data (C3/C4),
 *  - the plain-language session summary (C9).
 *
 * The builder is deliberately read-only: it performs only lookups and never mutates state, so it
 * is safe to embed on the quiz attempt-review page (Requirement 14). Decision controls
 * (release/confirm/notes) are intentionally omitted here — those belong to Requirement 7 (P1).
 *
 * The report keys several helpers off a proctoring report id (a
 * {@see quizaccess_proctoring_logs} row id), whereas this fragment is keyed by quiz attempt id.
 * The logs table stores the attempt id in its `status` column, so {@see self::resolve_reportid()}
 * maps a `(courseid, cmid, userid, attemptid)` tuple back to a representative report id. The
 * attempt-id-aware helpers ({@see quizaccess_proctoring_get_ai_review()},
 * {@see quizaccess_proctoring_get_risk_hold()},
 * {@see quizaccess_proctoring_resolve_certificate_label()}) are then given both the attempt id and
 * the resolved report id, and the risk calculator (which keys off the report id) is given the
 * resolved report id.
 */
final class attempt_panel {

    /**
     * Build the template-ready context for the embeddable per-attempt panel.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid User id.
     * @param int $attemptid Quiz attempt id.
     * @return array Template context for the quizaccess_proctoring/attempt_panel partial.
     */
    public static function build_context(int $courseid, int $cmid, int $userid, int $attemptid): array {
        // Resolve a representative proctoring report id from the attempt id via the logs table.
        $reportid = self::resolve_reportid($courseid, $cmid, $userid, $attemptid);

        // Risk score. The calculator keys off the report id (and derives the attempt id from the
        // log row's status column). When no log row is found it falls back to an aggregate score.
        $risk = quizaccess_proctoring_calculate_attempt_risk($courseid, $cmid, $userid, $reportid);

        // Prefer the caller-supplied attempt id; fall back to the one the calculator resolved.
        $effectiveattemptid = $attemptid > 0 ? $attemptid : (int)($risk['attemptid'] ?? 0);

        // Resolved certificate label reconciled against live hold + grade state (C2).
        $certificate = quizaccess_proctoring_resolve_certificate_label(
            $courseid,
            $cmid,
            $userid,
            $effectiveattemptid,
            $reportid
        );
        $hascertificate = ($certificate['label'] ?? '') !== '';

        // AI image-review status and compact presentation data (C3/C4).
        $aireview = quizaccess_proctoring_get_ai_review($courseid, $cmid, $userid, $effectiveattemptid, $reportid);
        $aireviewdata = $aireview ? quizaccess_proctoring_format_ai_review_for_template($aireview) : null;

        // Plain-language session summary (C9).
        $sessionsummary = quizaccess_proctoring_build_session_summary($risk, $aireview ?: false);

        return [
            'heading' => get_string('attemptpanel:heading', 'quizaccess_proctoring'),
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptid' => $effectiveattemptid,
            'reportid' => $reportid,
            'sessionsummary' => $sessionsummary,
            'hassessionsummary' => $sessionsummary !== '',
            'riskscore' => $risk,
            'certificate' => $hascertificate ? $certificate : null,
            'hascertificate' => $hascertificate,
            'certificatelabel' => get_string('attemptpanel:certificatelabel', 'quizaccess_proctoring'),
            'aireview' => $aireviewdata,
            'hasaireview' => $aireviewdata !== null,
        ];
    }

    /**
     * Render the embeddable per-attempt panel as a self-contained HTML fragment.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid User id.
     * @param int $attemptid Quiz attempt id.
     * @return string Rendered HTML fragment.
     */
    public static function render(int $courseid, int $cmid, int $userid, int $attemptid): string {
        global $OUTPUT;

        $context = self::build_context($courseid, $cmid, $userid, $attemptid);

        return $OUTPUT->render_from_template('quizaccess_proctoring/attempt_panel', (object)$context);
    }

    /**
     * Resolve a representative proctoring report id for an attempt.
     *
     * The logs table stores the quiz attempt id in its `status` column, so a row whose
     * `status` matches the attempt id identifies that attempt's proctoring log. When several
     * log rows exist for the attempt the most recent (highest id) is used. Deleted/soft-deleted
     * rows (`deletionprogress <> 0`) are excluded. Read-only and defensive: any lookup failure
     * yields 0 so callers can fall back to attempt-id-only lookups.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid User id.
     * @param int $attemptid Quiz attempt id.
     * @return int Representative report id, or 0 when none can be resolved.
     */
    public static function resolve_reportid(int $courseid, int $cmid, int $userid, int $attemptid): int {
        global $DB;

        if ($attemptid <= 0) {
            return 0;
        }

        try {
            $where = 'courseid = :courseid AND quizid = :cmid AND userid = :userid
                AND status = :attemptid AND deletionprogress = :deletionprogress';
            $params = [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'userid' => $userid,
                'attemptid' => $attemptid,
                'deletionprogress' => 0,
            ];

            $records = $DB->get_records_select(
                'quizaccess_proctoring_logs',
                $where,
                $params,
                'id DESC',
                'id',
                0,
                1
            );

            return $records ? (int)reset($records)->id : 0;
        } catch (\Throwable $e) {
            // Never let a read failure break an embedding page; fall back to attempt-id lookups.
            return 0;
        }
    }
}
