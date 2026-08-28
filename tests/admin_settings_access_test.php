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
     * The settings page itself grants access, not just the helper the other pages use.
     *
     * The helper was covered; the admin tree was not, and that is where the page actually decides.
     * admin_settingpage::check_access() iterates req_capability, so the property has to hold an
     * array - assigning a bare string to it (which skips the constructor's own wrapping) makes the
     * loop iterate nothing and refuse everybody, site administrators included. That is exactly what
     * shipped, and no test noticed because none of them built the tree.
     *
     * @param string $who Which kind of user to run as.
     * @dataProvider settings_page_access_provider
     */
    public function test_the_settings_page_grants_access(string $who): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');

        $this->resetAfterTest(true);

        if ($who === 'siteadmin') {
            $this->setAdminUser();
        } else {
            $user = $this->getDataGenerator()->create_user();
            $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
            role_assign($managerrole->id, $user->id, context_system::instance()->id);
            $this->setUser($user);
        }

        $page = admin_get_root(true, true)->locate('modsettingsquizcatproctoring');

        $this->assertNotNull($page, 'the proctoring settings page should be in the admin tree');
        $this->assertIsArray(
            $page->req_capability,
            'req_capability must be an array; check_access() foreach-es over it'
        );
        $this->assertTrue($page->check_access(), $who . ' should be able to open the settings page');
    }

    /**
     * The two kinds of user who may administer proctoring.
     *
     * @return array[] Test cases.
     */
    public static function settings_page_access_provider(): array {
        return [
            'site administrator' => ['siteadmin'],
            'manager holding the plugin capability' => ['manager'],
        ];
    }

    /**
     * A user with neither route cannot open the settings page.
     */
    public function test_the_settings_page_refuses_everyone_else(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $this->resetAfterTest(true);
        $this->setUser($this->getDataGenerator()->create_user());

        // The page is only added to the tree for users who may manage proctoring, so being absent
        // is itself a refusal - but if it is present it must still say no.
        $page = admin_get_root(true, true)->locate('modsettingsquizcatproctoring');
        if ($page !== null) {
            $this->assertFalse($page->check_access());
        } else {
            $this->assertNull($page);
        }
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
