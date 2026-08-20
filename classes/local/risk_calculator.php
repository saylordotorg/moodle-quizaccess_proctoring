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
 * | Phone detected             |           12 |  24 | events: phone_detected (factor shown only when webcam phone detection is enabled or evidence exists) |
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
 * - phone_detected                                             -> Phone detected factor
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
     * Attempts per chunk when scoring in bulk, to keep each IN () list a sane size.
     *
     * @var int
     */
    const BULK_CHUNK = 250;

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
        'phonedetected' => ['points' => 12, 'cap' => 24],
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
     * Determine whether the attempt risk score is capped at 100.
     *
     * Off by default. The 100 boundary is a presentation choice, not a measurement: while the
     * scoring model is still being evaluated, clamping to it hides how far past the threshold an
     * attempt actually went, and two attempts scoring 100 and 240 are not the same attempt.
     *
     * @return bool True when the summed factor points are clamped to 100.
     */
    public static function score_cap_enabled(): bool {
        $value = get_config('quizaccess_proctoring', 'riskscorecapenabled');
        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return (int)$value === 1;
    }

    /**
     * Get the maximum achievable attempt risk score under the current configuration.
     *
     * With the score cap enabled (the default) this is 100. With the cap disabled the score is
     * the raw factor sum, so the maximum is the sum of the configured caps of every enabled
     * factor. Factors whose monitors are switched off are still counted: historical evidence can
     * keep scoring after a monitor is disabled, and overestimating errs on the safe side for
     * consumers such as the auto-release ceiling (a hold is retained rather than released).
     *
     * @return int Maximum achievable score.
     */
    public static function max_possible_score(): int {
        if (self::score_cap_enabled()) {
            return 100;
        }

        $max = 0;
        foreach (array_keys(self::FACTOR_DEFAULTS) as $key) {
            if (self::factor_enabled($key)) {
                $max += self::factor_cap($key);
            }
        }

        return $max;
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
     * Get the active false-positive marks for one attempt, keyed by factor key.
     *
     * Reviewer false-positive verdicts exclude a factor's evidence from the recomputed risk
     * score. Scope matches the evidence queries in {@see self::calculate_attempt()}: by
     * attempt id when known, otherwise by the originating report id.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $studentid Student id.
     * @param int $attemptid Quiz attempt id (0 when unknown).
     * @param int $reportid Proctoring log id used as the fallback scope.
     * @return array<string, \stdClass> Active mark records keyed by factor key.
     */
    public static function get_false_positive_marks(
        int $courseid,
        int $cmid,
        int $studentid,
        int $attemptid,
        int $reportid
    ): array {
        global $DB;

        if (!self::false_positive_table_exists()) {
            return [];
        }

        $where = 'courseid = :fpcourseid AND quizid = :fpcmid AND userid = :fpstudentid
            AND verdict = :fpverdict AND revoked = 0';
        $params = [
            'fpcourseid' => $courseid,
            'fpcmid' => $cmid,
            'fpstudentid' => $studentid,
            'fpverdict' => 'false_positive',
        ];
        if ($attemptid > 0) {
            $where .= ' AND attemptid = :fpattemptid';
            $params['fpattemptid'] = $attemptid;
        } else if ($reportid > 0) {
            $where .= ' AND reportid = :fpreportid';
            $params['fpreportid'] = $reportid;
        }

        $marks = [];
        foreach ($DB->get_records_select('quizaccess_proctoring_finding_reviews', $where, $params, 'id ASC') as $record) {
            $marks[(string)$record->factorkey] = $record;
        }

        return $marks;
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
        $evidence = self::gather_evidence($courseid, $cmid, $studentid, $reportid, $attemptid);
        $fpmarks = self::get_false_positive_marks($courseid, $cmid, $studentid, $attemptid, $reportid);

        return self::assemble_result($evidence, $attemptid, $fpmarks);
    }

    /**
     * Count the evidence for one attempt, one query per enabled factor.
     *
     * Split out from {@see self::calculate_attempt()} so the scoring in
     * {@see self::assemble_result()} has exactly one implementation, whether the counts arrive one
     * attempt at a time from here or in bulk from {@see self::gather_evidence_bulk()}.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $studentid Student id.
     * @param int $reportid A quizaccess_proctoring_logs id for the attempt.
     * @param int $attemptid Quiz attempt id, or 0 when the report id is the only scope available.
     * @return array ['counts' => array<string, int>, 'durationseconds' => int, 'questioncount' => int]
     */
    private static function gather_evidence(
        int $courseid,
        int $cmid,
        int $studentid,
        int $reportid,
        int $attemptid
    ): array {
        global $DB;

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
        $phonedetectedcount = self::factor_enabled('phonedetected') ? self::count_events(
            $eventwhere,
            $eventparams,
            ['phone_detected']
        ) : 0;

        // Attempt duration, which the optional speed-based risk factor is derived from.
        $durationseconds = 0;
        $questioncount = 0;
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
            }
        }

        return [
            'counts' => [
                'webcam' => $webcamcount,
                'facemismatch' => $facemismatchcount,
                'facefailed' => $facefailedcount,
                'nofaceimage' => $nofaceimagecount,
                'nofaceevent' => $nofaceeventcount,
                'tabactivity' => $tabactivitycount,
                'clipboard' => $clipboardcount,
                'screenissue' => $screenissuecount,
                'multimonitor' => $multimonitorcount,
                'aitool' => $aitoolcount,
                'aitoolscreenshot' => $aitoolscreenshotcount,
                'f12' => $f12count,
                'othershortcut' => $othershortcutcount,
                'multiplefaces' => $multiplefacescount,
                'audio' => $audioactivitycount,
                'phonedetected' => $phonedetectedcount,
            ],
            'durationseconds' => $durationseconds,
            'questioncount' => $questioncount,
        ];
    }

    /**
     * Turn counted evidence into the scored risk result.
     *
     * This is the only place a risk score is assembled: both the single-attempt path and the bulk
     * path feed it the same counts, so a report cannot disagree with the attempt page about a score.
     *
     * @param array $evidence Output of {@see self::gather_evidence()} for one attempt.
     * @param int $attemptid Quiz attempt id, or 0 when unknown.
     * @param array $fpmarks Active false-positive marks keyed by factor key.
     * @return array Risk score template data.
     */
    private static function assemble_result(array $evidence, int $attemptid, array $fpmarks): array {
        $counts = $evidence['counts'];
        $durationseconds = (int)$evidence['durationseconds'];
        $questioncount = (int)$evidence['questioncount'];

        $webcamcount = (int)$counts['webcam'];
        $phonedetectedcount = (int)$counts['phonedetected'];

        // The optional speed factor: fast enough to be worth a look, but only when the attempt
        // actually recorded a duration and the quiz actually has questions to divide by.
        $speedenabled = (int)get_config('quizaccess_proctoring', 'speedreviewenabled') === 1;
        $speedfloor = (int)get_config('quizaccess_proctoring', 'speedreviewminsecondsperquestion');
        if ($speedfloor <= 0) {
            $speedfloor = 15;
        }
        $speedcount = 0;
        if ($speedenabled && $durationseconds > 0 && $questioncount > 0
                && ($durationseconds / $questioncount) < $speedfloor) {
            $speedcount = 1;
        }

        $factorcounts = [
            'facemismatch' => (int)$counts['facemismatch'],
            'multiplefaces' => (int)$counts['multiplefaces'],
            'noface' => max((int)$counts['nofaceimage'], (int)$counts['facefailed']) + (int)$counts['nofaceevent'],
            'phonedetected' => $phonedetectedcount,
            'screenshare' => (int)$counts['screenissue'],
            'multimonitor' => (int)$counts['multimonitor'],
            'aitool' => (int)$counts['aitool'],
            'aitoolscreenshot' => (int)$counts['aitoolscreenshot'],
            'clipboard' => (int)$counts['clipboard'],
            'tabactivity' => (int)$counts['tabactivity'],
            'f12' => (int)$counts['f12'],
            'shortcut' => (int)$counts['othershortcut'],
            'audio' => (int)$counts['audio'],
            'webcammissing' => $webcamcount > 0 ? 0 : 1,
        ];

        // Phone detection is an opt-in monitor: when it is switched off and the attempt has no
        // phone evidence, the factor is omitted entirely so reports list it as "not monitored"
        // rather than "passed". Existing evidence keeps scoring even after the monitor is
        // switched off.
        if ((int)get_config('quizaccess_proctoring', 'detectphone') !== 1 && $phonedetectedcount === 0) {
            unset($factorcounts['phonedetected']);
        }

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

        // Reviewer false-positive marks: a marked factor keeps its evidence count for display
        // but contributes no points, so the score recomputes without the dismissed evidence.
        foreach ($factors as $index => $factor) {
            $factorkey = (string)($factor['key'] ?? '');
            if ($factorkey === '' || empty($fpmarks[$factorkey])) {
                continue;
            }
            $factors[$index]['excludedpoints'] = (int)$factor['points'];
            $factors[$index]['points'] = 0;
            $factors[$index]['haspoints'] = false;
            $factors[$index]['falsepositive'] = true;
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
            // The pace the speed factor was judged against, so a report can state the threshold
            // instead of asserting that something was "unusually fast" and leaving the reader to
            // guess whether that means a percentile or a clock.
            'speedfloor' => $speedfloor,
            'timetakenlabel' => $timetakenlabel,
        ];
    }

    /**
     * Calculate risk scores for many attempts at once.
     *
     * {@see self::calculate_attempt()} spends roughly twenty small queries per attempt, which is
     * fine for one attempt page and ruinous for a report that has to score a whole filtered set
     * before it can order or exclude anything. This gathers the same evidence for every requested
     * attempt in a fixed handful of grouped queries per chunk, then assembles each result through
     * the same {@see self::assemble_result()} the single-attempt path uses, so the two cannot drift.
     *
     * Attempts whose id is unknown (a capture row that never got an attempt id) are scoped by report
     * id rather than attempt id, which does not group; those fall back to the per-attempt path.
     *
     * @param array $requests Rows keyed however the caller likes, each with 'courseid', 'cmid',
     *                        'userid', 'reportid', and optionally a known 'attemptid'.
     * @return array Risk results in the same key order as $requests.
     */
    public static function calculate_many(array $requests): array {
        global $DB;

        if (empty($requests)) {
            return [];
        }

        // Resolve any attempt ids the caller did not already know, in one query rather than one each.
        $unresolved = [];
        foreach ($requests as $key => $request) {
            if (!isset($request['attemptid'])) {
                $unresolved[(int)$request['reportid']][] = $key;
            }
        }
        if (!empty($unresolved)) {
            foreach (array_chunk(array_keys($unresolved), self::BULK_CHUNK) as $chunk) {
                [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'rep');
                $rows = $DB->get_records_select_menu(
                    'quizaccess_proctoring_logs',
                    "id {$insql}",
                    $params,
                    '',
                    'id, status'
                );
                foreach ($rows as $reportid => $status) {
                    foreach ($unresolved[(int)$reportid] ?? [] as $key) {
                        $requests[$key]['attemptid'] = (int)$status;
                    }
                }
            }
        }

        $bulk = [];
        $results = [];
        foreach ($requests as $key => $request) {
            if ((int)($request['attemptid'] ?? 0) > 0) {
                $bulk[$key] = $request;
                continue;
            }
            $results[$key] = self::calculate_attempt(
                (int)$request['courseid'],
                (int)$request['cmid'],
                (int)$request['userid'],
                (int)$request['reportid']
            );
        }

        if (!empty($bulk)) {
            $evidence = self::gather_evidence_bulk($bulk);
            foreach ($bulk as $key => $request) {
                $results[$key] = self::assemble_result(
                    $evidence[$key]['evidence'],
                    (int)$request['attemptid'],
                    $evidence[$key]['fpmarks']
                );
            }
        }

        // Hand back the caller's own order, not the order the two paths happened to finish in.
        $ordered = [];
        foreach (array_keys($requests) as $key) {
            if (isset($results[$key])) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * Count the same evidence as {@see self::gather_evidence()}, for many attempts at once.
     *
     * Every query is grouped by the full course/quiz/user/attempt key and matched back in PHP, so a
     * reused attempt id from another quiz cannot leak evidence into the wrong row. Factor gating
     * mirrors the single-attempt path exactly: a disabled factor is not queried and stays at zero.
     *
     * @param array $requests Rows keyed by caller key, each with a positive 'attemptid'.
     * @return array [caller key => ['evidence' => array, 'fpmarks' => array]]
     */
    private static function gather_evidence_bulk(array $requests): array {
        global $DB;

        $threshold = max(1, (int)quizaccess_proctoring_get_proctoring_settings('threshold'));

        // One canonical key per attempt, and the caller keys that map onto it - two report rows for
        // the same attempt must score identically, and must not be counted twice.
        $keysbyattempt = [];
        $tuples = [];
        foreach ($requests as $key => $request) {
            $tuple = implode(':', [
                (int)$request['courseid'],
                (int)$request['cmid'],
                (int)$request['userid'],
                (int)$request['attemptid'],
            ]);
            $keysbyattempt[$tuple][] = $key;
            $tuples[$tuple] = (int)$request['attemptid'];
        }

        $zero = [
            'webcam' => 0, 'facemismatch' => 0, 'facefailed' => 0, 'nofaceimage' => 0, 'nofaceevent' => 0,
            'tabactivity' => 0, 'clipboard' => 0, 'screenissue' => 0, 'multimonitor' => 0, 'aitool' => 0,
            'aitoolscreenshot' => 0, 'f12' => 0, 'othershortcut' => 0, 'multiplefaces' => 0, 'audio' => 0,
            'phonedetected' => 0,
        ];
        $counts = [];
        $durations = [];
        $questioncounts = [];
        $fpmarks = [];
        foreach (array_keys($tuples) as $tuple) {
            $counts[$tuple] = $zero;
            $durations[$tuple] = 0;
            $questioncounts[$tuple] = 0;
            $fpmarks[$tuple] = [];
        }

        // Which event types each count draws on, and the factor gate that has to be on to draw
        // them: the same pairings {@see self::gather_evidence()} uses one factor at a time.
        $eventfactors = [
            'nofaceevent' => ['noface', ['face_missing', 'no_face_detected']],
            'tabactivity' => ['tabactivity', ['focus_lost', 'tab_hidden', 'page_exit']],
            'clipboard' => ['clipboard', ['clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu']],
            'screenissue' => ['screenshare', ['screen_marker_missing', 'screen_share_stopped']],
            'multimonitor' => ['multimonitor', ['multiple_monitors_detected']],
            'aitool' => ['aitool', ['possible_ai_tool']],
            'multiplefaces' => ['multiplefaces', ['multiple_faces_detected']],
            'audio' => ['audio', ['audio_detected']],
            'phonedetected' => ['phonedetected', ['phone_detected']],
        ];

        $needf12 = self::factor_enabled('f12') || self::factor_enabled('shortcut');

        foreach (array_chunk($tuples, self::BULK_CHUNK, true) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($chunk), SQL_PARAMS_NAMED, 'att');

            // 1. Every event type for every attempt, plus the screenshot-backed subset that the
            //    AI-tool-with-screenshot factor scores, in one grouped pass.
            $rows = $DB->get_recordset_sql(
                "SELECT courseid, quizid, userid, attemptid, eventtype,
                        COUNT(1) AS cnt,
                        SUM(CASE WHEN COALESCE(screenshoturl, '') <> '' THEN 1 ELSE 0 END) AS withshot
                   FROM {quizaccess_proctoring_events}
                  WHERE attemptid {$insql}
               GROUP BY courseid, quizid, userid, attemptid, eventtype",
                $params
            );
            $eventcounts = [];
            $shortcuttuples = [];
            foreach ($rows as $row) {
                $tuple = $row->courseid . ':' . $row->quizid . ':' . $row->userid . ':' . $row->attemptid;
                if (!isset($counts[$tuple])) {
                    continue;
                }
                $eventcounts[$tuple][$row->eventtype] = [(int)$row->cnt, (int)$row->withshot];
                if ($row->eventtype === 'shortcut') {
                    $shortcuttuples[$tuple] = true;
                }
            }
            $rows->close();

            foreach ($eventcounts as $tuple => $bytype) {
                foreach ($eventfactors as $countkey => [$factorkey, $types]) {
                    if (!self::factor_enabled($factorkey)) {
                        continue;
                    }
                    foreach ($types as $type) {
                        $counts[$tuple][$countkey] += $bytype[$type][0] ?? 0;
                    }
                }
                if (self::factor_enabled('aitoolscreenshot')) {
                    $counts[$tuple]['aitoolscreenshot'] += $bytype['possible_ai_tool'][1] ?? 0;
                }
            }

            // 2. F12 has to be told apart from the other monitored shortcuts, and that lives in the
            //    event detail rather than the type, so those rows are read and matched in PHP.
            if ($needf12 && !empty($shortcuttuples)) {
                $shortcutrows = $DB->get_recordset_sql(
                    "SELECT id, courseid, quizid, userid, attemptid, eventdetail
                       FROM {quizaccess_proctoring_events}
                      WHERE eventtype = :shortcuttype AND attemptid {$insql}",
                    $params + ['shortcuttype' => 'shortcut']
                );
                foreach ($shortcutrows as $row) {
                    $tuple = $row->courseid . ':' . $row->quizid . ':' . $row->userid . ':' . $row->attemptid;
                    if (isset($counts[$tuple]) && self::event_has_shortcut($row->eventdetail, 'F12')) {
                        $counts[$tuple]['f12']++;
                    }
                }
                $shortcutrows->close();

                if (self::factor_enabled('shortcut')) {
                    foreach ($shortcuttuples as $tuple => $unused) {
                        $total = $eventcounts[$tuple]['shortcut'][0] ?? 0;
                        $counts[$tuple]['othershortcut'] = max(0, $total - $counts[$tuple]['f12']);
                    }
                }
            }

            // 3. Capture rows: webcam evidence, face mismatches and failed face checks.
            $logrows = $DB->get_recordset_sql(
                "SELECT courseid, quizid, userid, status AS attemptid,
                        SUM(CASE WHEN COALESCE(webcampicture, '') <> '' THEN 1 ELSE 0 END) AS webcam,
                        SUM(CASE WHEN awsflag = :checked AND awsscore < :threshold THEN 1 ELSE 0 END) AS facemismatch,
                        SUM(CASE WHEN awsflag = :failed THEN 1 ELSE 0 END) AS facefailed
                   FROM {quizaccess_proctoring_logs}
                  WHERE deletionprogress = 0 AND status {$insql}
               GROUP BY courseid, quizid, userid, status",
                $params + ['checked' => 2, 'threshold' => $threshold, 'failed' => 3]
            );
            foreach ($logrows as $row) {
                $tuple = $row->courseid . ':' . $row->quizid . ':' . $row->userid . ':' . $row->attemptid;
                if (!isset($counts[$tuple])) {
                    continue;
                }
                if (self::factor_enabled('webcammissing')) {
                    $counts[$tuple]['webcam'] = (int)$row->webcam;
                }
                if (self::factor_enabled('facemismatch')) {
                    $counts[$tuple]['facemismatch'] = (int)$row->facemismatch;
                }
                if (self::factor_enabled('noface')) {
                    $counts[$tuple]['facefailed'] = (int)$row->facefailed;
                }
            }
            $logrows->close();

            // 4. Stored face images that came back without a face.
            if (self::factor_enabled('noface')) {
                $facerows = $DB->get_recordset_sql(
                    "SELECT l.courseid, l.quizid, l.userid, l.status AS attemptid, COUNT(1) AS cnt
                       FROM {quizaccess_proctoring_face_images} fi
                       JOIN {quizaccess_proctoring_logs} l ON l.id = fi.parentid
                      WHERE l.deletionprogress = 0 AND fi.facefound <> :facefound AND l.status {$insql}
                   GROUP BY l.courseid, l.quizid, l.userid, l.status",
                    $params + ['facefound' => '1']
                );
                foreach ($facerows as $row) {
                    $tuple = $row->courseid . ':' . $row->quizid . ':' . $row->userid . ':' . $row->attemptid;
                    if (isset($counts[$tuple])) {
                        $counts[$tuple]['nofaceimage'] = (int)$row->cnt;
                    }
                }
                $facerows->close();
            }

            // 5. Attempt durations, and the question count each duration is judged against.
            $attemptrows = $DB->get_records_select(
                'quiz_attempts',
                "id {$insql}",
                $params,
                '',
                'id, quiz, timestart, timefinish'
            );
            $slotcounts = [];
            if (!empty($attemptrows)) {
                $quizids = array_unique(array_map(function ($row) {
                    return (int)$row->quiz;
                }, $attemptrows));
                [$quizinsql, $quizparams] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quiz');
                $slotcounts = $DB->get_records_sql_menu(
                    "SELECT quizid, COUNT(1)
                       FROM {quiz_slots}
                      WHERE quizid {$quizinsql}
                   GROUP BY quizid",
                    $quizparams
                );
            }
            foreach ($chunk as $tuple => $attemptid) {
                $attempt = $attemptrows[$attemptid] ?? null;
                if (!$attempt || (int)$attempt->timestart <= 0
                        || (int)$attempt->timefinish <= (int)$attempt->timestart) {
                    continue;
                }
                $durations[$tuple] = (int)$attempt->timefinish - (int)$attempt->timestart;
                $questioncounts[$tuple] = (int)($slotcounts[(int)$attempt->quiz] ?? 0);
            }

            // 6. Reviewer false-positive marks, which zero a factor's points without hiding it.
            if (self::false_positive_table_exists()) {
                $markrows = $DB->get_recordset_sql(
                    "SELECT id, courseid, quizid, userid, attemptid, factorkey
                       FROM {quizaccess_proctoring_finding_reviews}
                      WHERE verdict = :verdict AND revoked = 0 AND attemptid {$insql}
                   ORDER BY id ASC",
                    $params + ['verdict' => 'false_positive']
                );
                foreach ($markrows as $row) {
                    $tuple = $row->courseid . ':' . $row->quizid . ':' . $row->userid . ':' . $row->attemptid;
                    if (isset($fpmarks[$tuple])) {
                        $fpmarks[$tuple][(string)$row->factorkey] = $row;
                    }
                }
                $markrows->close();
            }
        }

        $evidence = [];
        foreach ($keysbyattempt as $tuple => $keys) {
            foreach ($keys as $key) {
                $evidence[$key] = [
                    'evidence' => [
                        'counts' => $counts[$tuple],
                        'durationseconds' => $durations[$tuple],
                        'questioncount' => $questioncounts[$tuple],
                    ],
                    'fpmarks' => $fpmarks[$tuple],
                ];
            }
        }

        return $evidence;
    }

    /**
     * Whether the reviewer-findings table is present.
     *
     * The table ships in 2026072007; both scoring paths guard on it so a mid-upgrade site scores
     * attempts instead of failing.
     *
     * @return bool True when false-positive marks can be read.
     */
    private static function false_positive_table_exists(): bool {
        global $DB;

        static $exists = null;
        if ($exists === null) {
            $exists = $DB->get_manager()->table_exists('quizaccess_proctoring_finding_reviews');
        }

        return $exists;
    }
}
