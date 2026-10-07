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
        $context = \context_module::instance($quiz->cmid);
        $this->assertTrue(reference_photo::reset((int)$student->id, 'Photo shows the ceiling', $context));
        $events = array_values(array_filter($sink->get_events(),
            fn($e) => $e instanceof \quizaccess_proctoring\event\reference_photo_reset));
        $sink->close();

        $this->assertFalse(reference_photo::exists((int)$student->id));
        $this->assertCount(1, $events);
        $this->assertSame((int)$reviewer->id, (int)$events[0]->userid);
        $this->assertSame((int)$student->id, (int)$events[0]->relateduserid);
        $this->assertSame('Photo shows the ceiling', $events[0]->other['reason']);
    }

    /**
     * A student's request goes to the proctoring reviewers, once a day.
     */
    public function test_request_reaches_reviewers_once_a_day(): void {
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
        $this->assertCount(1, $requested);
        $this->assertFalse(reference_photo::can_request((int)$student->id));
    }
}
