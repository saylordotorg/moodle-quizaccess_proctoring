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
 * Informational monitoring coverage, separate from misconduct scoring.
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Records expected channels once and compares them with evidence actually received by the server.
 */
final class monitoring_coverage {
    /** Neutral event types excluded from suspicious activity and AI review. */
    public const NEUTRAL_EVENTS = ['monitoring_started', 'screen_capture', 'phone_detection_started'];

    /**
     * Record the initial policy when Moodle creates a real, proctored attempt.
     *
     * Page-load fallbacks cannot establish a new screen collection policy for older attempts.
     * The mobile decision matches the access rule's effective desktop capture policy.
     *
     * @param \mod_quiz\event\attempt_started $event A newly created attempt.
     */
    public static function attempt_started(\mod_quiz\event\attempt_started $event): void {
        global $DB;
        $courseid = (int)$event->courseid;
        $cmid = (int)$event->contextinstanceid;
        $userid = (int)$event->relateduserid;
        $attemptid = (int)$event->objectid;
        $cm = get_coursemodule_from_id('quiz', $cmid, $courseid, false, IGNORE_MISSING);
        if (
            !$cm || !$DB->record_exists('quizaccess_proctoring', ['quizid' => $cm->instance, 'proctoringrequired' => 1]) ||
                !$DB->record_exists('quiz_attempts', [
                    'id' => $attemptid, 'quiz' => $cm->instance, 'userid' => $userid, 'preview' => 0,
                ])
        ) {
            return;
        }

        $desktopsetting = get_config('quizaccess_proctoring', 'captureviolationdesktop');
        $desktop = $desktopsetting === false || $desktopsetting === null || $desktopsetting === '' ||
            (int)$desktopsetting === 1;
        $mobilemode = get_config('quizaccess_proctoring', 'mobilescreensharemode');
        if (!in_array($mobilemode, ['bypass', 'require', 'block'], true)) {
            $mobilemode = 'bypass';
        }
        if (
            in_array(\core_useragent::get_device_type(), [
                \core_useragent::DEVICETYPE_MOBILE, \core_useragent::DEVICETYPE_TABLET,
            ], true) && $mobilemode === 'bypass'
        ) {
            $desktop = false;
        }
        self::start_attempt(
            $courseid,
            $cmid,
            $userid,
            $attemptid,
            true,
            $desktop && (int)get_config('quizaccess_proctoring', 'monitoringcoveragescreens') === 1,
            (int)get_config('quizaccess_proctoring', 'autoreconfigurecamshotdelay') ?: 30
        );
    }

