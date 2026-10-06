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

use quizaccess_proctoring\local\precheck_evidence;

/**
 * Removes what an abandoned precheck left behind (CPIT-464).
 *
 * Queues precheck face captures that no attempt claimed within a day for the image deletion
 * task, and removes self-registered reference photos that no proctored attempt followed.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_abandoned_prechecks_task extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:purge_abandoned_prechecks', 'quizaccess_proctoring');
    }

    /**
     * Queue abandoned captures and retire abandoned reference photos.
     *
     * @return void
     */
    public function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $now = time();
        $queued = precheck_evidence::queue_abandoned_captures($now);
        mtrace("Queued {$queued} abandoned precheck capture(s) for deletion.");
        $retired = precheck_evidence::retire_abandoned_references($now);
        mtrace("Removed {$retired} self-registered reference photo(s) that no proctored attempt followed.");
    }
}
