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
 * Tests for the ID verification exception request queue.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\id_exception;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers the pending-request queue, the reason labels staff read, and the decision path
 * shared by the per-exam panel and the site-wide Proctoring reports tab.
 *
 * @covers \quizaccess_proctoring\local\id_exception
 */
final class id_exception_test extends advanced_testcase {
    /**
     * Files an exception request the way the web service does.
     *
     * @param int $cmid Quiz course module id.
     * @param int $courseid Course id.
     * @param int $userid Requesting student.
     * @param array $detail eventdetail payload.
     * @param int $when Request timestamp.
     * @return void
     */
    private function record_request(int $cmid, int $courseid, int $userid, array $detail, int $when): void {
        global $DB;

        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => 0,
            'reportid' => 0,
            'eventtype' => 'id_exemption_requested',
            'eventdetail' => json_encode($detail),
            'pagevisibility' => 'visible',
            'currenturl' => '',
            'screenshoturl' => '',
            'timemodified' => $when,
        ]);
    }

    /**
     * Creates a course with one quiz.
     *
     * @return array [courseid, cmid]
     */
    private function create_quiz(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        return [(int)$course->id, (int)$quiz->cmid];
    }

    /**
     * Creates a student enrolled in the course, as any real requester would be.
     *
     * @param int $courseid Course to enrol into.
     * @param array $fields Extra user fields.
     * @return \stdClass The user record.
     */
    private function create_student(int $courseid, array $fields = []): \stdClass {
        return $this->getDataGenerator()->create_and_enrol(get_course($courseid), 'student', $fields);
    }

    /**
     * The queue carries the category and explanation the student gave, across every exam.
     */
    public function test_pending_requests_span_exams_and_keep_student_detail(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$courseone, $cmidone] = $this->create_quiz();
        [$coursetwo, $cmidtwo] = $this->create_quiz();
        $first = $this->create_student($courseone, ['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $second = $this->create_student($coursetwo);

        $this->record_request($cmidone, $courseone, (int)$first->id, [
            'reason' => id_exception::REASON_NOID,
            'category' => 'displaced',
            'detail' => 'My documents were left behind when I fled.',
            'alternatives' => 'I have a UNHCR registration card.',
        ], 1000);
        $this->record_request($cmidtwo, $coursetwo, (int)$second->id, [
            'reason' => id_exception::REASON_CAPTURE,
            'category' => '',
            'detail' => 'The webcam picture is always blurry.',
            'alternatives' => '',
        ], 2000);

        $pending = id_exception::pending_requests();
        $this->assertCount(2, $pending);

        // Newest first, so staff work the freshest request at the top.
        $this->assertSame($cmidtwo, $pending[0]['cmid']);
        $this->assertSame($cmidone, $pending[1]['cmid']);

        $displaced = $pending[1];
        $this->assertSame('Ada Lovelace', $displaced['student']);
        $this->assertSame('My documents were left behind when I fled.', $displaced['detail']);
        $this->assertSame('I have a UNHCR registration card.', $displaced['alternatives']);
        $this->assertStringContainsString('refugee', id_exception::reason_label($displaced));

        // Scoping to one exam returns just that exam's request.
        $scoped = id_exception::pending_requests($cmidone);
        $this->assertCount(1, $scoped);
        $this->assertSame((int)$first->id, $scoped[0]['userid']);
    }

    /**
     * A decision closes the request, and a later request from the same student reopens it.
     */
    public function test_decision_closes_the_request_and_a_new_one_reopens_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$courseid, $cmid] = $this->create_quiz();
        $student = $this->create_student($courseid);

        $this->record_request($cmid, $courseid, (int)$student->id, [
            'reason' => id_exception::REASON_NOID,
            'category' => 'never',
            'detail' => 'I have never held a passport or national ID.',
        ], time() - 100);
        $this->assertCount(1, id_exception::pending_requests($cmid));

        $sink = $this->redirectEmails();
        id_exception::decide($cmid, (int)$student->id, false);
        $sink->close();
        $this->assertSame([], id_exception::pending_requests($cmid));

        // Declining leaves the requirement in place, so no override was written.
        global $DB;
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_overrides', ['userid' => $student->id]));

        // The student may ask again with more information.
        $this->record_request($cmid, $courseid, (int)$student->id, [
            'reason' => id_exception::REASON_NOID,
            'category' => 'never',
            'detail' => 'Adding the letter from my consulate.',
        ], time());
        $this->assertCount(1, id_exception::pending_requests($cmid));
    }

    /**
     * Approving waives ID verification for that student and exam, and emails them.
     */
    public function test_approval_creates_the_override_and_emails_the_student(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('idexemptioncontactemail', 'contact@saylor.org', 'quizaccess_proctoring');
        [$courseid, $cmid] = $this->create_quiz();
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $student = $this->create_student($courseid);
        $this->record_request($cmid, $courseid, (int)$student->id, [
            'reason' => id_exception::REASON_NOID,
            'category' => 'withheld',
            'detail' => 'My employer holds my passport.',
        ], time() - 60);

        $sink = $this->redirectEmails();
        id_exception::decide($cmid, (int)$student->id, true);
        $messages = $sink->get_messages();
        $sink->close();

        $override = $DB->get_record('quizaccess_proctoring_overrides', [
            'userid' => $student->id,
            'quizid' => (int)$cm->instance,
        ], '*', MUST_EXIST);
        $this->assertSame((int)override_resolver::STATE_DISABLED, (int)$override->idverificationstate);

        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame($student->email, $message->to);
        $this->assertStringContainsString('contact@saylor.org', $message->header);

        $this->assertTrue($DB->record_exists('quizaccess_proctoring_events', [
            'quizid' => $cmid,
            'userid' => $student->id,
            'eventtype' => 'id_exemption_approved',
        ]));
        $this->assertSame([], id_exception::pending_requests($cmid));
    }

    /**
     * Requests written before categories existed still label and list cleanly.
     */
    public function test_legacy_requests_without_detail_are_labelled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$courseid, $cmid] = $this->create_quiz();
        $student = $this->create_student($courseid);
        $this->record_request($cmid, $courseid, (int)$student->id, ['contact' => 'studentaffairs@saylor.org'], time());

        $pending = id_exception::pending_requests($cmid);
        $this->assertCount(1, $pending);
        $this->assertSame('', $pending[0]['detail']);
        $this->assertSame(
            get_string('idexemption:reasonlabel_unknown', 'quizaccess_proctoring'),
            id_exception::reason_label($pending[0])
        );
        $this->assertStringContainsString(
            get_string('idexemption:nodetail', 'quizaccess_proctoring'),
            quizaccess_proctoring_render_id_exception_reason($pending[0])
        );
    }
}
