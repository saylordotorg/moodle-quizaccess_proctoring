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
 * Builds the site-wide aggregate proctoring report ("Overall reports").
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

use moodle_url;

/**
 * Aggregates proctoring activity across every course and quiz for site administrators.
 */
final class overall_report {
    /** @var int Attempts listed per page. */
    const PER_PAGE = 25;

    /** @var int Maximum attempts pulled from each source table per request, to bound load. */
    const MAX_ATTEMPTS = 2000;

    /**
     * Browser event types that count as violations, mirroring the events scored by the risk
     * calculator. Routine recovery/informational events (tab_visible, focus_returned,
     * mouse_returned_window, mouse_left_window, monitor_detection_unavailable) are excluded.
     *
     * @var string[]
     */
    const SUSPICIOUS_EVENT_TYPES = [
        'focus_lost', 'tab_hidden', 'page_exit',
        'clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu',
        'screen_marker_missing', 'screen_share_stopped',
        'multiple_monitors_detected', 'possible_ai_tool', 'shortcut',
        'multiple_faces_detected', 'audio_detected',
        'face_missing', 'no_face_detected', 'phone_detected',
    ];

    /**
     * Rolling date-range windows, mapping a filter key to its length in seconds (0 = all time).
     *
     * @return array<string, int> Range key to window length in seconds.
     */
    public static function range_seconds(): array {
        return [
            'today' => DAYSECS,
            '7days' => 7 * DAYSECS,
            '30days' => 30 * DAYSECS,
            'all' => 0,
        ];
    }

