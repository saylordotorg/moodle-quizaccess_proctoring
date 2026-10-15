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
 * messages Moodle sent the student about their held grade or certificate.
 *
 * The sender writes its own notification_sent row in the plugin's events table, because Moodle's
 * notifications table is not an archive: the "Delete read notifications" cleanup removes read
 * rows after the site's retention period, which would silently drop exactly the older decisions
 * an appeal needs (PR #64 review). The notifications table is still read for two things it alone
 * knows: when Moodle marked the notification read, and the sends from plugin versions that
 * predate the audit row - those are matched to the quiz by the quiz link they carried.
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

        // Every hold-decision notification Moodle still has for this student, keyed by id: the
        // read state for the plugin's own audit rows, and the full row for pre-audit sends.
        $notifications = $DB->get_records(
            'notifications',
            ['useridto' => $userid, 'component' => 'quizaccess_proctoring', 'eventtype' => 'holddecision'],
            '',
            'id, subject, fullmessage, contexturl, customdata, timeread, timecreated'
        );

        $rows = [];
        $audited = [];
        $events = $DB->get_records(
            'quizaccess_proctoring_events',
            ['quizid' => $cmid, 'userid' => $userid, 'eventtype' => 'notification_sent'],
            'timemodified ASC, id ASC',
            'id, eventdetail, timemodified'
        );
        foreach ($events as $event) {
            $detail = json_decode((string)$event->eventdetail, true);
            $detail = is_array($detail) ? $detail : [];
            $notificationid = (int)($detail['notificationid'] ?? 0);
            if ($notificationid > 0) {
                $audited[$notificationid] = true;
            }
            $rows[] = [
                'time' => (int)$event->timemodified,
                'subject' => trim((string)($detail['subject'] ?? '')),
                'message' => trim((string)($detail['body'] ?? '')),
                'read' => self::read_state($notifications[$notificationid] ?? null),
            ];
        }

        foreach ($notifications as $notification) {
            if (isset($audited[(int)$notification->id]) || !self::matches_quiz($notification, $cmid)) {
                continue;
            }
            $rows[] = [
                'time' => (int)$notification->timecreated,
                'subject' => trim((string)$notification->subject),
                'message' => trim((string)$notification->fullmessage),
                'read' => display_time::staff((int)$notification->timeread),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return $a['time'] <=> $b['time'];
        });

        return array_map(static function (array $row): array {
            return [
                'sent' => display_time::staff($row['time']),
                'subject' => $row['subject'],
                'message' => $row['message'],
                'read' => $row['read'],
            ];
        }, $rows);
    }

    /**
     * When Moodle marked a notification read, as the report shows it.
     *
     * @param \stdClass|null $notification Notification row, or null when a cleanup removed it.
     * @return string A date, '' for one still unread, or "no longer recorded" for a removed row -
     *                Moodle's cleanups delete read notifications after one retention period and
     *                all notifications after another, so a removed row says nothing either way.
     */
    private static function read_state(?\stdClass $notification): string {
        if ($notification === null) {
            return get_string('sentnotifications:readunknown', 'quizaccess_proctoring');
        }
        return display_time::staff((int)$notification->timeread);
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
