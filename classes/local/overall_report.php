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
            'violations' => get_string('overallreport:sortviolations', 'quizaccess_proctoring'),
            'recent' => get_string('overallreport:sortrecent', 'quizaccess_proctoring'),
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
     * @param string $sort Sort key: 'violations' or 'recent'.
     * @param int $page Zero-based page number.
     * @return array Template-ready report data.
     */
    public static function build(int $courseid, string $range, int $minviolations, string $sort, int $page): array {
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

        // Summary counters across the whole filtered set.
        $students = [];
        $courses = [];
        $withviolations = 0;
        foreach ($attempts as $a) {
            $students[$a['userid']] = true;
            $courses[$a['courseid']] = true;
            if ($a['violations'] > 0) {
                $withviolations++;
            }
        }

        $summary = [
            'totalattempts' => count($attempts),
            'students' => count($students),
            'courses' => count($courses),
            'withviolations' => $withviolations,
            'activeholds' => self::count_active_holds($courseid, $fromtime),
            'aiflagged' => self::count_ai_flagged($courseid, $fromtime),
        ];

        // Sort, then paginate.
        $attempts = array_values($attempts);
        if ($sort === 'recent') {
            usort($attempts, function ($a, $b) {
                return $b['lastactivity'] <=> $a['lastactivity'];
            });
        } else {
            usort($attempts, function ($a, $b) {
                return [$b['violations'], $b['lastactivity']] <=> [$a['violations'], $a['lastactivity']];
            });
        }

        $total = count($attempts);
        $totalpages = (int)ceil($total / self::PER_PAGE);
        if ($totalpages > 0 && $page > $totalpages - 1) {
            $page = $totalpages - 1;
        }
        $page = max(0, $page);
        $pagerows = array_slice($attempts, $page * self::PER_PAGE, self::PER_PAGE);

        return [
            'summary' => $summary,
            'rows' => self::decorate_rows($pagerows),
            'hasrows' => !empty($pagerows),
            'truncated' => $truncated,
            'total' => $total,
            'page' => $page,
            'perpage' => self::PER_PAGE,
        ];
    }

    /**
     * Count active risk holds within the filter window.
     *
     * @param int $courseid Course filter (0 for all).
     * @param int $fromtime Inclusive lower bound on hold creation time (0 for all time).
     * @return int Active hold count.
     */
    private static function count_active_holds(int $courseid, int $fromtime): int {
        global $DB;

        $where = 'status = :status';
        $params = ['status' => \QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE];
        if ($courseid > 0) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($fromtime > 0) {
            $where .= ' AND timecreated >= :fromtime';
            $params['fromtime'] = $fromtime;
        }
        return $DB->count_records_select('quizaccess_proctoring_risk_holds', $where, $params);
    }

    /**
     * Count attempt-level AI reviews that flagged the attempt within the filter window.
     *
     * @param int $courseid Course filter (0 for all).
     * @param int $fromtime Inclusive lower bound on review creation time (0 for all time).
     * @return int Flagged AI review count.
     */
    private static function count_ai_flagged(int $courseid, int $fromtime): int {
        global $DB;

        $where = 'reviewtype = :reviewtype AND status = :status AND decision = :decision';
        $params = [
            'reviewtype' => 'attempt',
            'status' => \QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE,
            'decision' => 'highly_suspicious',
        ];
        if ($courseid > 0) {
            $where .= ' AND courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($fromtime > 0) {
            $where .= ' AND timecreated >= :fromtime';
            $params['fromtime'] = $fromtime;
        }
        return $DB->count_records_select('quizaccess_proctoring_ai_reviews', $where, $params);
    }

    /**
     * Decorate the visible page of attempts with names, risk score, AI review, hold and links.
     *
     * @param array $pagerows Raw attempt rows for the current page.
     * @return array Template-ready row data.
     */
    private static function decorate_rows(array $pagerows): array {
        global $DB;

        if (empty($pagerows)) {
            return [];
        }

        $userids = array_values(array_unique(array_map(function ($a) {
            return $a['userid'];
        }, $pagerows)));
        $users = $DB->get_records_list('user', 'id', $userids);
        $aisettings = quizaccess_proctoring_get_ai_review_settings();
        $coursecache = [];
        $quizcache = [];
        $rows = [];

        foreach ($pagerows as $a) {
            $user = $users[$a['userid']] ?? null;
            if (!isset($coursecache[$a['courseid']])) {
                $shortname = $DB->get_field('course', 'shortname', ['id' => $a['courseid']]);
                $coursecache[$a['courseid']] = $shortname ? format_string($shortname) : ('#' . $a['courseid']);
            }
            if (!isset($quizcache[$a['cmid']])) {
                $cm = get_coursemodule_from_id('quiz', $a['cmid'], 0, false, IGNORE_MISSING);
                $quizcache[$a['cmid']] = $cm ? format_string($cm->name) : ('#' . $a['cmid']);
            }

            $risk = quizaccess_proctoring_calculate_attempt_risk(
                $a['courseid'],
                $a['cmid'],
                $a['userid'],
                $a['reportid']
            );
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

            $rows[] = [
                'fullname' => $user ? fullname($user) : get_string('overallreport:unknownuser', 'quizaccess_proctoring'),
                'userurl' => $userurl->out(false),
                'course' => $coursecache[$a['courseid']],
                'quiz' => $quizcache[$a['cmid']],
                'lastactivity' => $a['lastactivity'] > 0 ? userdate($a['lastactivity']) : '',
                'riskscore' => $risk['score'],
                'risklevel' => $risk['level'],
                'riskbadgeclass' => $risk['badgeclass'],
                'violations' => $a['violations'],
                'eventcount' => $a['eventcount'],
                'facemismatch' => $a['facemismatch'],
                'violationsbreakdown' => get_string('overallreport:violationsbreakdown', 'quizaccess_proctoring', (object)[
                    'events' => $a['eventcount'],
                    'face' => $a['facemismatch'],
                ]),
                'aireview' => $aidata,
                'holdlabel' => $hold ? quizaccess_proctoring_get_risk_hold_status_label($hold) : '',
                'hashold' => (bool)$hold,
                'viewurl' => $viewurl->out(false),
            ];
        }

        return $rows;
    }
}