    /**
     * Build select options for the date-range filter.
     *
     * @param string $selected Selected range key.
     * @return array List of option rows for the template.
     */
    public static function range_options(string $selected): array {
        $labels = [
            'today' => get_string('overallreport:rangetoday', 'quizaccess_proctoring'),
            '7days' => get_string('overallreport:range7days', 'quizaccess_proctoring'),
            '30days' => get_string('overallreport:range30days', 'quizaccess_proctoring'),
            'all' => get_string('overallreport:rangeall', 'quizaccess_proctoring'),
        ];
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'selected' => $value === $selected];
        }
        return $options;
    }

    /**
     * Build select options for the sort filter.
     *
     * @param string $selected Selected sort key.
     * @return array List of option rows for the template.
     */
    public static function sort_options(string $selected): array {
        $labels = [
            'recent' => get_string('overallreport:sortrecent', 'quizaccess_proctoring'),
            'oldest' => get_string('overallreport:sortoldest', 'quizaccess_proctoring'),
            'violations' => get_string('overallreport:sortviolations', 'quizaccess_proctoring'),
            'student' => get_string('overallreport:sortstudent', 'quizaccess_proctoring'),
            'email' => get_string('overallreport:sortemail', 'quizaccess_proctoring'),
        ];
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'selected' => $value === $selected];
        }
        return $options;
    }

    /**
     * Build select options for the course filter, listing only courses with proctoring data.
     *
     * @param int $selected Selected course id (0 for all courses).
     * @return array List of option rows for the template.
     */
    public static function course_options(int $selected): array {
        global $DB;

        $sql = "SELECT DISTINCT courseid
                  FROM {quizaccess_proctoring_logs}
                 WHERE deletionprogress = 0 AND courseid > 0
                 UNION
                SELECT DISTINCT courseid
                  FROM {quizaccess_proctoring_events}
                 WHERE courseid > 0";
        $courseids = $DB->get_fieldset_sql($sql);

        $options = [[
            'value' => 0,
            'label' => get_string('overallreport:allcourses', 'quizaccess_proctoring'),
            'selected' => $selected === 0,
        ]];
        if (empty($courseids)) {
            return $options;
        }

        $courses = $DB->get_records_list('course', 'id', $courseids, '', 'id, shortname, fullname');
        $named = [];
        foreach ($courseids as $courseid) {
            $courseid = (int)$courseid;
            $course = $courses[$courseid] ?? null;
            $named[$courseid] = $course ? format_string($course->shortname ?: $course->fullname) : ('#' . $courseid);
        }
        \core_collator::asort($named);
        foreach ($named as $courseid => $label) {
            $options[] = ['value' => $courseid, 'label' => $label, 'selected' => $courseid === $selected];
        }
        return $options;
    }

    /**
     * Build the aggregate report.
     *
     * @param int $courseid Course filter (0 for all courses).
     * @param string $range Date-range key (see {@see self::range_seconds()}).
     * @param int $minviolations Only include attempts with at least this many violations.
     * @param string $sort Sort key: 'recent', 'oldest', 'violations', 'student' or 'email'.
     * @param int $page Zero-based page number.
     * @param string $queue Review queue filter: 'needs', 'flagged', 'reviewed', 'escalated',
     *                      'clean', or 'all'.
     * @param string $search Case-insensitive substring matched against student name and email.
     * @param string $tifirst Single first-name initial from the initials bar ('' for all).
     * @param string $tilast Single surname initial from the initials bar ('' for all).
     * @param string $risklevel Risk band key ('low', 'moderate', 'high', 'critical') or '' for all.
     * @param int $riskmin Lowest risk score to include (0 for no lower bound).
     * @param int $riskmax Highest risk score to include; -1, or anything at or above the maximum
     *                     possible score, means no upper bound.
     * @return array Template-ready report data.
     */
    public static function build(
        int $courseid,
        string $range,
        int $minviolations,
        string $sort,
        int $page,
        string $queue = 'needs',
        string $search = '',
        string $tifirst = '',
        string $tilast = '',
        string $risklevel = '',
        int $riskmin = 0,
        int $riskmax = -1
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $ranges = self::range_seconds();
        $window = $ranges[$range] ?? (7 * DAYSECS);
        $fromtime = $window > 0 ? (time() - $window) : 0;
        $facethreshold = max(1, (int)quizaccess_proctoring_get_proctoring_settings('threshold'));

        // Candidate attempts keyed by course:cm:user:attempt, merged from captures and browser events.
        $attempts = [];
        $truncated = false;

        // 1. Webcam capture logs: capture count and face-mismatch count per attempt.
        $logwhere = 'l.deletionprogress = 0 AND l.status > 0';
        $logparams = ['facethreshold' => $facethreshold];
        if ($courseid > 0) {
            $logwhere .= ' AND l.courseid = :courseid';
            $logparams['courseid'] = $courseid;
        }
        if ($fromtime > 0) {
            $logwhere .= ' AND l.timemodified >= :fromtime';
            $logparams['fromtime'] = $fromtime;
        }
        $logsql = "SELECT l.courseid, l.quizid, l.userid, l.status AS attemptid,
                          MIN(l.id) AS reportid,
                          MAX(l.timemodified) AS lastactivity,
                          COUNT(l.id) AS capturecount,
                          SUM(CASE WHEN l.awsflag = 2 AND l.awsscore < :facethreshold THEN 1 ELSE 0 END) AS facemismatch
                     FROM {quizaccess_proctoring_logs} l
                    WHERE {$logwhere}
                 GROUP BY l.courseid, l.quizid, l.userid, l.status";
        $rs = $DB->get_recordset_sql($logsql, $logparams, 0, self::MAX_ATTEMPTS);
        $count = 0;
        foreach ($rs as $r) {
            $key = $r->courseid . ':' . $r->quizid . ':' . $r->userid . ':' . $r->attemptid;
            $attempts[$key] = [
                'courseid' => (int)$r->courseid,
                'cmid' => (int)$r->quizid,
                'userid' => (int)$r->userid,
                'attemptid' => (int)$r->attemptid,
                'reportid' => (int)$r->reportid,
                'lastactivity' => (int)$r->lastactivity,
                'capturecount' => (int)$r->capturecount,
                'facemismatch' => (int)$r->facemismatch,
                'eventcount' => 0,
            ];
            $count++;
        }
        $rs->close();
        $truncated = $truncated || $count >= self::MAX_ATTEMPTS;

        // 2. Browser violation events: event count per attempt (also surfaces capture-less attempts).
        $eventwhere = 'e.attemptid > 0';
        $eventparams = [];
        if ($courseid > 0) {
            $eventwhere .= ' AND e.courseid = :courseid';
            $eventparams['courseid'] = $courseid;
        }
        if ($fromtime > 0) {
            $eventwhere .= ' AND e.timemodified >= :fromtime';
            $eventparams['fromtime'] = $fromtime;
        }
        // Count only suspicious events as violations, mirroring the risk calculator; routine
        // recovery events (tab_visible, focus_returned, mouse_returned_window) are excluded.
        [$eventtypesql, $eventtypeparams] = $DB->get_in_or_equal(self::SUSPICIOUS_EVENT_TYPES, SQL_PARAMS_NAMED, 'evt');
        $eventwhere .= " AND e.eventtype {$eventtypesql}";
        $eventparams += $eventtypeparams;
        $eventsql = "SELECT e.courseid, e.quizid, e.userid, e.attemptid,
                            MIN(e.reportid) AS reportid,
                            MAX(e.timemodified) AS lastactivity,
                            COUNT(e.id) AS eventcount
                       FROM {quizaccess_proctoring_events} e
                      WHERE {$eventwhere}
                   GROUP BY e.courseid, e.quizid, e.userid, e.attemptid";
        $rs = $DB->get_recordset_sql($eventsql, $eventparams, 0, self::MAX_ATTEMPTS);
        $count = 0;
        foreach ($rs as $r) {
            $key = $r->courseid . ':' . $r->quizid . ':' . $r->userid . ':' . $r->attemptid;
            if (isset($attempts[$key])) {
                $attempts[$key]['eventcount'] = (int)$r->eventcount;
                $attempts[$key]['lastactivity'] = max($attempts[$key]['lastactivity'], (int)$r->lastactivity);
                if (empty($attempts[$key]['reportid'])) {
                    $attempts[$key]['reportid'] = (int)$r->reportid;
                }
            } else {
                $attempts[$key] = [
                    'courseid' => (int)$r->courseid,
                    'cmid' => (int)$r->quizid,
                    'userid' => (int)$r->userid,
                    'attemptid' => (int)$r->attemptid,
                    'reportid' => (int)$r->reportid,
                    'lastactivity' => (int)$r->lastactivity,
                    'capturecount' => 0,
                    'facemismatch' => 0,
                    'eventcount' => (int)$r->eventcount,
                ];
            }
            $count++;
        }
        $rs->close();
        $truncated = $truncated || $count >= self::MAX_ATTEMPTS;

        // Derive the violation total and apply the minimum-violations filter.
        foreach ($attempts as $k => $a) {
            $attempts[$k]['violations'] = $a['eventcount'] + $a['facemismatch'];
        }
        if ($minviolations > 0) {
            $attempts = array_filter($attempts, function ($a) use ($minviolations) {
                return $a['violations'] >= $minviolations;
            });
        }

        // Resolve the students once for the whole set, not just the visible page: the search box,
        // the initials bars and the name/email sorts all order or exclude rows that pagination has
        // not reached yet, and the counters above the list have to agree with them.
        $users = self::load_users($attempts);
        foreach ($attempts as $k => $a) {
            $user = $users[$a['userid']] ?? null;
            $attempts[$k]['fullname'] = $user
                ? fullname($user)
                : get_string('overallreport:unknownuser', 'quizaccess_proctoring');
            $attempts[$k]['email'] = $user ? (string)$user->email : '';
            $attempts[$k]['firstinitial'] = $user ? \core_text::substr((string)$user->firstname, 0, 1) : '';
            $attempts[$k]['lastinitial'] = $user ? \core_text::substr((string)$user->lastname, 0, 1) : '';
        }
        $attempts = self::filter_by_student($attempts, $search, $tifirst, $tilast);
        $attempts = self::filter_by_risk($attempts, $risklevel, $riskmin, $riskmax);

        // Summary counters across the whole filtered set.
        $students = [];
        $courses = [];
        $attemptids = [];
        $withviolations = 0;
        foreach ($attempts as $a) {
            $students[$a['userid']] = true;
            $courses[$a['courseid']] = true;
            if ($a['attemptid'] > 0) {
                $attemptids[$a['attemptid']] = true;
            }
            if ($a['violations'] > 0) {
                $withviolations++;
            }
        }
        $attemptids = array_keys($attemptids);

        // Map each attempt onto the review queue using the existing risk-hold lifecycle. Only an
        // active hold is actionable (release/confirm), so only that state enters the "needs review"
        // queue: a released hold is reviewed; a confirmed/auto-failed hold is escalated; an unheld
        // attempt that still has violations is "flagged" (surfaced under All attempts for context
        // but not parked in the actionable queue, since there is no hold to act on); everything
        // else is clean.
        $holdstates = self::hold_states($attemptids);
        // A flagged attempt has no hold to release, so its only decision is a reviewer sign-off.
        $signoffs = attempt_review::active_for($attempts);
        $pulse = ['needs' => 0, 'flagged' => 0, 'reviewed' => 0, 'escalated' => 0, 'clean' => 0];
        foreach ($attempts as $k => $a) {
            $status = $a['attemptid'] > 0 ? ($holdstates[$a['attemptid']] ?? null) : null;
            $signoffkey = attempt_review::key(
                (int)$a['courseid'],
                (int)$a['cmid'],
                (int)$a['userid'],
                (int)$a['attemptid'],
                (int)$a['reportid']
            );
            $signoff = $signoffs[$signoffkey] ?? null;
            if ($signoff !== null && !attempt_review::is_current($signoff, (int)$a['lastactivity'])) {
                // Evidence arrived after the sign-off, so it no longer speaks for this attempt.
                $signoff = null;
            }
            $attempts[$k]['signoff'] = $signoff;

            if ($status === \QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED) {
                $reviewstate = 'reviewed';
            } else if ($status === \QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED ||
                    $status === \QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED) {
                $reviewstate = 'escalated';
            } else if ($status === \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
                $reviewstate = 'needs';
            } else if ($a['violations'] > 0) {
                // Signed off counts as reviewed: a decision has been recorded either way, and the
                // row names who made it. Without one the attempt stays flagged and keeps asking.
                $reviewstate = $signoff !== null ? 'reviewed' : 'flagged';
            } else {
                $reviewstate = 'clean';
            }
            $attempts[$k]['reviewstate'] = $reviewstate;
            $pulse[$reviewstate]++;
        }

        $summary = [
            'totalattempts' => count($attempts),
            'students' => count($students),
            'courses' => count($courses),
            'withviolations' => $withviolations,
            'activeholds' => self::count_active_holds($attemptids),
            'aiflagged' => self::count_ai_flagged($attemptids),
            'needsreview' => $pulse['needs'],
            'flagged' => $pulse['flagged'],
            'reviewed' => $pulse['reviewed'],
            'escalated' => $pulse['escalated'],
            'clean' => $pulse['clean'],
        ];

        // Apply the review-queue filter before pagination. Every queue except 'all' selects exactly
        // one review state, so each card and pill lands on the rows its own count describes.
        if (in_array($queue, ['needs', 'flagged', 'reviewed', 'escalated', 'clean'], true)) {
            $attempts = array_filter($attempts, function ($a) use ($queue) {
                return $a['reviewstate'] === $queue;
            });
        }

        // Sort, then paginate. Every comparison falls back to newest-first so equal keys - a shared
        // surname, an empty email - still come out in a stable, useful order.
        $attempts = array_values($attempts);
        switch ($sort) {
            case 'oldest':
                usort($attempts, function ($a, $b) {
                    return $a['lastactivity'] <=> $b['lastactivity'];
                });
                break;
            case 'violations':
                usort($attempts, function ($a, $b) {
                    return [$b['violations'], $b['lastactivity']] <=> [$a['violations'], $a['lastactivity']];
                });
                break;
            case 'student':
                usort($attempts, function ($a, $b) {
                    return [\core_text::strtolower($a['fullname']), -$a['lastactivity']]
                        <=> [\core_text::strtolower($b['fullname']), -$b['lastactivity']];
                });
                break;
            case 'email':
                usort($attempts, function ($a, $b) {
                    return [\core_text::strtolower($a['email']), -$a['lastactivity']]
                        <=> [\core_text::strtolower($b['email']), -$b['lastactivity']];
                });
                break;
            default:
                usort($attempts, function ($a, $b) {
                    return $b['lastactivity'] <=> $a['lastactivity'];
                });
                break;
        }

        $total = count($attempts);
        $totalpages = (int)ceil($total / self::PER_PAGE);
        if ($totalpages > 0 && $page > $totalpages - 1) {
            $page = $totalpages - 1;
        }
        $page = max(0, $page);
        $pagerows = array_slice($attempts, $page * self::PER_PAGE, self::PER_PAGE);

        $canmanageholds = has_capability('quizaccess/proctoring:reviewriskholds', \context_system::instance());
        $filterparams = [
            'courseid' => $courseid,
            'range' => $range,
            'minviolations' => $minviolations,
            'sort' => $sort,
            'page' => $page,
            'queue' => $queue,
            'search' => $search,
            'tifirst' => $tifirst,
            'tilast' => $tilast,
            'risklevel' => $risklevel,
            'riskmin' => $riskmin,
            'riskmax' => $riskmax,
        ];

        return [
            'summary' => $summary,
            'rows' => self::decorate_rows($pagerows, $canmanageholds, $filterparams),
            'hasrows' => !empty($pagerows),
            'truncated' => $truncated,
            'total' => $total,
            'page' => $page,
            'perpage' => self::PER_PAGE,
        ];
    }

    /**
     * Load the name and identity fields for every student in an attempt set.
     *
     * Only the fields {@see fullname()} can consult are selected, plus the email the report shows
     * and sorts on, so a 2000-attempt set stays one narrow query rather than 2000 full user rows.
     *
     * @param array $attempts Attempt rows keyed however the caller likes.
     * @return array User records keyed by id.
     */
    private static function load_users(array $attempts): array {
        global $DB;

        $userids = [];
        foreach ($attempts as $a) {
            $userids[(int)$a['userid']] = true;
        }
        if (empty($userids)) {
            return [];
        }

        return $DB->get_records_list(
            'user',
            'id',
            array_keys($userids),
            '',
            'id, firstname, lastname, middlename, alternatename, firstnamephonetic, lastnamephonetic, email'
        );
    }

    /**
     * Apply the student search box and the two initials bars.
     *
     * The search matches the displayed name and the email address, so a reviewer can paste either
     * from a support ticket. The initials bars are the standard Moodle participant filters and
     * combine with the search rather than replacing it.
     *
     * @param array $attempts Attempt rows carrying 'fullname', 'email' and the two initials.
     * @param string $search Case-insensitive substring, or '' for no search.
     * @param string $tifirst First-name initial, or '' for all.
     * @param string $tilast Surname initial, or '' for all.
     * @return array The surviving attempt rows.
     */
    private static function filter_by_student(array $attempts, string $search, string $tifirst, string $tilast): array {
        $needle = \core_text::strtolower(trim($search));
        $tifirst = \core_text::strtolower($tifirst);
        $tilast = \core_text::strtolower($tilast);
        if ($needle === '' && $tifirst === '' && $tilast === '') {
            return $attempts;
        }

        return array_filter($attempts, function ($a) use ($needle, $tifirst, $tilast) {
            if ($tifirst !== '' && \core_text::strtolower($a['firstinitial']) !== $tifirst) {
                return false;
            }
            if ($tilast !== '' && \core_text::strtolower($a['lastinitial']) !== $tilast) {
                return false;
            }
            if ($needle === '') {
                return true;
            }
            return \core_text::strpos(\core_text::strtolower($a['fullname']), $needle) !== false
                || \core_text::strpos(\core_text::strtolower($a['email']), $needle) !== false;
        });
    }

    /**
     * Apply the risk band and risk score range filters.
     *
     * Risk scores are not stored - they are recomputed from current evidence and current factor
     * settings - so filtering on them means scoring the whole candidate set. That is one bulk pass
     * through {@see risk_calculator::calculate_many()} rather than twenty queries per attempt, and
     * the score each survivor was judged on is kept on the row so the visible page does not pay to
     * compute it a second time.
     *
     * @param array $attempts Attempt rows.
     * @param string $risklevel Band key, or '' for any band.
     * @param int $riskmin Lowest score to include.
     * @param int $riskmax Highest score to include.
     * @return array The surviving attempt rows, each carrying the risk result it was judged on.
     */
    private static function filter_by_risk(array $attempts, string $risklevel, int $riskmin, int $riskmax): array {
        $bandfilter = in_array($risklevel, ['low', 'moderate', 'high', 'critical'], true) ? $risklevel : '';
        $riskmin = max(0, $riskmin);

        // The upper bound is the highest score that can actually be reached, which is 100 only
        // while the score cap is on: with it off, a score is the sum of the factor caps and can run
        // past 100. Hard-coding 100 here would drop exactly the attempts a reviewer most wants -
        // asking for the Critical band would silently exclude a 135-point attempt.
        $scoremax = risk_calculator::max_possible_score();
        $unbounded = $riskmax < 0 || $riskmax >= $scoremax;
        if ($bandfilter === '' && $riskmin <= 0 && $unbounded) {
            return $attempts;
        }

        $scores = self::score_attempts($attempts);
        $kept = [];
        foreach ($attempts as $key => $a) {
            $risk = $scores[$key] ?? null;
            if ($risk === null) {
                continue;
            }
            if ($bandfilter !== '' && ($risk['levelkey'] ?? '') !== $bandfilter) {
                continue;
            }
            $score = (int)$risk['score'];
            if ($score < $riskmin || (!$unbounded && $score > $riskmax)) {
                continue;
            }
            $a['risk'] = $risk;
            $kept[$key] = $a;
        }

        return $kept;
    }

    /**
     * Score a set of attempt rows in one bulk pass.
     *
     * @param array $attempts Attempt rows keyed however the caller likes.
     * @return array Risk results keyed by the same keys.
     */
    private static function score_attempts(array $attempts): array {
        $requests = [];
        foreach ($attempts as $key => $a) {
            $requests[$key] = [
                'courseid' => (int)$a['courseid'],
                'cmid' => (int)$a['cmid'],
                'userid' => (int)$a['userid'],
                'reportid' => (int)$a['reportid'],
                'attemptid' => (int)$a['attemptid'],
            ];
        }

        return risk_calculator::calculate_many($requests);
    }

    /**
     * Build select options for the risk band filter.
     *
     * @param string $selected Selected band key ('' for any band).
     * @return array List of option rows for the template.
     */
    public static function risk_level_options(string $selected): array {
        ['moderate' => $moderate, 'high' => $high, 'critical' => $critical] = risk_calculator::get_level_boundaries();
        $labels = [
            '' => get_string('overallreport:riskany', 'quizaccess_proctoring'),
            'low' => get_string('riskscore:low', 'quizaccess_proctoring') . ' (0-' . ($moderate - 1) . ')',
            'moderate' => get_string('riskscore:moderate', 'quizaccess_proctoring') . ' (' . $moderate . '-' . ($high - 1) . ')',
            'high' => get_string('riskscore:high', 'quizaccess_proctoring') . ' (' . $high . '-' . ($critical - 1) . ')',
            'critical' => get_string('riskscore:critical', 'quizaccess_proctoring') . ' (' . $critical . '+)',
        ];
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'selected' => $value === $selected];
        }
        return $options;
    }

    /**
     * Count active risk holds among the given (already filtered) attempts.
     *
     * @param array $attemptids Quiz attempt ids in the current filtered result set.
     * @return int Active hold count.
     */
    private static function count_active_holds(array $attemptids): int {
        global $DB;

        if (empty($attemptids)) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'att');
        $params['status'] = \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
        return $DB->count_records_select(
            'quizaccess_proctoring_risk_holds',
            "status = :status AND attemptid {$insql}",
            $params
        );
    }

    /**
     * Map each of the given attempts to its most relevant risk-hold status.
     *
     * When an attempt has more than one hold row (rare), the most advanced decision wins, so the
     * review state reflects the latest reviewer action rather than a stale active row.
     *
     * @param array $attemptids Quiz attempt ids.
     * @return array<int, int> attemptid => risk-hold status constant.
     */
    private static function hold_states(array $attemptids): array {
        global $DB;

        if (empty($attemptids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'att');
        $records = $DB->get_records_select(
            'quizaccess_proctoring_risk_holds',
            "attemptid {$insql}",
            $params,
            'id ASC',
            'id, attemptid, status'
        );

        // Priority so a later decision (confirmed/auto-failed/released) beats a lingering active row.
        $priority = [
            \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE => 1,
            \QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED => 2,
            \QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED => 3,
            \QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED => 3,
        ];
        $states = [];
        $ranks = [];
        foreach ($records as $r) {
            $attemptid = (int)$r->attemptid;
            $status = (int)$r->status;
            $rank = $priority[$status] ?? 0;
            if (!isset($ranks[$attemptid]) || $rank >= $ranks[$attemptid]) {
                $ranks[$attemptid] = $rank;
                $states[$attemptid] = $status;
            }
        }
        return $states;
    }

    /**
     * Count attempt-level AI reviews that flagged any of the given (already filtered) attempts.
     *
     * @param array $attemptids Quiz attempt ids in the current filtered result set.
     * @return int Flagged AI review count.
     */
    private static function count_ai_flagged(array $attemptids): int {
        global $DB;

        if (empty($attemptids)) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED, 'att');
        $params['reviewtype'] = 'attempt';
        $params['status'] = \QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE;
        $params['decision'] = 'highly_suspicious';
        return $DB->count_records_select(
            'quizaccess_proctoring_ai_reviews',
            "reviewtype = :reviewtype AND status = :status AND decision = :decision AND attemptid {$insql}",
            $params
        );
    }

    /**
     * Decorate the visible page of attempts with names, risk score, AI review, hold and links.
     *
     * @param array $pagerows Raw attempt rows for the current page.
     * @param bool $canmanageholds Whether the viewer may release or confirm risk holds.
     * @param array $filterparams Current filter params, echoed onto hold action URLs to return here.
     * @return array Template-ready row data.
     */
    private static function decorate_rows(array $pagerows, bool $canmanageholds, array $filterparams): array {
        global $DB;

        if (empty($pagerows)) {
            return [];
        }

        $aisettings = quizaccess_proctoring_get_ai_review_settings();
        $coursecache = [];
        $quizcache = [];
        $rows = [];

        // One bulk scoring pass for the page. Rows a risk filter already scored keep that result.
        $unscored = array_filter($pagerows, function ($a) {
            return !isset($a['risk']);
        });
        $pagescores = !empty($unscored) ? self::score_attempts($unscored) : [];

        // The reviewers named on signed-off rows, in one query rather than one per row.
        $reviewerids = [];
        foreach ($pagerows as $a) {
            if (!empty($a['signoff'])) {
                $reviewerids[(int)$a['signoff']->reviewerid] = true;
            }
        }
        $reviewers = !empty($reviewerids)
            ? $DB->get_records_list('user', 'id', array_keys($reviewerids))
            : [];

        foreach ($pagerows as $rowkey => $a) {
            if (!isset($coursecache[$a['courseid']])) {
                $shortname = $DB->get_field('course', 'shortname', ['id' => $a['courseid']]);
                $coursecache[$a['courseid']] = $shortname ? format_string($shortname) : ('#' . $a['courseid']);
            }
            if (!isset($quizcache[$a['cmid']])) {
                $cm = get_coursemodule_from_id('quiz', $a['cmid'], 0, false, IGNORE_MISSING);
                $quizcache[$a['cmid']] = $cm ? format_string($cm->name) : ('#' . $a['cmid']);
            }

            $risk = $a['risk'] ?? $pagescores[$rowkey];
            $aireview = quizaccess_proctoring_get_ai_review(
                $a['courseid'],
                $a['cmid'],
                $a['userid'],
                $a['attemptid'],
                $a['reportid']
            );
            $aidata = $aireview ? quizaccess_proctoring_format_ai_review_for_template($aireview, $aisettings) : null;
            $hold = quizaccess_proctoring_get_risk_hold(
                $a['courseid'],
                $a['cmid'],
                $a['userid'],
                $a['attemptid'],
                $a['reportid']
            );

            $viewurl = new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
                'courseid' => $a['courseid'],
                'cmid' => $a['cmid'],
                'studentid' => $a['userid'],
                'reportid' => $a['reportid'],
            ]);
            $userurl = new moodle_url('/user/view.php', ['id' => $a['userid'], 'course' => $a['courseid']]);

            // A flagged attempt has no hold, so it gets the sign-off action instead of release and
            // escalate; a signed-off one gets the undo. Both re-check the capability on the course.
            $signoff = $a['signoff'] ?? null;
            $cansignoff = $canmanageholds && ($a['reviewstate'] ?? '') === 'flagged';
            $signoffurl = '';
            $undosignoffurl = '';
            if ($cansignoff) {
                // Row-scoped ids carry a prefix: plain 'courseid' is this page's course filter, and
                // reusing it here would silently re-filter the whole report on one row's course.
                $signoffurl = (new moodle_url(
                    '/mod/quiz/accessrule/proctoring/overall_reports.php',
                    $filterparams + [
                        'action' => 'signoff',
                        'rowcourseid' => (int)$a['courseid'],
                        'rowcmid' => (int)$a['cmid'],
                        'rowuserid' => (int)$a['userid'],
                        'rowattemptid' => (int)$a['attemptid'],
                        'rowreportid' => (int)$a['reportid'],
                        'sesskey' => sesskey(),
                    ]
                ))->out(false);
            }
            if ($signoff !== null && $canmanageholds) {
                $undosignoffurl = (new moodle_url(
                    '/mod/quiz/accessrule/proctoring/overall_reports.php',
                    $filterparams + [
                        'action' => 'undosignoff',
                        'signoffid' => (int)$signoff->id,
                        'sesskey' => sesskey(),
                    ]
                ))->out(false);
            }
            $signofflabel = '';
            if ($signoff !== null) {
                $reviewer = $reviewers[(int)$signoff->reviewerid] ?? null;
                $signofflabel = get_string('overallreport:signedoffby', 'quizaccess_proctoring', (object)[
                    'reviewer' => $reviewer
                        ? fullname($reviewer)
                        : get_string('overallreport:unknownuser', 'quizaccess_proctoring'),
                    'date' => userdate((int)$signoff->timecreated),
                ]);
            }

            $holdactive = $hold && (int)$hold->status === \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
            $canact = $holdactive && $canmanageholds;
            $releaseurl = '';
            $confirmurl = '';
            if ($canact) {
                $releaseurl = (new moodle_url(
                    '/mod/quiz/accessrule/proctoring/overall_reports.php',
                    $filterparams + ['action' => 'release', 'holdid' => (int)$hold->id, 'sesskey' => sesskey()]
                ))->out(false);
                $confirmurl = (new moodle_url(
                    '/mod/quiz/accessrule/proctoring/overall_reports.php',
                    $filterparams + ['action' => 'confirm', 'holdid' => (int)$hold->id, 'sesskey' => sesskey()]
                ))->out(false);
            }

            // Derive the certificate label from live hold + gradebook state so it never shows a
            // stale "held" label after a release/grade (Requirements 2.1, 2.2, 2.3).
            $cert = quizaccess_proctoring_resolve_certificate_label(
                (int)$a['courseid'],
                (int)$a['cmid'],
                (int)$a['userid'],
                (int)$a['attemptid'],
                (int)$a['reportid']
            );

            // Detected-signals and score-breakdown come from the scored risk factors that fired.
            $factors = isset($risk['factors']) && is_array($risk['factors']) ? $risk['factors'] : [];
            $breakdown = [];
            $signals = [];
            foreach ($factors as $factor) {
                if (empty($factor['haspoints'])) {
                    continue;
                }
                $breakdown[] = [
                    'factor' => $factor['label'],
                    'pts' => '+' . (int)$factor['points'],
                ];
                $signals[] = (int)$factor['count'] > 1
                    ? $factor['label'] . ' (' . (int)$factor['count'] . ')'
                    : $factor['label'];
            }
            $signalsummary = !empty($signals)
                ? implode(' · ', array_slice($signals, 0, 3))
                : get_string('overallreport:nosignals', 'quizaccess_proctoring');

            $reviewstate = $a['reviewstate'] ?? 'clean';
            $statuslabels = [
                'needs' => get_string('overallreport:status_needs', 'quizaccess_proctoring'),
                'flagged' => get_string('overallreport:status_flagged', 'quizaccess_proctoring'),
                'reviewed' => get_string('overallreport:status_reviewed', 'quizaccess_proctoring'),
                'escalated' => get_string('overallreport:status_escalated', 'quizaccess_proctoring'),
                'clean' => get_string('overallreport:status_clean', 'quizaccess_proctoring'),
            ];

            $rows[] = [
                // Name and email come from the set-wide pass in build(), so what the list shows is
                // exactly what the search matched and the name/email sorts ordered on.
                'fullname' => $a['fullname'],
                'email' => $a['email'],
                'userurl' => $userurl->out(false),
                'course' => $coursecache[$a['courseid']],
                'quiz' => $quizcache[$a['cmid']],
                'lastactivity' => $a['lastactivity'] > 0 ? userdate($a['lastactivity']) : '',
                'riskscore' => $risk['score'],
                'risklevel' => $risk['level'],
                'levelkey' => $risk['levelkey'] ?? '',
                'riskbadgeclass' => $risk['badgeclass'],
                'violations' => $a['violations'],
                // The list now shows this count, because sorting and filtering on a number the
                // reader cannot see is guesswork.
                'eventcountlabel' => get_string(
                    'overallreport:detectedevents',
                    'quizaccess_proctoring',
                    $a['violations']
                ),
                'eventcount' => $a['eventcount'],
                'facemismatch' => $a['facemismatch'],
                'violationsbreakdown' => get_string('overallreport:violationsbreakdown', 'quizaccess_proctoring', (object)[
                    'events' => $a['eventcount'],
                    'face' => $a['facemismatch'],
                ]),
                'signalsummary' => $signalsummary,
                'signals' => $breakdown,
                'hassignals' => !empty($breakdown),
                'breakdown' => $breakdown,
                'reviewstate' => $reviewstate,
                'statuslabel' => $statuslabels[$reviewstate] ?? $statuslabels['clean'],
                'isneeds' => $reviewstate === 'needs',
                'isclean' => $reviewstate === 'clean',
                'aireview' => $aidata,
                'holdlabel' => $hold ? $cert['label'] : '',
                'hashold' => (bool)$hold,
                'canact' => $canact,
                'releaseurl' => $releaseurl,
                'confirmurl' => $confirmurl,
                'cansignoff' => $cansignoff,
                'signoffurl' => $signoffurl,
                'undosignoffurl' => $undosignoffurl,
                'signofflabel' => $signofflabel,
                'viewurl' => $viewurl->out(false),
            ];
        }

        return $rows;
    }

    /**
     * Build the cross-course held-certificate dashboard.
     *
     * Lists every attempt whose certificate label currently resolves to "held" across all courses.
     * Because the label is derived live from the hold + gradebook state on each render (via C2's
     * {@see quizaccess_proctoring_resolve_certificate_label()}), the dashboard always reflects the
     * current state whenever a hold is created or its status changes in any course
     * (Requirements 17.1, 17.2).
     *
     * @param int $page Zero-based page number.
     * @return array Template-ready dashboard data.
     */
    public static function held_certificates(int $page = 0): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        // Pull active holds across every course, newest-first, bounded by MAX_ATTEMPTS to cap load.
        $holds = $DB->get_records(
            'quizaccess_proctoring_risk_holds',
            ['status' => \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE],
            'timecreated DESC, id DESC',
            'id, courseid, quizid, quizinstance, userid, attemptid, reportid, riskscore, status, timecreated',
            0,
            self::MAX_ATTEMPTS
        );
        $truncated = count($holds) >= self::MAX_ATTEMPTS;

        // Keep only attempts whose certificate label currently resolves to "held". The resolver
        // reconciles the live hold + gradebook state, so a released/graded attempt is excluded even
        // if a stale hold row remains.
        $held = [];
        foreach ($holds as $hold) {
            $cert = quizaccess_proctoring_resolve_certificate_label(
                (int)$hold->courseid,
                (int)$hold->quizid,
                (int)$hold->userid,
                (int)$hold->attemptid,
                (int)$hold->reportid
            );
            if ($cert['state'] !== 'held') {
                continue;
            }
            $held[] = [
                'courseid' => (int)$hold->courseid,
                'cmid' => (int)$hold->quizid,
                'userid' => (int)$hold->userid,
                'attemptid' => (int)$hold->attemptid,
                'reportid' => (int)$hold->reportid,
                'riskscore' => (int)$hold->riskscore,
                'timecreated' => (int)$hold->timecreated,
                'cert' => $cert,
            ];
        }

        // Paginate the filtered set (already newest-first from the query order).
        $total = count($held);
        $totalpages = (int)ceil($total / self::PER_PAGE);
        if ($totalpages > 0 && $page > $totalpages - 1) {
            $page = $totalpages - 1;
        }
        $page = max(0, $page);
        $pagerows = array_slice($held, $page * self::PER_PAGE, self::PER_PAGE);

        return [
            'rows' => self::decorate_held_rows($pagerows),
            'hasrows' => !empty($pagerows),
            'total' => $total,
            'page' => $page,
            'perpage' => self::PER_PAGE,
            'truncated' => $truncated,
        ];
    }

    /**
     * Decorate the visible page of held certificates with names, risk score, label and links.
     *
     * Mirrors {@see self::decorate_rows()}: batch-loads user records and caches course/quiz names,
     * computes the live risk score and links each row to the per-attempt report.
     *
     * @param array $pagerows Raw held-certificate rows for the current page.
     * @return array Template-ready row data.
     */
    private static function decorate_held_rows(array $pagerows): array {
        global $DB;

        if (empty($pagerows)) {
            return [];
        }

        $userids = array_values(array_unique(array_map(function ($a) {
            return $a['userid'];
        }, $pagerows)));
        $users = $DB->get_records_list('user', 'id', $userids);
        $coursecache = [];
        $quizcache = [];
        $rows = [];

        // Same bulk scoring pass as the attempts list: this page shows live scores too.
        $pagescores = self::score_attempts($pagerows);

        foreach ($pagerows as $rowkey => $a) {
            $user = $users[$a['userid']] ?? null;
            if (!isset($coursecache[$a['courseid']])) {
                $shortname = $DB->get_field('course', 'shortname', ['id' => $a['courseid']]);
                $coursecache[$a['courseid']] = $shortname ? format_string($shortname) : ('#' . $a['courseid']);
            }
            if (!isset($quizcache[$a['cmid']])) {
                $cm = get_coursemodule_from_id('quiz', $a['cmid'], 0, false, IGNORE_MISSING);
                $quizcache[$a['cmid']] = $cm ? format_string($cm->name) : ('#' . $a['cmid']);
            }

            $risk = $pagescores[$rowkey];

            $viewurl = new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
                'courseid' => $a['courseid'],
                'cmid' => $a['cmid'],
                'studentid' => $a['userid'],
                'reportid' => $a['reportid'],
            ]);
            $userurl = new moodle_url('/user/view.php', ['id' => $a['userid'], 'course' => $a['courseid']]);

            $rows[] = [
                'fullname' => $user ? fullname($user) : get_string('overallreport:unknownuser', 'quizaccess_proctoring'),
                'userurl' => $userurl->out(false),
                'course' => $coursecache[$a['courseid']],
                'quiz' => $quizcache[$a['cmid']],
                'heldsince' => $a['timecreated'] > 0 ? userdate($a['timecreated']) : '',
                'riskscore' => $risk['score'],
                'risklevel' => $risk['level'],
                'riskbadgeclass' => $risk['badgeclass'],
                'holdlabel' => $a['cert']['label'],
                'holdclass' => $a['cert']['class'],
                'viewurl' => $viewurl->out(false),
            ];
        }

        return $rows;
    }
}
