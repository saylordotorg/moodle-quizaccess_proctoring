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
 * Per-attempt proctoring summaries for the Saylor SIS (SIS-204).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Builds the read-only attempt summaries the SIS ingests for student affairs.
 *
 * SUMMARIES, NOT EVIDENCE. A row says what happened to an attempt - its risk score, how many
 * face mismatches and browser violations were counted, the ID check outcome, the grade-hold
 * decision, the AI review outcome and whether a reviewer signed it off - and links back to
 * report.php for the evidence itself. It never carries an image URL, a screenshot, an extracted
 * name from an ID, AI review text, a reviewer note or an override justification. Those stay in
 * Moodle, behind the capabilities that already guard them.
 *
 * ONE SCORE. The risk score comes from {@see risk_calculator::calculate_many()}, the same code the
 * overall report and the attempt page use, so the SIS cannot disagree with Moodle about an attempt.
 *
 * OFF UNLESS SWITCHED ON. {@see self::enabled()} reads the site setting `sisexportenabled`, which
 * defaults to off. A site that should never share proctoring data with the SIS (the certificate
 * site, learn.saylor.org) never turns it on, and the web service refuses there even for a token
 * that holds the capability.
 *
 * Paging is a keyset on (quiz_attempts.timemodified, id). Moodle bumps timemodified on every save
 * and on submission, so a new or finished attempt surfaces on the next sweep. Review decisions made
 * later (a hold released, an AI review completing, a sign-off) do not touch quiz_attempts, so the
 * SIS also re-requests the attempts it still considers open by id ({@see self::by_ids()}).
 */
final class sis_export {

    /** @var int Largest page the feed will return. */
    const MAX_LIMIT = 500;

    /** @var int Largest number of attempt ids one refresh call may name. */
    const MAX_IDS = 500;

    /** @var string[] Risk-hold status constant => wire value. */
    const HOLD_STATES = [
        0 => 'active',
        1 => 'released',
        2 => 'confirmed',
        3 => 'auto_failed',
    ];

    /** @var string[] AI review status constant => wire value. */
    const AI_STATES = [
        0 => 'queued',
        1 => 'processing',
        2 => 'complete',
        3 => 'failed',
    ];

    /**
     * Whether this site shares proctoring summaries with the SIS.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (int)get_config('quizaccess_proctoring', 'sisexportenabled') === 1;
    }

    /**
     * One page of proctored attempts changed after the keyset position.
     *
     * @param int $since quiz_attempts.timemodified of the last row already read.
     * @param int $sinceid quiz_attempts.id of the last row already read.
     * @param int $limit Page size, clamped to 1..MAX_LIMIT.
     * @return array ['attempts' => array, 'next_since' => int, 'next_since_id' => int, 'truncated' => bool]
     */
    public static function page(int $since, int $sinceid, int $limit): array {
        global $DB;

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        [$basesql, $params] = self::base_sql();
        $sql = $basesql . '
              AND (qa.timemodified > :since OR (qa.timemodified = :sinceeq AND qa.id > :sinceid))
         ORDER BY qa.timemodified ASC, qa.id ASC';
        $params += ['since' => $since, 'sinceeq' => $since, 'sinceid' => $sinceid];

        // One extra row says whether there is more, without a second count query.
        $rows = array_values($DB->get_records_sql($sql, $params, 0, $limit + 1));
        $truncated = count($rows) > $limit;
        if ($truncated) {
            array_pop($rows);
        }

        $nextsince = $since;
        $nextsinceid = $sinceid;
        if (!empty($rows)) {
            $last = end($rows);
            $nextsince = (int)$last->timemodified;
            $nextsinceid = (int)$last->attemptid;
        }

        return [
            'attempts' => self::summarise($rows),
            'next_since' => $nextsince,
            'next_since_id' => $nextsinceid,
            'truncated' => $truncated,
        ];
    }

