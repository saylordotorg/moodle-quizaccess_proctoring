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
 * Property-based tests for the report ORDER BY helper.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Property-based tests for quizaccess_proctoring_report_order_by().
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_report_order_by
 */
final class report_order_by_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for the property. */
    private const ITERATIONS = 200;

    /** @var string The newest-first default fragment returned for unknown input. */
    private const DEFAULT_FRAGMENT = 'timemodified DESC';

    /**
     * The allowlist the helper is specified to honour, mapping a sort key to the ordered list of
     * real report columns it sorts by. Mirrored here (independently of the implementation) so the
     * test derives its own expectation from the acceptance criteria.
     *
     * @var array<string, string[]>
     */
    private const ALLOWLIST = [
        'name' => ['lastname', 'firstname'],
        'date' => ['timemodified'],
        'risk' => ['riskscore'],
        'violations' => ['eventcount'],
    ];

    /**
     * Feature: proctoring-feedback-improvements, Property 7: Report sorting orders rows by the selected column and defaults to newest-first
     *
     * For any list of report rows and for any allowlisted sort column and direction, the fragment
     * returned by the helper, when used to sort the rows in memory, orders them by that column in
     * that direction; for any unknown sort key (or unknown direction), the fragment is the
     * newest-first default (`timemodified DESC`) and orders rows by `timemodified` descending.
     *
     * The helper returns an ORDER BY string fragment rather than performing the sort itself, so the
     * test parses the returned fragment into (column, direction) pairs, uses them to sort a
     * generated row list in memory, and asserts the result is correctly ordered. It also derives
     * the expected fragment independently from the allowlist and asserts unknown keys/directions
     * collapse to the newest-first default.
     *
     * Validates: Requirements 13.1, 13.2
     */
    public function test_report_order_by_sorts_by_selected_column_and_defaults_newest_first(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20240119);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            [$sortkey, $keyknown] = $this->generate_sort_key();
            [$dir, $dirknown] = $this->generate_direction();

            $fragment = quizaccess_proctoring_report_order_by($sortkey, $dir);

            $context = 'iteration=' . $iteration
                . ' sortkey=' . var_export($sortkey, true)
                . ' dir=' . var_export($dir, true)
                . ' fragment=' . var_export($fragment, true);

            // Determine the expected fragment purely from the acceptance criteria.
            if ($keyknown) {
                $direction = $dirknown ? strtoupper(trim($dir)) : 'DESC';
                $expectedparts = [];
                foreach (self::ALLOWLIST[strtolower(trim($sortkey))] as $column) {
                    $expectedparts[] = $column . ' ' . $direction;
                }
                $expectedfragment = implode(', ', $expectedparts);
            } else {
                // Unknown sort key => newest-first default regardless of direction (Req 13.1).
                $expectedfragment = self::DEFAULT_FRAGMENT;
            }

            $this->assertSame($expectedfragment, $fragment,
                'fragment did not match the allowlisted expectation: ' . $context);

            // Parse the returned fragment into (column, direction) pairs and use it to drive an
            // in-memory sort, proving the fragment actually orders rows as claimed (Req 13.2).
            $spec = $this->parse_fragment($fragment);
            $this->assertNotEmpty($spec, 'fragment parsed to an empty sort spec: ' . $context);

            $rows = $this->generate_rows();
            $sorted = $this->sort_rows($rows, $spec);

            // Every adjacent pair in the sorted output respects the fragment's ordering.
            for ($i = 1; $i < count($sorted); $i++) {
                $cmp = $this->compare_by_spec($sorted[$i - 1], $sorted[$i], $spec);
                $this->assertLessThanOrEqual(0, $cmp,
                    'rows out of order at index ' . $i . ': ' . $context);
            }

            // The sort keeps the same multiset of rows (nothing lost or duplicated).
            $this->assertSame(count($rows), count($sorted),
                'sorted row count changed: ' . $context);

            // When no sort is selected, the default orders by timemodified descending (Req 13.1).
            if (!$keyknown) {
                $this->assertSame([['timemodified', 'DESC']], $spec,
                    'unknown key did not yield newest-first spec: ' . $context);
                for ($i = 1; $i < count($sorted); $i++) {
                    $this->assertGreaterThanOrEqual(
                        (int) $sorted[$i]['timemodified'],
                        (int) $sorted[$i - 1]['timemodified'],
                        'default sort was not newest-first at index ' . $i . ': ' . $context);
                }
            }
        }
    }

    /**
     * Generate a sort key: either an allowlisted key (with case/whitespace noise) or an unknown one.
     *
     * @return array{0:string,1:bool} The sort key and whether it is an allowlisted (known) key.
     */
    private function generate_sort_key(): array {
        $known = array_keys(self::ALLOWLIST);
        // Genuinely unknown keys only: note that column names like 'lastname'/'timemodified' are
        // NOT allowlisted sort keys, and near-misses like 'names'/'risky' must not normalise to a
        // known key after the helper trims and lower-cases the input.
        $unknown = ['', '   ', 'email', 'lastname', 'DROP TABLE', 'timemodified', 'risk;', '123', 'names', 'risky'];

        if (mt_rand(0, 3) === 0) {
            // Unknown key branch (~1/4 of the time), exercises the newest-first default.
            return [$unknown[mt_rand(0, count($unknown) - 1)], false];
        }

        $key = $known[mt_rand(0, count($known) - 1)];
        // Add case/whitespace noise; the helper trims and lower-cases, so it stays a known key.
        return [$this->add_noise($key), true];
    }

    /**
     * Generate a direction: 'asc'/'desc' (with noise) or an unknown direction (defaults to DESC).
     *
     * @return array{0:string,1:bool} The direction and whether it is a recognised 'asc'.
     */
    private function generate_direction(): array {
        switch (mt_rand(0, 3)) {
            case 0:
                return [$this->add_noise('asc'), true];
            case 1:
                return [$this->add_noise('desc'), false];
            default:
                // Unknown direction => the helper treats it as descending.
                $bogus = ['', 'ascending', 'up', 'DESCX', '1', 'asc desc'];
                return [$bogus[mt_rand(0, count($bogus) - 1)], false];
        }
    }

    /**
     * Randomly perturb the case and surrounding whitespace of a token.
     *
     * @param string $value Token to perturb.
     * @return string The perturbed token.
     */
    private function add_noise(string $value): string {
        if (mt_rand(0, 1)) {
            $value = strtoupper($value);
        }
        if (mt_rand(0, 1)) {
            $value = ' ' . $value;
        }
        if (mt_rand(0, 1)) {
            $value .= '  ';
        }
        return $value;
    }

    /**
     * Parse an ORDER BY fragment into an ordered list of [column, DIRECTION] pairs.
     *
     * @param string $fragment ORDER BY fragment such as "lastname ASC, firstname ASC".
     * @return array<int, array{0:string,1:string}> Parsed sort spec.
     */
    private function parse_fragment(string $fragment): array {
        $spec = [];
        foreach (explode(',', $fragment) as $part) {
            $tokens = preg_split('/\s+/', trim($part));
            $this->assertCount(2, $tokens, 'unexpected fragment part: ' . $part);
            [$column, $direction] = $tokens;
            $this->assertContains($direction, ['ASC', 'DESC'],
                'fragment used a non-literal direction: ' . $part);
            $spec[] = [$column, $direction];
        }
        return $spec;
    }

    /**
     * Sort a copy of the rows according to the parsed sort spec.
     *
     * @param array<int, array<string, int|string>> $rows Rows to sort.
     * @param array<int, array{0:string,1:string}> $spec Parsed sort spec.
     * @return array<int, array<string, int|string>> Sorted rows.
     */
    private function sort_rows(array $rows, array $spec): array {
        usort($rows, function ($a, $b) use ($spec) {
            return $this->compare_by_spec($a, $b, $spec);
        });
        return $rows;
    }

    /**
     * Compare two rows using the parsed multi-column sort spec.
     *
     * @param array<string, int|string> $a First row.
     * @param array<string, int|string> $b Second row.
     * @param array<int, array{0:string,1:string}> $spec Parsed sort spec.
     * @return int Negative, zero, or positive per usort semantics.
     */
    private function compare_by_spec(array $a, array $b, array $spec): int {
        foreach ($spec as [$column, $direction]) {
            $this->assertArrayHasKey($column, $a, 'unknown sort column: ' . $column);
            $left = $a[$column];
            $right = $b[$column];
            if (is_string($left) || is_string($right)) {
                $cmp = strcasecmp((string) $left, (string) $right);
            } else {
                $cmp = $left <=> $right;
            }
            if ($direction === 'DESC') {
                $cmp = -$cmp;
            }
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }

    /**
     * Generate a random list of report rows exposing every sortable column.
     *
     * @return array<int, array<string, int|string>> Generated rows.
     */
    private function generate_rows(): array {
        $rows = [];
        $count = mt_rand(0, 12);
        $surnames = ['Smith', 'jones', 'ADAMS', 'nguyen', 'Zeta', 'brown', 'álvarez', 'smith'];
        $givennames = ['Ann', 'bob', 'Cara', 'DAN', 'eve', 'Al', 'ann'];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'lastname' => $surnames[mt_rand(0, count($surnames) - 1)],
                'firstname' => $givennames[mt_rand(0, count($givennames) - 1)],
                'timemodified' => mt_rand(1, 2000000000),
                'riskscore' => mt_rand(0, 100),
                'eventcount' => mt_rand(0, 50),
            ];
        }
        return $rows;
    }
}
