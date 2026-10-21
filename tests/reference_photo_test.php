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
 * Tests for reference photo resets and reset requests (CPIT-476).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\reference_photo;
use quizaccess_proctoring\local\reviewer_role;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Reference photo tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\reference_photo
 * @covers \quizaccess_proctoring\event\reference_photo_reset
 * @covers \quizaccess_proctoring\event\reference_reset_requested
 */
final class reference_photo_test extends advanced_testcase {

    /**
     * Give a user a reference photo row.
     *
     * @param int $userid User.
     */
    private function photo(int $userid): void {
        global $DB;
        $DB->insert_record('quizaccess_proctoring_user_images', (object)[
            'user_id' => $userid, 'photo_draft_id' => 0, 'timeused' => 0,
        ]);
    }

    /**
     * A reviewer's reset deletes the photo and logs who did it and why.
     */
    public function test_reset_deletes_the_photo_and_logs_the_reason(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $this->photo((int)$student->id);
        $this->setUser($reviewer);

        $sink = $this->redirectEvents();
        $messages = $this->redirectMessages();
        $context = \context_module::instance($quiz->cmid);
        $this->assertTrue(reference_photo::reset((int)$student->id, 'Photo shows the ceiling', $context));
        $events = array_values(array_filter($sink->get_events(),
            fn($e) => $e instanceof \quizaccess_proctoring\event\reference_photo_reset));
        $sink->close();
        $delivered = $messages->get_messages();
        $messages->close();

        $this->assertFalse(reference_photo::exists((int)$student->id));
        $this->assertCount(1, $events);
        $this->assertSame((int)$reviewer->id, (int)$events[0]->userid);
        $this->assertSame((int)$student->id, (int)$events[0]->relateduserid);
        $this->assertSame('Photo shows the ceiling', $events[0]->other['reason']);
        // The student hears about the reset from the system, not from staff chasing them (CPIT-476).
        $this->assertCount(1, $delivered);
        $this->assertSame((int)$student->id, (int)$delivered[0]->useridto);
        $this->assertSame(get_string('referencereset:donesubject', 'quizaccess_proctoring'), $delivered[0]->subject);
    }

    /**
     * A face check passed against a photo that staff then reset no longer lets the student start.
     */
    public function test_reset_invalidates_a_pending_face_pass(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->photo((int)$student->id);
        $this->setUser($student);
        quizaccess_proctoring_set_face_preflight_passed((int)$quiz->cmid);
        $this->assertTrue(quizaccess_proctoring_has_face_preflight_passed((int)$quiz->cmid));

        $messages = $this->redirectMessages();
        reference_photo::reset((int)$student->id, 'Wrong person', \context_module::instance($quiz->cmid));
        $messages->close();
        $this->assertFalse(quizaccess_proctoring_has_face_preflight_passed((int)$quiz->cmid));
    }

    /**
     * A student's request goes to the proctoring reviewers, one at a time while open.
     */
    public function test_request_reaches_reviewers_once_while_open(): void {
        $this->resetAfterTest();
        $role = reviewer_role::ensure();
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign($role->id, $reviewer->id, \context_system::instance());
        $student = $this->getDataGenerator()->create_user();
        $this->photo((int)$student->id);
        $this->setUser($student);

        $this->assertTrue(reference_photo::can_request((int)$student->id));
        $messages = $this->redirectMessages();
        $events = $this->redirectEvents();
        $sent = reference_photo::request_reset((int)$student->id, 'It is blurry');
        $delivered = $messages->get_messages();
        $requested = array_filter($events->get_events(),
            fn($e) => $e instanceof \quizaccess_proctoring\event\reference_reset_requested);
        $messages->close();
        $events->close();

        $this->assertSame(1, $sent);
        $this->assertCount(1, $delivered);
        $this->assertSame((int)$reviewer->id, (int)$delivered[0]->useridto);
        $this->assertStringContainsString('It is blurry', $delivered[0]->fullmessage);
        // The notification points reviewers at the queue, where decisions are marked (CPIT-476).
        $this->assertStringContainsString('view=photoresets', $delivered[0]->fullmessage);
        $this->assertCount(1, $requested);
        $this->assertFalse(reference_photo::can_request((int)$student->id));
        $open = reference_photo::open_request((int)$student->id);
        $this->assertNotNull($open, 'the request is a queue row, not just an email');
        $this->assertSame('It is blurry', $open->reason);

        // A repeat while the first request waits is refused and notifies nobody.
        $messages = $this->redirectMessages();
        $this->assertSame(-1, reference_photo::request_reset((int)$student->id, 'Again'));
        $this->assertCount(0, $messages->get_messages());
        $messages->close();
    }

    /**
     * Declining a request closes it, tells the student, and lets them ask again.
     */
    public function test_decline_closes_and_notifies(): void {
        global $DB;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $this->photo((int)$student->id);
        $this->setUser($student);
        $messages = $this->redirectMessages();
        reference_photo::request_reset((int)$student->id, 'Too dark');
        $messages->close();
        $open = reference_photo::open_request((int)$student->id);

        $this->setUser($reviewer);
        $messages = $this->redirectMessages();
        $this->assertTrue(reference_photo::decline_request((int)$open->id, (int)$reviewer->id));
        $delivered = $messages->get_messages();
        $messages->close();

        $row = $DB->get_record('quizaccess_proctoring_ref_requests', ['id' => (int)$open->id]);
        $this->assertEquals(reference_photo::REQUEST_DECLINED, (int)$row->status);
        $this->assertSame((int)$reviewer->id, (int)$row->decidedby);
        $this->assertCount(1, $delivered);
        $this->assertSame((int)$student->id, (int)$delivered[0]->useridto);
        $this->assertSame(get_string('referencereset:declinedsubject', 'quizaccess_proctoring'), $delivered[0]->subject);
        // The photo stands, and the student may ask again.
        $this->assertTrue(reference_photo::exists((int)$student->id));
        $this->assertTrue(reference_photo::can_request((int)$student->id));
        // A second decline of the same row reports that someone else got there first.
        $this->assertFalse(reference_photo::decline_request((int)$open->id, (int)$reviewer->id));
        // An approval of the decided row is refused too, and deletes nothing: the queue's reset
        // button must not override a decline another reviewer already made.
        $messages = $this->redirectMessages();
        $this->assertFalse(reference_photo::reset((int)$student->id, 'Queue approval',
            \context_system::instance(), (int)$open->id));
        $this->assertCount(0, $messages->get_messages());
        $messages->close();
        $this->assertTrue(reference_photo::exists((int)$student->id));
    }

    /**
     * A staff reset answers the open request: the queue cannot show work already done.
     */
    public function test_reset_closes_the_open_request(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $this->photo((int)$student->id);
        $this->setUser($student);
        $messages = $this->redirectMessages();
        reference_photo::request_reset((int)$student->id, 'Old photo');
        $messages->close();
        $open = reference_photo::open_request((int)$student->id);

        $this->setUser($reviewer);
        $messages = $this->redirectMessages();
        $this->assertTrue(reference_photo::reset((int)$student->id, 'Queue approval',
            \context_module::instance($quiz->cmid)));
        $messages->close();

        $row = $DB->get_record('quizaccess_proctoring_ref_requests', ['id' => (int)$open->id]);
        $this->assertEquals(reference_photo::REQUEST_RESET, (int)$row->status);
        $this->assertSame((int)$reviewer->id, (int)$row->decidedby);
        $this->assertNull(reference_photo::open_request((int)$student->id));
        $this->assertSame([], reference_photo::open_requests());
    }
}
