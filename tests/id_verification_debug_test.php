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
 * Tests for the tester-facing ID verification diagnostics (CPIT-502).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\id_verification_debug;

/**
 * The diagnostics panel says what was read and which gate decided, in the verdict's own terms.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\id_verification_debug
 */
final class id_verification_debug_test extends advanced_testcase {

    /** @var array A decision configuration with every knob at its default. */
    private const CONFIG = [
        'checkface' => true,
        'checkname' => true,
        'facethreshold' => 80,
        'namethreshold' => 80,
        'nameblocks' => true,
        'facecarries' => true,
        'strongfacescore' => 90,
        'namefloor' => 45,
    ];

    /**
     * A provider result with the given overrides.
     *
     * @param array $overrides Fields to change.
     * @return array
     */
    private function providerresult(array $overrides = []): array {
        return $overrides + [
            'status' => 'pass',
            'facescore' => 94,
            'namescore' => 88,
            'extractedname' => 'LINDSAY RICE',
            'romanizedname' => '',
            'matchedprofilename' => 'Lindsay Rice',
            'namematchreason' => 'fuzzy',
        ];
    }

    /**
     * The joined panel text for a result.
     *
     * @param array $result Provider result.
     * @param array $config Decision configuration.
     * @param string[] $variants Profile variants.
     * @return string
     */
    private function panel(array $result, array $config = self::CONFIG, array $variants = ['Lindsay Rice']): string {
        $described = id_verification_debug::describe($result, 'Lindsay Rice', $variants, $config);
        $this->assertNotEmpty($described['title']);

        return implode("\n", $described['lines']);
    }

    /**
     * A clean pass explains both gates and the extraction.
     */
    public function test_pass_shows_extraction_and_both_gates(): void {
        $text = $this->panel($this->providerresult());

        $this->assertStringContainsString('LINDSAY RICE', $text);
        $this->assertStringContainsString('Lindsay Rice', $text);
        $this->assertStringContainsString('score 94 vs threshold 80', $text);
        $this->assertStringContainsString('score 88 vs threshold 80', $text);
        $this->assertStringContainsString('Result: passed', $text);
        $this->assertStringNotContainsString('carried', $text);
    }

    /**
     * A weak name read carried by a strong face says so, instead of a bare pass.
     */
    public function test_carried_name_is_called_out(): void {
        $text = $this->panel($this->providerresult(['facescore' => 96, 'namescore' => 52]));

        $this->assertStringContainsString('carried by the strong face match', $text);
        $this->assertStringContainsString('floor 45, strong face 90', $text);
        $this->assertStringContainsString('Result: passed', $text);
    }

    /**
     * On a site where the name never blocks, a shortfall reads as advisory.
     */
    public function test_advisory_name_is_called_out(): void {
        $config = ['nameblocks' => false] + self::CONFIG;
        $text = $this->panel($this->providerresult(['facescore' => 85, 'namescore' => 30]), $config);

        $this->assertStringContainsString('advisory only', $text);
        $this->assertStringContainsString('Result: passed', $text);
    }

    /**
     * A failed face names the gate that decided.
     */
    public function test_failed_face_names_the_gate(): void {
        $text = $this->panel($this->providerresult(['status' => 'failed', 'facescore' => 40, 'namescore' => 95]));

        $this->assertStringContainsString('score 40 vs threshold 80 — failed', $text);
        $this->assertStringContainsString('score 95 vs threshold 80', $text);
        $this->assertStringContainsString('Result: failed', $text);
    }

    /**
     * A provider refusal (manual review) outranks passing scores, and the panel says so.
     *
     * call_id_verification_endpoint() normalizes a manual-review response to failed while the
     * passing preliminary scores stay attached; recomputing the result from the scores would show
     * "passed" beside a failed check - the very contradiction the panel exists to explain.
     */
    public function test_provider_override_outranks_passing_scores(): void {
        $text = $this->panel($this->providerresult(['status' => 'failed', 'facescore' => 96, 'namescore' => 92]));

        $this->assertStringContainsString('provider refused the attempt', $text);
        $this->assertStringContainsString('Result: failed', $text);
        $this->assertStringNotContainsString('Result: passed', $text);
    }

    /**
     * A check that is switched off is reported as not checked, not as a zero that failed.
     */
    public function test_disabled_face_check_reads_not_checked(): void {
        $config = ['checkface' => false] + self::CONFIG;
        $text = $this->panel($this->providerresult(['facescore' => 0, 'namescore' => 95]), $config);

        $this->assertStringContainsString('score 0 vs threshold 80 — not checked', $text);
        $this->assertStringContainsString('Result: passed', $text);
    }

    /**
     * Retry and error carry no decision, so no gate lines are invented for them.
     */
    public function test_undecided_statuses_show_no_gates(): void {
        foreach (['retry', 'error'] as $status) {
            $text = $this->panel($this->providerresult([
                'status' => $status,
                'facescore' => 0,
                'namescore' => 0,
                'extractedname' => '',
                'matchedprofilename' => '',
                'namematchreason' => '',
            ]));

            $this->assertStringContainsString('No pass/fail decision was made', $text);
            $this->assertStringContainsString('"' . $status . '"', $text);
            $this->assertStringNotContainsString('threshold', $text);
            $this->assertStringContainsString('(nothing readable)', $text);
        }
    }

    /**
     * A romanized form is shown only when it adds something.
     */
    public function test_romanized_name_shown_when_different(): void {
        $text = $this->panel($this->providerresult(['extractedname' => 'Иван Петров', 'romanizedname' => 'Ivan Petrov']));
        $this->assertStringContainsString('Romanized as: Ivan Petrov', $text);

        $text = $this->panel($this->providerresult(['extractedname' => 'Ivan Petrov', 'romanizedname' => 'Ivan Petrov']));
        $this->assertStringNotContainsString('Romanized as', $text);
    }

    /**
     * Long variant lists are cut so the panel stays readable.
     */
    public function test_variant_list_is_capped(): void {
        $variants = [];
        for ($i = 1; $i <= 12; $i++) {
            $variants[] = 'Variant ' . $i;
        }
        $text = $this->panel($this->providerresult(), self::CONFIG, $variants);

        $this->assertStringContainsString('Variant 1', $text);
        $this->assertStringNotContainsString('Variant 12', $text);
        $this->assertStringContainsString('+5', $text);
    }

    /**
     * The toggle gates the whole feature and defaults to off.
     */
    public function test_enabled_reads_the_site_setting(): void {
        $this->resetAfterTest();

        $this->assertFalse(id_verification_debug::enabled());
        set_config('idverificationdebug', 1, 'quizaccess_proctoring');
        $this->assertTrue(id_verification_debug::enabled());
    }
}
