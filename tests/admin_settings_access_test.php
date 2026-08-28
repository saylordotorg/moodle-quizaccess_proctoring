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
     * The settings page itself grants a site administrator, not just the helper.
     *
     * The helper was covered; the admin tree was not, and the tree is where the page actually
     * decides. admin_settingpage::check_access() iterates req_capability, so the property has to
     * hold an array - assigning a bare string to it (which skips the constructor's own wrapping)
     * makes the loop iterate nothing and refuse everybody, site administrators included. That is
     * what shipped in 1.8.0, and no test noticed because none of them built the tree.
     */
    public function test_the_settings_page_grants_a_site_admin(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $page = admin_get_root(true, true)->locate('modsettingsquizcatproctoring');

        $this->assertNotNull($page, 'the proctoring settings page should be in the admin tree');
        $this->assertIsArray(
            $page->req_capability,
            'req_capability must be an array; check_access() iterates it'
        );
        $this->assertTrue($page->check_access());
    }

    /**
     * A manager holding the capability is granted wherever the plugin does its own checking.
     *
     * Note what this does *not* claim. Core builds the module settings tree only for users with
     * moodle/site:config, so mod/quiz/settings.php - and therefore this plugin's settings.php - is
     * never included for a manager, and /admin/settings.php?section=modsettingsquizcatproctoring is
     * not in their tree to be granted. No req_capability on the page can change that, because the
     * code setting it never runs. The capability still governs every surface that checks it
     * directly, which is what this asserts; the settings page for capability holders is a separate
     * question against core's gating.
     */
    public function test_a_manager_is_granted_where_the_plugin_checks_for_itself(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');

        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $user->id, context_system::instance()->id);
        $this->setUser($user);

        $this->assertFalse(has_capability('moodle/site:config', context_system::instance()));
        $this->assertTrue(quizaccess_proctoring_can_manage_admin_settings());

        // And if core ever does put the page in a capability holder's tree, it must grant them
        // rather than refuse - which is the bug this pair of tests exists to catch.
        $page = admin_get_root(true, true)->locate('modsettingsquizcatproctoring');
        if ($page !== null) {
            $this->assertIsArray($page->req_capability);
            $this->assertTrue($page->check_access());
        }
    }

    /**
     * A user with neither route cannot open the settings page.
     */
    public function test_the_settings_page_refuses_everyone_else(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');

        $this->resetAfterTest(true);
        $this->setUser($this->getDataGenerator()->create_user());

        $page = admin_get_root(true, true)->locate('modsettingsquizcatproctoring');
        $this->assertTrue($page === null || $page->check_access() === false);
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
