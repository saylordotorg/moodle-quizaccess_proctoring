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
 * Which risk factor checks actually ran during an attempt (CPIT-467).
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Decides, per risk factor, whether an attempt with no evidence for it passed the check or was
 * simply never checked.
 *
 * A factor that scored nothing is only "passed" when its detector demonstrably ran during the
 * attempt. Everything else is "not monitored", with the reason, so the report never prints a clean
 * result ("Single face throughout", "One monitor detected") for a check that was not watching.
 * This never changes a score: evidence that exists is scored whatever this class says.
 */
final class factor_coverage {
    /** No detector exists for the factor, so it can never produce evidence. */
    public const REASON_NOT_BUILT = 'notbuilt';
    /** The factor, or the monitor that feeds it, was switched off. */
    public const REASON_SETTING_OFF = 'settingoff';
    /** The student's browser cannot report what the monitor needs. */
    public const REASON_BROWSER_UNSUPPORTED = 'browserunsupported';
    /** The browser sent no proctoring data at all, so no in-browser monitor can be shown to have run. */
    public const REASON_NO_BROWSER_DATA = 'nobrowserdata';
    /** The attempt predates monitor recording, so which monitors ran is unknown. */
    public const REASON_NOT_RECORDED = 'notrecorded';
    /** The detector was switched on but never reported that it started in the browser. */
    public const REASON_DETECTOR_FAILED = 'detectorfailed';
    /** No webcam capture was compared with the reference photo. */
    public const REASON_NOT_COMPARED = 'notcompared';
    /** No webcam capture was checked for a face. */
    public const REASON_NO_FACE_CHECK = 'nofacecheck';

    /** Factors with no detector anywhere in the plugin. */
    public const FACTORS_WITHOUT_DETECTOR = ['audio'];

    /**
     * Detectors that load a model in the browser and silently give up if they cannot: only an
     * event from the browser (it started, or it detected something) proves one ran.
     */
    private const DETECTOR_START_EVENTS = [
        'phonedetected' => ['phone_detection_started', 'phone_detected'],
        'multiplefaces' => ['multiple_faces_detection_started', 'multiple_faces_detected'],
    ];

    /**
     * The in-browser monitor each browser-side factor depends on. Every listed monitor must have
     * run for the factor to count as checked.
     */
    private const BROWSER_FACTOR_MONITORS = [
        'aitool' => ['activity'],
        'aitoolscreenshot' => ['activity', 'screen'],
        'clipboard' => ['clipboard'],
        'tabactivity' => ['activity'],
        'f12' => ['activity'],
        'shortcut' => ['activity'],
        'screenshare' => ['screen'],
        'multimonitor' => ['multimonitor'],
        'phonedetected' => ['phone'],
        'multiplefaces' => ['multiplefaces'],
    ];

    /**
     * Work out which factors were checked during one attempt.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Quiz attempt ID, or 0 when unknown.
     * @return array 'reasons' (factor key => reason, only for factors that were not checked),
     *               'browser' and 'os' (empty strings when not recorded).
     */
    public static function for_attempt(int $courseid, int $cmid, int $userid, int $attemptid): array {
        global $DB;

        $monitors = null;
        $browser = '';
        $os = '';
        $browserdata = false;
        $monitorunsupported = false;
        $detectorsstarted = [];
        // Unknown until the attempt's captures are counted; an attempt without an id has none to count.
        $comparedcount = -1;
        $facecheckcount = -1;

        if ($attemptid > 0) {
            $scope = ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid];
            $snapshots = $DB->get_records(
                'quizaccess_proctoring_events',
                $scope + ['eventtype' => 'monitoring_started'],
                'id ASC',
                'id, eventdetail',
                0,
                1
            );
            $policy = $snapshots ? json_decode(reset($snapshots)->eventdetail, true) : null;
            if (is_array($policy) && isset($policy['monitors']) && is_array($policy['monitors'])) {
                $monitors = $policy['monitors'];
                $browser = (string)($policy['browser'] ?? '');
                $os = (string)($policy['os'] ?? '');
            }

            // The unsupported notice is only logged on a change of state, so one is enough; a later
            // "multiple monitors" event shows detection did run at some point.
            $monitorunsupported = $DB->record_exists(
                'quizaccess_proctoring_events',
                $scope + ['eventtype' => 'monitor_detection_unavailable']
            ) && !$DB->record_exists(
                'quizaccess_proctoring_events',
                $scope + ['eventtype' => 'multiple_monitors_detected']
            );

            foreach (self::DETECTOR_START_EVENTS as $factorkey => $eventtypes) {
                [$typesql, $typeparams] = $DB->get_in_or_equal($eventtypes, SQL_PARAMS_NAMED, 'started');
                $detectorsstarted[$factorkey] = $DB->record_exists_select(
                    'quizaccess_proctoring_events',
                    'courseid = :courseid AND quizid = :quizid AND userid = :userid AND attemptid = :attemptid
                        AND eventtype ' . $typesql,
                    $scope + $typeparams
                );
            }

            $logwhere = 'courseid = :courseid AND quizid = :cmid AND userid = :userid
                AND status = :attemptid AND deletionprogress = 0';
            $logparams = ['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid];

            // Proof the proctoring script ran in the browser: a stored webcam capture, or any event
            // it sent. The attempt-start snapshot is written by the server, so it does not count.
            $browserdata = $DB->record_exists_select(
                'quizaccess_proctoring_logs',
                $logwhere . " AND COALESCE(webcampicture, '') <> ''",
                $logparams
            ) || $DB->record_exists_select(
                'quizaccess_proctoring_events',
                'courseid = :courseid AND quizid = :cmid AND userid = :userid AND attemptid = :attemptid
                    AND eventtype <> :snapshot',
                $logparams + ['snapshot' => 'monitoring_started']
            );

            $comparedcount = $DB->count_records_select('quizaccess_proctoring_logs', $logwhere . ' AND awsflag = 2', $logparams);
            $facecheckcount = $DB->count_records_select(
                'quizaccess_proctoring_logs',
                $logwhere . ' AND awsflag IN (2, 3)',
                $logparams
            ) + $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {quizaccess_proctoring_face_images} fi
                   JOIN {quizaccess_proctoring_logs} l ON l.id = fi.parentid
                  WHERE fi.parent_type = :parenttype
                    AND l.courseid = :courseid AND l.quizid = :cmid AND l.userid = :userid
                    AND l.status = :attemptid AND l.deletionprogress = 0",
                // Reference photos are face images too, but their parentid points at user_images.
                $logparams + ['parenttype' => 'camshot_image']
            );
        }

