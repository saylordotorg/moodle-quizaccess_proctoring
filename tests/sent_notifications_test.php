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
 * Tests for the notifications-sent-to-student list on the per-student report (CPIT-481).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\sent_notifications;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Sent-notification list tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\sent_notifications
 * @covers ::quizaccess_proctoring_notify_hold_decision
 */
final class sent_notifications_test extends advanced_testcase {

    /**
     * Insert a notification row as Moodle messaging stores them.
     *
     * @param int $userid Recipient.
     * @param array $override Fields to override.
     * @return int Notification id.
     */
    private function notification(int $userid, array $override = []): int {
        global $DB;
        $record = array_merge([
            'useridfrom' => 2,
            'useridto' => $userid,
            'subject' => 'Proctored quiz review complete: hold released',
            'fullmessage' => 'Your attempt has been reviewed.',
            'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => '<p>Your attempt has been reviewed.</p>',
            'smallmessage' => 'Proctored quiz review complete: hold released',
            'component' => 'quizaccess_proctoring',
            'eventtype' => 'holddecision',
            'contexturl' => null,
            'contexturlname' => null,
            'customdata' => null,
            'timeread' => null,
            'timecreated' => time() - 100,
        ], $override);
        return (int)$DB->insert_record('notifications', (object)$record);
    }

