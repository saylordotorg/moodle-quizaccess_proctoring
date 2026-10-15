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
 * The hold-decision notifications a student was sent, for the per-student report (CPIT-481).
 *
 * Student Affairs asked to see not only the in-exam warnings ({@see student_messages}) but the
 * messages Moodle sent the student about their held grade or certificate. Those go through
 * Moodle messaging, so the words that were sent are already stored in the notifications table;
 * this reads them back rather than keeping a second copy.
 *
 * A notification is matched to the quiz by the cmid the sender records in its custom data, or,
 * for notifications sent before that was recorded, by the quiz link the notification carried.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sent_notifications {

    /**
     * The hold-decision notifications sent to one student about one quiz, earliest first.
     *
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student.
     * @return array[] Each with sent, subject, message and read (read may be '').
     */
    public static function for_student(int $cmid, int $userid): array {
        global $DB;

        if ($cmid <= 0 || $userid <= 0) {
            return [];
        }

        $records = $DB->get_records(
            'notifications',
            ['useridto' => $userid, 'component' => 'quizaccess_proctoring', 'eventtype' => 'holddecision'],
            'timecreated ASC, id ASC',
            'id, subject, fullmessage, contexturl, customdata, timeread, timecreated'
        );

        $out = [];
        foreach ($records as $record) {
            if (!self::matches_quiz($record, $cmid)) {
                continue;
            }
            $out[] = [
                'sent' => display_time::staff((int)$record->timecreated),
                'subject' => trim((string)$record->subject),
                'message' => trim((string)$record->fullmessage),
                'read' => display_time::staff((int)$record->timeread),
            ];
        }
        return $out;
    }

    /**
     * Whether a notification record was sent about the given quiz.
     *
     * @param \stdClass $record Notification row (customdata and contexturl).
     * @param int $cmid Quiz course-module id.
     * @return bool
     */
    private static function matches_quiz(\stdClass $record, int $cmid): bool {
        $custom = json_decode((string)($record->customdata ?? ''), true);
        if (is_array($custom) && isset($custom['cmid'])) {
            return (int)$custom['cmid'] === $cmid;
        }

        // Notifications sent before the cmid was recorded carried the quiz link as their context
        // URL, so the quiz can still be read off them. One sent while the course module was
        // already deleted carried neither; it cannot be placed and is left out.
        $contexturl = (string)($record->contexturl ?? '');
        if ($contexturl === '') {
            return false;
        }
        $path = (string)parse_url($contexturl, PHP_URL_PATH);
        if (substr($path, -strlen('/mod/quiz/view.php')) !== '/mod/quiz/view.php') {
            return false;
        }
        parse_str((string)parse_url($contexturl, PHP_URL_QUERY), $query);
        return isset($query['id']) && (int)$query['id'] === $cmid;
    }
}
