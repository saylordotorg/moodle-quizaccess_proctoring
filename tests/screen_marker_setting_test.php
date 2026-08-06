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
 * Tests for the screen check marker admin setting.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');

/**
 * Tests that the screen check marker is only required when the admin asks for it.
 *
 * The marker is what identifies *which* screen a student shared. It used to be forced on for
 * every desktop attempt, because the persistent helper window term in the derivation was
 * unconditional and the helper is used for every desktop attempt in the default "auto"
 * persistence mode. The marker has no visibility of what is drawn in front of the quiz -- the
 * browser's own share picker and sharing bubble, a notification, or another app taking focus as
 * the share is granted all hide it, and that was reported as sharing the wrong screen.
 *
 * It is now an admin setting, off by default, and that setting is the master switch: only when
 * it is on do the persistent monitor and multi-monitor policy get a say.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class screen_marker_setting_test extends advanced_testcase {

    /** @var int The version whose upgrade step records the shipped default. */
    private const MARKER_SETTING_VERSION = 2026072126;

    /** @var string[] Every multi-monitor mode the rule accepts. */
    private const MULTI_MONITOR_MODES = ['off', 'log', 'warn', 'block'];

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
     * Call the private static derivation via reflection.
     *
     * @param string $multimonitormode One of the rule's MULTI_MONITOR_* modes.
     * @param bool $usepersistentmonitor Whether the helper window holds the share.
     * @return bool
     */
    private function should_require(string $multimonitormode, bool $usepersistentmonitor): bool {
        $reflection = new \ReflectionMethod('quizaccess_proctoring', 'should_require_screen_marker');
        $reflection->setAccessible(true);
        return $reflection->invoke(null, $multimonitormode, $usepersistentmonitor);
    }

    /**
     * Read a private class constant on the rule.
     *
     * @param string $name Constant name.
     * @return mixed
     */
    private function rule_constant(string $name) {
        return (new \ReflectionClass('quizaccess_proctoring'))->getConstant($name);
    }

    /**
     * Run the upgrade from just below the version that records the setting's default.
     *
     * The savepoint rejects anything not strictly newer than the recorded plugin version, and
     * the test site is already installed at the current version, so roll the record back first.
     */
    private function run_upgrade(): void {
        set_config('version', self::MARKER_SETTING_VERSION - 1, 'quizaccess_proctoring');

        // Output buffering swallows the progress markers the upgrade API prints.
        ob_start();
        xmldb_quizaccess_proctoring_upgrade(self::MARKER_SETTING_VERSION - 1);
        ob_end_clean();
    }

    /**
     * With nothing stored, the marker must be off - matching the setting's declared default.
     *
     * A site that has never saved the setting has to behave like one that saved it unticked,
     * otherwise the check comes back for any site whose admin clicked past the new settings page.
     */
    public function test_unset_setting_means_no_marker(): void {
        unset_config('requirescreenmarker', 'quizaccess_proctoring');
        $this->assertFalse(get_config('quizaccess_proctoring', 'requirescreenmarker'));

        foreach ([true, false] as $persistent) {
            foreach (self::MULTI_MONITOR_MODES as $mode) {
                $this->assertFalse($this->should_require($mode, $persistent),
                    "marker must stay off with the setting unset (mode {$mode})");
            }
        }
    }

    /**
     * Switching the setting off keeps the marker off in every other configuration.
     *
     * This is the regression that matters: the derivation used to OR in the persistent helper
     * window unconditionally, so there was no configuration in which a desktop attempt ran
     * without the marker.
     */
    public function test_disabled_setting_beats_persistent_monitor_and_policy(): void {
        set_config('requirescreenmarker', 0, 'quizaccess_proctoring');

        foreach ([true, false] as $persistent) {
            foreach (self::MULTI_MONITOR_MODES as $mode) {
                $this->assertFalse($this->should_require($mode, $persistent),
                    "marker must be off when the setting is off (mode {$mode}, persistent "
                        . var_export($persistent, true) . ')');
            }
        }
    }

    /**
     * With the setting on, the previous behaviour returns.
     *
     * The helper window samples whichever screen it was granted, so it needs the marker to
     * identify it whatever the multi-monitor policy; blocking multiple monitors outright already
     * guarantees there is only one screen to share, so the marker is redundant there.
     */
    public function test_enabled_setting_restores_previous_behaviour(): void {
        set_config('requirescreenmarker', 1, 'quizaccess_proctoring');
        $block = $this->rule_constant('MULTI_MONITOR_BLOCK');

        $this->assertTrue($this->should_require('off', false), 'off mode needs the marker');
        $this->assertTrue($this->should_require('log', false), 'log mode needs the marker');
        $this->assertTrue($this->should_require('warn', false), 'warn mode needs the marker');
        $this->assertFalse($this->should_require($block, false),
            'blocking multiple monitors already guarantees a single screen');
        $this->assertTrue($this->should_require($block, true),
            'the helper window still needs the marker to identify the screen it was granted');
    }

    /**
     * A site upgrading with the setting never stored gets the shipped default recorded.
     */
    public function test_upgrade_records_the_shipped_default(): void {
        unset_config('requirescreenmarker', 'quizaccess_proctoring');
        $this->assertFalse(get_config('quizaccess_proctoring', 'requirescreenmarker'));

        $this->run_upgrade();

        $this->assertSame('0', get_config('quizaccess_proctoring', 'requirescreenmarker'));
    }

    /**
     * An admin who deliberately switched the check on keeps it through the upgrade.
     */
    public function test_upgrade_does_not_overwrite_an_explicit_choice(): void {
        set_config('requirescreenmarker', 1, 'quizaccess_proctoring');

        $this->run_upgrade();

        $this->assertSame('1', get_config('quizaccess_proctoring', 'requirescreenmarker'));
    }

    /**
     * The plugin version must not fall behind the savepoint that carries this step.
     */
    public function test_plugin_version_covers_the_upgrade_step(): void {
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/mod/quiz/accessrule/proctoring/version.php');

        $this->assertGreaterThanOrEqual(self::MARKER_SETTING_VERSION, (int)$plugin->version,
            'version.php must be at least the savepoint recording the marker setting default.');
    }
}
