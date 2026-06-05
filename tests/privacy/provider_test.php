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
 * Privacy provider tests for quizaccess_proctoring.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\tests\provider_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider tests for quizaccess_proctoring.
 */
final class provider_test extends provider_testcase {

    /**
     * Sensitive proctoring stores and external processors must be declared in privacy metadata.
     */
    public function test_get_metadata_declares_sensitive_tables_and_processors(): void {
        $collection = provider::get_metadata(new collection('quizaccess_proctoring'));
        $items = [];
        foreach ($collection->get_collection() as $item) {
            $items[$item->get_name()] = $item;
        }

        foreach ([
            'quizaccess_proctoring_logs',
            'quizaccess_proctoring_events',
            'quizaccess_proctoring_risk_holds',
            'quizaccess_proctoring_ai_reviews',
            'quizaccess_proctoring_idv',
            'quizaccess_proctoring_user_images',
            'quizaccess_proctoring_face_images',
            'core_files',
            'openai',
            'anthropic',
            'compatibleai',
            'sayloridverification',
        ] as $itemname) {
            $this->assertArrayHasKey($itemname, $items);
        }

        $idvfields = $items['quizaccess_proctoring_idv']->get_privacy_fields();
        foreach ([
            'userid',
            'facescore',
            'namescore',
            'extractedname',
            'profilename',
            'idimageurl',
            'idbackimageurl',
            'liveimageurl',
            'errormessage',
        ] as $fieldname) {
            $this->assertArrayHasKey($fieldname, $idvfields);
        }

        $aireviewfields = $items['quizaccess_proctoring_ai_reviews']->get_privacy_fields();
        foreach (['provider', 'model', 'rawresponse', 'errormessage'] as $fieldname) {
            $this->assertArrayHasKey($fieldname, $aireviewfields);
        }

        $eventsfields = $items['quizaccess_proctoring_events']->get_privacy_fields();
        foreach (['eventdetail', 'currenturl', 'screenshoturl'] as $fieldname) {
            $this->assertArrayHasKey($fieldname, $eventsfields);
        }
    }

    /**
     * The provider should discover module/system contexts and users with proctoring data.
     */
    public function test_context_and_user_discovery_includes_proctoring_evidence(): void {
        $this->resetAfterTest();

        [$course, $quiz, $cm, $context] = $this->create_quiz_fixture();
        $student = $this->create_enrolled_user($course);
        $reviewer = $this->create_enrolled_user($course, 'teacher');
        $this->create_user_proctoring_data($course, $quiz, $cm, $context, $student, $reviewer);

        $contextids = provider::get_contexts_for_userid((int)$student->id)->get_contextids();
        $this->assertContains((int)$context->id, array_map('intval', $contextids));
        $this->assertContains((int)\context_system::instance()->id, array_map('intval', $contextids));

        $reviewercontextids = provider::get_contexts_for_userid((int)$reviewer->id)->get_contextids();
        $this->assertContains((int)$context->id, array_map('intval', $reviewercontextids));

        $moduleuserlist = new userlist($context, 'quizaccess_proctoring');
        provider::get_users_in_context($moduleuserlist);
        $moduleuserids = array_map('intval', $moduleuserlist->get_userids());
        $this->assertContains((int)$student->id, $moduleuserids);
        $this->assertContains((int)$reviewer->id, $moduleuserids);

        $systemuserlist = new userlist(\context_system::instance(), 'quizaccess_proctoring');
        provider::get_users_in_context($systemuserlist);
        $this->assertContains((int)$student->id, array_map('intval', $systemuserlist->get_userids()));
    }

    /**
     * Deleting one user's privacy data should remove their images/ID rows and preserve another student's data.
     */
    public function test_delete_data_for_user_removes_selected_student_evidence_only(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $quiz, $cm, $context] = $this->create_quiz_fixture();
        $student = $this->create_enrolled_user($course);
        $otherstudent = $this->create_enrolled_user($course);
        $reviewer = $this->create_enrolled_user($course, 'teacher');

        $studentdata = $this->create_user_proctoring_data($course, $quiz, $cm, $context, $student, $reviewer);
        $otherdata = $this->create_user_proctoring_data($course, $quiz, $cm, $context, $otherstudent, $reviewer);

