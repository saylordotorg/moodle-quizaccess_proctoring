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
 * Integration tests for the per-student proctoring override upgrade step.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use xmldb_table;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies that the 2026062406 upgrade step creates both per-student override tables
 * idempotently and that the plugin version declarations stay in agreement.
 *
 * Feature: per-student-proctoring-overrides
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class upgrade_override_tables_test extends advanced_testcase {

    /** @var string The plugin version introducing the override tables. */
    private const OVERRIDE_TABLES_VERSION = 2026062406;

    /** @var string Main per-student override table. */
    private const TABLE_OVERRIDES = 'quizaccess_proctoring_overrides';

    /** @var string Append-only audit trail table. */
    private const TABLE_AUDIT = 'quizaccess_proctoring_override_audit';

    /**
     * The upgrade step creates both override tables from scratch and can be re-run
     * without error because each create_table() call is guarded by table_exists().
     *
     * Validates: Requirements 1.1
     */
    public function test_upgrade_step_creates_both_tables_idempotently(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        $overrides = new xmldb_table(self::TABLE_OVERRIDES);
        $audit = new xmldb_table(self::TABLE_AUDIT);

        // Both tables ship in install.xml, so a freshly installed test DB already has them.
        $this->assertTrue($dbman->table_exists($overrides),
            'Overrides table should be installed from install.xml.');
        $this->assertTrue($dbman->table_exists($audit),
            'Override audit table should be installed from install.xml.');

        // Simulate an existing site that predates the override tables by dropping them.
        $dbman->drop_table($overrides);
        $dbman->drop_table($audit);
        $this->assertFalse($dbman->table_exists($overrides));
        $this->assertFalse($dbman->table_exists($audit));

        // The plugin upgrade function calls upgrade_plugin_savepoint(), which lives in
        // lib/upgradelib.php; that library is loaded during a real upgrade but not under PHPUnit,
        // so require it explicitly before invoking the upgrade step.
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/db/upgrade.php');

        // Running the upgrade from just below the target version executes only the
        // 2026062406 block, which must create both tables. Output buffering swallows the
        // savepoint progress markers the upgrade API emits.
        ob_start();
        xmldb_quizaccess_proctoring_upgrade(self::OVERRIDE_TABLES_VERSION - 1);
        ob_end_clean();

        $this->assertTrue($dbman->table_exists($overrides),
            'Upgrade step should create the overrides table.');
        $this->assertTrue($dbman->table_exists($audit),
            'Upgrade step should create the override audit table.');

        // Re-running the same step must be a no-op: the table_exists guards prevent a
        // "table already exists" failure, proving the step is idempotent.
        ob_start();
        xmldb_quizaccess_proctoring_upgrade(self::OVERRIDE_TABLES_VERSION - 1);
        ob_end_clean();

        $this->assertTrue($dbman->table_exists($overrides),
            'Overrides table should still exist after re-running the upgrade step.');
        $this->assertTrue($dbman->table_exists($audit),
            'Override audit table should still exist after re-running the upgrade step.');
    }

    /**
     * The created override table carries the tri-state requirement columns that default
     * to inherit (-1), confirming the upgrade produced the intended schema.
     *
     * Validates: Requirements 1.1
     */
    public function test_upgrade_step_creates_expected_override_columns(): void {
        global $DB;
        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        $columns = $DB->get_columns(self::TABLE_OVERRIDES);

        foreach (['captchastate', 'webcamstate', 'idverificationstate', 'screensharestate', 'multimonitorstate']
                as $statecolumn) {
            $this->assertArrayHasKey($statecolumn, $columns,
                "Overrides table should define the {$statecolumn} tri-state column.");
        }

        foreach (['courseid', 'quizid', 'userid', 'justification', 'expiry', 'revoked', 'grantedby',
                'timecreated', 'timemodified'] as $column) {
            $this->assertArrayHasKey($column, $columns,
                "Overrides table should define the {$column} column.");
        }

        $auditcolumns = $DB->get_columns(self::TABLE_AUDIT);
        foreach (['overrideid', 'actorid', 'action', 'fieldname', 'oldvalue', 'newvalue', 'timecreated']
                as $column) {
            $this->assertArrayHasKey($column, $auditcolumns,
                "Audit table should define the {$column} column.");
        }
    }

    /**
     * version.php and the install.xml VERSION attribute must declare the same version,
     * so the upgrade savepoint and the schema definition stay in lockstep.
     *
     * Validates: Requirements 1.1
     */
    public function test_version_php_and_install_xml_versions_agree(): void {
        global $CFG;

        $plugindir = $CFG->dirroot . '/mod/quiz/accessrule/proctoring';

        // Load the version declared in version.php.
        $plugin = new \stdClass();
        require($plugindir . '/version.php');
        $versionphp = (string) $plugin->version;

        // Extract the VERSION attribute from the XMLDB install file.
        $installxml = file_get_contents($plugindir . '/db/install.xml');
        $this->assertNotFalse($installxml, 'install.xml should be readable.');
        $this->assertSame(1, preg_match('/<XMLDB\b[^>]*\bVERSION="(\d+)"/', $installxml, $matches),
            'install.xml should declare a VERSION attribute.');
        $installxmlversion = $matches[1];

        $this->assertSame($versionphp, $installxmlversion,
            'version.php and install.xml VERSION must agree.');
        $this->assertSame((string) self::OVERRIDE_TABLES_VERSION, $versionphp,
            'The plugin version should be the override-tables version.');
    }
}
