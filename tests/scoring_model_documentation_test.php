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
 * Smoke test asserting the Risk-Scoring Model documentation exists and is complete.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Smoke test for the "Risk-Scoring Model" doc block on risk_calculator.
 *
 * Feature: proctoring-feedback-improvements
 *
 * The scoring model is documented as a canonical PHPDoc table on
 * {@see \quizaccess_proctoring\local\risk_calculator}. This test reads that class file source
 * and asserts the documentation exists and lists every scoring factor together with its cap, the
 * "clamped to 100" total rule, and the statement that the AI review outcome is never a scoring
 * input. Documenting the model in source keeps it discoverable next to the implementation it
 * describes (Requirement 16.1).
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class scoring_model_documentation_test extends advanced_testcase {

    /**
     * Read the risk_calculator class source from the plugin directory.
     *
     * @return string The raw file contents.
     */
    private function get_risk_calculator_source(): string {
        global $CFG;

        $path = $CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/risk_calculator.php';
        $this->assertFileExists($path, 'risk_calculator.php must exist to document the scoring model');

        $source = file_get_contents($path);
        $this->assertNotFalse($source, 'risk_calculator.php source must be readable');

        return (string)$source;
    }

    /**
     * Feature: proctoring-feedback-improvements
     *
     * The Risk-Scoring Model doc block exists and names every factor with its cap, states the
     * total is clamped to 100, and states AI review is never a scoring input.
     *
     * Validates: Requirements 16.1
     */
    public function test_scoring_model_documentation_lists_every_factor_and_cap(): void {
        $source = $this->get_risk_calculator_source();

        // The documentation section heading must be present.
        $this->assertStringContainsString('Risk-Scoring Model', $source,
            'The "Risk-Scoring Model" documentation section heading must be present');

        // Every factor label from the canonical table must be documented, each with its cap.
        // Each entry is [factor label, cap]. Both the label and the cap must appear in the doc block.
        $factors = [
            ['Face mismatch', 35],
            ['Multiple faces', 30],
            ['No face', 24],
            ['Screen-share issues', 36],
            ['Multiple monitors', 25],
            ['Possible AI tool', 30],
            ['AI tool w/ screenshot', 30],
            ['Clipboard/context menu', 24],
            ['Tab/focus activity', 20],
            ['F12', 15],
            ['Other keyboard shortcuts', 24],
            ['Audio', 18],
            ['Webcam missing', 15],
            ['Speed', 25],
        ];

        foreach ($factors as [$label, $cap]) {
            $this->assertStringContainsString($label, $source,
                'Scoring factor "' . $label . '" must be documented');
            $this->assertStringContainsString((string)$cap, $source,
                'Cap ' . $cap . ' for factor "' . $label . '" must be documented');
        }

        // The total-scoring rule: summed then clamped to 100.
        $this->assertStringContainsString('clamped to a maximum of 100', $source,
            'The doc block must state the total is clamped to a maximum of 100');

        // AI review outcome is never a scoring input.
        $this->assertStringContainsString('AI review is NEVER a scoring input', $source,
            'The doc block must state that AI review is never a scoring input');
    }
}
