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

namespace quizaccess_proctoring\local;

/**
 * The warnings a student was shown during an attempt, for the per-student report (CPIT-481).
 *
 * The exam page logs a warning_shown event when a warning appears and warning_cleared, with how
 * long it was up, when it goes. The face pause (the quiz blurred because no face was in view) is
 * read from its own face_missing_start and face_missing_end events.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_messages {
    /** @var array<string, string> Warning key logged by the page => language string shown. */
    private const MESSAGES = [
        'quiznotinview' => 'attemptwarning:quiznotinview',
        'wrongscreen' => 'attemptwarning:wrongscreen',
        'screenshare' => 'attemptwarning:screensharestopped',
        'multiplemonitors' => 'attemptwarning:multiplemonitors',
        'facenotfoundoncam' => 'facenotfoundoncam',
        'faceblur' => 'faceblurmessage',
    ];

    /**
     * The messages shown during one attempt, earliest first.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student.
     * @param int $attemptid Quiz attempt id.
     * @return array[] Each with time, message and duration (both may be '').
     */
    public static function for_attempt(int $courseid, int $cmid, int $userid, int $attemptid): array {
        global $DB;

        if ($attemptid <= 0) {
            return [];
        }
        [$typesql, $typeparams] = $DB->get_in_or_equal(
            ['warning_shown', 'warning_cleared', 'face_missing_start', 'face_missing_end'],
            SQL_PARAMS_NAMED,
            'smt'
        );
        $events = $DB->get_records_sql(
            "SELECT id, eventtype, eventdetail,
                    CASE WHEN capturedat > 0 THEN capturedat ELSE timemodified END AS eventtime
               FROM {quizaccess_proctoring_events}
              WHERE courseid = :courseid AND quizid = :cmid AND userid = :userid AND attemptid = :attemptid
                AND eventtype {$typesql}
           ORDER BY eventtime ASC, id ASC",
            ['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'attemptid' => $attemptid] + $typeparams
        );

        $rows = [];
        $open = [];
        foreach ($events as $event) {
            $detail = json_decode((string)$event->eventdetail, true);
            $detail = is_array($detail) ? $detail : [];
            $time = (int)$event->eventtime;
            switch ($event->eventtype) {
                case 'warning_shown':
                case 'face_missing_start':
                    $key = $event->eventtype === 'face_missing_start' ? 'faceblur' : (string)($detail['key'] ?? '');
                    $rows[] = ['key' => $key, 'start' => $time, 'seconds' => null];
                    $open[$key] = count($rows) - 1;
                    break;
                case 'warning_cleared':
                case 'face_missing_end':
                    $key = $event->eventtype === 'face_missing_end' ? 'faceblur' : (string)($detail['key'] ?? '');
                    if (isset($open[$key])) {
                        $index = $open[$key];
                        $rows[$index]['seconds'] = isset($detail['seconds'])
                            ? max(0, (int)$detail['seconds'])
                            : max(0, $time - $rows[$index]['start']);
                        unset($open[$key]);
                    }
                    break;
            }
        }

        $timeformat = get_string('strftimetime', 'langconfig');
        $out = [];
        foreach ($rows as $row) {
            $stringkey = self::MESSAGES[$row['key']] ?? '';
            $out[] = [
                'time' => userdate($row['start'], $timeformat),
                'message' => $stringkey !== ''
                    ? trim(strip_tags(get_string($stringkey, 'quizaccess_proctoring')))
                    : get_string('studentmessages:unknown', 'quizaccess_proctoring', $row['key']),
                'duration' => $row['seconds'] === null ? '' : format_time($row['seconds']),
            ];
        }
        return $out;
    }
}