    /**
     * Snapshot the expected channels once per attempt, preserving the policy at monitoring start.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Quiz attempt ID.
     * @param bool $webcam Whether webcam captures are expected.
     * @param bool $screen Whether screen captures are expected.
     * @param int $interval Expected capture interval in seconds.
     * @return array Original monitoring policy, or an empty array when it could not be recorded.
     */
    public static function start_attempt(
        int $courseid,
        int $cmid,
        int $userid,
        int $attemptid,
        bool $webcam,
        bool $screen,
        int $interval
    ): array {
        global $DB;
        $attempt = self::get_attempt($courseid, $cmid, $userid, $attemptid);
        if (!$attempt || !in_array($attempt->state, ['inprogress', 'overdue'], true)) {
            return [];
        }
        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring');
        $lock = $factory->get_lock('coverage:' . $attemptid, 5);
        if (!$lock) {
            // Missing instrumentation must not prevent a student continuing their exam.
            return [];
        }
        try {
            $scope = ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid,
                'attemptid' => $attemptid, 'eventtype' => 'monitoring_started'];
            $snapshots = $DB->get_records('quizaccess_proctoring_events', $scope, 'id ASC', 'id, eventdetail', 0, 1);
            if (!$snapshots) {
                $policy = ['version' => 1, 'webcam' => $webcam, 'screen' => $screen,
                    'interval' => max(5, $interval ?: 30)];
                $DB->insert_record('quizaccess_proctoring_events', (object)($scope + [
                    'eventdetail' => json_encode($policy),
                    'timemodified' => time(),
                ]));
                return $policy;
            }
            $snapshot = reset($snapshots);
            $policy = json_decode($snapshot->eventdetail, true);
            return is_array($policy) && ($policy['version'] ?? 0) === 1 &&
                isset($policy['interval'], $policy['webcam'], $policy['screen']) ? $policy : [];
        } finally {
            $lock->release();
        }
    }

    /**
     * Add the browser monitors the first attempt page ran with to the attempt's snapshot (CPIT-467).
     *
     * The attempt-start snapshot is written before any attempt page exists, so it cannot know which
     * in-browser monitors that page will run, or in which browser. The first attempt page load fills
     * them in once; later loads leave them alone, like the rest of the snapshot. Without this a
     * report cannot tell "nothing was detected" from "nothing was watching".
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Quiz attempt ID.
     * @param array $monitors Monitor name => whether the page runs it.
     * @param string $useragent The student's user agent, reduced to a browser and OS before storing.
     */
    public static function record_monitors(
        int $courseid,
        int $cmid,
        int $userid,
        int $attemptid,
        array $monitors,
        string $useragent
    ): void {
        global $DB;
        if ($attemptid <= 0) {
            return;
        }
        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring');
        $lock = $factory->get_lock('coverage:' . $attemptid, 5);
        if (!$lock) {
            return;
        }
        try {
            $snapshots = $DB->get_records('quizaccess_proctoring_events', [
                'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid,
                'attemptid' => $attemptid, 'eventtype' => 'monitoring_started',
            ], 'id ASC', 'id, eventdetail', 0, 1);
            $snapshot = $snapshots ? reset($snapshots) : null;
            $policy = $snapshot ? json_decode($snapshot->eventdetail, true) : null;
            if (!is_array($policy) || ($policy['version'] ?? 0) !== 1 || isset($policy['monitors'])) {
                return;
            }
            $policy['monitors'] = array_map('boolval', $monitors);
            $policy += self::describe_browser($useragent);
            $DB->set_field('quizaccess_proctoring_events', 'eventdetail', json_encode($policy), ['id' => $snapshot->id]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Reduce a user agent to a browser name with its major version, and an operating system.
     *
     * Only these two are kept: they are what a reviewer needs to know which checks could run (the
     * monitor count, for example, is only reported by Chromium browsers), and nothing more of the
     * user agent string is stored.
     *
     * @param string $useragent User agent string.
     * @return array 'browser' and 'os', each an empty string when not recognised.
     */
    public static function describe_browser(string $useragent): array {
        $browsers = [
            'Edge' => '~\bEdg(?:e|A|iOS)?/(\d+)~',
            'Opera' => '~\b(?:OPR|Opera)/(\d+)~',
            'Samsung Internet' => '~\bSamsungBrowser/(\d+)~',
            'Firefox' => '~\b(?:Firefox|FxiOS)/(\d+)~',
            'Chrome' => '~\b(?:Chrome|CriOS)/(\d+)~',
            'Safari' => '~\bVersion/(\d+)[.\d]* (?:Mobile/\S+ )?Safari/~',
        ];
        $browser = '';
        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $useragent, $matches)) {
                $browser = $name . ' ' . $matches[1];
                break;
            }
        }
        $systems = [
            'iOS' => '~\b(?:iPhone|iPad|iPod)\b~',
            'Android' => '~\bAndroid\b~',
            'ChromeOS' => '~\bCrOS\b~',
            'Windows' => '~\bWindows\b~',
            'macOS' => '~\bMac OS X\b~',
            'Linux' => '~\bLinux\b~',
        ];
        $os = '';
        foreach ($systems as $name => $pattern) {
            if (preg_match($pattern, $useragent)) {
                $os = $name;
                break;
            }
        }
        return ['browser' => $browser, 'os' => $os];
    }

    /**
     * Load one scoped attempt; callers remain responsible for report-view permission.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @return \stdClass|false Attempt record.
     */
    private static function get_attempt(int $courseid, int $cmid, int $userid, int $attemptid) {
        global $DB;
        if ($attemptid <= 0) {
            return false;
        }
        return $DB->get_record_sql(
            "SELECT qa.id, qa.timestart, qa.timefinish, qa.state
                  FROM {quiz_attempts} qa
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {course_modules} cm ON cm.instance = q.id AND cm.course = q.course
                  JOIN {modules} m ON m.id = cm.module AND m.name = :quizmodule
                 WHERE qa.id = :attemptid AND qa.userid = :userid AND cm.id = :cmid AND q.course = :courseid",
            ['attemptid' => $attemptid, 'userid' => $userid, 'cmid' => $cmid,
            'courseid' => $courseid,
            'quizmodule' => 'quiz']
        );
    }

    /**
     * Build a reviewer-only detail panel using four bounded, attempt-scoped reads, never a per-row list scan.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @param int|null $now Server time used for an in-progress attempt.
     * @return array Template context; legacy attempts explicitly have unknown expectations.
     */
    public static function for_attempt(int $courseid, int $cmid, int $userid, int $attemptid, ?int $now = null): array {
        global $DB;
        $base = ['available' => false, 'channels' => [], 'legacy' => true];
        $attempt = self::get_attempt($courseid, $cmid, $userid, $attemptid);
        if (!$attempt || !in_array($attempt->state, ['inprogress', 'overdue', 'finished'], true)) {
            return $base;
        }
        $scope = ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid];
        $snapshots = $DB->get_records(
            'quizaccess_proctoring_events',
            $scope + ['eventtype' => 'monitoring_started'],
            'id ASC',
            'id, eventdetail, timemodified',
            0,
            1
        );
        $snapshot = $snapshots ? reset($snapshots) : null;
        $policy = $snapshot ? json_decode($snapshot->eventdetail, true) : null;
        if (!$policy || ($policy['version'] ?? 0) !== 1 || !isset($policy['interval'], $policy['webcam'], $policy['screen'])) {
            return $base;
        }
        $start = max((int)$attempt->timestart, (int)$snapshot->timemodified);
        $end = $attempt->state === 'finished' ? (int)$attempt->timefinish : ($now ?? time());
        $end = max($start, $end);
        $interval = max(5, (int)$policy['interval']);
        $logs = $DB->get_records('quizaccess_proctoring_logs', [
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid,
            'status' => $attemptid, 'deletionprogress' => 0,
        ], 'timemodified ASC', 'id, capturedat, timemodified, webcampicture');
        $events = $DB->get_records(
            'quizaccess_proctoring_events',
            $scope + ['eventtype' => 'screen_capture'],
            'timemodified ASC',
            'id, capturedat, timemodified, screenshoturl'
        );
        $channels = [];
        foreach (['webcam' => [$logs, 'webcampicture'], 'screen' => [$events, 'screenshoturl']] as $channel => [$rows, $field]) {
            $captures = array_values(array_filter($rows, static function (\stdClass $row) use ($field): bool {
                return !empty($row->$field);
            }));
            $summary = self::summarize($start, $end, $interval, $captures);
            $summary['label'] = get_string('coverage:' . $channel, 'quizaccess_proctoring');
            $summary['required'] = (bool)$policy[$channel];
            $summary['status'] = get_string(!$policy[$channel] ? 'coverage:notrequired' :
                ($summary['gapcount'] > 0 ? 'coverage:missing' : 'coverage:recorded'), 'quizaccess_proctoring');
            $summary['missingduration'] = format_time($summary['missingseconds']);
            $summary['gaprows'] = [];
            foreach (array_slice($summary['gaps'], 0, 20) as $gap) {
                $summary['gaprows'][] = ['from' => display_time::staff($gap['start']),
                    'to' => display_time::staff($gap['end']), 'duration' => format_time($gap['seconds'])];
            }
            $summary['hasgaps'] = !empty($summary['gaprows']);
            $summary['moregaps'] = $summary['gapcount'] > 20;
            $channels[] = $summary;
        }
        return ['available' => true, 'legacy' => false, 'channels' => $channels,
            'from' => display_time::staff($start), 'to' => display_time::staff($end), 'interval' => $interval,
            'partial' => $start > (int)$attempt->timestart + $interval,
            'ongoing' => $attempt->state !== 'finished'];
    }

    /**
     * Identify intervals without timely periodic samples, allowing one cadence either side of a sample.
     *
     * This estimates gaps in received evidence; it cannot prove whether the camera or screen was active.
     * Browser capture times are advisory. Legacy rows fall back to the server receipt time and are counted.
     *
     * @param int $start Beginning of the snapshotted monitoring period.
     * @param int $end End of the monitoring period.
     * @param int $interval Expected cadence in seconds.
     * @param array $captures Records with capturedat and timemodified values.
     * @return array Counts and gap intervals, with no risk score or misconduct decision.
     */
    public static function summarize(int $start, int $end, int $interval, array $captures): array {
        $interval = max(5, $interval);
        $end = max($start, $end);
        $times = [];
        $delayed = 0;
        $legacy = 0;
        foreach ($captures as $capture) {
            $arrival = (int)$capture->timemodified;
            $captured = (int)($capture->capturedat ?? 0);
            $time = $captured > 0 ? min($captured, $arrival) : $arrival;
            if ($time < $start || $time > $end) {
                continue;
            }
            $times[] = $time;
            $legacy += $captured <= 0 ? 1 : 0;
            $delayed += $captured > 0 && $arrival - $captured > 10 ? 1 : 0;
        }
        sort($times, SORT_NUMERIC);
        // Allow the initial scheduled capture to become due before reporting a gap.
        $cursor = min($end, $start + $interval);
        $gaps = [];
        foreach ($times as $time) {
            $left = max($start, $time - $interval);
            if ($left > $cursor) {
                $gaps[] = ['start' => $cursor, 'end' => $left, 'seconds' => $left - $cursor];
            }
            $cursor = max($cursor, min($end, $time + $interval));
        }
        if ($cursor < $end) {
            $gaps[] = ['start' => $cursor, 'end' => $end, 'seconds' => $end - $cursor];
        }
        return ['capturecount' => count($times), 'delayedcount' => $delayed, 'legacycount' => $legacy,
            'gapcount' => count($gaps), 'missingseconds' => array_sum(array_column($gaps, 'seconds')), 'gaps' => $gaps];
    }
}
