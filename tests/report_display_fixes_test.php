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
 * Example tests for the small report display fixes (Requirement 18).
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;

/**
 * Example tests for the report display fixes in report.php and the proctoring AMD module.
 *
 * Feature: proctoring-feedback-improvements
 *
 * report.php is a page script whose output is produced by a full Moodle page render, so it is not
 * practical to unit test its rendered HTML in isolation. Per the design's Testing Strategy these
 * criteria are covered by worked examples that assert on the page-script source: the date column
 * uses Moodle's locale-aware userdate() (18.1), "View report" is the primary/emphasized action
 * while "Delete" is de-emphasized but keeps its destructive confirm() guard (18.3), and the
 * non-actionable "This can include…" note has been removed from the client module (18.4).
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_display_fixes_test extends advanced_testcase {

    /** @var string Absolute path to the plugin root. */
    private string $pluginroot;

    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        $this->pluginroot = $CFG->dirroot . '/mod/quiz/accessrule/proctoring';
    }

    /**
     * Read a plugin file relative to the plugin root, asserting it exists.
     *
     * @param string $relative Relative path such as 'report.php'.
     * @return string File contents.
     */
    private function read_plugin_file(string $relative): string {
        $path = $this->pluginroot . '/' . ltrim($relative, '/');
        $this->assertFileExists($path, "expected plugin file to exist: {$relative}");
        $contents = file_get_contents($path);
        $this->assertIsString($contents, "could not read plugin file: {$relative}");
        return $contents;
    }

    /**
     * The report uses Moodle's locale/timezone-aware userdate() for the date columns and no longer
     * uses the raw date('Y/M/d H:i:s', ...) formatting.
     *
     * Validates: Requirements 18.1
     */
    public function test_report_uses_userdate_not_raw_date_format(): void {
        $report = $this->read_plugin_file('report.php');

        // The legacy raw date format is gone.
        $this->assertStringNotContainsString("date('Y/M/d", $report,
            "report.php must not use the raw date('Y/M/d ...) format for the report date column");

        // The row and event timestamps are rendered via userdate() (locale/timezone aware). The
        // event date takes an explicit format argument, so only the call prefix is asserted.
        $this->assertStringContainsString('userdate((int)$info->timemodified)', $report,
            'the report row date must be rendered via userdate()');
        $this->assertStringContainsString('userdate((int)$eventrecord->timemodified', $report,
            'the event date must be rendered via userdate()');
    }

    /**
     * "View report" is rendered as the primary/emphasized action and "Delete" is de-emphasized
     * (muted link, not a danger/prominent button) while retaining its destructive confirm() guard.
     *
     * Validates: Requirements 18.3
     */
    public function test_view_report_primary_and_delete_deemphasized(): void {
        $report = $this->read_plugin_file('report.php');

        // "View report" is the primary action (the viewimages string key is kept for translations).
        $this->assertStringContainsString("get_string('viewimages', 'quizaccess_proctoring')", $report,
            'report.php must render a "View report" action');
        $this->assertStringContainsString("'class' => 'btn btn-primary btn-sm'", $report,
            '"View report" must be rendered as a primary button');

        // "Delete" is de-emphasized: a muted link, never a danger/prominent button.
        $this->assertStringContainsString('btn btn-link btn-sm text-muted', $report,
            'the Delete action must be de-emphasized as a muted link');
        $this->assertStringNotContainsString('text-danger', $report,
            'the Delete action must not be styled as a prominent danger action');
        $this->assertStringNotContainsString('btn-danger', $report,
            'the Delete action must not be styled as a prominent danger button');

        // The destructive confirm() guard is retained on the Delete action.
        $this->assertStringContainsString("'onclick' => 'return confirm(", $report,
            'the Delete action must retain its destructive confirm() guard');
        $this->assertStringContainsString("areyousure_delete_record", $report,
            'the Delete confirm() guard must use the delete-record confirmation string');
    }

    /**
     * The non-actionable "This can include…" note has been removed from both the AMD source and the
     * built bundle.
     *
     * Validates: Requirements 18.4
     */
    public function test_this_can_include_note_removed_from_client_module(): void {
        $src = $this->read_plugin_file('amd/src/proctoring.js');
        $build = $this->read_plugin_file('amd/build/proctoring.min.js');

        $this->assertStringNotContainsString('This can include', $src,
            'the non-actionable "This can include…" note must be removed from amd/src/proctoring.js');
        $this->assertStringNotContainsString('This can include', $build,
            'the non-actionable "This can include…" note must be removed from the built bundle');
    }
}
