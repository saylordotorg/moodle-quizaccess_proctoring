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
 * Security regressions for outbound transport, override scope and privacy erasure.
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use quizaccess_proctoring\local\outbound_endpoint_validator;
use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\privacy\provider;

/**
 * Exercise the security boundaries through their public APIs.
 *
 * @covers \quizaccess_proctoring\local\outbound_endpoint_validator
 * @covers \quizaccess_proctoring\local\override_manager
 * @covers \quizaccess_proctoring\privacy\provider
 */
final class service_security_audit_test extends \advanced_testcase {
    /**
     * Transport must reject cleartext and IP forms that can reach internal networks.
     */
    public function test_outbound_rejects_cleartext_and_nonpublic_addresses(): void {
        foreach (
            [
            ['http://api.example.test/check', '93.184.216.34'],
            ['https://api.example.test/check', '::ffff:127.0.0.1'],
            ['https://api.example.test/check', '64:ff9b::7f00:1'],
            ['https://api.example.test/check', '2002:7f00:1::'],
            ['https://api.example.test/check', '100.100.100.200'],
            ['https://api.example.test/check', '224.0.0.1'],
            ['https://api.example.test/check', 'fec0::1'],
            ] as [$url, $ip]
        ) {
            try {
                outbound_endpoint_validator::validate($url, static function () use ($ip): array {
                    return [$ip];
                });
                $this->fail('Unsafe endpoint accepted: ' . $url . ' / ' . $ip);
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['outboundendpointinvalid', 'outboundendpointblocked']);
            }
        }
    }

    /**
     * A later DNS answer must not change the addresses used by the request.
     */
    public function test_outbound_options_pin_the_single_checked_resolution(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $calls = 0;
        $options = outbound_endpoint_validator::request_options(
            'https://api.example.test:8443/check',
            static function () use (&$calls): array {
                $calls++;
                return $calls === 1 ? ['93.184.216.34', '2606:4700:4700::1111'] : ['127.0.0.1'];
            }
        );
        $this->assertSame(1, $calls);
        $this->assertSame(['api.example.test:8443:93.184.216.34,[2606:4700:4700::1111]'], $options['CURLOPT_RESOLVE']);
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(CURLPROTO_HTTPS, $options['CURLOPT_PROTOCOLS']);
    }

    /**
     * Pins must preserve Moodle's configured IP blocklist.
     */
    public function test_outbound_pins_cannot_bypass_moodle_blocked_hosts(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '93.184.216.34');
        $this->expectException(\moodle_exception::class);
        outbound_endpoint_validator::request_options('https://api.example.test/check', static function (): array {
            return ['93.184.216.34'];
        });
    }

    /**
     * An activity-specific manager cannot waive requirements for another activity or the course.
     */
    public function test_override_scope_checks_the_target_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $otherquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $manager = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $roleid = create_role('Activity override manager', 'activityoverride', '');
        assign_capability('quizaccess/proctoring:manageoverrides', CAP_ALLOW, $roleid, $context->id);
        role_assign($roleid, $manager->id, $context->id);
        $this->setUser($manager);

        $data = (object)['userid' => $student->id, 'quizid' => $quiz->id, 'justification' => 'Approved accommodation'];
        $id = override_manager::create($context, $data);
        $this->assertGreaterThan(0, $id);
        foreach ([0, $otherquiz->id] as $quizid) {
            $data->quizid = $quizid;
            try {
                override_manager::create($context, $data);
                $this->fail('Override outside the manager capability context was accepted.');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * Erasure removes embedded personal text and pending work, not just a user ID column.
     */
    public function test_privacy_erasure_removes_complete_evidence_and_pending_jobs(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $ids = [];
        foreach ([$student, $other] as $user) {
            $base = ['courseid' => $course->id, 'quizid' => $quiz->cmid, 'userid' => $user->id];
            $logid = $DB->insert_record('quizaccess_proctoring_logs', (object)($base + [
                'webcampicture' => 'https://example.test/private-' . $user->id, 'status' => 123, 'timemodified' => time(),
            ]));
            $DB->insert_record('quizaccess_proctoring_facematch_task', (object)[
                'reportid' => $logid, 'refimageurl' => 'private-reference', 'targetimageurl' => 'private-capture',
                'timemodified' => time(),
            ]);
            $DB->insert_record('quizaccess_proctoring_events', (object)($base + [
                'reportid' => $logid, 'attemptid' => 123, 'eventdetail' => 'Personal detail',
                'currenturl' => 'https://example.test/private',
            ]));
            $DB->insert_record('quizaccess_proctoring_ai_reviews', (object)($base + [
                'reportid' => $logid, 'attemptid' => 123, 'rawresponse' => 'Student name and evidence',
            ]));
            $DB->insert_record('quizaccess_proctoring_fm_warnings', (object)($base + ['reportid' => $logid]));
            $ids[$user->id] = $logid;
        }
        provider::delete_data_for_user(new approved_contextlist($student, 'quizaccess_proctoring', [$context->id]));
        foreach (['logs', 'events', 'ai_reviews', 'fm_warnings'] as $suffix) {
            $this->assertFalse($DB->record_exists('quizaccess_proctoring_' . $suffix, ['userid' => $student->id]));
            $this->assertSame(1, $DB->count_records('quizaccess_proctoring_' . $suffix));
        }
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_logs', ['id' => $ids[$student->id]]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_facematch_task', ['reportid' => $ids[$student->id]]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_facematch_task', ['reportid' => $ids[$other->id]]));
    }

    /**
     * Course and quiz overrides, including historical reasons, participate in discovery and erasure.
     */
    public function test_privacy_overrides_discovered_and_deleted_in_their_contexts(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $modulecontext = \context_module::instance($quiz->cmid);
        $coursecontext = \context_course::instance($course->id);
        $student = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $ids = [];
        foreach ([0, $quiz->id] as $quizid) {
            $id = $DB->insert_record('quizaccess_proctoring_overrides', (object)[
                'courseid' => $course->id, 'quizid' => $quizid, 'userid' => $student->id,
                'justification' => 'Private accommodation', 'grantedby' => $staff->id,
            ]);
            $DB->insert_record('quizaccess_proctoring_override_audit', (object)[
                'overrideid' => $id, 'actorid' => $staff->id, 'fieldname' => 'justification',
                'oldvalue' => 'Original private accommodation', 'newvalue' => 'Private accommodation',
            ]);
            $ids[$quizid] = $id;
        }
        foreach ([$student, $staff] as $user) {
            $contexts = array_map('intval', provider::get_contexts_for_userid($user->id)->get_contextids());
            $this->assertContains((int)$coursecontext->id, $contexts);
            $this->assertContains((int)$modulecontext->id, $contexts);
        }
        $users = new userlist($coursecontext, 'quizaccess_proctoring');
        provider::get_users_in_context($users);
        $this->assertContains((int)$student->id, array_map('intval', $users->get_userids()));
        $this->assertContains((int)$staff->id, array_map('intval', $users->get_userids()));

        provider::delete_data_for_users(new approved_userlist($coursecontext, 'quizaccess_proctoring', [$student->id]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_overrides', ['id' => $ids[0]]));
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_override_audit', ['overrideid' => $ids[0]]));
        $this->assertTrue($DB->record_exists('quizaccess_proctoring_overrides', ['id' => $ids[$quiz->id]]));
        provider::delete_data_for_all_users_in_context($modulecontext);
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_overrides'));
        $this->assertSame(0, $DB->count_records('quizaccess_proctoring_override_audit'));
    }
}
