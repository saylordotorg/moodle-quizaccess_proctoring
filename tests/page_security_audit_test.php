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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Security regression tests for report page action targets.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use quizaccess_proctoring\local\report_access;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Regression tests for report targets and scoped evidence deletion.
 *
 * @covers \quizaccess_proctoring\local\report_access
 */
final class page_security_audit_test extends \advanced_testcase {
    /**
     * The submitted report must belong to the authorized course, quiz, student and attempt.
     */
    public function test_report_rejects_mismatched_identifiers(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $reportid = (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $quiz->cmid,
            'userid' => $student->id,
            'webcampicture' => '',
            'status' => 42,
            'timemodified' => time(),
        ]);

        $arguments = [(int)$course->id, (int)$quiz->cmid, (int)$student->id, $reportid, 42];
        $report = report_access::require_report(...$arguments);
        $this->assertSame($reportid, (int)$report->id);
        $this->assertSame(42, (int)$report->status);

        foreach (array_keys($arguments) as $index) {
            $mismatch = $arguments;
            $mismatch[$index]++;
            try {
                report_access::require_report(...$mismatch);
                $this->fail('Mismatched report target was accepted at index ' . $index);
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidrequest', $e->errorcode);
            }
        }
    }

    /**
     * A course-wide review grant must not bypass a prohibition on one quiz.
     */
    public function test_review_respects_module_capability_prohibition(): void {
        global $DB, $PAGE;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $allowed = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $blocked = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $roleid);
        $blockedcontext = \context_module::instance($blocked->cmid);
        assign_capability('quizaccess/proctoring:reviewriskholds', CAP_PROHIBIT, $roleid, $blockedcontext->id);
        $this->setUser($teacher);

        $this->assertTrue(has_capability('quizaccess/proctoring:reviewriskholds', \context_course::instance($course->id)));
        $this->assertSame((int)$allowed->cmid, (int)report_access::require_review($course->id, $allowed->cmid)->instanceid);

        // Each action is a separate request; require_login() sets that request's page context.
        $PAGE = new \moodle_page();
        $this->expectException(\required_capability_exception::class);
        report_access::require_review($course->id, $blocked->cmid);
    }

    /**
     * A supplied course cannot lend its review authorization to another course's quiz.
     */
    public function test_review_rejects_course_module_mismatch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $othercourse->id]);

        $this->expectException(\moodle_exception::class);
        report_access::require_review($course->id, $quiz->cmid);
    }

    /**
     * Removing a reference photo must not erase a camshot face with the same parent id.
     *
     * @covers ::quizaccess_proctoring_get_image_file
     */
    public function test_reference_photo_deletion_preserves_camshot_face_with_same_parent_id(): void {
        global $DB;

        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        $fs = get_file_storage();
        $fileinfo = [
            'contextid' => $context->id,
            'component' => 'quizaccess_proctoring',
            'filearea' => 'user_photo',
            'itemid' => $student->id,
            'filepath' => '/',
            'filename' => 'reference.png',
            'userid' => $student->id,
        ];
        $photo = $fs->create_file_from_string($fileinfo, 'reference photo');
        $parentid = (int)$DB->insert_record('quizaccess_proctoring_user_images', (object)[
            'user_id' => $student->id,
            'photo_draft_id' => 0,
        ]);
        $fileinfo['filearea'] = 'face_image';
        $fileinfo['filename'] = 'reference-face.png';
        $face = $fs->create_file_from_string($fileinfo, 'reference face');
        $faceurl = \moodle_url::make_pluginfile_url(
            $context->id,
            'quizaccess_proctoring',
            'face_image',
            $student->id,
            '/',
            'reference-face.png'
        )->out(false);
        $common = ['parentid' => $parentid, 'facefound' => 1, 'timemodified' => time()];
        $adminfaceid = $DB->insert_record('quizaccess_proctoring_face_images', (object)([
            'parent_type' => 'admin_image', 'faceimage' => $faceurl,
        ] + $common));
        $camshotfaceid = $DB->insert_record('quizaccess_proctoring_face_images', (object)([
            'parent_type' => 'camshot_image', 'faceimage' => '',
        ] + $common));

        $this->assertSame((int)$photo->get_id(), (int)quizaccess_proctoring_get_image_file($student->id)->get_id());
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_face_images', ['id' => $adminfaceid]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_face_images', ['id' => $camshotfaceid]));
        $this->assertFalse($fs->get_file_by_id($face->get_id()));
    }
}
