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
 * Ad hoc task removing a deleted user's proctoring data (CPIT-472).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\task;

/**
 * Removes every proctoring record and file of a user whose Moodle account was deleted.
 *
 * Failures are thrown, so the task is retried and shows as failed rather than passing silently.
 */
class purge_deleted_user_task extends \core\task\adhoc_task {
    /**
     * Run the purge.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $userid = (int)($data->userid ?? 0);
        if ($userid <= 0) {
            return;
        }
        \quizaccess_proctoring\local\user_data_purge::purge($userid);
        mtrace('Removed the proctoring data of deleted user ' . $userid . '.');
    }
}
