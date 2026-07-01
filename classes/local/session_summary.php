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
 * Plain-language session summary generator.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Turns a risk-factor breakdown and AI outcome into a short plain-language summary.
 *
 * The generator is deliberately pure: it depends only on its arguments (and language
 * strings) so its behaviour can be exercised with property-based tests. It never emits
 * raw telemetry key tokens (for example "viewportheight") and never lists individual
 * occurrences; high evidence counts are collapsed into a single count phrase so the
 * output stays bounded regardless of how large a count grows.
 */
final class session_summary {
    /** @var int Maximum number of contributing factors mentioned in the summary. */
    private const MAX_FACTORS = 3;

    /** @var int Maximum number of sentences the summary may contain. */
    private const MAX_SENTENCES = 3;

    /**
     * Build a one-to-three sentence plain-language summary.
     *
     * @param array $risk Result of quizaccess_proctoring_calculate_attempt_risk().
     * @param \stdClass|false $aireview AI review row, or false when none exists.
     * @return string One to three sentences; empty string only when there is nothing to report.
     */
    public static function build(array $risk, $aireview): string {
        $sentences = [];

        $factorsentence = self::build_factor_sentence($risk);
        if ($factorsentence !== '') {
            $sentences[] = $factorsentence;
        }

        $aisentence = self::build_ai_sentence($aireview);
        if ($aisentence !== '') {
            $sentences[] = $aisentence;
        }

        // Guarantee the bound even if future branches are added.
        $sentences = array_slice($sentences, 0, self::MAX_SENTENCES);

        return implode(' ', $sentences);
    }

    /**
     * Build the sentence describing the top contributing risk factors.
     *
     * @param array $risk Risk calculator result.
     * @return string A single sentence, or empty string when no factor contributes.
     */
    private static function build_factor_sentence(array $risk): string {
        $factors = self::top_factors($risk);
        if (empty($factors)) {
            return '';
        }

        $clauses = [];
        foreach ($factors as $factor) {
            $clauses[] = self::factor_clause($factor);
        }

        return get_string('sessionsummary:intro', 'quizaccess_proctoring', self::join_clauses($clauses));
    }

    /**
     * Select the highest-scoring contributing factors, capped at MAX_FACTORS.
     *
     * @param array $risk Risk calculator result.
     * @return array Ordered list of factor arrays with points > 0.
     */
    private static function top_factors(array $risk): array {
        $factors = [];
        if (!empty($risk['factors']) && is_array($risk['factors'])) {
            foreach ($risk['factors'] as $factor) {
                if (!is_array($factor) || empty($factor['haspoints'])) {
                    continue;
                }
                $factors[] = [
                    'label' => (string)($factor['label'] ?? ''),
                    'count' => (int)($factor['count'] ?? 0),
                    'points' => (int)($factor['points'] ?? 0),
                ];
            }
        }

        // Order by contribution (points), then by evidence volume, then label for stability.
        usort($factors, static function (array $a, array $b): int {
            return [$b['points'], $b['count'], $a['label']] <=> [$a['points'], $a['count'], $b['label']];
        });

        return array_slice($factors, 0, self::MAX_FACTORS);
    }

    /**
     * Phrase a single factor in plain language, collapsing high counts into a count phrase.
     *
     * @param array $factor Factor with label and count.
     * @return string Plain-language clause.
     */
    private static function factor_clause(array $factor): string {
        $label = trim((string)$factor['label']);
        $count = (int)$factor['count'];

        if ($count > 1) {
            return get_string('sessionsummary:factorcount', 'quizaccess_proctoring', (object)[
                'label' => $label,
                'count' => $count,
            ]);
        }

        return $label;
    }

    /**
     * Join factor clauses into a single grammatical list.
     *
     * @param array $clauses Plain-language clauses.
     * @return string Joined list.
     */
    private static function join_clauses(array $clauses): string {
        $clauses = array_values(array_filter($clauses, static function (string $clause): bool {
            return trim($clause) !== '';
        }));

        $total = count($clauses);
        if ($total === 0) {
            return '';
        }
        if ($total === 1) {
            return $clauses[0];
        }

        $last = array_pop($clauses);
        return get_string('sessionsummary:join', 'quizaccess_proctoring', (object)[
            'list' => implode(', ', $clauses),
            'last' => $last,
        ]);
    }

    /**
     * Build the sentence describing the AI image-review outcome, when completed.
     *
     * @param \stdClass|false $aireview AI review row or false.
     * @return string A single sentence, or empty string when there is no completed review.
     */
    private static function build_ai_sentence($aireview): string {
        if (!is_object($aireview)) {
            return '';
        }
        if ((int)($aireview->status ?? 0) !== QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE) {
            return '';
        }

        $score = (int)($aireview->reviewscore ?? 0);
        $decision = trim((string)($aireview->decision ?? ''));

        if ($decision !== '') {
            return get_string('sessionsummary:ai', 'quizaccess_proctoring', (object)[
                'score' => $score,
                'decision' => quizaccess_proctoring_get_ai_review_decision_label($decision),
            ]);
        }

        return get_string('sessionsummary:aiscore', 'quizaccess_proctoring', $score);
    }
}
