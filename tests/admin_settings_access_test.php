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
 * Tests for access to the site-wide proctoring settings.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_system;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Tests that the proctoring settings capability grants settings access without moodle/site:config.
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_can_manage_admin_settings
 */
final class admin_settings_access_test extends advanced_testcase {
    /**
     * Site administrators always have access.
     */
    public function test_site_admin_has_access(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->assertTrue(quizaccess_proctoring_can_manage_admin_settings());
    }

    /**
     * A manager reaches the settings through the plugin capability, without moodle/site:config.
     */
    public function test_manager_has_access_without_siteconfig(): void {
        global $DB;

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $user->id, context_system::instance()->id);

        $this->setUser($user);

        // The grant comes from the plugin capability, not from site config.
        $this->assertFalse(has_capability('moodle/site:config', context_system::instance()));
        $this->assertTrue(has_capability(
            QUIZACCESS_PROCTORING_CAP_ADMIN_SETTINGS,
            context_system::instance()
        ));
        $this->assertTrue(quizaccess_proctoring_can_manage_admin_settings());
    }

    /**
     * An ordinary user, and a teacher without the capability, are both refused.
     */
    public function test_user_without_capability_has_no_access(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertFalse(quizaccess_proctoring_can_manage_admin_settings());

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        // A course-level teacher role must not reach the site-wide settings.
        $this->assertFalse(quizaccess_proctoring_can_manage_admin_settings());
    }

    /**
     * The capability is installed at the system context with the config risk flag, so Moodle warns
     * when it is assigned and it can never be granted from a course or module context.
     */
    public function test_capability_definition(): void {
        $this->resetAfterTest(true);

        $info = get_capability_info(QUIZACCESS_PROCTORING_CAP_ADMIN_SETTINGS);

        $this->assertNotNull($info, 'the proctoring settings capability is not installed');
        $this->assertSame(CONTEXT_SYSTEM, (int)$info->contextlevel);
        $this->assertSame(RISK_CONFIG, (int)$info->riskbitmask);
        $this->assertSame('write', $info->captype);
    }
}
