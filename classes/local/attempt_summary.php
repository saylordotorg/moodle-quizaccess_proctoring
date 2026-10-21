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
     * The box itself is core's own attempt summary, rendered by mod_quiz: the plugin's hand-built
     * copy drifted from the results report in labels ("Finished" for "Completed", "Time taken" for
     * "Duration"), in timezone, in date format, and in missing rows (Marks, the grade's
     * percentage). Reusing core's builder makes drift impossible (CPIT-475, Student Affairs).
     *
     * @param int $attemptid Quiz attempt id.
     * @param stdClass $quiz The quiz record the report is for.
     * @return array|null attemptnumber, summaryhtml, reviewurl
     */
    public static function for_attempt(int $attemptid, stdClass $quiz): ?array {
        global $CFG, $DB, $PAGE;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if ($attemptid <= 0) {
            return null;
        }
        $attempt = $DB->get_record(
            'quiz_attempts',
            ['id' => $attemptid, 'quiz' => $quiz->id],
            'id, attempt'
        );
        if (!$attempt) {
            return null;
        }

        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        $options = $attemptobj->get_display_options(true);
        // The reader is a reviewer deciding a hold: the marks must show whatever the quiz's own
        // review options would hide from the student right now.
        $options->marks = \question_display_options::MARK_AND_MAX;
        $summary = \mod_quiz\output\attempt_summary_information::create_for_attempt($attemptobj, $options);
        // The student's name and photo head the report already, and overall feedback is the
        // student's reading matter, not the reviewer's evidence.
        $summary->remove_item('user');
        $summary->remove_item('feedback');

        return [
            'attemptnumber' => (int)$attempt->attempt,
            'summaryhtml' => $PAGE->get_renderer('mod_quiz')->render($summary),
            'reviewurl' => (new moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid]))->out(false),
        ];
    }
}
