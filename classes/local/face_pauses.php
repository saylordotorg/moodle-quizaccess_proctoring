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
 * Pauses of the quiz because no face was in view (CPIT-470).
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Totals the logged face_missing_start / face_missing_end events of an attempt.
 *
 * Each pause is logged by the browser when it begins (with the webcam frame) and when it ends (with
 * its duration). The "no face" score is capped, so these totals are what show a reviewer that the
 * behaviour kept repeating. They are informational and never scored.
 */
final class face_pauses {
    /** One pause longer than this is treated as this long, so one bad value cannot swamp the total. */
    private const MAX_PAUSE_SECONDS = DAYSECS;

    /**
     * Count the pauses of one attempt and their total length.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @return array 'count' (pauses begun), 'seconds' (total of the recorded ends), 'open' (pauses
     *               with no recorded end).
     */
    public static function for_attempt(int $courseid, int $cmid, int $userid, int $attemptid): array {
        global $DB;

        $totals = ['count' => 0, 'seconds' => 0, 'open' => 0];
        if ($attemptid <= 0) {
            return $totals;
        }
        [$typesql, $typeparams] = $DB->get_in_or_equal(['face_missing_start', 'face_missing_end'], SQL_PARAMS_NAMED, 'pause');
        $events = $DB->get_records_select(
            'quizaccess_proctoring_events',
            'courseid = :courseid AND quizid = :cmid AND userid = :userid AND attemptid = :attemptid AND eventtype ' . $typesql,
            ['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid] + $typeparams,
            '',
            'id, eventtype, eventdetail'
        );
        $ends = 0;
        foreach ($events as $event) {
            if ($event->eventtype === 'face_missing_start') {
                $totals['count']++;
                continue;
            }
            $ends++;
            $detail = json_decode((string)$event->eventdetail, true);
            $seconds = is_array($detail) ? (int)($detail['durationseconds'] ?? 0) : 0;
            $totals['seconds'] += max(0, min(self::MAX_PAUSE_SECONDS, $seconds));
        }
        $totals['open'] = max(0, $totals['count'] - $ends);
        return $totals;
    }

    /**
     * One-line summary for the report, or '' when the quiz was never paused for this.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student ID.
     * @param int $attemptid Attempt ID.
     * @return string Summary.
     */
    public static function summary(int $courseid, int $cmid, int $userid, int $attemptid): string {
        $totals = self::for_attempt($courseid, $cmid, $userid, $attemptid);
        if ($totals['count'] === 0) {
            return '';
        }
        $a = (object)['count' => $totals['count'], 'total' => format_time($totals['seconds'])];
        $summary = get_string(
            $totals['count'] === 1 ? 'verdict:facepauses_one' : 'verdict:facepauses',
            'quizaccess_proctoring',
            $a
        );
        if ($totals['open'] > 0) {
            $summary .= ' (' . get_string('verdict:facepausesopen', 'quizaccess_proctoring', $totals['open']) . ')';
        }
        return $summary;
    }
}
