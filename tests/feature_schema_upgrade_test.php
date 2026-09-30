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

namespace quizaccess_proctoring;

/**
 * Upgrade preservation for identity rechecks and evidence recovery metadata.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_quizaccess_proctoring_upgrade
 */
final class feature_schema_upgrade_test extends \advanced_testcase {
    /**
     * Existing evidence survives migration, nullable retry tokens allow older clients, and retries are idempotent.
     */
    public function test_upgrade_preserves_legacy_evidence_and_is_idempotent(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/db/upgrade.php');
        $dbman = $DB->get_manager();
        $fields = [
            'quizaccess_proctoring_logs' => ['requestid', 'capturedat'],
            'quizaccess_proctoring_events' => ['requestid', 'capturedat'],
            'quizaccess_proctoring_idv' => ['policyhash', 'profilehash', 'verifiedat'],
        ];
        foreach ($fields as $tablename => $names) {
            $table = new \xmldb_table($tablename);
            if ($tablename !== 'quizaccess_proctoring_idv') {
                $dbman->drop_index($table, new \xmldb_index('userquizrequest', XMLDB_INDEX_UNIQUE, ['userid', 'quizid', 'requestid']));
            }
            foreach ($names as $name) {
                $dbman->drop_field($table, new \xmldb_field($name));
            }
        }
        $legacylog = (object)[
            'courseid' => 2, 'quizid' => 3, 'userid' => 4, 'status' => 12,
            'webcampicture' => '', 'timemodified' => 12345,
        ];
        $logid = $DB->insert_record('quizaccess_proctoring_logs', $legacylog);
        $idvid = $DB->insert_record('quizaccess_proctoring_idv', [
            'courseid' => 2, 'quizid' => 3, 'userid' => 4, 'status' => 'pass', 'timecreated' => 12345,
        ]);
        for ($run = 0; $run < 2; $run++) {
            set_config('version', 2026092500, 'quizaccess_proctoring');
            ob_start();
            try {
                $this->assertTrue(xmldb_quizaccess_proctoring_upgrade(2026092500));
            } finally {
                ob_end_clean();
            }
            foreach ($fields as $tablename => $names) {
                foreach ($names as $name) {
                    $this->assertTrue($dbman->field_exists(new \xmldb_table($tablename), new \xmldb_field($name)));
                }
            }
        }
        $log = $DB->get_record('quizaccess_proctoring_logs', ['id' => $logid], '*', MUST_EXIST);
        $this->assertSame(12345, (int)$log->timemodified);
        $this->assertSame(0, (int)$log->capturedat);
        $this->assertNull($log->requestid);
        $verification = $DB->get_record('quizaccess_proctoring_idv', ['id' => $idvid], '*', MUST_EXIST);
        $this->assertSame('pass', $verification->status);
        $this->assertSame('', $verification->profilehash);
        $this->assertSame('', $verification->policyhash);
        $this->assertSame(0, (int)$verification->verifiedat);
        $this->assertGreaterThan(0, $DB->insert_record('quizaccess_proctoring_logs', $legacylog));
        $this->assertGreaterThan(0, $DB->insert_record('quizaccess_proctoring_logs', $legacylog));
    }
}
