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
 * Whether an ID verification result passes, and which check decided it.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Turns a face score and a name score into a verdict.
 *
 * The two scores used to be gated independently - each had to clear the same threshold, and either
 * one falling short failed the attempt. That reads as two safeguards and behaves as one liability,
 * because the checks are not measuring comparable things:
 *
 * - The **face** score is the identity control. The photo printed on the document has to match the
 *   person sitting at the camera, which is what stops a student using somebody else's card.
 * - The **name** score is a corroboration, and its ceiling is set by how well a webcam photographed
 *   text on a piece of plastic. Recorded failures on real attempts read "RICE" (correct but
 *   partial), "LINDSAS HOWARD X" (a misread of the student's own name) and "SIGNATURE OF THE
 *   HOLDER" (a caption printed on the card, read instead of a name) - while the same attempts
 *   matched the face at 94, 96 and 98 out of 100. Not one of them was a name that disagreed.
 *
 * So a strong face match may now carry a weak name read: when the face clears a higher bar and the
 * name is at least plausible, the attempt passes and the reviewer can see on the report that the
 * name did not stand on its own. A weak face match still faces the full name gate, so the
 * corroboration is not abandoned where the identity evidence is thin - which is the only place it
 * was ever doing work.
 *
 * Lowering the name threshold instead cannot solve this: on the recorded failures it would have to
 * drop to 50 to admit them, and at 50 the margin over a meaningless read (41) is nine points.
 */
final class id_verification_decision {

    /** @var int Default score either check must clear on its own. */
    const DEFAULT_THRESHOLD = 80;

    /** @var int Default face score that lets a weak name read through. */
    const DEFAULT_STRONG_FACE = 90;

    /** @var int Default lowest name score accepted when the face match is strong. */
    const DEFAULT_NAME_FLOOR = 45;

    /**
     * Read the decision settings, clamped so no combination of them is incoherent.
     *
     * @return array Decision configuration.
     */
    public static function config(): array {
        $int = static function (string $name, int $default): int {
            $value = get_config('quizaccess_proctoring', $name);

            return max(1, min(100, $value === false || $value === '' ? $default : (int)$value));
        };
        $bool = static function (string $name, bool $default): bool {
            $value = get_config('quizaccess_proctoring', $name);

            return $value === false || $value === '' ? $default : ((int)$value === 1);
        };

        $facethreshold = $int('idverificationfacethreshold', self::DEFAULT_THRESHOLD);
        $namethreshold = $int('idverificationnamethreshold', self::DEFAULT_THRESHOLD);

        $checkface = $bool('idverificationcheckface', true);
        $checkname = $bool('idverificationcheckname', true);
        // Both off is a misconfiguration that would verify anybody, so it means both on - the same
        // reading the rest of the plugin has always taken.
        if (!$checkface && !$checkname) {
            $checkface = true;
            $checkname = true;
        }

        return [
            'checkface' => $checkface,
            'checkname' => $checkname,
            'facethreshold' => $facethreshold,
            'namethreshold' => $namethreshold,
            'facecarries' => $bool('idverificationfacecarriesname', true),
            // Never a weaker bar than the face gate itself: carrying a name on a face score that
            // would not have passed on its own is not corroboration, it is nothing.
            'strongfacescore' => max($facethreshold, $int('idverificationstrongfacescore', self::DEFAULT_STRONG_FACE)),
            // Never stricter than the name gate it is a relaxation of.
            'namefloor' => min($namethreshold, $int('idverificationnamefloor', self::DEFAULT_NAME_FLOOR)),
        ];
    }

    /**
     * Decide an attempt from its two scores.
     *
     * Pure: every input is a parameter, so the same scores and settings always give the same
     * verdict, and both the web service that records the attempt and the report that explains it
     * afterwards reach it the same way. They used to derive it separately, which meant a student
     * could be told the name check failed on an attempt the name check had not decided.
     *
     * @param int $facescore Face match score, 0-100.
     * @param int $namescore Name match score, 0-100.
     * @param array|null $config Decision configuration; read from settings when omitted.
     * @return array{passed: bool, facefailed: bool, namefailed: bool, namecarried: bool}
     */
    public static function evaluate(int $facescore, int $namescore, ?array $config = null): array {
        $config = $config ?? self::config();

        $facefailed = !empty($config['checkface']) && $facescore < (int)$config['facethreshold'];
        $namefailed = false;
        $namecarried = false;

        if (!empty($config['checkname']) && $namescore < (int)$config['namethreshold']) {
            // The carry needs face evidence to lean on: with the face check switched off there is
            // none, so the name gate stands alone however high a face score happens to be recorded.
            $carried = !empty($config['facecarries'])
                && !empty($config['checkface'])
                && !$facefailed
                && $facescore >= (int)$config['strongfacescore']
                && $namescore >= (int)$config['namefloor'];
            if ($carried) {
                $namecarried = true;
            } else {
                $namefailed = true;
            }
        }

        return [
            'passed' => !$facefailed && !$namefailed,
            'facefailed' => $facefailed,
            'namefailed' => $namefailed,
            'namecarried' => $namecarried,
        ];
    }
}