        $approvedcontextlist = new approved_contextlist($student, 'quizaccess_proctoring', [
            $context->id,
            \context_system::instance()->id,
        ]);
        provider::delete_data_for_user($approvedcontextlist);

        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_logs', 'userid', ['id' => $studentdata['logid']]));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_events', 'userid', ['id' => $studentdata['eventid']]));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_risk_holds', 'userid',
            ['id' => $studentdata['holdid']]));
        $this->assertSame(0, (int)$DB->get_field('quizaccess_proctoring_ai_reviews', 'userid',
            ['id' => $studentdata['aireviewid']]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_idv', ['id' => $studentdata['idvid']]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_user_images', ['id' => $studentdata['userimageid']]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_face_images', ['parentid' => $studentdata['logid']]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_face_images', [
            'parentid' => $studentdata['userimageid'],
            'parent_type' => 'admin_image',
        ]));

        $this->assert_area_file_count($context, 'picture', $studentdata['logid'], 0);
        $this->assert_area_file_count($context, 'face_image', $studentdata['logid'], 0);
        $this->assert_area_file_count($context, 'violation_screenshot', $studentdata['eventid'], 0);
        $this->assert_area_file_count($context, 'id_document', $studentdata['idvid'], 0);
        $this->assert_area_file_count($context, 'id_back_document', $studentdata['idvid'], 0);
        $this->assert_area_file_count($context, 'id_live_image', $studentdata['idvid'], 0);
        $this->assert_area_file_count(\context_system::instance(), 'user_photo', (int)$student->id, 0);
        $this->assert_area_file_count(\context_system::instance(), 'face_image', (int)$student->id, 0);

        $this->assertSame((int)$otherstudent->id, (int)$DB->get_field('quizaccess_proctoring_logs', 'userid',
            ['id' => $otherdata['logid']]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_idv', ['id' => $otherdata['idvid']]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_user_images', ['id' => $otherdata['userimageid']]));
        $this->assert_area_file_count($context, 'picture', $otherdata['logid'], 1);
        $this->assert_area_file_count(\context_system::instance(), 'user_photo', (int)$otherstudent->id, 1);
    }

    /**
     * Creates a course, quiz, course module, and module context fixture.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \context_module}
     */
    private function create_quiz_fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);

        return [$course, $quiz, $cm, $context];
    }

    /**
     * Creates and enrols a user in the supplied course.
     *
     * @param \stdClass $course Course record.
     * @param string $role Role shortname.
     * @return \stdClass User record.
     */
    private function create_enrolled_user(\stdClass $course, string $role = 'student'): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);

        return $user;
    }

    /**
     * Creates representative proctoring records and files for one user.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $quiz Quiz instance.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $student Student user.
     * @param \stdClass $reviewer Reviewer user.
     * @return array Created record IDs.
     */
    private function create_user_proctoring_data(
        \stdClass $course,
        \stdClass $quiz,
        \stdClass $cm,
        \context_module $context,
        \stdClass $student,
        \stdClass $reviewer
    ): array {
        global $DB;

        $now = time();
        $logid = $DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $student->id,
            'webcampicture' => '',
            'status' => 0,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => $now,
        ]);
        $webcampicture = $this->create_file($context, 'picture', $logid, $student);
        $DB->set_field('quizaccess_proctoring_logs', 'webcampicture', $webcampicture, ['id' => $logid]);

        $faceimage = $this->create_file($context, 'face_image', $logid, $student);
        $DB->insert_record('quizaccess_proctoring_face_images', (object)[
            'parent_type' => 'webcam_image',
            'parentid' => $logid,
            'faceimage' => $faceimage,
            'facefound' => 1,
            'timemodified' => $now,
        ]);

        $eventid = $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $student->id,
            'attemptid' => 0,
            'reportid' => $logid,
            'eventtype' => 'screen_share_stopped',
            'eventdetail' => '{"reason":"privacy test"}',
            'pagevisibility' => 'visible',
            'currenturl' => 'https://example.test/mod/quiz/attempt.php',
            'screenshoturl' => '',
            'timemodified' => $now,
        ]);
        $screenshoturl = $this->create_file($context, 'violation_screenshot', $eventid, $student);
        $DB->set_field('quizaccess_proctoring_events', 'screenshoturl', $screenshoturl, ['id' => $eventid]);

        $holdid = $DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'quizinstance' => $quiz->id,
            'userid' => $student->id,
            'attemptid' => 0,
            'reportid' => $logid,
            'riskscore' => 90,
            'threshold' => 80,
            'originalgrade' => 8.50000,
            'status' => 0,
            'reviewerid' => $reviewer->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => 0,
        ]);

        $aireviewid = $DB->insert_record('quizaccess_proctoring_ai_reviews', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $student->id,
            'attemptid' => 0,
            'reportid' => $logid,
            'eventid' => $eventid,
            'reviewtype' => 'event',
            'holdid' => $holdid,
            'riskscore' => 90,
            'triggerthreshold' => 80,
            'provider' => 'openai',
            'model' => 'privacy-test',
            'reviewscore' => 85,
            'decision' => 'needs_review',
            'status' => 2,
            'summary' => 'Privacy test summary',
            'evidence' => '{"screen":"shared"}',
            'rawresponse' => '{"decision":"needs_review"}',
            'errormessage' => '',
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => $now,
        ]);

        $idvid = $DB->insert_record('quizaccess_proctoring_idv', (object)[
            'courseid' => $course->id,
            'quizid' => $cm->id,
            'userid' => $student->id,
            'attemptid' => 0,
            'status' => 'pass',
            'facescore' => 95,
            'namescore' => 90,
            'extractedname' => fullname($student),
            'profilename' => fullname($student),
            'idimageurl' => '',
            'idbackimageurl' => '',
            'liveimageurl' => '',
            'errormessage' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->update_record('quizaccess_proctoring_idv', (object)[
            'id' => $idvid,
            'idimageurl' => $this->create_file($context, 'id_document', $idvid, $student),
            'idbackimageurl' => $this->create_file($context, 'id_back_document', $idvid, $student),
            'liveimageurl' => $this->create_file($context, 'id_live_image', $idvid, $student),
        ]);

        $userimageid = $DB->insert_record('quizaccess_proctoring_user_images', (object)[
            'user_id' => $student->id,
            'photo_draft_id' => 0,
        ]);
        $this->create_file(\context_system::instance(), 'user_photo', (int)$student->id, $student);
        $referenceface = $this->create_file(\context_system::instance(), 'face_image', (int)$student->id, $student);
        $DB->insert_record('quizaccess_proctoring_face_images', (object)[
            'parent_type' => 'admin_image',
            'parentid' => $userimageid,
            'faceimage' => $referenceface,
            'facefound' => 1,
            'timemodified' => $now,
        ]);

        return [
            'logid' => (int)$logid,
            'eventid' => (int)$eventid,
            'holdid' => (int)$holdid,
            'aireviewid' => (int)$aireviewid,
            'idvid' => (int)$idvid,
            'userimageid' => (int)$userimageid,
        ];
    }

    /**
     * Creates a stored plugin file and returns its pluginfile URL.
     *
     * @param \context $context File context.
     * @param string $filearea File area.
     * @param int $itemid File item ID.
     * @param \stdClass $user File owner.
     * @return string Pluginfile URL.
     */
    private function create_file(\context $context, string $filearea, int $itemid, \stdClass $user): string {
        $filename = $filearea . '-' . $user->id . '-' . $itemid . '.png';
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'quizaccess_proctoring',
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $user->id,
        ];
        get_file_storage()->create_file_from_string($filerecord, 'privacy test image');

        return \moodle_url::make_pluginfile_url(
            $context->id,
            'quizaccess_proctoring',
            $filearea,
            $itemid,
            '/',
            $filename,
            false
        )->out(false);
    }

    /**
     * Asserts the number of files in one plugin file area.
     *
     * @param \context $context File context.
     * @param string $filearea File area.
     * @param int $itemid File item ID.
     * @param int $expected Expected number of files.
     */
    private function assert_area_file_count(\context $context, string $filearea, int $itemid, int $expected): void {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'quizaccess_proctoring',
            $filearea,
            $itemid,
            '',
            false
        );
        $this->assertCount($expected, $files);
    }
}