    /**
     * Insert the plugin's own audit row for a send.
     *
     * @param int $userid Student.
     * @param array $detail Event detail.
     * @param int $time Send time.
     * @param int $cmid Quiz course-module id.
     */
    private function auditrow(int $userid, array $detail, int $time, int $cmid = 90): void {
        global $DB;
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => 7, 'quizid' => $cmid, 'userid' => $userid, 'attemptid' => 3, 'reportid' => 0,
            'eventtype' => 'notification_sent', 'eventdetail' => json_encode($detail), 'timemodified' => $time,
        ]);
    }

    /**
     * The list holds this quiz's hold-decision notifications, matched by cmid or quiz link.
     */
    public function test_lists_only_this_quizs_notifications(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int)$user->id;
        $now = time();

        // Matched by the cmid recorded with the send.
        $this->notification($userid, [
            'customdata' => json_encode(['cmid' => 90, 'courseid' => 7, 'attemptid' => 3, 'decision' => 'released']),
            'timecreated' => $now - 50,
            'timeread' => $now - 40,
        ]);
        // Sent before the cmid was recorded: matched by the quiz link it carried.
        $this->notification($userid, [
            'subject' => 'Proctored quiz review complete: violation confirmed',
            'fullmessage' => 'A proctoring violation was confirmed.',
            'contexturl' => 'https://example.com/mod/quiz/view.php?id=90',
            'timecreated' => $now - 200,
        ]);
        // Another quiz, another component, another student: all left out.
        $this->notification($userid, ['customdata' => json_encode(['cmid' => 91])]);
        $this->notification($userid, ['contexturl' => 'https://example.com/mod/quiz/view.php?id=91']);
        $this->notification($userid, ['component' => 'mod_quiz']);
        $this->notification($userid + 1000, ['customdata' => json_encode(['cmid' => 90])]);
        // No cmid recorded and no link carried: cannot be placed on a quiz.
        $this->notification($userid, []);

        $rows = sent_notifications::for_student(90, $userid);

        $this->assertCount(2, $rows);
        // Earliest first, and the exact words that were sent.
        $this->assertSame('Proctored quiz review complete: violation confirmed', $rows[0]['subject']);
        $this->assertSame('A proctoring violation was confirmed.', $rows[0]['message']);
        $this->assertSame('', $rows[0]['read'], 'an unread notification shows no read time');
        $this->assertSame('Proctored quiz review complete: hold released', $rows[1]['subject']);
        $this->assertNotSame('', $rows[1]['read']);
        $this->assertNotSame('', $rows[1]['sent']);

        $this->assertSame([], sent_notifications::for_student(0, $userid));
        $this->assertSame([], sent_notifications::for_student(90, 0));
    }

    /**
     * The plugin's own audit row keeps a send listed after Moodle's notification cleanup, and a
     * send recorded in both places is listed once, with the read state from the notification.
     */
    public function test_audit_rows_survive_notification_cleanup(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int)$user->id;
        $now = time();

        // Still in the notifications table, read: listed once, with the read time.
        $keptid = $this->notification($userid, [
            'customdata' => json_encode(['cmid' => 90]),
            'timecreated' => $now - 500,
            'timeread' => $now - 400,
        ]);
        $this->auditrow($userid, [
            'decision' => 'released',
            'subject' => 'Kept subject',
            'body' => 'Kept body.',
            'notificationid' => $keptid,
        ], $now - 500);
        // The cleanup removed this one; only the audit row remains.
        $this->auditrow($userid, [
            'decision' => 'confirmed',
            'subject' => 'Purged subject',
            'body' => 'Purged body.',
            'notificationid' => $keptid + 1000,
        ], $now - 200);
        // Another quiz's audit row is not listed.
        $this->auditrow($userid, [
            'decision' => 'released', 'subject' => 'Other quiz', 'body' => 'x', 'notificationid' => 0,
        ], $now - 100, 91);

        $rows = sent_notifications::for_student(90, $userid);

        $this->assertCount(2, $rows);
        $this->assertSame('Kept subject', $rows[0]['subject']);
        $this->assertSame('Kept body.', $rows[0]['message']);
        $this->assertNotSame('', $rows[0]['read']);
        $this->assertSame('Purged subject', $rows[1]['subject']);
        $this->assertSame(
            get_string('sentnotifications:readunknown', 'quizaccess_proctoring'),
            $rows[1]['read'],
            'a purged notification cannot say whether it was read'
        );
    }

    /**
     * A hold-decision send records which quiz and attempt it was about.
     */
    public function test_notify_hold_decision_records_the_quiz(): void {
        $this->resetAfterTest();
        set_config('holddecisionnotify', 1, 'quizaccess_proctoring');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $user = $generator->create_user();

        $hold = (object)[
            'courseid' => (int)$course->id,
            'quizid' => (int)$quiz->cmid,
            'quizinstance' => (int)$quiz->id,
            'userid' => (int)$user->id,
            'attemptid' => 12345,
        ];

        $sink = $this->redirectMessages();
        quizaccess_proctoring_notify_hold_decision($hold, 'released');
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $custom = json_decode((string)$messages[0]->customdata, true);
        $this->assertSame((int)$quiz->cmid, (int)$custom['cmid']);
        $this->assertSame((int)$course->id, (int)$custom['courseid']);
        $this->assertSame(12345, (int)$custom['attemptid']);
        $this->assertSame('released', $custom['decision']);

        // The plugin's own audit row, so the send outlives Moodle's notification cleanup.
        global $DB;
        $events = $DB->get_records('quizaccess_proctoring_events',
            ['eventtype' => 'notification_sent', 'userid' => (int)$user->id]);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertSame((int)$quiz->cmid, (int)$event->quizid);
        $this->assertSame(12345, (int)$event->attemptid);
        $detail = json_decode((string)$event->eventdetail, true);
        $this->assertSame('released', $detail['decision']);
        $this->assertSame((int)$messages[0]->id, (int)$detail['notificationid']);
        $this->assertNotSame('', trim((string)$detail['subject']));
        $this->assertNotSame('', trim((string)$detail['body']));

        // And the report lists the send from that row alone, as after a cleanup.
        $DB->delete_records('notifications', ['id' => (int)$messages[0]->id]);
        $rows = \quizaccess_proctoring\local\sent_notifications::for_student((int)$quiz->cmid, (int)$user->id);
        $this->assertCount(1, $rows);
        $this->assertSame($detail['subject'], $rows[0]['subject']);
    }
}
