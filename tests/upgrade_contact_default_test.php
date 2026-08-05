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
 * Tests for the ID exception contact address upgrade step.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests that upgrading sites end up with a usable ID exception contact address.
 *
 * The address defaults to contact@saylor.org in settings.php, but a settings.php default only
 * reaches config when defaults are applied - a CLI upgrade does that, a web upgrade only does it if
 * the admin walks the "new settings" page and saves. Everywhere the address is read treats "not
 * set" as "feature off", so without the upgrade step an upgraded site would silently keep the
 * self-service ID exception link hidden and send decision emails with no Reply-To.
 *
 * The step must also leave a deliberately emptied field empty: the setting's description promises
 * that clearing it hides the option, so '' is a decision, not a gap.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_contact_default_test extends advanced_testcase {

    /** @var int Version whose upgrade block sets the contact default. */
    private const CONTACT_DEFAULT_VERSION = 2026072125;

    /** @var string The default the plugin ships. */
    private const CONTACT_DEFAULT = 'contact@saylor.org';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG;
        // upgrade_plugin_savepoint() lives in lib/upgradelib.php, which a real upgrade loads but
        // PHPUnit does not; db/upgrade.php defines the function under test.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/db/upgrade.php');
    }

    /**
     * Run the upgrade from just below the version that carries the contact default.
     *
     * The savepoint rejects anything not strictly newer than the recorded plugin version, and the
     * test site is already installed at the current version, so roll the record back first.
     */
    private function run_upgrade(): void {
        set_config('version', self::CONTACT_DEFAULT_VERSION - 1, 'quizaccess_proctoring');

        // Output buffering swallows the progress markers the upgrade API prints.
        ob_start();
        xmldb_quizaccess_proctoring_upgrade(self::CONTACT_DEFAULT_VERSION - 1);
        ob_end_clean();
    }

    /**
     * A site upgrading with the setting never stored gets the shipped default.
     */
    public function test_absent_setting_is_filled_in(): void {
        unset_config('idexemptioncontactemail', 'quizaccess_proctoring');
        $this->assertFalse(get_config('quizaccess_proctoring', 'idexemptioncontactemail'));

        $this->run_upgrade();

        $this->assertSame(
            self::CONTACT_DEFAULT,
            get_config('quizaccess_proctoring', 'idexemptioncontactemail')
        );
    }

    /**
     * An address an admin already configured is left exactly as it is.
     */
    public function test_configured_address_is_not_overwritten(): void {
        set_config('idexemptioncontactemail', 'registrar@example.edu', 'quizaccess_proctoring');

        $this->run_upgrade();

        $this->assertSame(
            'registrar@example.edu',
            get_config('quizaccess_proctoring', 'idexemptioncontactemail')
        );
    }

    /**
     * An address an admin deliberately cleared stays cleared, because an empty field is how the
     * setting documents "hide the ID exception option entirely".
     */
    public function test_deliberately_emptied_address_stays_empty(): void {
        set_config('idexemptioncontactemail', '', 'quizaccess_proctoring');

        $this->run_upgrade();

        $this->assertSame('', get_config('quizaccess_proctoring', 'idexemptioncontactemail'));
    }

    /**
     * Re-running the step changes nothing, so a repeated or resumed upgrade is safe.
     */
    public function test_step_is_idempotent(): void {
        unset_config('idexemptioncontactemail', 'quizaccess_proctoring');

        $this->run_upgrade();
        $this->run_upgrade();

        $this->assertSame(
            self::CONTACT_DEFAULT,
            get_config('quizaccess_proctoring', 'idexemptioncontactemail')
        );
    }

    /**
     * Once filled in, the address is one the readers accept: the Reply-To helper returns it, so the
     * feature is actually on rather than merely stored.
     */
    public function test_filled_in_address_is_usable_by_the_readers(): void {
        unset_config('idexemptioncontactemail', 'quizaccess_proctoring');

        $this->run_upgrade();

        $this->assertSame(
            self::CONTACT_DEFAULT,
            \quizaccess_proctoring\local\support_contact::address()
        );
    }

    /**
     * The version that carries the step matches what the plugin declares, so the block actually
     * runs on upgrade rather than being skipped as already-applied.
     */
    public function test_step_version_matches_the_plugin_version(): void {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/mod/quiz/accessrule/proctoring/version.php');

        $this->assertGreaterThanOrEqual(self::CONTACT_DEFAULT_VERSION, (int)$plugin->version);
    }
}
