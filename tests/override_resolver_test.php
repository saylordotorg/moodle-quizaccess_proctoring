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
 * Property-based tests for the pure per-student proctoring override_resolver.
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
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_resolver.php');

/**
 * Property-based tests for override_resolver's pure resolution logic.
 *
 * Feature: per-student-proctoring-overrides
 *
 * Properties 1-3 exercise only the pure precedence logic (apply_override / pick_winner),
 * which needs no database. Property 4 exercises applicability gating (applicable_overrides),
 * which reads $DB, so the case extends advanced_testcase and uses DB fixtures via
 * resetAfterTest(). The pure properties run unchanged under advanced_testcase.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\override_resolver
 */
final class override_resolver_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for each property. */
    private const ITERATIONS = 150;

    /** @var int[] The three tri-state values an override column may hold. */
    private const STATES = [
        override_resolver::STATE_INHERIT,
        override_resolver::STATE_DISABLED,
        override_resolver::STATE_ENABLED,
    ];

    /**
     * Feature: per-student-proctoring-overrides, Property 1: Resolution matches the reference model (site → quiz → override)
     *
     * For any base requirement state and any set of applicable (pre-ordered) overrides, the
     * effective state produced by the resolver equals the state produced by a simple reference
     * implementation of the site → quiz → override precedence: the first non-inherit winning
     * override value is used, otherwise the base state is used. In particular, an all-inherit
     * override set (or no override) reproduces exactly the base state (inherit is a no-op), and
     * a non-inherit applicable override determines the outcome regardless of the base state.
     *
     * This is a model-based test: the resolver's pick_winner()/apply_override() pipeline is
     * compared against a small, obviously-correct reference resolver across many generated
     * inputs with a deterministic seed so any counterexample is reproducible.
     *
     * Validates: Requirements 2.2, 2.3, 2.4, 2.6, 3.1, 3.2, 3.3, 3.4, 5.4
     */
    public function test_resolution_matches_reference_model(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260624);

        $requirements = override_resolver::requirement_keys();

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Random base state per requirement (the collapsed site -> quiz boolean).
            $basestates = [];
            foreach ($requirements as $requirement) {
                $basestates[$requirement] = (bool)mt_rand(0, 1);
            }

            // Random, already-ordered set of overrides (0..4), each assigning a tri-state
            // value to every requirement column. Ordering/filtering is out of scope here;
            // Property 1 only asserts the precedence semantics over the ordered list.
            $overridecount = mt_rand(0, 4);
            $overrides = [];
            for ($o = 0; $o < $overridecount; $o++) {
                $overrides[] = $this->generate_override_row($requirements);
            }

            foreach ($requirements as $requirement) {
                // Resolver under test: pick the winner from the ordered list, then layer it
                // onto the base state.
                $winner = override_resolver::pick_winner($overrides, $requirement);
                $actual = override_resolver::apply_override($basestates[$requirement], $winner);

                // Reference model: first non-inherit column value in order wins, else base.
                $expected = $this->reference_resolve(
                    $basestates[$requirement], $overrides, $requirement);

                $context = 'iteration=' . $iteration
                    . ' requirement=' . $requirement
                    . ' base=' . var_export($basestates[$requirement], true)
                    . ' overrides=' . json_encode($this->summarise_overrides($overrides))
                    . ' winner=' . $winner
                    . ' actual=' . var_export($actual, true)
                    . ' expected=' . var_export($expected, true);

                $this->assertSame($expected, $actual,
                    'resolver disagreed with the reference model: ' . $context);
            }
        }
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 2: Per-requirement independence
     *
     * For any assignment of tri-state values to the five requirements, the effective state
     * resolved for a single requirement depends only on that requirement's own state (its own
     * override column across the applicable overrides) and its base state, and is unaffected by
     * the tri-state values assigned to the other four requirements.
     *
     * The property is exercised by resolving a target requirement against a reference override
     * set, then re-resolving it against a perturbed override set that is identical in the target
     * requirement's own column (and identical base state) but has every other requirement's column
     * re-randomised in every row. If resolution truly depends only on the target's own inputs, the
     * two effective states must be equal for every perturbation. A deterministic seed keeps any
     * counterexample reproducible.
     *
     * Validates: Requirements 2.1, 2.5
     */
    public function test_per_requirement_independence(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260625);

        $requirements = override_resolver::requirement_keys();

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Random base state per requirement (the collapsed site -> quiz boolean).
            $basestates = [];
            foreach ($requirements as $requirement) {
                $basestates[$requirement] = (bool)mt_rand(0, 1);
            }

            // A reference, already-ordered override set (0..4 rows) assigning a tri-state value to
            // every requirement column.
            $overridecount = mt_rand(0, 4);
            $reference = [];
            for ($o = 0; $o < $overridecount; $o++) {
                $reference[] = $this->generate_override_row($requirements);
            }

            foreach ($requirements as $target) {
                // Effective state for the target requirement under the reference override set.
                $expectedwinner = override_resolver::pick_winner($reference, $target);
                $expected = override_resolver::apply_override($basestates[$target], $expectedwinner);

                // Build a perturbed set: same number of rows, the target requirement's own column
                // preserved row-for-row, but every OTHER requirement column re-randomised.
                $perturbed = $this->perturb_other_columns($reference, $target, $requirements);

                $actualwinner = override_resolver::pick_winner($perturbed, $target);
                $actual = override_resolver::apply_override($basestates[$target], $actualwinner);

                $context = 'iteration=' . $iteration
                    . ' target=' . $target
                    . ' base=' . var_export($basestates[$target], true)
                    . ' reference=' . json_encode($this->summarise_overrides($reference))
                    . ' perturbed=' . json_encode($this->summarise_overrides($perturbed))
                    . ' expected=' . var_export($expected, true)
                    . ' actual=' . var_export($actual, true);

                $this->assertSame($expected, $actual,
                    'target requirement resolution changed when only other requirements were '
                    . 'perturbed: ' . $context);
            }
        }
    }

    /**
     * Clone an ordered override set, preserving the target requirement's own tri-state column in
     * every row while re-randomising every other requirement's column.
     *
     * Row identity (count and order) is preserved so that pick_winner()'s precedence walk sees the
     * same sequence; only the non-target columns change, which must not affect resolution of the
     * target requirement.
     *
     * @param array $overrides Reference override rows.
     * @param string $target The requirement whose own column must be preserved.
     * @param string[] $requirements All requirement keys.
     * @return array Perturbed override rows.
     */
    private function perturb_other_columns(array $overrides, string $target, array $requirements): array {
        $targetcolumn = override_resolver::STATE_COLUMNS[$target];

        $perturbed = [];
        foreach ($overrides as $override) {
            $row = new \stdClass();
            foreach ($requirements as $requirement) {
                $column = override_resolver::STATE_COLUMNS[$requirement];
                if ($column === $targetcolumn) {
                    // Preserve the target requirement's own state exactly.
                    $row->$column = $override->$column;
                } else {
                    // Re-randomise every other requirement's state independently.
                    $row->$column = self::STATES[mt_rand(0, count(self::STATES) - 1)];
                }
            }
            $perturbed[] = $row;
        }
        return $perturbed;
    }

    /**
     * Reference resolver: the obviously-correct implementation of site → quiz → override
     * precedence for a single requirement over an ordered override list.
     *
     * Walk the overrides in the given (pre-ordered) sequence; the first non-inherit value for
     * the requirement's column is the winner and replaces the base state. If every override
     * inherits (or there are none), the base state stands.
     *
     * @param bool $basestate Collapsed site -> quiz boolean for the requirement.
     * @param array $overrides Ordered override rows (stdClass with *state columns).
     * @param string $requirement One of override_resolver::REQ_*.
     * @return bool Expected effective state.
     */
    private function reference_resolve(bool $basestate, array $overrides, string $requirement): bool {
        $column = override_resolver::STATE_COLUMNS[$requirement];
        foreach ($overrides as $override) {
            $state = (int)$override->$column;
            if ($state === override_resolver::STATE_DISABLED) {
                return false;
            }
            if ($state === override_resolver::STATE_ENABLED) {
                return true;
            }
            // STATE_INHERIT: keep looking at the next, less-specific override.
        }
        return $basestate;
    }

    /**
     * Generate a single override row assigning a random tri-state value to every requirement
     * column.
     *
     * @param string[] $requirements Requirement keys.
     * @return \stdClass Override row with the five *state columns populated.
     */
    private function generate_override_row(array $requirements): \stdClass {
        $row = new \stdClass();
        foreach ($requirements as $requirement) {
            $column = override_resolver::STATE_COLUMNS[$requirement];
            $row->$column = self::STATES[mt_rand(0, count(self::STATES) - 1)];
        }
        return $row;
    }

    /**
     * Reduce override rows to their state columns for compact failure reporting.
     *
     * @param array $overrides Override rows.
     * @return array<int, array<string, int>> Per-row column => state maps.
     */
    private function summarise_overrides(array $overrides): array {
        $summary = [];
        foreach ($overrides as $override) {
            $summary[] = (array)$override;
        }
        return $summary;
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 3: Deterministic conflict tie-break
     *
     * For any collection of two or more applicable overrides assigning non-inherit values to the
     * same requirement, the resolver selects the value from the override with the most specific
     * scope (quiz-scoped over course-scoped), breaking remaining ties by the most recently created
     * override (greatest timecreated, then greatest id), yielding a single deterministic winner.
     *
     * This is a model-based test. pick_winner() consumes a PRE-ORDERED array (the ordering is done
     * by applicable_overrides()), so each generated override carries quizid/timecreated/id fields
     * and is ordered by a local helper that mirrors applicable_overrides()' exact usort (quiz-scoped
     * first, then timecreated descending, then id descending). The winning tri-state returned by
     * pick_winner() over that ordered list is compared against an independent reference selection
     * that picks the single deterministic winner directly from the unordered set. A deterministic
     * seed keeps any counterexample reproducible.
     *
     * Validates: Requirements 3.5
     */
    public function test_deterministic_conflict_tiebreak(): void {
        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260626);

        $requirements = override_resolver::requirement_keys();

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Generate two or more scoped overrides. Unique ids guarantee a total, deterministic
            // ordering; a small timecreated range and a mix of quiz/course scopes force genuine
            // scope- and recency-level ties that must be broken deterministically.
            $overridecount = mt_rand(2, 5);
            $unordered = [];
            for ($o = 0; $o < $overridecount; $o++) {
                $unordered[] = $this->generate_scoped_override_row($requirements, $o + 1);
            }

            // Resolver input contract: pick_winner() expects the array already ordered by
            // applicable_overrides()' tie-break. Mirror that ordering locally.
            $ordered = $this->order_like_applicable_overrides($unordered);

            foreach ($requirements as $requirement) {
                $column = override_resolver::STATE_COLUMNS[$requirement];

                // Independent reference: the single deterministic winner among the non-inherit
                // overrides for this requirement, chosen by scope specificity then recency.
                $referencewinner = $this->reference_tiebreak_winner($unordered, $requirement);
                $expected = ($referencewinner === null)
                    ? override_resolver::STATE_INHERIT
                    : (int)$referencewinner->$column;

                $actual = override_resolver::pick_winner($ordered, $requirement);

                $context = 'iteration=' . $iteration
                    . ' requirement=' . $requirement
                    . ' unordered=' . json_encode($this->summarise_overrides($unordered))
                    . ' ordered=' . json_encode($this->summarise_overrides($ordered))
                    . ' expected=' . $expected
                    . ' actual=' . $actual;

                $this->assertSame($expected, $actual,
                    'pick_winner did not select the deterministic tie-break winner: ' . $context);
            }
        }
    }

    /**
     * Order an override set exactly as override_resolver::applicable_overrides() does before
     * handing it to pick_winner(): quiz-scoped (quizid != 0) first, then most recently created
     * (timecreated descending), then greatest id first as a stable final tie-break.
     *
     * @param array $overrides Unordered override rows.
     * @return array Rows ordered for the deterministic tie-break.
     */
    private function order_like_applicable_overrides(array $overrides): array {
        $ordered = $overrides;
        usort($ordered, static function ($a, $b) {
            $aspecific = ((int)$a->quizid !== 0) ? 1 : 0;
            $bspecific = ((int)$b->quizid !== 0) ? 1 : 0;
            if ($aspecific !== $bspecific) {
                return $bspecific <=> $aspecific;
            }
            if ((int)$a->timecreated !== (int)$b->timecreated) {
                return (int)$b->timecreated <=> (int)$a->timecreated;
            }
            return (int)$b->id <=> (int)$a->id;
        });
        return $ordered;
    }

    /**
     * Independent reference for the deterministic tie-break: from the overrides that assign a
     * non-inherit value to the given requirement, return the single winner selected by most
     * specific scope (quiz-scoped over course-scoped), then greatest timecreated, then greatest id.
     *
     * Returns null when no override assigns a non-inherit value to the requirement.
     *
     * @param array $overrides Unordered override rows.
     * @param string $requirement One of override_resolver::REQ_*.
     * @return \stdClass|null The winning override row, or null when none apply.
     */
    private function reference_tiebreak_winner(array $overrides, string $requirement): ?\stdClass {
        $column = override_resolver::STATE_COLUMNS[$requirement];

        $winner = null;
        foreach ($overrides as $override) {
            $state = (int)$override->$column;
            if ($state !== override_resolver::STATE_DISABLED && $state !== override_resolver::STATE_ENABLED) {
                // Inherit contributes no candidate for this requirement.
                continue;
            }
            if ($winner === null || $this->is_more_preferred($override, $winner)) {
                $winner = $override;
            }
        }
        return $winner;
    }

    /**
     * Total preference order used by the reference selection: an override is preferred over the
     * current best when it is more scope-specific, or (same specificity) more recently created, or
     * (same specificity and timecreated) has the greater id.
     *
     * @param \stdClass $candidate Candidate override row.
     * @param \stdClass $current Current best override row.
     * @return bool True when the candidate should replace the current best.
     */
    private function is_more_preferred(\stdClass $candidate, \stdClass $current): bool {
        $candidatespecific = ((int)$candidate->quizid !== 0) ? 1 : 0;
        $currentspecific = ((int)$current->quizid !== 0) ? 1 : 0;
        if ($candidatespecific !== $currentspecific) {
            return $candidatespecific > $currentspecific;
        }
        if ((int)$candidate->timecreated !== (int)$current->timecreated) {
            return (int)$candidate->timecreated > (int)$current->timecreated;
        }
        return (int)$candidate->id > (int)$current->id;
    }

    /**
     * Generate a single scoped override row for the tie-break property: a unique id, a random
     * scope (course-scoped quizid = 0 or quiz-scoped quizid != 0), a timecreated drawn from a small
     * range so recency ties occur, and a random tri-state value for every requirement column.
     *
     * @param string[] $requirements Requirement keys.
     * @param int $id Unique id for this row (guarantees a total ordering).
     * @return \stdClass Override row with id, quizid, timecreated and the five *state columns.
     */
    private function generate_scoped_override_row(array $requirements, int $id): \stdClass {
        $row = new \stdClass();
        $row->id = $id;
        // Course-scoped (0) vs quiz-scoped (a fixed non-zero quiz id) to exercise scope specificity.
        $row->quizid = (mt_rand(0, 1) === 1) ? 7 : 0;
        // Small timecreated range so equal timecreated (recency ties) arise and force the id tie-break.
        $row->timecreated = mt_rand(1000, 1003);
        foreach ($requirements as $requirement) {
            $column = override_resolver::STATE_COLUMNS[$requirement];
            $row->$column = self::STATES[mt_rand(0, count(self::STATES) - 1)];
        }
        return $row;
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 4: Applicability gating (scope, revocation, expiry)
     *
     * For any override row and any attempt {courseid, quizid, userid, now}, the override is
     * applicable to the attempt if and only if ALL of the following hold: the override's target
     * userid equals the attempt userid, the override's courseid equals the attempt courseid, the
     * override's quizid is either 0 (course-scoped) or equal to the attempt's quiz, the override is
     * not revoked, and its expiry is null or strictly greater than now. Overrides failing any single
     * condition are never returned by applicable_overrides() and therefore never influence the
     * effective state.
     *
     * This is a model-based DB test. Each iteration inserts one randomly generated override row into
     * quizaccess_proctoring_overrides, generates a random attempt, calls applicable_overrides(), and
     * asserts that the override's membership in the result exactly matches an independent reference
     * predicate over the five gating conditions. A deterministic seed keeps any counterexample
     * reproducible; DB state is reset after the test via resetAfterTest().
     *
     * Validates: Requirements 1.5, 1.6, 7.3, 8.2, 8.3
     */
    public function test_applicability_gating(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260627);

        // Small id spaces keep matches frequent enough to exercise both branches of every gate.
        $courseids = [10, 11];
        $quizids = [0, 21, 22];
        $userids = [30, 31];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Generate a random override row.
            $overridecourseid = $courseids[mt_rand(0, count($courseids) - 1)];
            $overridequizid = $quizids[mt_rand(0, count($quizids) - 1)];
            $overrideuserid = $userids[mt_rand(0, count($userids) - 1)];
            $revoked = mt_rand(0, 1);

            // Expiry: null (no expiry) or a timestamp drawn from a small range so it straddles now.
            $expiry = (mt_rand(0, 1) === 0) ? null : mt_rand(1000, 1004);

            $record = new \stdClass();
            $record->courseid = $overridecourseid;
            $record->quizid = $overridequizid;
            $record->userid = $overrideuserid;
            $record->captchastate = override_resolver::STATE_INHERIT;
            $record->webcamstate = override_resolver::STATE_INHERIT;
            $record->idverificationstate = override_resolver::STATE_INHERIT;
            $record->screensharestate = override_resolver::STATE_INHERIT;
            $record->multimonitorstate = override_resolver::STATE_INHERIT;
            $record->justification = 'property test';
            $record->expiry = $expiry;
            $record->revoked = $revoked;
            $record->grantedby = 99;
            $record->timecreated = 1000;
            $record->timemodified = 1000;

            $id = $DB->insert_record('quizaccess_proctoring_overrides', $record);

            // Generate a random attempt.
            $attemptcourseid = $courseids[mt_rand(0, count($courseids) - 1)];
            // Attempt quiz is always a concrete quiz (never 0); an attempt belongs to one quiz.
            $attemptquizid = [21, 22][mt_rand(0, 1)];
            $attemptuserid = $userids[mt_rand(0, count($userids) - 1)];
            $now = mt_rand(1000, 1004);

            // Independent reference predicate over the five gating conditions.
            $expectedapplicable =
                ($overrideuserid === $attemptuserid)
                && ($overridecourseid === $attemptcourseid)
                && ($overridequizid === 0 || $overridequizid === $attemptquizid)
                && ($revoked === 0)
                && ($expiry === null || $expiry > $now);

            $applicable = override_resolver::applicable_overrides(
                $attemptcourseid, $attemptquizid, $attemptuserid, $now);

            $ids = array_map(static function ($row) {
                return (int)$row->id;
            }, $applicable);
            $actualapplicable = in_array((int)$id, $ids, true);

            $context = 'iteration=' . $iteration
                . ' override={courseid=' . $overridecourseid
                . ', quizid=' . $overridequizid
                . ', userid=' . $overrideuserid
                . ', revoked=' . $revoked
                . ', expiry=' . var_export($expiry, true) . '}'
                . ' attempt={courseid=' . $attemptcourseid
                . ', quizid=' . $attemptquizid
                . ', userid=' . $attemptuserid
                . ', now=' . $now . '}'
                . ' expected=' . var_export($expectedapplicable, true)
                . ' actual=' . var_export($actualapplicable, true);

            $this->assertSame($expectedapplicable, $actualapplicable,
                'applicable_overrides() membership disagreed with the reference predicate: '
                . $context);

            // Clean up this iteration's row so ids never collide across iterations.
            $DB->delete_records('quizaccess_proctoring_overrides', ['id' => $id]);
        }
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 5: Isolation outside scope
     *
     * For any population of overrides and any attempt whose {userid, quizid} is outside every
     * override's scope, the effective state of each of the five requirements for that attempt
     * equals the base state computed from the site default and per-quiz settings alone. In other
     * words, an override never changes the outcome for a student or a quiz outside its own scope.
     *
     * This is a DB-backed model test. Each iteration inserts one or more ACTIVE, non-inherit
     * overrides that are deliberately placed OUT of the attempt's scope via one of three isolation
     * strategies (different target user, different course, or - for purely quiz-scoped overrides -
     * a non-matching quiz). Because every inserted override assigns forced enabled/disabled states,
     * any scope leak would flip an effective state away from its base value and be caught. The test
     * then calls resolve_all() and asserts every requirement's effective state equals its base
     * state exactly. A deterministic seed keeps any counterexample reproducible; DB state is reset
     * after the test via resetAfterTest().
     *
     * A minimal in-scope sanity contrast runs first to prove the inserted overrides are actually
     * capable of changing the outcome, so the isolation assertions below are not vacuous.
     *
     * Validates: Requirements 4.1, 4.2, 4.3, 4.4
     */
    public function test_isolation_outside_scope(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260628);

        $requirements = override_resolver::requirement_keys();

        // Prove the inserted overrides can change the outcome (guards against a vacuous test).
        $this->assert_inscope_sanity_contrast();

        // Small id spaces keep out-of-scope alternatives available for every isolation strategy.
        $courseids = [10, 11];
        $quizids = [21, 22, 23]; // Concrete quizzes (an attempt always belongs to one quiz, never 0).
        $userids = [30, 31, 32];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Random base states (the collapsed site -> quiz booleans).
            $basestates = [];
            foreach ($requirements as $requirement) {
                $basestates[$requirement] = (bool)mt_rand(0, 1);
            }

            // The attempt under evaluation.
            $attemptcourseid = $courseids[mt_rand(0, count($courseids) - 1)];
            $attemptquizid = $quizids[mt_rand(0, count($quizids) - 1)];
            $attemptuserid = $userids[mt_rand(0, count($userids) - 1)];
            $now = mt_rand(1000, 1004);

            // Choose an isolation strategy that guarantees every inserted override is out of scope:
            // 0 = different target user, 1 = different course, 2 = quiz-scoped but non-matching quiz.
            $strategy = mt_rand(0, 2);

            $insertedids = [];
            $overridecount = mt_rand(1, 4);
            for ($o = 0; $o < $overridecount; $o++) {
                $record = $this->build_isolated_override(
                    $strategy, $attemptcourseid, $attemptquizid, $attemptuserid,
                    $courseids, $quizids, $userids, $requirements);
                $insertedids[] = $DB->insert_record('quizaccess_proctoring_overrides', $record);
            }

            $effective = override_resolver::resolve_all(
                $attemptcourseid, $attemptquizid, $attemptuserid, $now, $basestates);

            foreach ($requirements as $requirement) {
                $context = 'iteration=' . $iteration
                    . ' strategy=' . $strategy
                    . ' requirement=' . $requirement
                    . ' attempt={courseid=' . $attemptcourseid
                    . ', quizid=' . $attemptquizid
                    . ', userid=' . $attemptuserid
                    . ', now=' . $now . '}'
                    . ' base=' . var_export($basestates[$requirement], true)
                    . ' effective=' . var_export($effective[$requirement], true);

                $this->assertSame($basestates[$requirement], $effective[$requirement],
                    'an out-of-scope override changed the effective state: ' . $context);
            }

            // Clean up this iteration's rows so ids never collide across iterations.
            foreach ($insertedids as $id) {
                $DB->delete_records('quizaccess_proctoring_overrides', ['id' => $id]);
            }
        }
    }

    /**
     * Minimal in-scope sanity contrast for the isolation property.
     *
     * Insert a single active, course-scoped override that forces every requirement OFF for a
     * specific {courseid, userid}. With an all-enabled base, an in-scope attempt must resolve every
     * requirement to disabled (the override changes the outcome), while an out-of-scope attempt by a
     * different user must resolve every requirement to its enabled base state (the override does not
     * leak). This proves the inserted overrides are capable of altering resolution, so the isolation
     * assertions in the main loop are meaningful rather than vacuous.
     */
    private function assert_inscope_sanity_contrast(): void {
        global $DB;

        $requirements = override_resolver::requirement_keys();

        $courseid = 500;
        $quizid = 600;
        $targetuserid = 700;
        $otheruserid = 701;

        // Course-scoped (quizid = 0), active, all requirements forced OFF.
        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->quizid = 0;
        $record->userid = $targetuserid;
        foreach ($requirements as $requirement) {
            $column = override_resolver::STATE_COLUMNS[$requirement];
            $record->$column = override_resolver::STATE_DISABLED;
        }
        $record->justification = 'sanity contrast';
        $record->expiry = null;
        $record->revoked = 0;
        $record->grantedby = 99;
        $record->timecreated = 1000;
        $record->timemodified = 1000;
        $id = $DB->insert_record('quizaccess_proctoring_overrides', $record);

        // All-enabled base so that "forced off" is a visible change.
        $basestates = [];
        foreach ($requirements as $requirement) {
            $basestates[$requirement] = true;
        }

        // In scope: the target student in the course -> every requirement forced off.
        $inscope = override_resolver::resolve_all($courseid, $quizid, $targetuserid, 1000, $basestates);
        foreach ($requirements as $requirement) {
            $this->assertFalse($inscope[$requirement],
                'in-scope sanity: expected the course-scoped override to force ' . $requirement
                . ' off but it did not');
        }

        // Out of scope: a different student -> every requirement keeps its enabled base state.
        $outscope = override_resolver::resolve_all($courseid, $quizid, $otheruserid, 1000, $basestates);
        foreach ($requirements as $requirement) {
            $this->assertTrue($outscope[$requirement],
                'in-scope sanity: expected a different student to keep the enabled base state for '
                . $requirement . ' but the override leaked');
        }

        $DB->delete_records('quizaccess_proctoring_overrides', ['id' => $id]);
    }

    /**
     * Build an active, non-inherit override row placed deliberately OUT of the attempt's scope
     * according to the given isolation strategy.
     *
     * The row is always active (not revoked, no expiry) and assigns forced enabled/disabled states
     * to every requirement, so that any scope leak would flip an effective state and be detected.
     *
     * - Strategy 0: target a different user than the attempt (never applies regardless of quiz).
     * - Strategy 1: target a different course than the attempt (never applies regardless of quiz).
     * - Strategy 2: same user and course as the attempt, but quiz-scoped to a different, non-zero
     *   quiz (course-scoped quizid = 0 is deliberately excluded so the override cannot apply).
     *
     * @param int $strategy Isolation strategy (0, 1 or 2).
     * @param int $attemptcourseid Course id of the attempt.
     * @param int $attemptquizid Quiz id of the attempt.
     * @param int $attemptuserid User id of the attempt.
     * @param int[] $courseids Available course ids.
     * @param int[] $quizids Available concrete quiz ids.
     * @param int[] $userids Available user ids.
     * @param string[] $requirements Requirement keys.
     * @return \stdClass Override record ready for insertion, guaranteed out of the attempt's scope.
     */
    private function build_isolated_override(
        int $strategy,
        int $attemptcourseid,
        int $attemptquizid,
        int $attemptuserid,
        array $courseids,
        array $quizids,
        array $userids,
        array $requirements
    ): \stdClass {
        $courseid = $attemptcourseid;
        $userid = $attemptuserid;
        // Default quiz scope: randomly course-scoped (0) or some concrete quiz.
        $quizid = (mt_rand(0, 1) === 0) ? 0 : $quizids[mt_rand(0, count($quizids) - 1)];

        if ($strategy === 0) {
            // Different target user: out of scope regardless of course/quiz.
            $userid = $this->pick_other($userids, $attemptuserid);
        } else if ($strategy === 1) {
            // Different course: out of scope regardless of user/quiz.
            $courseid = $this->pick_other($courseids, $attemptcourseid);
        } else {
            // Same user and course, but quiz-scoped to a different, non-zero quiz.
            $quizid = $this->pick_other($quizids, $attemptquizid);
        }

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->quizid = $quizid;
        $record->userid = $userid;
        foreach ($requirements as $requirement) {
            $column = override_resolver::STATE_COLUMNS[$requirement];
            // Forced (non-inherit) states only, so a scope leak would be detectable.
            $record->$column = (mt_rand(0, 1) === 0)
                ? override_resolver::STATE_DISABLED
                : override_resolver::STATE_ENABLED;
        }
        $record->justification = 'isolation property test';
        $record->expiry = null;
        $record->revoked = 0;
        $record->grantedby = 99;
        $record->timecreated = 1000;
        $record->timemodified = 1000;

        return $record;
    }

    /**
     * Return a random value from $values that is not equal to $exclude.
     *
     * The caller guarantees $values contains at least one element differing from $exclude.
     *
     * @param int[] $values Candidate values.
     * @param int $exclude Value to avoid.
     * @return int A value from $values not equal to $exclude.
     */
    private function pick_other(array $values, int $exclude): int {
        $candidates = array_values(array_filter($values, static function ($value) use ($exclude) {
            return (int)$value !== $exclude;
        }));

        return $candidates[mt_rand(0, count($candidates) - 1)];
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 6: Effective state maps to the client requirement flag
     *
     * For any resolved attempt, each of the five requirement flags placed in the config record
     * handed to startAttempt.js is on if and only if that requirement's effective state is enabled;
     * a requirement resolved to disabled always produces an off flag (so its Pre_Check step is
     * omitted). The mapping between resolve_all()'s boolean output and the client config flag is an
     * identity mapping: an enabled effective state yields a truthy (on) flag and a disabled effective
     * state yields a falsy (off) flag.
     *
     * This is a DB-backed model test. Each iteration inserts a random population of ACTIVE, in-scope
     * overrides for a single attempt, then calls resolve_all(). It asserts two things per requirement:
     * (1) resolve_all() returns a STRICT boolean (the value that will be written to the config flag),
     * and (2) that boolean equals the reference effective state (the applicable overrides ordered
     * exactly as applicable_overrides() does, layered onto the base state). The client flag is then
     * derived from the resolver's boolean via the on/off mapping and checked to be on iff enabled and
     * off iff disabled - i.e. the effective-state -> flag identity holds. A deterministic seed keeps
     * any counterexample reproducible; DB state is reset after the test via resetAfterTest().
     *
     * Validates: Requirements 5.1, 5.4
     */
    public function test_effective_state_maps_to_client_flag(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260629);

        $requirements = override_resolver::requirement_keys();

        // Concrete quizzes only (an attempt always belongs to one quiz, never course-scoped 0).
        $courseids = [10, 11];
        $quizids = [21, 22];
        $userids = [30, 31];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Random base states (the collapsed site -> quiz booleans).
            $basestates = [];
            foreach ($requirements as $requirement) {
                $basestates[$requirement] = (bool)mt_rand(0, 1);
            }

            // The attempt under evaluation.
            $attemptcourseid = $courseids[mt_rand(0, count($courseids) - 1)];
            $attemptquizid = $quizids[mt_rand(0, count($quizids) - 1)];
            $attemptuserid = $userids[mt_rand(0, count($userids) - 1)];
            $now = mt_rand(1000, 1004);

            // Insert 0..4 ACTIVE, in-scope overrides for this exact attempt. In-scope means same
            // course and user, quiz-scoped to the attempt quiz or course-scoped (0), not revoked and
            // no expiry - so every inserted override is applicable and can influence the outcome.
            $overridecount = mt_rand(0, 4);
            $inserted = [];
            for ($o = 0; $o < $overridecount; $o++) {
                $record = $this->build_inscope_override(
                    $attemptcourseid, $attemptquizid, $attemptuserid, $requirements);
                $record->id = $DB->insert_record('quizaccess_proctoring_overrides', $record);
                $inserted[] = $record;
            }

            // Reference: order the inserted (all applicable) overrides exactly as
            // applicable_overrides() does, then resolve each requirement against the base state.
            $ordered = $this->order_like_applicable_overrides($inserted);

            $effective = override_resolver::resolve_all(
                $attemptcourseid, $attemptquizid, $attemptuserid, $now, $basestates);

            foreach ($requirements as $requirement) {
                $expectedeffective = $this->reference_resolve($basestates[$requirement], $ordered, $requirement);

                // The value handed to the config record must be a STRICT boolean.
                $resolved = $effective[$requirement];

                // The client requirement flag derived from the resolver's boolean via the on/off map.
                $flag = $this->to_client_flag($resolved);

                $context = 'iteration=' . $iteration
                    . ' requirement=' . $requirement
                    . ' attempt={courseid=' . $attemptcourseid
                    . ', quizid=' . $attemptquizid
                    . ', userid=' . $attemptuserid
                    . ', now=' . $now . '}'
                    . ' base=' . var_export($basestates[$requirement], true)
                    . ' overrides=' . json_encode($this->summarise_overrides($ordered))
                    . ' expectedEffective=' . var_export($expectedeffective, true)
                    . ' resolved=' . var_export($resolved, true)
                    . ' flag=' . var_export($flag, true);

                // resolve_all() must produce a strict boolean per requirement.
                $this->assertIsBool($resolved,
                    'resolve_all() must return a strict boolean per requirement: ' . $context);

                // The resolver's boolean is exactly the effective state written to the flag.
                $this->assertSame($expectedeffective, $resolved,
                    'resolve_all() effective state disagreed with the reference model: ' . $context);

                // Identity mapping: the flag is on iff enabled, off iff disabled.
                $this->assertSame($expectedeffective ? 1 : 0, $flag,
                    'the client requirement flag did not map identically to the effective state: '
                    . $context);
                $this->assertSame($expectedeffective, (bool)$flag,
                    'the client flag is truthy if and only if the requirement is enabled: ' . $context);
            }

            // Clean up this iteration's rows so ids never collide across iterations.
            foreach ($inserted as $record) {
                $DB->delete_records('quizaccess_proctoring_overrides', ['id' => $record->id]);
            }
        }
    }

    /**
     * Map a resolved effective state to the client requirement flag placed in the config record
     * handed to startAttempt.js: an enabled (true) effective state yields an on flag (1), a disabled
     * (false) effective state yields an off flag (0). This is the identity mapping under test.
     *
     * @param bool $effective The effective enabled/disabled state from resolve_all().
     * @return int The client requirement flag: 1 (on) when enabled, 0 (off) when disabled.
     */
    private function to_client_flag(bool $effective): int {
        return $effective ? 1 : 0;
    }

    /**
     * Build an ACTIVE override row that is in-scope for the given attempt: same course and user,
     * quiz-scoped to the attempt quiz or course-scoped (0), never revoked and with no expiry, so it
     * is always applicable. Each requirement column is assigned a random tri-state value; a small
     * timecreated range plus mixed quiz/course scope exercise the tie-break ordering. The id is set
     * by the caller from the DB insert so the row can be ordered like applicable_overrides().
     *
     * @param int $attemptcourseid Course id of the attempt.
     * @param int $attemptquizid Quiz id of the attempt.
     * @param int $attemptuserid User id of the attempt.
     * @param string[] $requirements Requirement keys.
     * @return \stdClass Override record ready for insertion, guaranteed in the attempt's scope.
     */
    private function build_inscope_override(
        int $attemptcourseid,
        int $attemptquizid,
        int $attemptuserid,
        array $requirements
    ): \stdClass {
        $record = new \stdClass();
        $record->courseid = $attemptcourseid;
        $record->userid = $attemptuserid;
        // Quiz-scoped to the attempt quiz or course-scoped (0): either way applicable to the attempt.
        $record->quizid = (mt_rand(0, 1) === 0) ? 0 : $attemptquizid;
        foreach ($requirements as $requirement) {
            $column = override_resolver::STATE_COLUMNS[$requirement];
            $record->$column = self::STATES[mt_rand(0, count(self::STATES) - 1)];
        }
        $record->justification = 'flag mapping property test';
        $record->expiry = null;
        $record->revoked = 0;
        $record->grantedby = 99;
        // Small timecreated range so recency ties arise and force the id tie-break.
        $record->timecreated = mt_rand(1000, 1002);
        $record->timemodified = $record->timecreated;

        return $record;
    }
}
