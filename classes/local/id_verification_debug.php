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
 * Tester-facing diagnostics for an ID verification result (CPIT-502).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Explains an ID verification result in the same terms the verdict was reached in.
 *
 * Testers on dev could only see pass/fail, so an odd name read - "RICE", "SIGNATURE OF THE
 * HOLDER" - gave them nothing to report beyond "it failed". With the site debug toggle on, the
 * precheck shows what the provider read off the document, which profile names it was compared
 * against, and how each gate decided, built here from the same id_verification_decision
 * evaluation the verdict came from so the explanation can never disagree with the outcome.
 *
 * The lines are composed server-side: the webservice hands the browser finished text, so the
 * panel needs no client-side knowledge of the decision rules and the whole thing is covered by
 * ordinary unit tests.
 */
final class id_verification_debug {
    /** @var int Profile variants shown before the list is cut, to keep the panel readable. */
    const MAX_VARIANTS_SHOWN = 8;

    /**
     * Whether the site wants diagnostics shown to test-takers.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (int)get_config('quizaccess_proctoring', 'idverificationdebug') === 1;
    }

    /**
     * Build the diagnostics panel for one verification result.
     *
     * @param array $result Normalized provider result: status, facescore, namescore,
     *                      extractedname, romanizedname, matchedprofilename, namematchreason.
     * @param string $profilename The profile name the student verified against.
     * @param string[] $profilevariants Every name variant that was offered for matching.
     * @param array|null $config Decision configuration; read from settings when omitted.
     * @return array{title: string, lines: string[]}
     */
    public static function describe(
        array $result,
        string $profilename,
        array $profilevariants,
        ?array $config = null
    ): array {
        $config = $config ?? id_verification_decision::config();
        $status = (string)($result['status'] ?? '');
        $facescore = (int)($result['facescore'] ?? 0);
        $namescore = (int)($result['namescore'] ?? 0);
        $extracted = trim((string)($result['extractedname'] ?? ''));
        $romanized = trim((string)($result['romanizedname'] ?? ''));
        $matched = trim((string)($result['matchedprofilename'] ?? ''));
        $reason = trim((string)($result['namematchreason'] ?? ''));

        $lines = [];
        $nothing = get_string('idvdebug:readnothing', 'quizaccess_proctoring');
        $lines[] = get_string('idvdebug:read', 'quizaccess_proctoring', $extracted !== '' ? $extracted : $nothing);
        if ($romanized !== '' && $romanized !== $extracted) {
            $lines[] = get_string('idvdebug:romanized', 'quizaccess_proctoring', $romanized);
        }

        $variants = array_values(array_unique(array_filter(array_map('trim', $profilevariants))));
        if (!in_array($profilename, $variants, true) && trim($profilename) !== '') {
            array_unshift($variants, trim($profilename));
        }
        if (count($variants) > self::MAX_VARIANTS_SHOWN) {
            $shown = array_slice($variants, 0, self::MAX_VARIANTS_SHOWN);
            $shown[] = '… +' . (count($variants) - self::MAX_VARIANTS_SHOWN);
            $variants = $shown;
        }
        if ($variants) {
            $lines[] = get_string('idvdebug:profile', 'quizaccess_proctoring', implode(' | ', $variants));
        }
        if ($matched !== '') {
            $lines[] = get_string('idvdebug:matched', 'quizaccess_proctoring', $matched);
        }
        if ($reason !== '') {
            $lines[] = get_string('idvdebug:reason', 'quizaccess_proctoring', $reason);
        }

        if ($status === 'pass' || $status === 'failed') {
            $decision = id_verification_decision::evaluate($facescore, $namescore, $config);

            $faceverdict = get_string('idvdebug:verdictnotchecked', 'quizaccess_proctoring');
            if (!empty($config['checkface'])) {
                $faceverdict = get_string(
                    $decision['facefailed'] ? 'idvdebug:verdictfailed' : 'idvdebug:verdictpassed',
                    'quizaccess_proctoring'
                );
            }
            $lines[] = get_string('idvdebug:face', 'quizaccess_proctoring', (object)[
                'score' => $facescore,
                'threshold' => (int)$config['facethreshold'],
                'verdict' => $faceverdict,
            ]);

            $nameverdict = get_string('idvdebug:verdictnotchecked', 'quizaccess_proctoring');
            if (!empty($config['checkname'])) {
                if ($decision['namecarried']) {
                    $nameverdict = get_string('idvdebug:verdictcarried', 'quizaccess_proctoring');
                } else if ($decision['nameadvisory']) {
                    $nameverdict = get_string('idvdebug:verdictadvisory', 'quizaccess_proctoring');
                } else if ($decision['namefailed']) {
                    $nameverdict = get_string('idvdebug:verdictfailed', 'quizaccess_proctoring');
                } else {
                    $nameverdict = get_string('idvdebug:verdictpassed', 'quizaccess_proctoring');
                }
            }
            $lines[] = get_string('idvdebug:name', 'quizaccess_proctoring', (object)[
                'score' => $namescore,
                'threshold' => (int)$config['namethreshold'],
                'floor' => (int)$config['namefloor'],
                'strongface' => (int)$config['strongfacescore'],
                'verdict' => $nameverdict,
            ]);

            $lines[] = get_string('idvdebug:result', 'quizaccess_proctoring', get_string(
                $decision['passed'] ? 'idvdebug:verdictpassed' : 'idvdebug:verdictfailed',
                'quizaccess_proctoring'
            ));
        } else {
            // retry / error / anything else: the attempt was not decided, and score zeros or
            // decision flags shown as if it had been would send a tester chasing a gate that
            // never ran.
            $lines[] = get_string('idvdebug:nodecision', 'quizaccess_proctoring', $status);
        }

        return [
            'title' => get_string('idvdebug:title', 'quizaccess_proctoring'),
            'lines' => $lines,
        ];
    }
}