        $reasons = [];
        foreach (array_keys(risk_calculator::FACTOR_DEFAULTS) as $factorkey) {
            $reason = self::reason(
                $factorkey,
                $monitors,
                $browserdata,
                $monitorunsupported,
                $comparedcount,
                $facecheckcount,
                $detectorsstarted
            );
            if ($reason !== null) {
                $reasons[$factorkey] = $reason;
            }
        }

        return ['reasons' => $reasons, 'browser' => $browser, 'os' => $os];
    }

    /**
     * Why one factor was not checked, or null when it was.
     *
     * @param string $factorkey Factor key.
     * @param array|null $monitors Recorded in-browser monitors, or null for an attempt without them.
     * @param bool $browserdata Whether the browser sent any proctoring data during the attempt.
     * @param bool $monitorunsupported Whether the browser said it cannot count monitors.
     * @param int $comparedcount Webcam captures compared with the reference photo, or -1 when unknown.
     * @param int $facecheckcount Webcam captures checked for a face, or -1 when unknown.
     * @param array $detectorsstarted Factor key => whether the browser reported that its detector started.
     * @return string|null One of the REASON_* constants, or null when the check ran.
     */
    public static function reason(
        string $factorkey,
        ?array $monitors,
        bool $browserdata,
        bool $monitorunsupported,
        int $comparedcount,
        int $facecheckcount,
        array $detectorsstarted = []
    ): ?string {
        if (in_array($factorkey, self::FACTORS_WITHOUT_DETECTOR, true)) {
            return self::REASON_NOT_BUILT;
        }
        if ($factorkey === 'facemismatch') {
            return $comparedcount < 0 ? self::REASON_NOT_RECORDED
                : ($comparedcount > 0 ? null : self::REASON_NOT_COMPARED);
        }
        if ($factorkey === 'noface') {
            return $facecheckcount < 0 ? self::REASON_NOT_RECORDED
                : ($facecheckcount > 0 ? null : self::REASON_NO_FACE_CHECK);
        }
        if (!isset(self::BROWSER_FACTOR_MONITORS[$factorkey])) {
            // Checked by the server from data it holds: webcam captures missing, completion speed.
            return null;
        }
        if ($monitors === null) {
            return self::REASON_NOT_RECORDED;
        }
        foreach (self::BROWSER_FACTOR_MONITORS[$factorkey] as $monitor) {
            if (empty($monitors[$monitor])) {
                return self::REASON_SETTING_OFF;
            }
        }
        if (!$browserdata) {
            return self::REASON_NO_BROWSER_DATA;
        }
        if ($factorkey === 'multimonitor' && $monitorunsupported) {
            return self::REASON_BROWSER_UNSUPPORTED;
        }
        if (isset(self::DETECTOR_START_EVENTS[$factorkey]) && empty($detectorsstarted[$factorkey])) {
            return self::REASON_DETECTOR_FAILED;
        }
        return null;
    }
}
