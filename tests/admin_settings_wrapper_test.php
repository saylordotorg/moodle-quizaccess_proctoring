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
 * Tests for the settings pages served to capability holders outside the admin tree.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_system;
use quizaccess_proctoring\local\admin_settings_pages;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->libdir . '/adminlib.php');

/**
 * Settings wrapper tests: the pages exist, grant the right people, and never carry a secret.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\admin_settings_pages
 */
final class admin_settings_wrapper_test extends advanced_testcase {

    /**
     * Every admin_setting on a page, flattened.
     *
     * @param \admin_settingpage $page Settings page.
     * @return \admin_setting[]
     */
    private function settings_of(\admin_settingpage $page): array {
        return array_values((array)$page->settings);
    }

    /**
     * A capability holder gets both settings pages, with no credential field on either.
     */
    public function test_capability_holder_pages_carry_no_secret(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $user->id, context_system::instance()->id);
        $this->setUser($user);

        $root = admin_settings_pages::build(false);

        foreach ([admin_settings_pages::MAIN_SECTION, admin_settings_pages::RISKFACTORS_SECTION] as $section) {
            $page = $root->locate($section);
            $this->assertInstanceOf(\admin_settingpage::class, $page, "$section must exist");
            $this->assertTrue($page->check_access(), "$section must grant a capability holder");
            foreach ($this->settings_of($page) as $setting) {
                $this->assertNotInstanceOf(
                    \admin_setting_configpasswordunmask::class,
                    $setting,
                    "a capability holder's page must never carry a credential field ({$setting->name})"
                );
            }
        }

        // The named credentials are not on the page under any other widget either - and neither
        // are the endpoints they are sent to: the server posts the stored key (and the images)
        // to whatever URL stands there, so repointing one would hand both to the new host.
        $mainnames = array_map(
            static fn(\admin_setting $setting): string => (string)$setting->name,
            $this->settings_of($root->locate(admin_settings_pages::MAIN_SECTION))
        );
        $adminonly = [
            'turnstilesecretkey', 'custom_api_key', 'idverificationapikey',
            'custom_ai_endpoint', 'idverificationendpoint',
        ];
        foreach ($adminonly as $secret) {
            $this->assertNotContains($secret, $mainnames, "$secret must stay site-administrator only");
        }
    }

    /**
     * The same builder with site config produces the credential fields: the difference between the
     * two trees is the flag, not an accident of what happens to be configured.
     */
    public function test_site_admin_build_carries_the_credential_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = admin_settings_pages::build(true)->locate(admin_settings_pages::MAIN_SECTION);

        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $secrets = array_filter(
            $this->settings_of($page),
            static fn(\admin_setting $setting): bool => $setting instanceof \admin_setting_configpasswordunmask
        );
        $this->assertNotEmpty($secrets, 'the site-administrator build must include the credential fields');
    }

    /**
     * A user without the capability is refused: settings.php does not even register the pages
     * when the building user holds neither route, and a page that did exist would deny access.
     */
    public function test_pages_refuse_a_user_without_the_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $page = admin_settings_pages::build(false)->locate(admin_settings_pages::MAIN_SECTION);

        $this->assertTrue(
            $page === null || $page->check_access() === false,
            'a user without the capability must not reach the settings'
        );
    }

    /**
     * Writes go through the settings the page defines, and only those: the save loop finds a
     * submitted value by the setting's own form name and persists it, and a name the page does
     * not define is ignored.
     */
    public function test_save_writes_only_this_pages_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $page = admin_settings_pages::build(false)->locate(admin_settings_pages::RISKFACTORS_SECTION);
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $target = null;
        foreach ($this->settings_of($page) as $setting) {
            if ($setting->name === 'risklevelmoderate') {
                $target = $setting;
            }
        }
        $this->assertNotNull($target, 'the risk level setting must be on the risk factors page');

        $data = [
            $target->get_full_name() => '25',
            's_quizaccess_proctoring_custom_api_key' => 'must-not-land',
        ];
        $written = admin_find_write_settings($page, $data);
        $this->assertArrayHasKey($target->get_full_name(), $written);
        $this->assertCount(1, $written, 'only settings this page defines may be written');

        foreach ($written as $fullname => $setting) {
            $this->assertSame('', $setting->write_setting($data[$fullname]));
        }
        $this->assertSame('25', get_config('quizaccess_proctoring', 'risklevelmoderate'));
        $this->assertFalse(get_config('quizaccess_proctoring', 'custom_api_key'));
    }
}