    /**
     * Fresh summaries for named attempts, so the SIS can re-read reviews that were still open.
     *
     * Ids that are not proctored attempts (or no longer exist) are simply absent from the result.
     *
     * @param int[] $attemptids quiz_attempts ids, at most MAX_IDS.
     * @return array ['attempts' => array, 'next_since' => 0, 'next_since_id' => 0, 'truncated' => false]
     */
    public static function by_ids(array $attemptids): array {
        global $DB;

        $attemptids = array_values(array_unique(array_filter(array_map('intval', $attemptids), function ($id) {
            return $id > 0;
        })));
        if (count($attemptids) > self::MAX_IDS) {
            throw new \invalid_parameter_exception('At most ' . self::MAX_IDS . ' attempt ids per call.');
        }

        $rows = [];
        if (!empty($attemptids)) {
            [$basesql, $params] = self::base_sql();
            [$insql, $inparams] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'ida');
            $rows = array_values($DB->get_records_sql(
                $basesql . " AND qa.id {$insql} ORDER BY qa.id ASC",
                $params + $inparams
            ));
        }

        return [
            'attempts' => self::summarise($rows),
            'next_since' => 0,
            'next_since_id' => 0,
            'truncated' => false,
        ];
    }

    /**
     * The attempt rows both entry points start from: real (non-preview) attempts that were proctored.
     *
     * Whether an attempt was proctored is read from what was recorded for it (captures, browser
     * events or a hold), not from the quiz's current setting, which an administrator can change after
     * the fact: turning proctoring on would otherwise export every earlier unproctored attempt, and
     * turning it off would drop proctored ones even from by_ids() refreshes (PR #41 review). An
     * attempt whose evidence the retention schedule has since deleted, and that never had a hold, is
     * no longer listed: there is nothing left to summarise.
     *
     * @return array [sql, params]
     */
    private static function base_sql(): array {
        global $DB;

        $moduleid = (int)$DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $sql = "SELECT qa.id AS attemptid, qa.userid, qa.attempt, qa.state, qa.timestart, qa.timefinish,
                       qa.timemodified, q.id AS quizid, q.name AS quizname, q.course AS courseid,
                       cm.id AS cmid, c.shortname AS courseshortname, c.idnumber AS courseidnumber
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = :quizmodule
                  JOIN {course} c ON c.id = q.course
                 WHERE qa.preview = 0
                   AND (EXISTS (SELECT 1 FROM {quizaccess_proctoring_logs} pl
                                 WHERE pl.status = qa.id AND pl.userid = qa.userid AND pl.quizid = cm.id)
                        OR EXISTS (SELECT 1 FROM {quizaccess_proctoring_events} pe
                                    WHERE pe.attemptid = qa.id AND pe.userid = qa.userid AND pe.quizid = cm.id)
                        OR EXISTS (SELECT 1 FROM {quizaccess_proctoring_risk_holds} ph
                                    WHERE ph.attemptid = qa.id AND ph.userid = qa.userid AND ph.quizid = cm.id))";
        return [$sql, ['quizmodule' => $moduleid]];
    }

    /**
     * Turn attempt rows into wire summaries, gathering every per-attempt fact in grouped queries.
     *
     * @param array $rows Rows from {@see self::base_sql()}.
     * @return array Summaries in the same order as $rows.
     */
    private static function summarise(array $rows): array {
        global $CFG;

        if (empty($rows)) {
            return [];
        }
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $attemptids = array_map(function ($r) {
            return (int)$r->attemptid;
        }, $rows);

        $captures = self::capture_counts($rows);
        $violations = self::violation_counts($rows);
        $overrides = self::override_states($rows);
        $idv = self::id_verifications($rows, $overrides);
        $holds = self::hold_states($attemptids);
        $ai = self::ai_reviews($attemptids);

        // The same scoring call the overall report makes, so both read the same number.
        $requests = [];
        $signoffinput = [];
        foreach ($rows as $r) {
            $key = (int)$r->attemptid;
            $reportid = (int)($captures[$key]['reportid'] ?? 0) ?: (int)($violations[$key]['reportid'] ?? 0);
            $requests[$key] = [
                'courseid' => (int)$r->courseid,
                'cmid' => (int)$r->cmid,
                'userid' => (int)$r->userid,
                'reportid' => $reportid,
                'attemptid' => $key,
            ];
            $signoffinput[$key] = $requests[$key];
        }
        $risks = risk_calculator::calculate_many($requests);
        $signoffs = attempt_review::active_for($signoffinput);

        $out = [];
        foreach ($rows as $r) {
            $attemptid = (int)$r->attemptid;
            $request = $requests[$attemptid];
            $risk = $risks[$attemptid] ?? ['score' => 0, 'levelkey' => 'low'];
            $capture = $captures[$attemptid] ?? ['count' => 0, 'facemismatch' => 0, 'lastactivity' => 0];
            $violation = $violations[$attemptid] ?? ['count' => 0, 'lastactivity' => 0];
            $hold = $holds[$attemptid] ?? null;
            $review = $ai[$attemptid] ?? null;
            $check = $idv[$attemptid] ?? null;

            $signoff = $signoffs[attempt_review::key(
                (int)$r->courseid,
                (int)$r->cmid,
                (int)$r->userid,
                $attemptid,
                (int)$request['reportid']
            )] ?? null;
            $lastactivity = max((int)$capture['lastactivity'], (int)$violation['lastactivity']);

            $reporturl = '';
            if ((int)$request['reportid'] > 0) {
                $reporturl = (new \moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
                    'courseid' => (int)$r->courseid,
                    'cmid' => (int)$r->cmid,
                    'studentid' => (int)$r->userid,
                    'reportid' => (int)$request['reportid'],
                ]))->out(false);
            }

            $out[] = [
                'attemptid' => $attemptid,
                'userid' => (int)$r->userid,
                'courseid' => (int)$r->courseid,
                'course_shortname' => (string)$r->courseshortname,
                'course_idnumber' => (string)$r->courseidnumber,
                'cmid' => (int)$r->cmid,
                'quizid' => (int)$r->quizid,
                'quizname' => \core_external\util::format_string($r->quizname, \context_module::instance((int)$r->cmid)),
                'attempt' => (int)$r->attempt,
                'state' => (string)$r->state,
                'timestart' => (int)$r->timestart,
                'timefinish' => (int)$r->timefinish,
                'timemodified' => (int)$r->timemodified,
                'risk_score' => (int)$risk['score'],
                'risk_level' => (string)($risk['levelkey'] ?? 'low'),
                'capture_count' => (int)$capture['count'],
                'face_mismatch_count' => (int)$capture['facemismatch'],
                'violation_count' => (int)$violation['count'],
                'idv_status' => $check['status'] ?? 'none',
                'idv_face_score' => $check['facescore'] ?? null,
                'idv_name_score' => $check['namescore'] ?? null,
                'idv_verified_at' => (int)($check['verifiedat'] ?? 0),
                'hold_status' => $hold === null ? 'none' : (self::HOLD_STATES[(int)$hold->status] ?? 'unknown'),
                'hold_reviewed_at' => $hold === null ? 0 : (int)$hold->timereviewed,
                'ai_review_status' => $review === null ? 'none' : (self::AI_STATES[(int)$review->status] ?? 'unknown'),
                'ai_review_decision' => $review === null ? '' : (string)$review->decision,
                'reviewed' => $signoff !== null,
                'reviewed_at' => $signoff === null ? 0 : (int)$signoff->timecreated,
                'review_current' => $signoff !== null && attempt_review::is_current($signoff, $lastactivity),
                'overrides' => $overrides[$attemptid] ?? [],
                'last_activity' => $lastactivity,
                'report_url' => $reporturl,
            ];
        }

        return $out;
    }

    /**
     * Webcam capture counts, face-mismatch counts and the first capture id (the report id) per attempt.
     *
     * Grouped on the full course/quiz/user/attempt key and matched back against the attempt row, so an
     * attempt id reused by some other capture scope cannot lend its evidence to the wrong attempt.
     *
     * @param array $rows Attempt rows.
     * @return array attemptid => ['count', 'facemismatch', 'reportid', 'lastactivity']
     */
    private static function capture_counts(array $rows): array {
        global $DB;

        $byid = self::rows_by_id($rows);
        [$insql, $params] = $DB->get_in_or_equal(array_keys($byid), SQL_PARAMS_NAMED, 'cap');
        $params['facethreshold'] = max(1, (int)quizaccess_proctoring_get_proctoring_settings('threshold'));
        $params['deletion'] = 0;
        $records = $DB->get_recordset_sql(
            "SELECT l.courseid, l.quizid, l.userid, l.status AS attemptid,
                    MIN(l.id) AS reportid, MAX(l.timemodified) AS lastactivity,
                    SUM(CASE WHEN COALESCE(l.webcampicture, '') <> '' THEN 1 ELSE 0 END) AS capturecount,
                    SUM(CASE WHEN l.awsflag = 2 AND l.awsscore < :facethreshold THEN 1 ELSE 0 END) AS facemismatch
               FROM {quizaccess_proctoring_logs} l
              WHERE l.status {$insql} AND l.deletionprogress = :deletion
           GROUP BY l.courseid, l.quizid, l.userid, l.status",
            $params
        );
        $out = [];
        foreach ($records as $rec) {
            $row = $byid[(int)$rec->attemptid] ?? null;
            if (!$row || !self::same_scope($row, $rec)) {
                continue;
            }
            $out[(int)$rec->attemptid] = [
                'count' => (int)$rec->capturecount,
                'facemismatch' => (int)$rec->facemismatch,
                'reportid' => (int)$rec->reportid,
                'lastactivity' => (int)$rec->lastactivity,
            ];
        }
        $records->close();
        return $out;
    }

    /**
     * Suspicious browser-event counts per attempt, using the overall report's definition of a violation.
     *
     * @param array $rows Attempt rows.
     * @return array attemptid => ['count', 'reportid', 'lastactivity']
     */
    private static function violation_counts(array $rows): array {
        global $DB;

        $byid = self::rows_by_id($rows);
        [$insql, $params] = $DB->get_in_or_equal(array_keys($byid), SQL_PARAMS_NAMED, 'evat');
        [$typesql, $typeparams] = $DB->get_in_or_equal(
            overall_report::SUSPICIOUS_EVENT_TYPES,
            SQL_PARAMS_NAMED,
            'evty'
        );
        $records = $DB->get_recordset_sql(
            "SELECT e.courseid, e.quizid, e.userid, e.attemptid,
                    MIN(e.reportid) AS reportid, MAX(e.timemodified) AS lastactivity, COUNT(e.id) AS eventcount
               FROM {quizaccess_proctoring_events} e
              WHERE e.attemptid {$insql} AND e.eventtype {$typesql}
           GROUP BY e.courseid, e.quizid, e.userid, e.attemptid",
            $params + $typeparams
        );
        $out = [];
        foreach ($records as $rec) {
            $row = $byid[(int)$rec->attemptid] ?? null;
            if (!$row || !self::same_scope($row, $rec)) {
                continue;
            }
            $out[(int)$rec->attemptid] = [
                'count' => (int)$rec->eventcount,
                'reportid' => (int)$rec->reportid,
                'lastactivity' => (int)$rec->lastactivity,
            ];
        }
        $records->close();
        return $out;
    }

    /**
     * The ID check that governed each attempt.
     *
     * The attempt's own row when it has one (latest wins). Otherwise a pass recorded earlier on the
     * same quiz for the same student - the plugin reuses that pass instead of asking again - reported
     * as `reused_pass` so the SIS can tell "checked for this attempt" from "covered by an earlier check".
     *
     * An earlier pass is only credited when it could have governed the attempt (PR #32 review): an ID
     * check was required at the start (the site setting, as changed by any per-student override that
     * stood then), reuse was allowed (not "verify every attempt"), and the pass was within the maximum
     * age at the start. Otherwise the attempt reports `none` - an exempted student is not "verified".
     * Limitation: the site settings are read as they are now, and the name/policy-change rechecks are
     * not replayed, because neither the settings nor the profile at the start are recorded.
     *
     * @param array $rows Attempt rows.
     * @param array $overrides attemptid => override states, from {@see self::override_states()}.
     * @return array attemptid => ['status', 'facescore', 'namescore', 'verifiedat']
     */
    private static function id_verifications(array $rows, array $overrides): array {
        global $DB;

        $byid = self::rows_by_id($rows);
        [$insql, $params] = $DB->get_in_or_equal(array_keys($byid), SQL_PARAMS_NAMED, 'idv');
        $records = $DB->get_records_select(
            'quizaccess_proctoring_idv',
            "attemptid {$insql}",
            $params,
            'timemodified ASC, id ASC',
            'id, courseid, quizid, userid, attemptid, status, facescore, namescore, verifiedat, timecreated'
        );
        $out = [];
        foreach ($records as $rec) {
            $out[(int)$rec->attemptid] = self::idv_wire($rec, (string)$rec->status);
        }

        // Attempts with no row of their own: the latest pass on the same quiz recorded no later than
        // the attempt STARTED. A check made after the start - for a later attempt, say - cannot have
        // governed this one. One query for the whole page, matched back per attempt in PHP.
        $policy = identity_recheck_policy::config();
        $siterequired = (int)get_config('quizaccess_proctoring', 'idverificationenabled') === 1;
        $missing = array_filter($byid, function ($row) use ($out, $overrides, $siterequired, $policy) {
            $attemptid = (int)$row->attemptid;
            if (isset($out[$attemptid]) || !empty($policy['eachattempt'])) {
                return false;
            }
            $states = $overrides[$attemptid] ?? [];
            if (in_array('idverification:off', $states, true)) {
                return false;
            }
            return $siterequired || in_array('idverification:on', $states, true);
        });
        if (!empty($missing)) {
            // Exactly the (course, quiz, student) scopes on the page, not the cross-product of every
            // student with every quiz on it (PR #32 review); each tuple uses the coursequizuser index.
            $scopes = [];
            foreach ($missing as $row) {
                $scopes[self::scope_key((int)$row->courseid, (int)$row->cmid, (int)$row->userid)] =
                    [(int)$row->courseid, (int)$row->cmid, (int)$row->userid];
            }
            $passesbyscope = [];
            foreach (array_chunk($scopes, 100, true) as $chunk) {
                $where = [];
                $params = ['status' => 'pass'];
                $i = 0;
                foreach ($chunk as [$courseid, $cmid, $userid]) {
                    $where[] = "(courseid = :c{$i} AND quizid = :q{$i} AND userid = :u{$i})";
                    $params["c{$i}"] = $courseid;
                    $params["q{$i}"] = $cmid;
                    $params["u{$i}"] = $userid;
                    $i++;
                }
                $passes = $DB->get_records_select(
                    'quizaccess_proctoring_idv',
                    'status = :status AND (' . implode(' OR ', $where) . ')',
                    $params,
                    'verifiedat DESC, timecreated DESC, id DESC',
                    'id, courseid, quizid, userid, status, facescore, namescore, verifiedat, timecreated'
                );
                foreach ($passes as $pass) {
                    $key = self::scope_key((int)$pass->courseid, (int)$pass->quizid, (int)$pass->userid);
                    $passesbyscope[$key][] = $pass;
                }
            }
            foreach ($missing as $attemptid => $row) {
                $start = self::attempt_start($row);
                $key = self::scope_key((int)$row->courseid, (int)$row->cmid, (int)$row->userid);
                foreach ($passesbyscope[$key] ?? [] as $pass) {
                    // The check must have COMPLETED by the start (PR #32 review): a request begun
                    // before the attempt but finished after it cannot have governed it. verifiedat is
                    // the completion time; legacy rows without one fall back to timecreated.
                    $verifiedat = (int)($pass->verifiedat ?: $pass->timecreated);
                    $fresh = (int)$policy['maxage'] === 0 || $verifiedat + (int)$policy['maxage'] > $start;
                    if ($verifiedat <= $start && $fresh) {
                        $out[$attemptid] = self::idv_wire($pass, 'reused_pass');
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Wire shape for one ID check.
     *
     * @param \stdClass $rec idv row.
     * @param string $status Status to report.
     * @return array
     */
    private static function idv_wire(\stdClass $rec, string $status): array {
        return [
            'status' => $status,
            'facescore' => $rec->facescore === null ? null : (int)$rec->facescore,
            'namescore' => $rec->namescore === null ? null : (int)$rec->namescore,
            'verifiedat' => (int)($rec->verifiedat ?: $rec->timecreated),
        ];
    }

    /**
     * The most relevant risk hold per attempt: a later decision beats a lingering active row,
     * exactly as the overall report ranks them.
     *
     * @param int[] $attemptids Attempt ids.
     * @return array attemptid => hold record
     */
    private static function hold_states(array $attemptids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'hold');
        $records = $DB->get_records_select(
            'quizaccess_proctoring_risk_holds',
            "attemptid {$insql}",
            $params,
            'id ASC',
            'id, attemptid, status, timereviewed'
        );
        $priority = [
            \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE => 1,
            \QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED => 2,
            \QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED => 3,
            \QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED => 3,
        ];
        $out = [];
        $ranks = [];
        foreach ($records as $rec) {
            $attemptid = (int)$rec->attemptid;
            $rank = $priority[(int)$rec->status] ?? 0;
            if (!isset($ranks[$attemptid]) || $rank >= $ranks[$attemptid]) {
                $ranks[$attemptid] = $rank;
                $out[$attemptid] = $rec;
            }
        }
        return $out;
    }

    /**
     * The latest attempt-level AI review per attempt. Status and decision only; never its text.
     *
     * @param int[] $attemptids Attempt ids.
     * @return array attemptid => review record
     */
    private static function ai_reviews(array $attemptids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'air');
        $params['reviewtype'] = 'attempt';
        $records = $DB->get_records_select(
            'quizaccess_proctoring_ai_reviews',
            "reviewtype = :reviewtype AND attemptid {$insql}",
            $params,
            'timecreated ASC, id ASC',
            'id, attemptid, status, decision'
        );
        $out = [];
        foreach ($records as $rec) {
            $out[(int)$rec->attemptid] = $rec;
        }
        return $out;
    }

    /**
     * Which requirements a per-student override changed for each attempt, as "requirement:on|off".
     *
     * AS OF THE ATTEMPT'S START, not as of now: an override counts when it was created no later than
     * the start, had not expired by then, and was either never revoked or revoked after the start. So
     * a waiver granted afterwards is not read back onto an earlier attempt, and one revoked afterwards
     * still shows on the attempt it covered. The winner per requirement is chosen with the resolver's
     * own ordering and pick_winner(), as the attempt itself chose it. Limitation: an override whose
     * states were EDITED after the start is read with its current states (edits are only in the audit
     * table).
     *
     * One query for the page. Only states cross; never the justification, which can describe an
     * accommodation.
     *
     * @param array $rows Attempt rows.
     * @return array attemptid => string[]
     */
    private static function override_states(array $rows): array {
        global $DB;

        $byid = self::rows_by_id($rows);
        // Only the (course, student) pairs the page actually has, in chunks, as the ID check lookup
        // does: separate IN lists would load every page user's overrides in every page course
        // (PR #41 review).
        $pairs = [];
        foreach ($byid as $row) {
            $pairs[(int)$row->courseid . ':' . (int)$row->userid] = [(int)$row->courseid, (int)$row->userid];
        }
        $columns = implode(', ', array_values(override_resolver::STATE_COLUMNS));
        $recordsbypair = [];
        foreach (array_chunk($pairs, 100, true) as $chunk) {
            $where = [];
            $params = [];
            $i = 0;
            foreach ($chunk as [$courseid, $userid]) {
                $where[] = "(courseid = :ovc{$i} AND userid = :ovu{$i})";
                $params["ovc{$i}"] = $courseid;
                $params["ovu{$i}"] = $userid;
                $i++;
            }
            $records = $DB->get_records_select(
                'quizaccess_proctoring_overrides',
                implode(' OR ', $where),
                $params,
                '',
                "id, courseid, quizid, userid, expiry, revoked, timerevoked, timecreated, {$columns}"
            );
            foreach ($records as $record) {
                $recordsbypair[(int)$record->courseid . ':' . (int)$record->userid][] = $record;
            }
        }

        $out = [];
        foreach ($byid as $attemptid => $row) {
            $start = self::attempt_start($row);
            $candidates = $recordsbypair[(int)$row->courseid . ':' . (int)$row->userid] ?? [];
            $applicable = array_values(array_filter($candidates, function ($o) use ($row, $start) {
                return ((int)$o->quizid === 0 || (int)$o->quizid === (int)$row->quizid)
                    && (int)$o->timecreated <= $start
                    && ($o->expiry === null || (int)$o->expiry > $start)
                    && ((int)$o->revoked === 0 || ((int)$o->timerevoked > 0 && (int)$o->timerevoked > $start));
            }));
            if (empty($applicable)) {
                $out[$attemptid] = [];
                continue;
            }
            // The resolver's tie-break: quiz-scoped first, then newest, then highest id.
            usort($applicable, static function ($a, $b) {
                $aspecific = ((int)$a->quizid !== 0) ? 1 : 0;
                $bspecific = ((int)$b->quizid !== 0) ? 1 : 0;
                if ($aspecific !== $bspecific) {
                    return $bspecific <=> $aspecific;
                }
                if ((int)$a->timecreated !== (int)$b->timecreated) {
                    return (int)$b->timecreated <=> (int)$a->timecreated;
                }
                return (int)$b->id <=> (int)$a->id;
            });
            $states = [];
            foreach (override_resolver::requirement_keys() as $requirement) {
                $state = override_resolver::pick_winner($applicable, $requirement);
                if ($state === override_resolver::STATE_ENABLED) {
                    $states[] = $requirement . ':on';
                } else if ($state === override_resolver::STATE_DISABLED) {
                    $states[] = $requirement . ':off';
                }
            }
            $out[$attemptid] = $states;
        }
        return $out;
    }

    /**
     * Key for one (course, quiz course-module, student) scope.
     *
     * @param int $courseid Course id.
     * @param int $cmid Course-module id.
     * @param int $userid Student id.
     * @return string
     */
    private static function scope_key(int $courseid, int $cmid, int $userid): string {
        return $courseid . ':' . $cmid . ':' . $userid;
    }

    /**
     * When the attempt started, falling back to its last change for a row with no start recorded.
     *
     * @param \stdClass $row Attempt row.
     * @return int
     */
    private static function attempt_start(\stdClass $row): int {
        return (int)$row->timestart > 0 ? (int)$row->timestart : (int)$row->timemodified;
    }

    /**
     * Index attempt rows by attempt id.
     *
     * @param array $rows Attempt rows.
     * @return array attemptid => row
     */
    private static function rows_by_id(array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->attemptid] = $r;
        }
        return $out;
    }

    /**
     * Whether a grouped evidence row belongs to the attempt row's course, quiz and student.
     *
     * Evidence tables store the course-module id in their `quizid` column.
     *
     * @param \stdClass $row Attempt row.
     * @param \stdClass $rec Evidence aggregate.
     * @return bool
     */
    private static function same_scope(\stdClass $row, \stdClass $rec): bool {
        return (int)$rec->courseid === (int)$row->courseid
            && (int)$rec->quizid === (int)$row->cmid
            && (int)$rec->userid === (int)$row->userid;
    }
}
