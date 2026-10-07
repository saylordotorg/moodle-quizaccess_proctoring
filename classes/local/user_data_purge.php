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
 * Removes a deleted user's proctoring data (CPIT-472).
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

use quizaccess_proctoring\privacy\provider;

/**
 * Deleting a Moodle account now removes the user's proctoring data too.
 *
 * Moodle's data-privacy tool already erased it through the privacy provider, but an ordinary admin
 * account deletion did not: webcam captures, ID images, the reference photo and every proctoring
 * record stayed behind. The purge runs in an ad hoc task, so a user with a long history does not hold
 * up the deletion request.
 */
final class user_data_purge {
    /**
     * Queue the purge when an account is deleted.
     *
     * @param \core\event\user_deleted $event Account deletion.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        $userid = (int)$event->objectid;
        if ($userid <= 0) {
            return;
        }
        $task = new \quizaccess_proctoring\task\purge_deleted_user_task();
        $task->set_custom_data(['userid' => $userid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Remove everything the plugin holds about one user, the way a data-privacy erasure would.
     *
     * @param int $userid User ID.
     */
    public static function purge(int $userid): void {
        if ($userid <= 0) {
            return;
        }
        provider::delete_all_user_data($userid);
    }
}
