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

use moodle_url;
use stdClass;

/**
 * The quiz attempt's own facts, for the header of the per-student proctoring report (CPIT-475).
 *
 * A reviewer used to keep the attempt's review page open in another tab for its grade and timing;
 * the report now states them, with a link to that page.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_summary {
    /**
     * Template data for one attempt, or null when there is no such attempt of this quiz.
     *
     * @param int $attemptid Quiz attempt id.
     * @param stdClass $quiz The quiz record the report is for.
     * @return array|null started, finished, timetaken, grade, state, reviewurl
     */
    public static function for_attempt(int $attemptid, stdClass $quiz): ?array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if ($attemptid <= 0) {
            return null;
        }
        $attempt = $DB->get_record(
            'quiz_attempts',
            ['id' => $attemptid, 'quiz' => $quiz->id],
            'id, attempt, state, timestart, timefinish, sumgrades'
        );
        if (!$attempt) {
            return null;
        }

        $finished = (int)$attempt->timefinish > 0;
        $grade = '';
        if ($attempt->sumgrades !== null && $finished) {
            $grade = get_string('overallreport:scoreoutof', 'quizaccess_proctoring', (object)[
                'score' => quiz_rescale_grade($attempt->sumgrades, $quiz, true),
                'max' => format_float($quiz->grade, $quiz->decimalpoints),
            ]);
        } else if ($finished) {
            $grade = get_string('overallreport:notgraded', 'quizaccess_proctoring');
        }

        return [
            'attemptnumber' => (int)$attempt->attempt,
            'state' => get_string('state' . $attempt->state, 'quiz'),
            'started' => display_time::staff((int)$attempt->timestart),
            'finished' => $finished ? display_time::staff((int)$attempt->timefinish) : '',
            'timetaken' => $finished ? format_time(max(0, (int)$attempt->timefinish - (int)$attempt->timestart)) : '',
            'grade' => $grade,
            'reviewurl' => (new moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid]))->out(false),
        ];
    }
}
