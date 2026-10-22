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

namespace quizaccess_proctoring\task;

/**
 * Submits attempts the face strikes ended whose page never submitted them (CPIT-510).
 *
 * The page submits the attempt as soon as the server says the strikes ended it, and the next
 * attempt page submits it if that page did not. This catches the student who closed the browser
 * and never came back, on a quiz with no time limit to close the attempt otherwise.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_face_strike_attempts_task extends \core\task\scheduled_task {
    /**
     * Return task name.
     *
     * @return string Task name.
     */
    public function get_name() {
        return get_string('task:finish_face_strike_attempts', 'quizaccess_proctoring');
    }

    /**
     * Submit the attempts.
     *
     * @return void
     */
    public function execute() {
        $finished = \quizaccess_proctoring\local\face_strikes::finish_abandoned_attempts(time());
        mtrace("Submitted {$finished} Saylor Proctored Quiz attempt(s) ended by face strikes.");
    }
}
