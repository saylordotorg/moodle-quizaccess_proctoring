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
 * Tests that a per-student "Single-monitor check" override reaches the attempt page.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/rule.php');

/**
 * The multi-monitor policy of the attempt page follows the student's override (CPIT-480).
 *
 * The pre-check honoured the override, but the attempt page read the site mode on its own, so a
 * student whose single-monitor check was waived was still warned, blurred and logged for a
 * second monitor once the exam was running.
 *
 * @covers \quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_monitor_override_test extends advanced_testcase {

    /**
     * Call the private static derivation via reflection.
     *
     * @param string $sitemode The quiz's mode without overrides.
     * @param bool $siteblur Whether the site blurs the quiz with extra monitors.
     * @param int $overridestate An override_resolver::STATE_* value.
     * @return array ['mode' => string, 'blur' => bool]
     */
    private function policy(string $sitemode, bool $siteblur, int $overridestate): array {
        $reflection = new \ReflectionMethod('quizaccess_proctoring', 'attempt_monitor_policy');
        $reflection->setAccessible(true);
        return $reflection->invoke(null, $sitemode, $siteblur, $overridestate);
    }

    /**
     * A waiver switches off every mode, and the blur with it.
     */
    public function test_waiver_switches_off_detection_and_blur(): void {
        foreach (['off', 'log', 'warn', 'block'] as $mode) {
            foreach ([true, false] as $blur) {
                $this->assertSame(
                    ['mode' => 'off', 'blur' => false],
                    $this->policy($mode, $blur, override_resolver::STATE_DISABLED),
                    "a waiver must switch off mode {$mode}"
                );
            }
        }
    }

    /**
     * Without an override the site policy stands, the blur rule included.
     */
    public function test_inherit_keeps_the_site_policy(): void {
        $inherit = override_resolver::STATE_INHERIT;
        $this->assertSame(['mode' => 'off', 'blur' => false], $this->policy('off', false, $inherit));
        // Blur alone is an enforcement of its own.
        $this->assertSame(['mode' => 'off', 'blur' => true], $this->policy('off', true, $inherit));
        $this->assertSame(['mode' => 'block', 'blur' => true], $this->policy('block', true, $inherit));
        // Log and Warn allow extra monitors, so they win over the blur checkbox.
        $this->assertSame(['mode' => 'log', 'blur' => false], $this->policy('log', true, $inherit));
        $this->assertSame(['mode' => 'warn', 'blur' => false], $this->policy('warn', true, $inherit));
    }

    /**
     * A forced check becomes Block where the site has it off, and leaves a set mode alone.
     */
    public function test_forced_check_is_enforceable(): void {
        $forced = override_resolver::STATE_ENABLED;
        $this->assertSame(['mode' => 'block', 'blur' => false], $this->policy('off', false, $forced));
        $this->assertSame(['mode' => 'block', 'blur' => true], $this->policy('off', true, $forced));
        $this->assertSame(['mode' => 'warn', 'blur' => false], $this->policy('warn', true, $forced));
        $this->assertSame(['mode' => 'log', 'blur' => false], $this->policy('log', false, $forced));
    }
}
