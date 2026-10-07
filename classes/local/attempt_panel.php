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
 * The builder is read-only: it performs only lookups and never mutates state, so it is safe to
 * embed on the quiz attempt-review page (Requirement 14). Since CPIT-475 the panel also carries
 * what a reviewer needs to decide a typical hold without leaving that page: the flagged moments
 * with their times, a strip of the flagged webcam captures, and Release / Confirm links. The links
 * go to the per-quiz report's existing hold actions, which check the capability and the session
 * key and then return to the attempt.
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
            'coverage' => monitoring_coverage::for_attempt($courseid, $cmid, $userid, $effectiveattemptid),
            'reporturl' => $reportid > 0 ? (new \moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'studentid' => $userid,
                'reportid' => $reportid,
            ]))->out(false) : '',
        ] + self::flagged_moments($courseid, $cmid, $userid, $effectiveattemptid)
          + self::flagged_captures($courseid, $cmid, $userid, $effectiveattemptid)
          + self::hold_controls($courseid, $cmid, $userid, $effectiveattemptid, $reportid);
    }

    /** @var int How many flagged moments and captures the panel lists before pointing to the report. */
    private const FLAGGED_LIMIT = 6;

    /**
     * The attempt's suspicious browser events, earliest first, with their times.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Quiz attempt id.
     * @return array Template keys flaggedmoments, hasflaggedmoments, moreflaggedmoments.
     */
    private static function flagged_moments(int $courseid, int $cmid, int $userid, int $attemptid): array {
        global $DB;

        if ($attemptid <= 0) {
            return ['flaggedmoments' => [], 'hasflaggedmoments' => false, 'moreflaggedmoments' => ''];
        }
        [$typesql, $typeparams] = $DB->get_in_or_equal(overall_report::SUSPICIOUS_EVENT_TYPES, SQL_PARAMS_NAMED, 'pt');
        $where = "courseid = :courseid AND quizid = :cmid AND userid = :userid AND attemptid = :attemptid
                  AND eventtype {$typesql}";
        $params = ['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid] + $typeparams;
        $total = $DB->count_records_select('quizaccess_proctoring_events', $where, $params);
        // When it happened in the browser: evidence resent after a dropped connection keeps its
        // capture time, and only its arrival is late (CPIT-475 review).
        $events = $DB->get_records_sql(
            "SELECT id, eventtype, CASE WHEN capturedat > 0 THEN capturedat ELSE timemodified END AS evidencetime
               FROM {quizaccess_proctoring_events}
              WHERE {$where}
           ORDER BY evidencetime ASC, id ASC",
            $params,
            0,
            self::FLAGGED_LIMIT
        );
        $timeformat = get_string('strftimetime', 'langconfig');
        $moments = [];
        foreach ($events as $event) {
            $moments[] = [
                'time' => userdate((int)$event->evidencetime, $timeformat),
                'label' => quizaccess_proctoring_get_event_label((string)$event->eventtype),
            ];
        }
        return [
            'flaggedmoments' => $moments,
            'hasflaggedmoments' => !empty($moments),
            'moreflaggedmoments' => $total > count($moments)
                ? get_string('attemptpanel:moreinreport', 'quizaccess_proctoring', $total - count($moments))
                : '',
        ];
    }

    /**
     * The attempt's webcam captures that did not match the reference or showed no face.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Quiz attempt id.
     * @return array Template keys flaggedcaptures, hasflaggedcaptures, moreflaggedcaptures.
     */
    private static function flagged_captures(int $courseid, int $cmid, int $userid, int $attemptid): array {
        global $DB;

        if ($attemptid <= 0) {
            return ['flaggedcaptures' => [], 'hasflaggedcaptures' => false, 'moreflaggedcaptures' => ''];
        }
        $threshold = max(1, (int)quizaccess_proctoring_get_proctoring_settings('threshold'));
        // The same no-face rule the risk score counts: the face-match check found no face, or the
        // browser's detector found none in a capture it checked (CPIT-475 review).
        [$nofacesql, $nofaceparams] = risk_calculator::no_face_capture_sql();
        $where = "l.courseid = :courseid AND l.quizid = :cmid AND l.userid = :userid AND l.status = :attemptid
                  AND l.deletionprogress = 0 AND l.webcampicture <> ''
                  AND ((l.awsflag = 2 AND l.awsscore < :threshold) OR {$nofacesql})";
        $params = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'threshold' => $threshold,
        ] + $nofaceparams;
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {quizaccess_proctoring_logs} l WHERE {$where}", $params);
        $logs = $DB->get_records_sql(
            "SELECT l.id, l.webcampicture, l.awsflag, l.awsscore,
                    CASE WHEN l.capturedat > 0 THEN l.capturedat ELSE l.timemodified END AS evidencetime
               FROM {quizaccess_proctoring_logs} l
              WHERE {$where}
           ORDER BY evidencetime ASC, l.id ASC",
            $params,
            0,
            self::FLAGGED_LIMIT
        );
        $timeformat = get_string('strftimetime', 'langconfig');
        $captures = [];
        foreach ($logs as $log) {
            $captures[] = [
                'url' => (string)$log->webcampicture,
                'time' => userdate((int)$log->evidencetime, $timeformat),
                'label' => (int)$log->awsflag === 2 && (int)$log->awsscore < $threshold
                    ? get_string('reportcaptures:badgemismatch', 'quizaccess_proctoring', (int)$log->awsscore)
                    : get_string('reportcaptures:badgenoface', 'quizaccess_proctoring'),
            ];
        }
        return [
            'flaggedcaptures' => $captures,
            'hasflaggedcaptures' => !empty($captures),
            'moreflaggedcaptures' => $total > count($captures)
                ? get_string('attemptpanel:moreinreport', 'quizaccess_proctoring', $total - count($captures))
                : '',
        ];
    }

    /**
     * Release and Confirm links for an active hold, for a viewer who may decide it.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Quiz attempt id.
     * @param int $reportid Representative proctoring log id.
     * @return array Template keys canacthold, releaseurl, confirmurl.
     */
    private static function hold_controls(int $courseid, int $cmid, int $userid, int $attemptid, int $reportid): array {
        $none = ['canacthold' => false, 'releaseurl' => '', 'confirmurl' => ''];
        $hold = quizaccess_proctoring_get_risk_hold($courseid, $cmid, $userid, $attemptid, $reportid);
        if (!$hold || (int)$hold->status !== QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
            return $none;
        }
        $context = \context_module::instance($cmid);
        if (!has_all_capabilities(['quizaccess/proctoring:reviewriskholds', 'quizaccess/proctoring:viewreport'], $context)) {
            return $none;
        }
        $base = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'holdid' => (int)$hold->id,
            'returnattempt' => $attemptid,
            'sesskey' => sesskey(),
        ];
        // The report checks a student and capture pair when both are given. Once the attempt's
        // captures were deleted there is no capture to name, and the hold alone identifies the
        // decision (CPIT-475 review).
        if ($reportid > 0) {
            $base += ['studentid' => $userid, 'reportid' => $reportid];
        }
        return [
            'canacthold' => true,
            'releaseurl' => (new \moodle_url('/mod/quiz/accessrule/proctoring/report.php', $base + ['riskaction' => 'release']))
                ->out(false),
            'confirmurl' => (new \moodle_url('/mod/quiz/accessrule/proctoring/report.php', $base + ['riskaction' => 'confirm']))
                ->out(false),
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
