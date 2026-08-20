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
 * Tests for the ID verification verdict.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use quizaccess_proctoring\local\id_verification_decision;

defined('MOODLE_INTERNAL') || die();

/**
 * A strong face match may carry a weak name read; a weak face match may not.
 *
 * @covers \quizaccess_proctoring\local\id_verification_decision
 */
final class id_verification_decision_test extends \advanced_testcase {

    /**
     * The shipped defaults, as an explicit array rather than read from settings, so these tests
     * describe the intended rule instead of whatever a site happens to be configured with.
     *
     * @param array $overrides Values to change.
     * @return array Decision configuration.
     */
    private function config(array $overrides = []): array {
        return array_merge([
            'checkface' => true,
            'checkname' => true,
            'facethreshold' => 80,
            'namethreshold' => 80,
            'facecarries' => true,
            'strongfacescore' => 90,
            'namefloor' => 45,
        ], $overrides);
    }

    /**
     * Both scores clearing their thresholds passes, with nothing carried.
     */
    public function test_two_strong_scores_pass_outright(): void {
        $result = id_verification_decision::evaluate(99, 100, $this->config());

        $this->assertTrue($result['passed']);
        $this->assertFalse($result['facefailed']);
        $this->assertFalse($result['namefailed']);
        $this->assertFalse($result['namecarried']);
    }

    /**
     * The attempts that were wrongly blocked now pass, and the one genuine failure still fails.
     *
     * These are the real face and name scores recorded on the dev site, which is what prompted the
     * rule: three attempts by one student whose face matched at 94, 96 and 98 while the OCR read
     * "RICE", "4 1 LINDSAP INC" and "LINDSAS HOWARD X" off her licence. The fourth row is a
     * genuinely bad capture - the face scored 1 - and must still be refused.
     *
     * @dataProvider recorded_attempts_provider
     * @param int $facescore Face score.
     * @param int $namescore Name score.
     * @param bool $expectedpass Whether the attempt should pass.
     * @param bool $expectedcarry Whether the name should be carried.
     * @param string $note What the attempt was.
     */
    public function test_recorded_attempts(
        int $facescore,
        int $namescore,
        bool $expectedpass,
        bool $expectedcarry,
        string $note
    ): void {
        $result = id_verification_decision::evaluate($facescore, $namescore, $this->config());

        $this->assertSame($expectedpass, $result['passed'], $note);
        $this->assertSame($expectedcarry, $result['namecarried'], $note);
    }

    /**
     * Face and name scores recorded on real attempts.
     *
     * @return array[] Test cases.
     */
    public static function recorded_attempts_provider(): array {
        return [
            'read "4 1 LINDSAP INC"' => [96, 67, true, true, 'strong face, partial read'],
            'read "RICE" (surname only)' => [94, 50, true, true, 'strong face, surname only'],
            'read "LINDSAS HOWARD X"' => [98, 57, true, true, 'strong face, misread name'],
            'read "SIGNATURE OF THE HOLDER"' => [1, 41, false, false, 'no face match at all'],
            'clean read' => [99, 100, true, false, 'both scores strong'],
        ];
    }

    /**
     * A face match that is merely adequate does not get to carry the name.
     *
     * The point of the rule is that overwhelming face evidence makes a poor OCR read irrelevant. A
     * face score that only just cleared its own threshold is not overwhelming, so the name gate
     * stands - this is the boundary that keeps the change from being "the name check is off".
     */
    public function test_an_adequate_face_does_not_carry_a_weak_name(): void {
        $result = id_verification_decision::evaluate(85, 50, $this->config());

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['namefailed']);
        $this->assertFalse($result['namecarried']);
    }

    /**
     * Below the floor, a name read is treated as unreadable and no face score rescues it.
     */
    public function test_a_name_below_the_floor_is_not_carried(): void {
        $result = id_verification_decision::evaluate(99, 44, $this->config());

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['namefailed']);
        $this->assertFalse($result['namecarried']);
    }

    /**
     * A failing face is a failing attempt whatever the name says.
     */
    public function test_a_failed_face_fails_the_attempt(): void {
        $result = id_verification_decision::evaluate(50, 100, $this->config());

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['facefailed']);
        $this->assertFalse($result['namecarried']);
    }

    /**
     * With the carry disabled, the old both-must-pass behaviour is restored exactly.
     */
    public function test_disabling_the_carry_restores_independent_gates(): void {
        $config = $this->config(['facecarries' => false]);

        foreach ([[96, 67], [94, 50], [98, 57]] as [$face, $name]) {
            $result = id_verification_decision::evaluate($face, $name, $config);
            $this->assertFalse($result['passed'], "face {$face} name {$name} should fail");
            $this->assertTrue($result['namefailed']);
            $this->assertFalse($result['namecarried']);
        }
    }

    /**
     * With the name check off, the name score cannot fail an attempt at all.
     */
    public function test_the_name_check_can_be_switched_off(): void {
        $result = id_verification_decision::evaluate(99, 0, $this->config(['checkname' => false]));

        $this->assertTrue($result['passed']);
        $this->assertFalse($result['namefailed']);
        // Nothing was carried: the check did not run, which is a different thing from a weak read
        // being accepted, and the report should not claim otherwise.
        $this->assertFalse($result['namecarried']);
    }

    /**
     * With the face check off there is no face evidence to carry anything, so the name gate stands.
     *
     * A recorded face score is not evidence when the administrator has said not to check faces.
     */
    public function test_no_carry_when_the_face_check_is_off(): void {
        $result = id_verification_decision::evaluate(99, 50, $this->config(['checkface' => false]));

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['namefailed']);
        $this->assertFalse($result['namecarried']);
    }

    /**
     * config() clamps the two new settings so no combination of them is incoherent.
     */
    public function test_config_clamps_incoherent_settings(): void {
        // The only test here that touches settings, so it is the only one that needs the database.
        $this->resetAfterTest(true);

        set_config('idverificationfacethreshold', 80, 'quizaccess_proctoring');
        set_config('idverificationnamethreshold', 80, 'quizaccess_proctoring');
        // A carry bar below the face gate would mean a face that failed could corroborate a name.
        set_config('idverificationstrongfacescore', 40, 'quizaccess_proctoring');
        // A floor above the name gate would make the relaxation stricter than the rule it relaxes.
        set_config('idverificationnamefloor', 95, 'quizaccess_proctoring');

        $config = id_verification_decision::config();

        $this->assertGreaterThanOrEqual($config['facethreshold'], $config['strongfacescore']);
        $this->assertLessThanOrEqual($config['namethreshold'], $config['namefloor']);
    }
}
