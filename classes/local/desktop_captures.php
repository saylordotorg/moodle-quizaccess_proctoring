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
 * Desktop captures of an attempt, and how many absences they cover (CPIT-471).
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Counts an attempt's desktop captures and the absences from the quiz they cover.
 *
 * An absence is one stretch away from the quiz: its leave events (focus_lost, tab_hidden) and the
 * captures taken while away (away_capture) share a key. An absence is covered when any of them
 * carries a desktop capture. Leave events from before the key existed each count as an absence.
 */
final class desktop_captures {
    /** Events whose attached image is a webcam frame, not a desktop capture. */
    private const WEBCAM_FRAME_EVENTS = ['phone_detected', 'multiple_faces_detected', 'face_missing_start'];

    /** Events that belong to an absence from the quiz. */
    private const AWAY_EVENTS = ['focus_lost', 'tab_hidden', 'away_capture'];

    /**
     * Totals for one attempt.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @return array 'captures' (desktop captures), 'periods' (absences), 'covered' (absences with a capture).
     */
    public static function for_attempt(int $courseid, int $cmid, int $userid, int $attemptid): array {
        global $DB;

        $totals = ['captures' => 0, 'periods' => 0, 'covered' => 0];
        if ($attemptid <= 0) {
            return $totals;
        }
        $scope = 'courseid = :courseid AND quizid = :cmid AND userid = :userid AND attemptid = :attemptid';
        $params = ['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid];

        [$webcamsql, $webcamparams] = $DB->get_in_or_equal(self::WEBCAM_FRAME_EVENTS, SQL_PARAMS_NAMED, 'webcam', false);
        $totals['captures'] = $DB->count_records_select(
            'quizaccess_proctoring_events',
            $scope . " AND COALESCE(screenshoturl, '') <> '' AND eventtype " . $webcamsql,
            $params + $webcamparams
        );

        [$awaysql, $awayparams] = $DB->get_in_or_equal(self::AWAY_EVENTS, SQL_PARAMS_NAMED, 'away');
        $events = $DB->get_records_select(
            'quizaccess_proctoring_events',
            $scope . ' AND eventtype ' . $awaysql,
            $params + $awayparams,
            'id ASC',
            'id, eventtype, eventdetail, screenshoturl'
        );
        $periods = [];
        foreach ($events as $event) {
            $detail = json_decode((string)$event->eventdetail, true);
            $key = is_array($detail) && !empty($detail['awaykey']) ? 'key:' . (string)$detail['awaykey'] : '';
            if ($key === '') {
                if ($event->eventtype === 'away_capture') {
                    continue;
                }
                $key = 'event:' . $event->id;
            }
            $periods[$key] = ($periods[$key] ?? false) || (string)$event->screenshoturl !== '';
        }
        $totals['periods'] = count($periods);
        $totals['covered'] = count(array_filter($periods));
        return $totals;
    }

    /**
     * One-line summary for the report, or '' when desktop capture was not on for the attempt.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @return string Summary.
     */
    public static function summary(int $courseid, int $cmid, int $userid, int $attemptid): string {
        global $DB;

        if ($attemptid <= 0) {
            return '';
        }
        // The attempt recorded which monitors ran (CPIT-467): with desktop capture off, "0 of N
        // covered" would read as a failure rather than a choice.
        $snapshots = $DB->get_records('quizaccess_proctoring_events', [
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid,
            'attemptid' => $attemptid, 'eventtype' => 'monitoring_started',
        ], 'id ASC', 'id, eventdetail', 0, 1);
        $policy = $snapshots ? json_decode((string)reset($snapshots)->eventdetail, true) : null;
        if (is_array($policy) && isset($policy['monitors']) && empty($policy['monitors']['screen'])) {
            return '';
        }

        $totals = self::for_attempt($courseid, $cmid, $userid, $attemptid);
        if ($totals['periods'] > 0) {
            return get_string('verdict:desktopcapturesaway', 'quizaccess_proctoring', (object)$totals);
        }
        return $totals['captures'] > 0
            ? get_string('verdict:desktopcaptures', 'quizaccess_proctoring', (object)$totals)
            : '';
    }
}
