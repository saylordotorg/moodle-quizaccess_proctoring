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
 * What makes an attempt "Flagged" on the Proctoring reviews pages.
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Decides whether an attempt with no grade hold is listed as Flagged.
 *
 * An attempt without a hold used to be flagged by any detected event at all, so a single tab
 * switch put it in the same list as a face mismatch. Student Affairs asked for the list to mean
 * something: in "rules" mode an attempt is flagged only when one of its risk factors reaches a
 * set count (for example "No face visible" three times), or its risk score reaches a set value.
 *
 * The rules read the attempt's risk result ({@see risk_calculator::calculate_many()}), so they
 * count evidence exactly as the risk score does: a factor switched off for the quiz is not
 * counted and cannot flag, and a factor a reviewer marked as a false positive does not flag.
 *
 * Every setting can be changed by a proctoring profile ({@see profile::definitions()}), so ACE
 * and degree exams can flag on less than certificate exams do.
 */
final class flag_rules {
    /** @var string Component. */
    private const COMPONENT = 'quizaccess_proctoring';

    /** @var string Any detected event flags the attempt (the behaviour before flag rules). */
    public const MODE_ANY = 'any';

    /** @var string Only the rules below flag the attempt. */
    public const MODE_RULES = 'rules';

    /** @var string Config name of the mode. */
    public const MODE_SETTING = 'flagmode';

    /** @var string Config name of the risk score rule. */
    public const SCORE_SETTING = 'flagminscore';

    /** @var string Prefix of each factor's config name. */
    public const FACTOR_PREFIX = 'flagrule_';

    /** @var int Highest count or score a rule can ask for. */
    public const MAX_VALUE = 100;

    /**
     * How many times each factor must be counted before it flags the attempt, as shipped.
     * A factor not listed never flags by itself (0). These are the values Student Affairs
     * proposed on 2026-11-11, plus "Signs of software operating the exam page", which shipped
     * after that list and is the same kind of evidence as a possible AI tool.
     *
     * @var array<string, int>
     */
    public const DEFAULT_COUNTS = [
        'facemismatch' => 1,
        'phonedetected' => 1,
        'aitool' => 1,
        'aitoolscreenshot' => 1,
        'automation' => 1,
        'webcammissing' => 1,
        'speed' => 1,
        'screenshare' => 2,
        'noface' => 3,
        'tabactivity' => 5,
    ];

    /** @var int Risk score that flags the attempt, as shipped. */
    public const DEFAULT_MIN_SCORE = 60;

    /**
     * The config name of one factor's rule.
     *
     * @param string $factor Factor key from {@see risk_calculator::FACTOR_DEFAULTS}.
     * @return string Config name.
     */
    public static function factor_setting(string $factor): string {
        return self::FACTOR_PREFIX . $factor;
    }

    /**
     * The count a factor flags at when nothing is configured.
     *
     * @param string $factor Factor key.
     * @return int Count; 0 means the factor never flags by itself.
     */
    public static function default_count(string $factor): int {
        return self::DEFAULT_COUNTS[$factor] ?? 0;
    }

    /**
     * Whether a quiz flags by rules, or by any detected event.
     *
     * @param int $quizid Quiz instance id, whose proctoring profile may change it; 0 for the site.
     * @return string {@see self::MODE_RULES} or {@see self::MODE_ANY}.
     */
    public static function mode(int $quizid = 0): string {
        return (string)profile::config($quizid, self::MODE_SETTING) === self::MODE_RULES
            ? self::MODE_RULES
            : self::MODE_ANY;
    }

    /**
     * Whether any quiz can flag by rules: the site does, or some proctoring profile does.
     *
     * Lets a report skip the scoring pass the rules need when nothing uses them.
     *
     * @return bool True when rules apply somewhere.
     */
    public static function used_anywhere(): bool {
        if (self::mode() === self::MODE_RULES) {
            return true;
        }
        foreach (profile::all_values() as $values) {
            if (($values[self::MODE_SETTING] ?? '') === self::MODE_RULES) {
                return true;
            }
        }
        return false;
    }

    /**
     * How many times a factor must be counted before it flags the attempt.
     *
     * @param string $factor Factor key from {@see risk_calculator::FACTOR_DEFAULTS}.
     * @param int $quizid Quiz instance id; 0 for the site.
     * @return int Count; 0 means the factor never flags by itself.
     */
    public static function threshold(string $factor, int $quizid = 0): int {
        return self::number(
            profile::config($quizid, self::factor_setting($factor)),
            self::default_count($factor)
        );
    }

    /**
     * The risk score at which an attempt is flagged whatever its factors say.
     *
     * @param int $quizid Quiz instance id; 0 for the site.
     * @return int Score; 0 means the score never flags by itself.
     */
    public static function min_score(int $quizid = 0): int {
        return self::number(profile::config($quizid, self::SCORE_SETTING), self::DEFAULT_MIN_SCORE);
    }

    /**
     * Why an attempt is flagged under the rules; empty when it is not.
     *
     * @param array $risk The attempt's risk result from {@see risk_calculator}.
     * @param int $quizid The attempt's quiz instance id; 0 for the site rules.
     * @return array[] One entry per rule met: 'key' (factor key, or 'score'), 'label', 'count'
     *                 and 'threshold'.
     */
    public static function reasons(array $risk, int $quizid = 0): array {
        $reasons = [];
        foreach ($risk['factors'] ?? [] as $factor) {
            $key = (string)($factor['key'] ?? '');
            if ($key === '' || !empty($factor['falsepositive'])) {
                continue;
            }
            $threshold = self::threshold($key, $quizid);
            $count = (int)($factor['count'] ?? 0);
            if ($threshold > 0 && $count >= $threshold) {
                $reasons[] = [
                    'key' => $key,
                    'label' => (string)($factor['label'] ?? $key),
                    'count' => $count,
                    'threshold' => $threshold,
                ];
            }
        }

        $minscore = self::min_score($quizid);
        $score = (int)($risk['score'] ?? 0);
        if ($minscore > 0 && $score >= $minscore) {
            $reasons[] = [
                'key' => 'score',
                'label' => get_string('riskscore:attemptscore', self::COMPONENT),
                'count' => $score,
                'threshold' => $minscore,
            ];
        }

        return $reasons;
    }

    /**
     * The reasons as one line for a report row, for example "No face visible (3) · Risk score 64".
     *
     * @param array[] $reasons Result of {@see self::reasons()}.
     * @return string Readable line, empty when there are no reasons.
     */
    public static function describe(array $reasons): string {
        $parts = [];
        foreach ($reasons as $reason) {
            $parts[] = get_string(
                $reason['key'] === 'score' ? 'flagrules:reasonscore' : 'flagrules:reasonfactor',
                self::COMPONENT,
                (object)['label' => $reason['label'], 'count' => $reason['count']]
            );
        }
        return implode(' · ', $parts);
    }

    /**
     * A stored rule value, or the default when it is unset or not a number.
     *
     * @param mixed $value Stored value.
     * @param int $default Shipped default.
     * @return int Value within 0 to {@see self::MAX_VALUE}.
     */
    private static function number($value, int $default): int {
        if ($value === false || $value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return max(0, min(self::MAX_VALUE, (int)$value));
    }
}
