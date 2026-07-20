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
 * Risk score calculation service.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Calculates proctoring risk scores and risk presentation data.
 *
 * Risk-Scoring Model
 * ------------------
 * The attempt risk score is the clamped sum of independent factors. Each factor scores a
 * distinct class of student-attributable evidence as `min(cap, count * pointsperevent)` via
 * {@see risk_calculator::build_factor()}, all factor points are summed, and the total is
 * clamped to a maximum of 100. The clamp can be disabled site-wide ("Cap attempt risk score
 * at 100" on the Risk factor scoring admin page, config `riskscorecapenabled`), in which case
 * the score is the raw factor sum. Factors never subtract, so adding suspicious evidence can
 * only hold the score steady or raise it (monotonicity — Requirement 16.2).
 *
 * Each factor's points-per-event and cap are site-configurable, and each factor can be disabled
 * outright, on the "Risk factor scoring" admin page (config keys `riskfactor_{key}_enabled`,
 * `riskfactor_{key}_points`, `riskfactor_{key}_cap`; see {@see risk_calculator::FACTOR_DEFAULTS}).
 * A disabled factor contributes nothing and is omitted from the risk score details. The values in
 * the canonical table below are the shipped defaults, used whenever no override is configured.
 *
 * The canonical table below mirrors the factors built in {@see risk_calculator::calculate_attempt()}:
 *
 * | Factor                     | Points/event | Cap | Evidence source                                                   |
 * |----------------------------|-------------:|----:|-------------------------------------------------------------------|
 * | Face mismatch              |           35 |  35 | logs with awsflag = 2 and awsscore < threshold                    |
 * | Multiple faces             |           30 |  30 | events: multiple_faces_detected                                   |
 * | No face (images/events)    |            8 |  24 | max(no-face face_images, awsflag = 3 logs) + face_missing/no_face_detected events |
 * | Screen-share issues        |           18 |  36 | events: screen_marker_missing, screen_share_stopped               |
 * | Multiple monitors          |           25 |  25 | events: multiple_monitors_detected                                |
 * | Possible AI tool           |           20 |  30 | events: possible_ai_tool                                          |
 * | AI tool w/ screenshot      |           15 |  30 | events: possible_ai_tool having a desktop screenshot              |
 * | Clipboard/context menu     |            8 |  24 | events: clipboard_copy, clipboard_cut, clipboard_paste, contextmenu |
 * | Tab/focus activity         |            5 |  20 | events: focus_lost, tab_hidden, page_exit                         |
 * | F12                        |           15 |  15 | shortcut events whose detail resolves to F12                      |
 * | Other keyboard shortcuts   |            8 |  24 | shortcut events that are NOT F12 (Alt+Tab, Ctrl+T/N/W/R/A/L, Ctrl+Shift+I/J/C, Ctrl+C/X/V) |
 * | Audio                      |            6 |  18 | events: audio_detected                                            |
 * | Webcam missing             |           15 |  15 | attempt has no stored webcam capture                              |
 * | Speed (optional)           |           25 |  25 | enabled + seconds-per-question below the configured floor         |
 *
 * Reconciliation with {@see overall_report::SUSPICIOUS_EVENT_TYPES} (Requirement 16.2)
 * -----------------------------------------------------------------------------------
 * Every browser event type that `overall_report` counts as a violation maps to a scoring factor
 * here, so there is no "counts as a violation but scores nothing" gap:
 *
 * - focus_lost, tab_hidden, page_exit                          -> Tab/focus activity factor
 * - clipboard_copy, clipboard_cut, clipboard_paste, contextmenu -> Clipboard/context menu factor
 * - screen_marker_missing, screen_share_stopped                -> Screen-share issues factor
 * - multiple_monitors_detected                                 -> Multiple monitors factor
 * - possible_ai_tool                                           -> Possible AI tool (+ AI tool w/ screenshot) factors
 * - shortcut                                                   -> F12 factor (F12 detail) and Other keyboard shortcuts factor (non-F12 detail)
 * - multiple_faces_detected                                    -> Multiple faces factor
 * - audio_detected                                             -> Audio factor
 * - face_missing, no_face_detected                             -> No face factor
 *
 * The `shortcut` event type is the only one that previously mapped to a single sub-case (F12).
 * Non-F12 monitored shortcuts were counted as violations by `overall_report` but scored nothing;
 * the "Other keyboard shortcuts" factor closes that gap. F12 shortcut rows are excluded from that
 * factor (they are already scored by the F12 factor) so no shortcut row is scored twice. The
 * clipboard factor scores the distinct clipboard_* / contextmenu event rows, not shortcut rows, so
 * the two factors count disjoint event types just as `overall_report` treats them as separate
 * violations.
 *
 * AI review is NEVER a scoring input. The scoring factors above are functions of
 * student-attributable proctoring events only. The outcome of AI_Image_Review (including a
 * "nothing found" or a tool-failure result) is reported alongside the score but is never summed
 * into it, so an AI result cannot inflate the score (Requirement 16.2).
 */
final class risk_calculator {
    /**
     * Default points-per-event and cap for every scoring factor, keyed by factor key.
     *
     * The factor key doubles as the suffix of the report label lang string
     * (`riskscore:{key}`) and of the admin override config names
     * (`riskfactor_{key}_enabled` / `_points` / `_cap`). Order here is the display
     * order of the factors on the admin page and in risk score details.
     */
    public const FACTOR_DEFAULTS = [
        'facemismatch' => ['points' => 35, 'cap' => 35],
        'multiplefaces' => ['points' => 30, 'cap' => 30],
        'noface' => ['points' => 8, 'cap' => 24],
        'screenshare' => ['points' => 18, 'cap' => 36],
        'multimonitor' => ['points' => 25, 'cap' => 25],
        'aitool' => ['points' => 20, 'cap' => 30],
        'aitoolscreenshot' => ['points' => 15, 'cap' => 30],
        'clipboard' => ['points' => 8, 'cap' => 24],
        'tabactivity' => ['points' => 5, 'cap' => 20],
        'f12' => ['points' => 15, 'cap' => 15],
        'shortcut' => ['points' => 8, 'cap' => 24],
        'audio' => ['points' => 6, 'cap' => 18],
        'webcammissing' => ['points' => 15, 'cap' => 15],
        'speed' => ['points' => 25, 'cap' => 25],
    ];

    /**
     * Determine whether the attempt risk score is capped at 100 (the default).
     *
     * @return bool True when the summed factor points are clamped to 100.
     */
    public static function score_cap_enabled(): bool {
        $value = get_config('quizaccess_proctoring', 'riskscorecapenabled');
        if ($value === false || $value === null || $value === '') {
            return true;
        }

        return (int)$value === 1;
    }

    /**
     * Determine whether a scoring factor is enabled (factors default to enabled).
     *
     * @param string $key Factor key from {@see self::FACTOR_DEFAULTS}.
     * @return bool True when the factor should score evidence.
     */
    public static function factor_enabled(string $key): bool {
        $value = get_config('quizaccess_proctoring', 'riskfactor_' . $key . '_enabled');
        if ($value === false || $value === null || $value === '') {
            return true;
        }

        return (int)$value === 1;
    }

    /**
     * Get the configured points-per-event for a scoring factor.
     *
     * @param string $key Factor key from {@see self::FACTOR_DEFAULTS}.
     * @return int Points per event, clamped to 0-100.
     */
    public static function factor_points(string $key): int {
        return self::factor_value($key, 'points');
    }

    /**
     * Get the configured maximum points (cap) for a scoring factor.
     *
     * @param string $key Factor key from {@see self::FACTOR_DEFAULTS}.
     * @return int Factor cap, clamped to 0-100.
     */
    public static function factor_cap(string $key): int {
        return self::factor_value($key, 'cap');
    }

    /**
     * Resolve a configured factor value with fallback to the shipped default.
     *
     * @param string $key Factor key from {@see self::FACTOR_DEFAULTS}.
     * @param string $field Either 'points' or 'cap'.
     * @return int Configured value clamped to 0-100, or the default when unset/invalid.
     */
    private static function factor_value(string $key, string $field): int {
        $default = self::FACTOR_DEFAULTS[$key][$field] ?? 0;
        $value = get_config('quizaccess_proctoring', 'riskfactor_' . $key . '_' . $field);
        if ($value === false || $value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, min(100, (int)$value));
    }

    /**
     * Build a factor using its configured points and cap, or null when the factor is disabled.
     *
     * @param string $key Factor key from {@see self::FACTOR_DEFAULTS}.
     * @param int $count Evidence count.
     * @return array|null Factor data, or null when the factor is disabled or unknown.
     */
    public static function build_configured_factor(string $key, int $count): ?array {
        if (!isset(self::FACTOR_DEFAULTS[$key]) || !self::factor_enabled($key)) {
            return null;
        }

        $factor = self::build_factor(
            get_string('riskscore:' . $key, 'quizaccess_proctoring'),
            $count,
            self::factor_points($key),
            self::factor_cap($key)
        );
        $factor['key'] = $key;

        return $factor;
    }

    /**
     * Determines whether an event detail JSON contains a specific shortcut.
     *
     * @param string $eventdetail JSON event detail.
     * @param string $shortcut Shortcut text to match.
     * @return bool True when the shortcut matches.
     */
    public static function event_has_shortcut(string $eventdetail, string $shortcut): bool {
        $decoded = json_decode($eventdetail, true);
        if (!is_array($decoded) || empty($decoded['shortcut'])) {
            return false;
        }

        return strtoupper((string)$decoded['shortcut']) === strtoupper($shortcut);
    }

    /**
     * Count attempt events for one or more event types.
     *
     * @param string $eventwhere Base event WHERE clause.
     * @param array $eventparams Base event query params.
     * @param array $eventtypes Event types to count.
     * @param bool $requirescreenshot True to only count events with a desktop screenshot.
     * @return int Number of matching events.
     */
    public static function count_events(
        string $eventwhere,
        array $eventparams,
        array $eventtypes,
        bool $requirescreenshot = false
    ): int {
        global $DB;

        if (empty($eventtypes)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($eventtypes, SQL_PARAMS_NAMED, 'riskevent');
        $where = $eventwhere . " AND eventtype {$insql}";
        if ($requirescreenshot) {
            $where .= " AND COALESCE(screenshoturl, '') <> ''";
        }

        return $DB->count_records_select('quizaccess_proctoring_events', $where, array_merge($eventparams, $inparams));
    }

    /**
     * Count shortcut events matching the requested shortcut.
     *
     * @param string $eventwhere Base event WHERE clause.
     * @param array $eventparams Base event query params.
     * @param string $shortcut Shortcut to match.
     * @return int Number of matching shortcut events.
     */
    public static function count_shortcuts(string $eventwhere, array $eventparams, string $shortcut): int {
        global $DB;

        $shortcutrecords = $DB->get_records_select(
            'quizaccess_proctoring_events',
            $eventwhere . ' AND eventtype = :riskshortcuttype',
            $eventparams + ['riskshortcuttype' => 'shortcut'],
            '',
            'id, eventdetail'
        );

        $count = 0;
        foreach ($shortcutrecords as $shortcutrecord) {
            if (self::event_has_shortcut($shortcutrecord->eventdetail, $shortcut)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Build one risk factor for the risk score details table.
     *
     * @param string $label Factor label.
     * @param int $count Evidence count.
     * @param int $pointsperevent Points for each event.
     * @param int $maxpoints Maximum points this factor can add.
     * @return array Factor data.
     */
    public static function build_factor(string $label, int $count, int $pointsperevent, int $maxpoints): array {
        $points = min($maxpoints, max(0, $count) * $pointsperevent);

        return [
            'label' => $label,
            'count' => $count,
            'points' => $points,
            'haspoints' => $points > 0,
        ];
    }

    /**
     * Resolve a configured risk-level boundary with fallback to its default.
     *
     * @param string $name Config name (risklevelmoderate, risklevelhigh, risklevelcritical).
     * @param int $default Default boundary score.
     * @return int Boundary clamped to 1-100.
     */
    private static function level_boundary(string $name, int $default): int {
        $value = get_config('quizaccess_proctoring', $name);
        if ($value === false || $value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(1, min(100, (int)$value));
    }

    /**
     * Get the resolved risk-level boundaries.
     *
     * Boundaries are site-configurable (defaults: Moderate 20, High 50, Critical 80) and are
     * clamped so they never invert: High never exceeds Critical and Moderate never exceeds High.
     *
     * @return array ['moderate' => int, 'high' => int, 'critical' => int]
     */
    public static function get_level_boundaries(): array {
        $critical = self::level_boundary('risklevelcritical', 80);
        $high = min(self::level_boundary('risklevelhigh', 50), $critical);
        $moderate = min(self::level_boundary('risklevelmoderate', 20), $high);

        return ['moderate' => $moderate, 'high' => $high, 'critical' => $critical];
    }

    /**
     * Get risk-level presentation details for a score.
     *
     * @param int $score Score from 0 to 100.
     * @return array Risk-level template data.
     */
    public static function get_level(int $score): array {
        ['moderate' => $moderate, 'high' => $high, 'critical' => $critical] = self::get_level_boundaries();

        if ($score >= $critical) {
            return [
                'label' => get_string('riskscore:critical', 'quizaccess_proctoring'),
                'class' => 'proctoring-risk-critical',
                'levelkey' => 'critical',
            ];
        }
        if ($score >= $high) {
            return [
                'label' => get_string('riskscore:high', 'quizaccess_proctoring'),
                'class' => 'proctoring-risk-high',
                'levelkey' => 'high',
            ];
        }
        if ($score >= $moderate) {
            return [
                'label' => get_string('riskscore:moderate', 'quizaccess_proctoring'),
                'class' => 'proctoring-risk-moderate',
                'levelkey' => 'moderate',
            ];
        }

        return [
            'label' => get_string('riskscore:low', 'quizaccess_proctoring'),
            'class' => 'proctoring-risk-low',
            'levelkey' => 'low',
        ];
    }

    /**
     * Calculate a proctoring risk score for one quiz attempt.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $studentid Student id.
     * @param int $reportid A quizaccess_proctoring_logs id for the attempt.
     * @return array Risk score template data.
     */
    public static function calculate_attempt(int $courseid, int $cmid, int $studentid, int $reportid): array {
        global $DB;

        $attemptid = (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $reportid]);
        $threshold = max(1, (int)quizaccess_proctoring_get_proctoring_settings('threshold'));

        $eventwhere = 'courseid = :riskcourseid AND quizid = :riskcmid AND userid = :riskstudentid';
        $eventparams = [
            'riskcourseid' => $courseid,
            'riskcmid' => $cmid,
            'riskstudentid' => $studentid,
        ];
        if ($attemptid > 0) {
            $eventwhere .= ' AND attemptid = :riskattemptid';
            $eventparams['riskattemptid'] = $attemptid;
        }

        $logwhere = 'courseid = :risklogcourseid AND quizid = :risklogcmid AND userid = :risklogstudentid
            AND deletionprogress = :riskdeletionprogress';
        $logparams = [
            'risklogcourseid' => $courseid,
            'risklogcmid' => $cmid,
            'risklogstudentid' => $studentid,
            'riskdeletionprogress' => 0,
        ];
        if ($attemptid > 0) {
            $logwhere .= ' AND status = :risklogattemptid';
            $logparams['risklogattemptid'] = $attemptid;
        } else if ($reportid > 0) {
            $logwhere .= ' AND id = :risklogreportid';
            $logparams['risklogreportid'] = $reportid;
        }

        $faceimagewhere = 'l.courseid = :riskfacecourseid AND l.quizid = :riskfacecmid
            AND l.userid = :riskfacestudentid AND l.deletionprogress = :riskfacedeletionprogress';
        $faceimageparams = [
            'riskfacecourseid' => $courseid,
            'riskfacecmid' => $cmid,
            'riskfacestudentid' => $studentid,
            'riskfacedeletionprogress' => 0,
            'riskfacefound' => '1',
        ];
        if ($attemptid > 0) {
            $faceimagewhere .= ' AND l.status = :riskfaceattemptid';
            $faceimageparams['riskfaceattemptid'] = $attemptid;
        } else if ($reportid > 0) {
            $faceimagewhere .= ' AND l.id = :riskfacereportid';
            $faceimageparams['riskfacereportid'] = $reportid;
        }

        // Evidence counts are only queried for enabled factors; a disabled factor scores nothing,
        // so its count query would be wasted work.
        $webcamcount = 0;
        if (self::factor_enabled('webcammissing')) {
            $webcamcount = $DB->count_records_select(
                'quizaccess_proctoring_logs',
                $logwhere . " AND COALESCE(webcampicture, '') <> ''",
                $logparams
            );
        }

        $facemismatchcount = 0;
        if (self::factor_enabled('facemismatch')) {
            $facemismatchcount = $DB->count_records_select(
                'quizaccess_proctoring_logs',
                $logwhere . ' AND awsflag = :riskawschecked AND awsscore < :riskthreshold',
                $logparams + [
                    'riskawschecked' => 2,
                    'riskthreshold' => $threshold,
                ]
            );
        }

        $facefailedcount = 0;
        $nofaceimagecount = 0;
        $nofaceeventcount = 0;
        if (self::factor_enabled('noface')) {
            $facefailedcount = $DB->count_records_select(
                'quizaccess_proctoring_logs',
                $logwhere . ' AND awsflag = :riskawsfailed',
                $logparams + ['riskawsfailed' => 3]
            );
            $nofaceimagecount = $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {quizaccess_proctoring_face_images} fi
                   JOIN {quizaccess_proctoring_logs} l ON l.id = fi.parentid
                  WHERE {$faceimagewhere}
                    AND fi.facefound <> :riskfacefound",
                $faceimageparams
            );
            $nofaceeventcount = self::count_events(
                $eventwhere,
                $eventparams,
                ['face_missing', 'no_face_detected']
            );
        }

        $tabactivitycount = self::factor_enabled('tabactivity') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['focus_lost', 'tab_hidden', 'page_exit']
        ) : 0;
        $clipboardcount = self::factor_enabled('clipboard') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu']
        ) : 0;
        $screenissuecount = self::factor_enabled('screenshare') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['screen_marker_missing', 'screen_share_stopped']
        ) : 0;
        $multimonitorcount = self::factor_enabled('multimonitor') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['multiple_monitors_detected']
        ) : 0;
        $aitoolcount = self::factor_enabled('aitool') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['possible_ai_tool']
        ) : 0;
        $aitoolscreenshotcount = self::factor_enabled('aitoolscreenshot') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['possible_ai_tool'],
            true
        ) : 0;
        // The F12 count also feeds the other-shortcuts subtraction below, so it is needed whenever
        // either shortcut factor is enabled. Disabling the F12 factor does not move F12 presses
        // into the other-shortcuts factor: each factor scores its own evidence or nothing.
        $f12count = (self::factor_enabled('f12') || self::factor_enabled('shortcut'))
            ? self::count_shortcuts($eventwhere, $eventparams, 'F12') : 0;
        // Non-F12 monitored shortcuts (Alt+Tab, Ctrl+T/N/W/R/A/L, Ctrl+Shift+I/J/C, Ctrl+C/X/V).
        // overall_report counts every 'shortcut' event as a violation, but only F12 was scored; the
        // remaining shortcut rows are scored here. F12 rows are excluded so no row is scored twice.
        $othershortcutcount = self::factor_enabled('shortcut') ? max(
            0,
            self::count_events($eventwhere, $eventparams, ['shortcut']) - $f12count
        ) : 0;
        $multiplefacescount = self::factor_enabled('multiplefaces') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['multiple_faces_detected']
        ) : 0;
        $audioactivitycount = self::factor_enabled('audio') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['audio_detected']
        ) : 0;

        // Attempt duration and the optional speed-based risk factor.
        $speedenabled = (int)get_config('quizaccess_proctoring', 'speedreviewenabled') === 1;
        $speedfloor = (int)get_config('quizaccess_proctoring', 'speedreviewminsecondsperquestion');
        if ($speedfloor <= 0) {
            $speedfloor = 15;
        }
        $durationseconds = 0;
        $questioncount = 0;
        $speedcount = 0;
        if ($attemptid > 0) {
            $attemptrecord = $DB->get_record(
                'quiz_attempts',
                ['id' => $attemptid],
                'id, quiz, timestart, timefinish'
            );
            if (
                $attemptrecord
                && (int)$attemptrecord->timestart > 0
                && (int)$attemptrecord->timefinish > (int)$attemptrecord->timestart
            ) {
                $durationseconds = (int)$attemptrecord->timefinish - (int)$attemptrecord->timestart;
                static $slotcountcache = [];
                $quizid = (int)$attemptrecord->quiz;
                if (!array_key_exists($quizid, $slotcountcache)) {
                    $slotcountcache[$quizid] = (int)$DB->count_records('quiz_slots', ['quizid' => $quizid]);
                }
                $questioncount = $slotcountcache[$quizid];
                if ($speedenabled && $questioncount > 0 && ($durationseconds / $questioncount) < $speedfloor) {
                    $speedcount = 1;
                }
            }
        }

        $factorcounts = [
            'facemismatch' => $facemismatchcount,
            'multiplefaces' => $multiplefacescount,
            'noface' => max($nofaceimagecount, $facefailedcount) + $nofaceeventcount,
            'screenshare' => $screenissuecount,
            'multimonitor' => $multimonitorcount,
            'aitool' => $aitoolcount,
            'aitoolscreenshot' => $aitoolscreenshotcount,
            'clipboard' => $clipboardcount,
            'tabactivity' => $tabactivitycount,
            'f12' => $f12count,
            'shortcut' => $othershortcutcount,
            'audio' => $audioactivitycount,
            'webcammissing' => $webcamcount > 0 ? 0 : 1,
        ];

        $factors = [];
        foreach ($factorcounts as $factorkey => $factorcount) {
            $factor = self::build_configured_factor($factorkey, $factorcount);
            if ($factor !== null) {
                $factors[] = $factor;
            }
        }

        if ($speedenabled && $durationseconds > 0 && $questioncount > 0) {
            $factor = self::build_configured_factor('speed', $speedcount);
            if ($factor !== null) {
                $factors[] = $factor;
            }
        }

        $score = 0;
        foreach ($factors as $factor) {
            $score += (int)$factor['points'];
        }
        if (self::score_cap_enabled()) {
            $score = min(100, $score);
        }
        $level = self::get_level($score);

        $secondsperquestion = $questioncount > 0 ? (int)round($durationseconds / $questioncount) : 0;
        $durationformatted = $durationseconds > 0 ? format_time($durationseconds) : '';
        $timetakenlabel = '';
        if ($durationseconds > 0 && $questioncount > 0) {
            $timetakenlabel = get_string('riskscore:timetakenlabel', 'quizaccess_proctoring', (object)[
                'duration' => $durationformatted,
                'perquestion' => $secondsperquestion,
                'questions' => $questioncount,
            ]);
        } else if ($durationseconds > 0) {
            $timetakenlabel = get_string('riskscore:timetakenshort', 'quizaccess_proctoring', $durationformatted);
        }

        return [
            'score' => $score,
            'level' => $level['label'],
            'levelkey' => $level['levelkey'],
            'badgeclass' => 'proctoring-risk-badge ' . $level['class'],
            'cardclass' => 'proctoring-risk-card ' . $level['class'],
            'factors' => $factors,
            'attemptid' => $attemptid,
            'durationseconds' => $durationseconds,
            'durationformatted' => $durationformatted,
            'secondsperquestion' => $secondsperquestion,
            'questioncount' => $questioncount,
            'timetakenlabel' => $timetakenlabel,
        ];
    }
}
