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
 * Property-based tests for the per-student proctoring override_manager write layer.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_module;
use core_text;
use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_resolver.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_manager.php');

/**
 * Property-based tests for override_manager's write/validation/audit layer.
 *
 * Feature: per-student-proctoring-overrides
 *
 * The override_manager write path validates input up front and persists via $DB inside a
 * transaction, and it capability-gates every mutation with require_capability(). These tests
 * therefore extend advanced_testcase, create a real course/quiz/enrolled-student fixture, and act
 * as an editingteacher (whose archetype holds quizaccess/proctoring:manageoverrides) so the
 * capability check passes and only the behaviour under test can reject a request.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\override_manager
 */
final class override_manager_test extends advanced_testcase {

    /** @var int Number of generated iterations to run for each property. */
    private const ITERATIONS = 120;

    /** @var int[] The three valid tri-state values an override column may hold. */
    private const VALID_STATES = [
        override_resolver::STATE_INHERIT,
        override_resolver::STATE_DISABLED,
        override_resolver::STATE_ENABLED,
    ];

    /**
     * A pool of values that are NOT valid tri-states. Mixed types (out-of-range ints, integer
     * strings, non-numeric strings, and a float) exercise every rejection branch of
     * validate_states(): out-of-range integers, integer-like strings that still fall outside
     * {-1,0,1}, and loosely-typed junk that must not be silently coerced.
     *
     * @var array
     */
    private const INVALID_STATES = [2, -2, 3, 99, -100, '2', '-3', '7', 'abc', '', 1.5];

    /**
     * Feature: per-student-proctoring-overrides, Property 7: Invalid state assignment is atomic and rejected
     *
     * For any create/edit request in which at least one requirement is assigned a value outside
     * {-1, 0, 1}, the operation is rejected with an "invalid state" error and the stored state of
     * every requirement on the override is left unchanged (no partial write).
     *
     * The property is exercised in two halves per iteration, sharing one deterministic seed so any
     * counterexample is reproducible:
     *
     * - create(): a data object carrying at least one invalid tri-state (all other inputs valid)
     *   must throw moodle_exception('error:invalidstate') and must NOT insert any row -- the total
     *   override count is unchanged.
     * - edit(): the same invalid-state data applied to a pre-existing valid override must throw
     *   moodle_exception('error:invalidstate') and leave all five stored tri-states byte-for-byte
     *   as they were, proving the rejection is atomic (no partial column update).
     *
     * Validates: Requirements 2.7
     */
    public function test_invalid_state_assignment_is_atomic_and_rejected(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260701);

        // Course + enrolled target student + acting reviewer (editingteacher archetype holds
        // quizaccess/proctoring:manageoverrides), plus a quiz module to build a module context.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $this->setUser($teacher);

        $statecolumns = array_values(override_resolver::STATE_COLUMNS);

        // A single valid baseline override to exercise edit() atomicity against. All other inputs
        // are valid so that only an invalid state can cause a rejection.
        $baselineid = override_manager::create($context, $this->build_valid_create_data($student->id));

        // Snapshot the baseline's stored tri-states; they must never change on a rejected edit.
        $baselinestates = $this->load_states($baselineid, $statecolumns);

        // Exactly one override row exists at the start and must persist across all rejections.
        $this->assertSame(1, $DB->count_records('quizaccess_proctoring_overrides'),
            'Exactly the baseline override should exist before the property loop.');

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $invalidstates = $this->generate_states_with_at_least_one_invalid($statecolumns);
            $summary = json_encode($invalidstates);

            // --- create() half: an invalid state must reject with no row inserted. ---
            $createdata = $this->build_valid_create_data($student->id);
            foreach ($invalidstates as $column => $value) {
                $createdata->$column = $value;
            }

            $countbefore = $DB->count_records('quizaccess_proctoring_overrides');

            $threw = false;
            try {
                override_manager::create($context, $createdata);
            } catch (\moodle_exception $e) {
                $threw = true;
                $this->assertSame('error:invalidstate', $e->errorcode,
                    'create() should reject an invalid tri-state with error:invalidstate. '
                    . 'iteration=' . $iteration . ' states=' . $summary
                    . ' errorcode=' . $e->errorcode);
            }
            $this->assertTrue($threw,
                'create() must throw when a tri-state is invalid. iteration=' . $iteration
                . ' states=' . $summary);

            $this->assertSame($countbefore, $DB->count_records('quizaccess_proctoring_overrides'),
                'A rejected create() must not insert any row (atomic). iteration=' . $iteration
                . ' states=' . $summary);

            // --- edit() half: an invalid state must reject and leave stored states unchanged. ---
            $editdata = new \stdClass();
            foreach ($invalidstates as $column => $value) {
                $editdata->$column = $value;
            }

            $threw = false;
            try {
                override_manager::edit($context, $baselineid, $editdata);
            } catch (\moodle_exception $e) {
                $threw = true;
                $this->assertSame('error:invalidstate', $e->errorcode,
                    'edit() should reject an invalid tri-state with error:invalidstate. '
                    . 'iteration=' . $iteration . ' states=' . $summary
                    . ' errorcode=' . $e->errorcode);
            }
            $this->assertTrue($threw,
                'edit() must throw when a tri-state is invalid. iteration=' . $iteration
                . ' states=' . $summary);

            $this->assertEquals($baselinestates, $this->load_states($baselineid, $statecolumns),
                'A rejected edit() must leave every stored tri-state unchanged (atomic). '
                . 'iteration=' . $iteration . ' states=' . $summary);
        }
    }

    /**
     * Build a fully valid create() data object for the given target student: every tri-state is a
     * valid value, the justification is non-blank and within length, and there is no expiry. Tests
     * overwrite individual state columns with invalid values to isolate state validation.
     *
     * @param int $userid Target (enrolled) student id.
     * @return \stdClass Valid create() input.
     */
    private function build_valid_create_data(int $userid): \stdClass {
        $data = new \stdClass();
        $data->quizid = 0; // Course-scoped; scope is irrelevant to state validation.
        $data->userid = $userid;
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $data->$column = self::VALID_STATES[mt_rand(0, count(self::VALID_STATES) - 1)];
        }
        $data->justification = 'Property 7 baseline justification';
        $data->expiry = null;
        return $data;
    }

    /**
     * Generate a map of the five state columns to values in which at least one column carries an
     * invalid (non-tri-state) value; the remaining columns carry valid tri-states. This keeps the
     * only rejectable input the state assignment itself.
     *
     * @param string[] $statecolumns The five override state column names.
     * @return array<string, mixed> Column => value map with >= 1 invalid value.
     */
    private function generate_states_with_at_least_one_invalid(array $statecolumns): array {
        $states = [];
        $invalidcount = 0;
        foreach ($statecolumns as $column) {
            if (mt_rand(0, 1) === 1) {
                $states[$column] = self::INVALID_STATES[mt_rand(0, count(self::INVALID_STATES) - 1)];
                $invalidcount++;
            } else {
                $states[$column] = self::VALID_STATES[mt_rand(0, count(self::VALID_STATES) - 1)];
            }
        }

        // Guarantee the "at least one invalid" precondition of Property 7.
        if ($invalidcount === 0) {
            $forced = $statecolumns[mt_rand(0, count($statecolumns) - 1)];
            $states[$forced] = self::INVALID_STATES[mt_rand(0, count(self::INVALID_STATES) - 1)];
        }

        return $states;
    }

    /**
     * Load the five stored tri-state columns of an override as a column => int map.
     *
     * @param int $overrideid Override id.
     * @param string[] $statecolumns The five override state column names.
     * @return array<string, int> Stored tri-state values keyed by column.
     */
    private function load_states(int $overrideid, array $statecolumns): array {
        global $DB;

        $record = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);
        $states = [];
        foreach ($statecolumns as $column) {
            $states[$column] = (int)$record->$column;
        }
        return $states;
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 8: Justification validation
     *
     * A justification is valid iff it is non-blank after trimming AND its trimmed length is at
     * most MAX_JUSTIFICATION_LENGTH (2000) characters; otherwise it is invalid. create() must
     * accept every valid justification (inserting exactly one row whose stored justification is
     * the trimmed value) and reject every invalid one with moodle_exception('error:invalidjustification')
     * while inserting no row.
     *
     * The property is checked against an independent reference predicate that mirrors the
     * implementation exactly (trim, then core_text::strlen(trimmed) <= 2000). A seeded generator
     * mixes valid and invalid justifications -- empty strings, whitespace-only strings of various
     * lengths, and content strings (optionally wrapped in whitespace) whose trimmed length spans
     * the 2000-character boundary in both directions. The explicit boundary lengths 0, 1, 2000 and
     * 2001, plus an all-whitespace case, are asserted directly before the randomised loop so those
     * exact edges are always covered.
     *
     * Validates: Requirements 6.2
     */
    public function test_justification_validation(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260702);

        // Course + enrolled target student + acting reviewer (editingteacher archetype holds
        // quizaccess/proctoring:manageoverrides), plus a quiz module to build a module context.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $this->setUser($teacher);

        // Explicit boundary cases: [justification, human-readable label]. The empty and
        // all-whitespace cases are blank after trim (invalid); the 1- and 2000-char cases are
        // valid; the 2001-char case exceeds the maximum (invalid). Whitespace-only strings of a
        // few lengths ensure "blank after trim" is rejected regardless of raw length.
        $max = override_manager::MAX_JUSTIFICATION_LENGTH;
        $boundaries = [
            [$this->make_content(0), 'length 0 (empty)'],
            [str_repeat(' ', 1), 'whitespace-only length 1'],
            [str_repeat(" \t\n", 20), 'whitespace-only length 60'],
            [$this->make_content(1), 'length 1'],
            [$this->make_content($max), 'length 2000 (max)'],
            [$this->make_content($max + 1), 'length 2001 (over max)'],
            // Content padded with whitespace: trimmed length is exactly the boundary.
            ['   ' . $this->make_content($max) . "  \n", 'padded, trimmed length 2000'],
            ['   ' . $this->make_content($max + 1) . "  \n", 'padded, trimmed length 2001'],
        ];
        foreach ($boundaries as [$text, $label]) {
            $this->assert_create_matches_predicate($context, $student->id, $text, 'boundary: ' . $label);
        }

        // Randomised mix of valid and invalid justifications.
        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $text = $this->generate_justification($max);
            $this->assert_create_matches_predicate($context, $student->id, $text, 'iteration=' . $iteration);
        }
    }

    /**
     * Assert that create() with the given justification behaves exactly as the independent
     * reference predicate dictates: a valid justification inserts exactly one row whose stored
     * justification equals the trimmed input; an invalid one throws error:invalidjustification and
     * inserts no row.
     *
     * @param \context_module $context Module context to create the override in.
     * @param int $userid Target (enrolled) student id.
     * @param string $justification Justification text under test.
     * @param string $where Human-readable case label included in every assertion message.
     * @return void
     */
    private function assert_create_matches_predicate(
        \context_module $context,
        int $userid,
        string $justification,
        string $where
    ): void {
        global $DB;

        // Independent reference predicate mirroring override_manager::validate_justification():
        // trim, then require non-empty and trimmed length within the maximum.
        $trimmed = trim($justification);
        $expectvalid = ($trimmed !== ''
            && core_text::strlen($trimmed) <= override_manager::MAX_JUSTIFICATION_LENGTH);

        $summary = $where . ' rawlen=' . core_text::strlen($justification)
            . ' trimmedlen=' . core_text::strlen($trimmed);

        $countbefore = $DB->count_records('quizaccess_proctoring_overrides');

        $data = $this->build_valid_create_data($userid);
        $data->justification = $justification;

        if ($expectvalid) {
            $overrideid = override_manager::create($context, $data);
            $this->assertGreaterThan(0, $overrideid,
                'A valid justification should create an override. ' . $summary);
            $this->assertSame($countbefore + 1, $DB->count_records('quizaccess_proctoring_overrides'),
                'A valid justification should insert exactly one row. ' . $summary);

            $stored = $DB->get_field('quizaccess_proctoring_overrides', 'justification',
                ['id' => $overrideid], MUST_EXIST);
            $this->assertSame($trimmed, $stored,
                'A valid justification should be stored as its trimmed value. ' . $summary);
        } else {
            $threw = false;
            try {
                override_manager::create($context, $data);
            } catch (\moodle_exception $e) {
                $threw = true;
                $this->assertSame('error:invalidjustification', $e->errorcode,
                    'An invalid justification should reject with error:invalidjustification. '
                    . $summary . ' errorcode=' . $e->errorcode);
            }
            $this->assertTrue($threw,
                'An invalid justification must throw. ' . $summary);
            $this->assertSame($countbefore, $DB->count_records('quizaccess_proctoring_overrides'),
                'A rejected justification must not insert any row. ' . $summary);
        }
    }

    /**
     * Build a string of exactly $length non-whitespace ASCII characters, so its trimmed length
     * equals $length. Used to construct exact boundary-length justifications.
     *
     * @param int $length Desired character length (>= 0).
     * @return string A non-whitespace string of the requested length.
     */
    private function make_content(int $length): string {
        return $length <= 0 ? '' : str_repeat('x', $length);
    }

    /**
     * Generate a justification that mixes valid and invalid cases. Categories:
     *  - empty string (invalid: blank after trim);
     *  - whitespace-only of a random length (invalid: blank after trim);
     *  - content whose trimmed length straddles the $max boundary, optionally wrapped in
     *    leading/trailing whitespace (valid when trimmed length in [1, $max], else invalid).
     *
     * The content length is drawn from a range that spans $max in both directions so the loop
     * reliably produces both accepted and rejected justifications.
     *
     * @param int $max Maximum allowed trimmed length (MAX_JUSTIFICATION_LENGTH).
     * @return string A generated justification.
     */
    private function generate_justification(int $max): string {
        $category = mt_rand(0, 9);

        if ($category === 0) {
            // Empty string.
            return '';
        }

        if ($category <= 2) {
            // Whitespace-only string of a random length -> blank after trim.
            $whitespacechars = [' ', "\t", "\n", "\r"];
            $len = mt_rand(1, 50);
            $text = '';
            for ($i = 0; $i < $len; $i++) {
                $text .= $whitespacechars[mt_rand(0, count($whitespacechars) - 1)];
            }
            return $text;
        }

        // Content string: choose a trimmed length spanning the boundary (1 .. max + 100).
        $contentlen = mt_rand(1, $max + 100);
        $content = str_repeat('a', $contentlen);

        // Optionally wrap in leading/trailing whitespace; trim() must strip it so validity is
        // determined solely by $contentlen.
        if (mt_rand(0, 1) === 1) {
            $lead = str_repeat(' ', mt_rand(0, 5));
            $trail = str_repeat(" \t\n", mt_rand(0, 3));
            return $lead . $content . $trail;
        }

        return $content;
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 9: Expiry must be in the future
     *
     * For any submitted expiry value and submission time "now", the expiry is accepted if and only
     * if it is strictly greater than now; a past-or-equal expiry is rejected with a "future expiry"
     * error (error:expiryinpast) and leaves stored override state unchanged (no row inserted). A
     * null expiry ("no expiry") is always accepted, and an accepted expiry is stored verbatim
     * (null stays null; a future timestamp is stored as that exact integer).
     *
     * Because override_manager::create() reads the current time via time() internally, the
     * generator produces expiries as offsets relative to a "now" captured immediately before each
     * call, and the expected validity is derived from that offset:
     *
     *  - null offset        -> no expiry, always valid;
     *  - future offset      -> now + [100, 100000], strictly greater than now, valid. The offset is
     *                          kept well clear of 0 so a one-second clock tick between the captured
     *                          now and create()'s internal time() cannot flip the outcome;
     *  - equal offset (0)   -> expiry == captured now, invalid. This stays invalid even if the
     *                          clock ticks, since a larger internal now only makes expiry <= now
     *                          "more true" (strictly-greater is required);
     *  - past offset        -> now - [1, 100000], at or before now, invalid, and likewise tick-safe.
     *
     * The equal (0) and just-past (-1) offsets are asserted explicitly before the randomised loop
     * so those exact edges around the strict boundary are always covered.
     *
     * Validates: Requirements 8.4
     */
    public function test_expiry_must_be_in_the_future(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260703);

        // Course + enrolled target student + acting reviewer (editingteacher archetype holds
        // quizaccess/proctoring:manageoverrides), plus a quiz module to build a module context.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $this->setUser($teacher);

        // Explicit boundary offsets around the strict "> now" edge: null (no expiry, valid),
        // 0 (exactly now, invalid), and -1 (one second in the past, invalid).
        $this->assert_expiry_matches_predicate($context, $student->id, null, 'boundary: null (no expiry)');
        $this->assert_expiry_matches_predicate($context, $student->id, 0, 'boundary: offset 0 (== now)');
        $this->assert_expiry_matches_predicate($context, $student->id, -1, 'boundary: offset -1 (past)');

        // Randomised mix of null, past-or-equal, and future expiries.
        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            $offset = $this->generate_expiry_offset();
            $this->assert_expiry_matches_predicate($context, $student->id, $offset, 'iteration=' . $iteration);
        }
    }

    /**
     * Generate an expiry offset (in seconds) relative to "now", drawn from three categories that
     * together span the strict future boundary:
     *
     *  - null           -> "no expiry" (always valid);
     *  - future offset  -> uniformly in [100, 100000] (strictly future, valid);
     *  - equal offset   -> exactly 0 (== now, invalid);
     *  - past offset    -> uniformly in [-100000, -1] (at or before now, invalid).
     *
     * Offsets are kept clear of the +/-1 second neighbourhood (except the deliberate 0 case) so a
     * clock tick between the test's captured now and create()'s internal time() cannot change the
     * expected outcome.
     *
     * @return int|null Offset in seconds relative to now, or null for "no expiry".
     */
    private function generate_expiry_offset(): ?int {
        $category = mt_rand(0, 3);
        switch ($category) {
            case 0:
                return null;                 // No expiry.
            case 1:
                return mt_rand(100, 100000);  // Strictly future.
            case 2:
                return 0;                    // Exactly now (invalid: strictly-greater required).
            default:
                return -mt_rand(1, 100000);   // Past.
        }
    }

    /**
     * Assert that create() with an expiry of (now + $offset) behaves exactly as the strict-future
     * predicate dictates: a null offset (no expiry) or a strictly-future offset inserts exactly one
     * row whose stored expiry matches the input (null stays null; a future timestamp is stored as
     * that exact integer); an at-or-before-now expiry throws error:expiryinpast and inserts no row.
     *
     * "now" is captured immediately before create() so the passed expiry is deterministic; the
     * offsets are chosen far enough from 0 that create()'s internal time() cannot flip the result.
     *
     * @param \context_module $context Module context to create the override in.
     * @param int $userid Target (enrolled) student id.
     * @param int|null $offset Offset in seconds relative to now, or null for "no expiry".
     * @param string $where Human-readable case label included in every assertion message.
     * @return void
     */
    private function assert_expiry_matches_predicate(
        \context_module $context,
        int $userid,
        ?int $offset,
        string $where
    ): void {
        global $DB;

        $countbefore = $DB->count_records('quizaccess_proctoring_overrides');

        // Capture now immediately before the call so the expiry we pass is deterministic.
        $now = time();
        $expiry = ($offset === null) ? null : $now + $offset;

        // Independent reference predicate mirroring override_manager::validate_expiry():
        // valid iff no expiry, or expiry strictly greater than now.
        $expectvalid = ($expiry === null) || ($expiry > $now);

        $summary = $where . ' offset=' . var_export($offset, true) . ' expiry=' . var_export($expiry, true);

        $data = $this->build_valid_create_data($userid);
        $data->expiry = $expiry;

        if ($expectvalid) {
            $overrideid = override_manager::create($context, $data);
            $this->assertGreaterThan(0, $overrideid,
                'A null or strictly-future expiry should create an override. ' . $summary);
            $this->assertSame($countbefore + 1, $DB->count_records('quizaccess_proctoring_overrides'),
                'A valid expiry should insert exactly one row. ' . $summary);

            $stored = $DB->get_field('quizaccess_proctoring_overrides', 'expiry',
                ['id' => $overrideid], MUST_EXIST);
            if ($expiry === null) {
                $this->assertNull($stored,
                    'A null expiry should be stored as null (no expiry). ' . $summary);
            } else {
                $this->assertSame($expiry, (int)$stored,
                    'A future expiry should be stored as the exact submitted timestamp. ' . $summary);
            }
        } else {
            $threw = false;
            try {
                override_manager::create($context, $data);
            } catch (\moodle_exception $e) {
                $threw = true;
                $this->assertSame('error:expiryinpast', $e->errorcode,
                    'A past-or-equal expiry should reject with error:expiryinpast. '
                    . $summary . ' errorcode=' . $e->errorcode);
            }
            $this->assertTrue($threw,
                'A past-or-equal expiry must throw. ' . $summary);
            $this->assertSame($countbefore, $DB->count_records('quizaccess_proctoring_overrides'),
                'A rejected expiry must not insert any row (atomic). ' . $summary);
        }
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 10: Create/read round-trip
     *
     * For any valid create() input, reading the created override back exposes exactly the recorded
     * fields: the five per-requirement tri-states, the scope (quizid), the target userid, the
     * justification (stored trimmed), the expiry (null or the exact future timestamp), the acting
     * reviewer (grantedby), and a positive timecreated; a freshly created override is not revoked.
     * A create() followed by a read therefore round-trips every recorded field faithfully.
     *
     * A seeded generator builds a fully valid create() input each iteration with random-but-valid
     * values: a random valid tri-state for each of the five columns; a random scope in
     * {0 (course-scoped), the real quiz id}; a justification with random surrounding whitespace
     * (so the trimmed-storage contract is exercised); and an expiry that is either null or a
     * strictly-future timestamp. After create(), the row is loaded via $DB->get_record and each
     * recorded field is asserted equal to the submitted value (justification trimmed; expiry
     * null-or-exact; states as ints; userid = target; grantedby = acting reviewer; timecreated
     * positive; revoked = 0).
     *
     * Validates: Requirements 6.3, 8.1
     */
    public function test_create_read_round_trip(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260704);

        // Course + enrolled target student + acting reviewer (editingteacher archetype holds
        // quizaccess/proctoring:manageoverrides), plus a quiz module to build a module context.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $this->setUser($teacher);

        $statecolumns = array_values(override_resolver::STATE_COLUMNS);

        // The two valid scope choices: 0 (course-scoped) and the real quiz instance id.
        $scopes = [0, (int)$quiz->id];

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Build a fully valid, random create() input.
            $data = new \stdClass();
            $data->quizid = $scopes[mt_rand(0, count($scopes) - 1)];
            $data->userid = (int)$student->id;

            // Random valid tri-state per column; remember what we submitted for the round-trip.
            $expectedstates = [];
            foreach ($statecolumns as $column) {
                $state = self::VALID_STATES[mt_rand(0, count(self::VALID_STATES) - 1)];
                $data->$column = $state;
                $expectedstates[$column] = (int)$state;
            }

            // Justification with random surrounding whitespace so trimmed-storage is exercised.
            $core = 'Round-trip justification ' . $iteration;
            $lead = str_repeat(' ', mt_rand(0, 5));
            $trail = str_repeat(" \t\n", mt_rand(0, 3));
            $data->justification = $lead . $core . $trail;
            $expectedjustification = trim($data->justification);

            // Expiry: null (no expiry) or a strictly-future timestamp. Keep the offset well clear
            // of 0 so a clock tick between our capture and create()'s internal time() is harmless.
            if (mt_rand(0, 1) === 0) {
                $data->expiry = null;
                $expectedexpiry = null;
            } else {
                $data->expiry = time() + mt_rand(100, 100000);
                $expectedexpiry = (int)$data->expiry;
            }

            $summary = 'iteration=' . $iteration
                . ' quizid=' . $data->quizid
                . ' states=' . json_encode($expectedstates)
                . ' expiry=' . var_export($expectedexpiry, true);

            $before = time();
            $overrideid = override_manager::create($context, $data);
            $after = time();

            $this->assertGreaterThan(0, $overrideid,
                'A valid create() should return a positive override id. ' . $summary);

            $record = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid],
                '*', MUST_EXIST);

            // Scope + target round-trip exactly.
            $this->assertSame((int)$data->quizid, (int)$record->quizid,
                'Stored quizid (scope) should equal the submitted scope. ' . $summary);
            $this->assertSame((int)$data->userid, (int)$record->userid,
                'Stored userid should equal the target student. ' . $summary);
            $this->assertSame((int)$course->id, (int)$record->courseid,
                'Stored courseid should equal the module context course. ' . $summary);

            // The five tri-states round-trip as ints.
            foreach ($statecolumns as $column) {
                $this->assertSame($expectedstates[$column], (int)$record->$column,
                    'Stored tri-state for ' . $column . ' should equal the submitted state. '
                    . $summary);
            }

            // Justification is stored trimmed.
            $this->assertSame($expectedjustification, (string)$record->justification,
                'Stored justification should equal the trimmed submitted value. ' . $summary);

            // Expiry round-trips: null stays null; a future timestamp is stored exactly.
            if ($expectedexpiry === null) {
                $this->assertNull($record->expiry,
                    'A null expiry should be stored as null. ' . $summary);
            } else {
                $this->assertSame($expectedexpiry, (int)$record->expiry,
                    'A future expiry should be stored as the exact submitted timestamp. ' . $summary);
            }

            // grantedby is the acting reviewer; timecreated is set within the call window.
            $this->assertSame((int)$teacher->id, (int)$record->grantedby,
                'grantedby should record the acting reviewer. ' . $summary);
            $this->assertGreaterThan(0, (int)$record->timecreated,
                'timecreated should be a positive timestamp. ' . $summary);
            $this->assertGreaterThanOrEqual($before, (int)$record->timecreated,
                'timecreated should be no earlier than the call start. ' . $summary);
            $this->assertLessThanOrEqual($after, (int)$record->timecreated,
                'timecreated should be no later than the call end. ' . $summary);

            // A freshly created override is not revoked.
            $this->assertSame(0, (int)$record->revoked,
                'A freshly created override should not be revoked. ' . $summary);
        }
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 11: Immutable creation fields
     *
     * The creation fields grantedby and timecreated are immutable: once stamped by create(), no
     * edit() or revoke() ever changes them from their at-creation values, regardless of what an
     * edit attempts to submit (including edits that explicitly carry grantedby/timecreated keys in
     * their $data, and edits/revokes performed by a DIFFERENT reviewer who also holds the
     * manageoverrides capability). override_manager::edit() only ever writes editable_columns()
     * (the five tri-states, justification, expiry) plus timemodified, and revoke() only ever writes
     * the revocation fields plus timemodified -- neither touches grantedby/timecreated.
     *
     * A seeded generator creates an override as the ORIGINAL reviewer (capturing grantedby and
     * timecreated from the stored row), then applies a random series of edits -- each mutating a
     * random subset of the editable fields AND injecting bogus grantedby/timecreated values into
     * $data that must be ignored -- with some edits and the final revoke acting as a SECOND
     * reviewer. After every edit and after the revoke, the row is reloaded and grantedby and
     * timecreated are asserted byte-for-byte equal to their at-creation values, proving the second
     * reviewer never becomes the granter and the creation timestamp never moves.
     *
     * Validates: Requirements 6.4
     */
    public function test_immutable_creation_fields(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260705);

        // Course + enrolled target student + acting reviewer, plus a SECOND editingteacher who
        // also holds quizaccess/proctoring:manageoverrides so we can prove grantedby stays the
        // ORIGINAL granter even when a different capable reviewer edits/revokes.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $teacher2 = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $statecolumns = array_values(override_resolver::STATE_COLUMNS);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Create the override as the ORIGINAL reviewer.
            $this->setUser($teacher);
            $overrideid = override_manager::create($context, $this->build_valid_create_data($student->id));

            // Capture the immutable creation fields exactly as stored at creation.
            $created = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid],
                'grantedby, timecreated', MUST_EXIST);
            $origgrantedby = (int)$created->grantedby;
            $origtimecreated = (int)$created->timecreated;

            $this->assertSame((int)$teacher->id, $origgrantedby,
                'grantedby should be stamped as the creating reviewer. iteration=' . $iteration);

            // Apply a random series of edits. Some act as the SECOND reviewer; every edit injects
            // bogus grantedby/timecreated keys that edit() must ignore.
            $editcount = mt_rand(1, 4);
            for ($e = 0; $e < $editcount; $e++) {
                // Alternate the acting reviewer so a capable non-granter cannot become grantedby.
                $this->setUser(mt_rand(0, 1) === 0 ? $teacher : $teacher2);

                $editdata = $this->build_random_edit_data($statecolumns);

                // Inject immutable-field keys that the write path must never honour.
                $editdata->grantedby = (int)$teacher2->id;   // A different, capable reviewer's id.
                $editdata->timecreated = $origtimecreated + 999999; // A wildly different timestamp.

                override_manager::edit($context, $overrideid, $editdata);

                $row = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid],
                    'grantedby, timecreated', MUST_EXIST);
                $this->assertSame($origgrantedby, (int)$row->grantedby,
                    'grantedby must stay the ORIGINAL granter after edit. iteration=' . $iteration
                    . ' edit=' . $e);
                $this->assertSame($origtimecreated, (int)$row->timecreated,
                    'timecreated must be unchanged after edit. iteration=' . $iteration
                    . ' edit=' . $e);
            }

            // Revoke as the SECOND reviewer; grantedby/timecreated must still be untouched.
            $this->setUser($teacher2);
            override_manager::revoke($context, $overrideid);

            $row = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid],
                'grantedby, timecreated, revoked, revokedby', MUST_EXIST);
            $this->assertSame(1, (int)$row->revoked,
                'The override should be revoked after revoke(). iteration=' . $iteration);
            $this->assertSame((int)$teacher2->id, (int)$row->revokedby,
                'revokedby should record the revoking reviewer (not grantedby). iteration='
                . $iteration);
            $this->assertSame($origgrantedby, (int)$row->grantedby,
                'grantedby must stay the ORIGINAL granter after revoke. iteration=' . $iteration);
            $this->assertSame($origtimecreated, (int)$row->timecreated,
                'timecreated must be unchanged after revoke. iteration=' . $iteration);
        }
    }

    /**
     * Build a random-but-valid edit() data object: each of the five tri-state columns gets a
     * random valid state, the justification is a random non-blank string within the length limit,
     * and the expiry is either null (no expiry) or a strictly-future timestamp. This exercises the
     * editable fields so edit() has real changes to write, while keeping every value valid so the
     * edit is never rejected before it can (not) touch the immutable creation fields.
     *
     * @param string[] $statecolumns The five override state column names.
     * @return \stdClass Valid edit() input (without any immutable-field keys).
     */
    private function build_random_edit_data(array $statecolumns): \stdClass {
        $data = new \stdClass();
        foreach ($statecolumns as $column) {
            $data->$column = self::VALID_STATES[mt_rand(0, count(self::VALID_STATES) - 1)];
        }
        $data->justification = 'Property 11 edit justification ' . mt_rand(0, PHP_INT_MAX);
        $data->expiry = (mt_rand(0, 1) === 0) ? null : time() + mt_rand(100, 100000);
        return $data;
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 12: Edit audit captures per-field before/after
     *
     * For any edit() that changes a subset of an override's editable fields (the five tri-states,
     * the justification, and the expiry), exactly one audit row is appended per CHANGED field, each
     * carrying action='edit', the acting reviewer, a positive timestamp, the field name, and the
     * field's correct previous (oldvalue) and new (newvalue) values; fields whose value did not
     * change produce no audit row, and an edit in which nothing changes produces no audit row at
     * all. The set of (fieldname, oldvalue, newvalue) audit rows written by an edit therefore
     * corresponds exactly, set-wise, to the set of fields whose normalised value actually changed.
     *
     * Each iteration creates a fresh, fully valid override (with random states, a random
     * justification, and a null-or-future expiry), reads back its stored editable values, and then
     * builds an edit $data that mutates a RANDOM subset of the editable fields while leaving the
     * rest absent or set to their current value -- reliably producing both changed and unchanged
     * fields, and sometimes an all-unchanged edit. The EXPECTED changed-field set (with old/new
     * audit strings) is computed by an independent reference that mirrors override_manager::edit()
     * exactly: state columns compared as ints; justification compared after trim (and stored
     * trimmed); expiry normalised to a positive int or null; audit_value maps null to null and
     * everything else to its string form. The audit rows appended by the edit are then isolated
     * (id greater than the max audit id captured immediately before the call) and asserted to match
     * the expected set field-for-field, with no row for any unchanged field. An all-absent edit is
     * asserted explicitly to append zero audit rows.
     *
     * Validates: Requirements 7.5
     */
    public function test_edit_audit_captures_per_field_before_after(): void {
        global $DB;

        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260706);

        // Course + enrolled target student + acting reviewer (editingteacher archetype holds
        // quizaccess/proctoring:manageoverrides), plus a quiz module to build a module context.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $this->setUser($teacher);

        $statecolumns = array_values(override_resolver::STATE_COLUMNS);

        // Explicit edge case: an edit that changes nothing (empty $data) must append zero audit
        // rows. Create an override, snapshot the audit high-water mark, then edit with no fields.
        $edgeid = override_manager::create($context, $this->build_varied_create_data($student->id));
        $edgemax = $this->max_audit_id();
        override_manager::edit($context, $edgeid, new \stdClass());
        $this->assertSame([], $this->new_edit_audit_rows($edgeid, $edgemax),
            'An edit that changes no field must append zero audit rows.');

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Fresh, fully valid override with varied baseline values to diff against.
            $overrideid = override_manager::create($context, $this->build_varied_create_data($student->id));

            // The stored editable values are the "before" the edit diffs against.
            $existing = $this->load_editable_values($overrideid, $statecolumns);

            // Build an edit mutating a random subset of fields (some absent/equal -> no row).
            $editdata = $this->build_random_edit_subset($existing, $statecolumns);

            // Independent reference: the fields we expect to change, with old/new audit strings.
            $expected = $this->compute_expected_edit_changes($existing, $editdata, $statecolumns);

            $summary = 'iteration=' . $iteration
                . ' edit=' . json_encode($editdata)
                . ' expected=' . json_encode($expected);

            // Isolate exactly the audit rows this edit appends.
            $maxbefore = $this->max_audit_id();
            override_manager::edit($context, $overrideid, $editdata);
            $actual = $this->new_edit_audit_rows($overrideid, $maxbefore);

            // Exactly one audit row per changed field: the changed-field sets match.
            $this->assertSame(
                $this->sorted_keys($expected),
                $this->sorted_keys($actual),
                'The set of audited field names must equal the set of changed fields '
                . '(no row for unchanged fields, exactly one row per changed field). ' . $summary
            );

            // Each audited field carries the correct previous and new values.
            foreach ($expected as $field => $delta) {
                $this->assertArrayHasKey($field, $actual,
                    'Changed field ' . $field . ' must have an audit row. ' . $summary);
                $this->assertSame($delta['oldvalue'], $actual[$field]['oldvalue'],
                    'oldvalue for ' . $field . ' must be the pre-edit value. ' . $summary);
                $this->assertSame($delta['newvalue'], $actual[$field]['newvalue'],
                    'newvalue for ' . $field . ' must be the post-edit value. ' . $summary);
                $this->assertSame('edit', $actual[$field]['action'],
                    'Every per-field audit row must record action=edit. ' . $summary);
                $this->assertSame((int)$teacher->id, $actual[$field]['actorid'],
                    'Every audit row must record the acting reviewer. ' . $summary);
                $this->assertGreaterThan(0, $actual[$field]['timecreated'],
                    'Every audit row must carry a positive timestamp. ' . $summary);
            }

            // Unchanged fields (present in the override but not in the expected set) have no row.
            foreach (array_merge($statecolumns, ['justification', 'expiry']) as $field) {
                if (!array_key_exists($field, $expected)) {
                    $this->assertArrayNotHasKey($field, $actual,
                        'Unchanged field ' . $field . ' must not produce an audit row. ' . $summary);
                }
            }
        }
    }

    /**
     * Build a fully valid create() data object with VARIED baseline values so edits have a diverse
     * "before" to diff against: a random valid tri-state per column, a random non-blank
     * justification within the length limit, and an expiry that is either null (no expiry) or a
     * strictly-future timestamp (kept well clear of "now" so a clock tick cannot invalidate it).
     *
     * @param int $userid Target (enrolled) student id.
     * @return \stdClass Valid create() input with randomised editable values.
     */
    private function build_varied_create_data(int $userid): \stdClass {
        $data = new \stdClass();
        $data->quizid = 0; // Course-scoped; scope is irrelevant to the audit diff.
        $data->userid = $userid;
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $data->$column = self::VALID_STATES[mt_rand(0, count(self::VALID_STATES) - 1)];
        }
        $data->justification = 'Baseline justification ' . mt_rand(0, PHP_INT_MAX);
        $data->expiry = (mt_rand(0, 1) === 0) ? null : time() + mt_rand(100, 100000);
        return $data;
    }

    /**
     * Load an override's stored editable values as the manager normalises them for diffing: each
     * state column as an int, the justification as its stored (already trimmed) string, and the
     * expiry as a positive int or null.
     *
     * @param int $overrideid Override id.
     * @param string[] $statecolumns The five override state column names.
     * @return array<string, mixed> Editable column => normalised stored value.
     */
    private function load_editable_values(int $overrideid, array $statecolumns): array {
        global $DB;

        $record = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);
        $values = [];
        foreach ($statecolumns as $column) {
            $values[$column] = (int)$record->$column;
        }
        $values['justification'] = (string)$record->justification;
        $values['expiry'] = ($record->expiry === null || $record->expiry === '') ? null : (int)$record->expiry;
        return $values;
    }

    /**
     * Build an edit() $data mutating a RANDOM subset of the editable fields relative to the stored
     * values. Each field independently becomes one of: absent (keep stored -> no change), set equal
     * to the stored value (no change, exercising the "present but equal" path, including
     * whitespace-padded justifications that trim back to the stored value), or set to a valid but
     * DIFFERENT value (a real change). All produced values are valid so the edit is never rejected.
     *
     * @param array $existing Normalised stored editable values from {@see load_editable_values()}.
     * @param string[] $statecolumns The five override state column names.
     * @return \stdClass A valid edit() input touching a random subset of fields.
     */
    private function build_random_edit_subset(array $existing, array $statecolumns): \stdClass {
        $data = new \stdClass();

        // State columns: absent, same, or a different valid tri-state.
        foreach ($statecolumns as $column) {
            switch (mt_rand(0, 2)) {
                case 0:
                    // Absent: keep stored value (no change).
                    break;
                case 1:
                    // Present but equal (no change).
                    $data->$column = (int)$existing[$column];
                    break;
                default:
                    // A different valid tri-state (a real change).
                    $data->$column = $this->different_state((int)$existing[$column]);
                    break;
            }
        }

        // Justification: absent, equal (optionally whitespace-padded so trim() maps it back to the
        // stored value), or a new distinct non-blank string.
        switch (mt_rand(0, 2)) {
            case 0:
                // Absent (no change).
                break;
            case 1:
                // Present but trims back to the stored value (no change).
                $data->justification = '  ' . $existing['justification'] . "  \n";
                break;
            default:
                // A new distinct justification (a real change).
                $data->justification = 'Edited justification ' . mt_rand(0, PHP_INT_MAX);
                break;
        }

        // Expiry: absent (keep stored), or set to null / a future timestamp. Each is valid.
        switch (mt_rand(0, 3)) {
            case 0:
                // Absent: keep stored expiry (no change).
                break;
            case 1:
                // Explicit "no expiry".
                $data->expiry = null;
                break;
            case 2:
                // Zero also normalises to "no expiry".
                $data->expiry = 0;
                break;
            default:
                // A strictly-future timestamp (kept clear of now so it stays valid).
                $data->expiry = time() + mt_rand(100, 100000);
                break;
        }

        return $data;
    }

    /**
     * Pick a valid tri-state different from the given current value, so a state edit is a real
     * change.
     *
     * @param int $current The current tri-state value.
     * @return int A valid tri-state not equal to $current.
     */
    private function different_state(int $current): int {
        $choices = [];
        foreach (self::VALID_STATES as $state) {
            if ((int)$state !== $current) {
                $choices[] = (int)$state;
            }
        }
        return $choices[mt_rand(0, count($choices) - 1)];
    }

    /**
     * Independent reference mirroring override_manager::edit()'s field diff and audit_value()
     * formatting: compute exactly which editable fields change and their audit oldvalue/newvalue
     * strings, given the stored values and the submitted edit data.
     *
     * State columns are compared as ints; the justification is compared after trim() (and stored
     * trimmed); the expiry is normalised to a positive int or null. audit_value maps null to null
     * and everything else to its string form.
     *
     * @param array $existing Normalised stored editable values.
     * @param \stdClass $data Submitted edit data.
     * @param string[] $statecolumns The five override state column names.
     * @return array<string, array{oldvalue: ?string, newvalue: ?string}> Changed fields with deltas.
     */
    private function compute_expected_edit_changes(array $existing, \stdClass $data, array $statecolumns): array {
        $changes = [];

        // State columns: compare as ints; audit values are the int cast to string.
        foreach ($statecolumns as $column) {
            $old = (int)$existing[$column];
            $new = isset($data->$column) ? (int)$data->$column : $old;
            if ($old !== $new) {
                $changes[$column] = ['oldvalue' => (string)$old, 'newvalue' => (string)$new];
            }
        }

        // Justification: compare after trim; stored value is already trimmed.
        $oldj = (string)$existing['justification'];
        $rawj = isset($data->justification) ? (string)$data->justification : $oldj;
        $newj = trim($rawj);
        if ($oldj !== $newj) {
            $changes['justification'] = ['oldvalue' => $oldj, 'newvalue' => $newj];
        }

        // Expiry: normalise to positive int or null; audit values map null -> null else (string).
        $olde = $existing['expiry'];
        $newe = property_exists($data, 'expiry') ? $this->normalise_expiry_ref($data->expiry) : $olde;
        if ($olde !== $newe) {
            $changes['expiry'] = [
                'oldvalue' => $olde === null ? null : (string)$olde,
                'newvalue' => $newe === null ? null : (string)$newe,
            ];
        }

        return $changes;
    }

    /**
     * Independent reference mirroring override_manager::normalise_expiry(): null/'' -> null; any
     * other value cast to int, then a non-positive result also becomes null ("no expiry").
     *
     * @param mixed $value Raw submitted expiry value.
     * @return int|null Positive timestamp, or null for no expiry.
     */
    private function normalise_expiry_ref($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $expiry = (int)$value;
        return $expiry > 0 ? $expiry : null;
    }

    /**
     * The current maximum id in the override audit table (0 when empty). Captured immediately
     * before an edit so the rows the edit appends can be isolated as those with a greater id.
     *
     * @return int The highest audit row id, or 0 if there are none.
     */
    private function max_audit_id(): int {
        global $DB;

        return (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(id), 0) FROM {quizaccess_proctoring_override_audit}');
    }

    /**
     * Fetch the audit rows appended for a given override AFTER a captured high-water mark, keyed by
     * field name, exposing each row's action, actor, timestamp, and old/new values. Since only
     * edit() appends per-field rows (with a non-null fieldname), this isolates exactly the rows a
     * single edit wrote.
     *
     * @param int $overrideid Override id.
     * @param int $maxbefore The max audit id captured immediately before the edit.
     * @return array<string, array{action: string, actorid: int, timecreated: int, oldvalue: ?string, newvalue: ?string}>
     */
    private function new_edit_audit_rows(int $overrideid, int $maxbefore): array {
        global $DB;

        $rows = $DB->get_records_select(
            'quizaccess_proctoring_override_audit',
            'id > :maxid AND overrideid = :oid',
            ['maxid' => $maxbefore, 'oid' => $overrideid],
            'id ASC'
        );

        $byfield = [];
        foreach ($rows as $row) {
            $byfield[$row->fieldname] = [
                'action' => (string)$row->action,
                'actorid' => (int)$row->actorid,
                'timecreated' => (int)$row->timecreated,
                'oldvalue' => $row->oldvalue === null ? null : (string)$row->oldvalue,
                'newvalue' => $row->newvalue === null ? null : (string)$row->newvalue,
            ];
        }
        return $byfield;
    }

    /**
     * Return the sorted keys of an associative array, for order-insensitive set comparison of the
     * expected-vs-actual changed field names.
     *
     * @param array $map Associative array.
     * @return string[] Sorted keys.
     */
    private function sorted_keys(array $map): array {
        $keys = array_keys($map);
        sort($keys);
        return $keys;
    }

    /**
     * Feature: per-student-proctoring-overrides, Property 13: Audit trail is append-only and monotonic
     *
     * For any sequence of create/edit/revoke operations on a single override, the audit trail only
     * ever grows: the count of audit rows is non-decreasing, every audit row present in an earlier
     * snapshot is still present and byte-for-byte identical in every later snapshot (no existing
     * row is ever mutated or deleted), audit row ids are strictly increasing in insertion order
     * (monotonic), and timecreated is non-decreasing when ordered by id. This mirrors the
     * implementation: override_manager::audit() is the sole writer of the audit table and only ever
     * insert_records() -- there is no update or delete path.
     *
     * Each iteration creates a fresh override (which appends exactly one 'create' row), then applies
     * a bounded random sequence of edits (each appending zero-or-more 'edit' rows depending on how
     * many editable fields actually change) followed by a single revoke (which appends exactly one
     * 'revoke' row). After EVERY operation the ENTIRE audit trail for the override is snapshotted
     * (id, action, fieldname, oldvalue, newvalue, actorid, timecreated). Across the whole sequence
     * the append-only and monotonic invariants are asserted: (1) the row count never decreases;
     * (2) every row from an earlier snapshot appears unchanged in the current one; (3) ids strictly
     * increase in insertion order; (4) timecreated is non-decreasing by id. The generator alternates
     * the acting reviewer between two capable teachers so audit rows carry varied actorids without
     * affecting the invariant.
     *
     * The number of iterations (ITERATIONS = 120) exceeds the 100-iteration floor, and each
     * iteration performs a create + several edits + a revoke, so well over 100 operations are
     * exercised in total.
     *
     * Validates: Requirements 7.6
     */
    public function test_audit_trail_is_append_only_and_monotonic(): void {
        $this->resetAfterTest(true);

        // Deterministic seed so any counterexample is reproducible.
        mt_srand(20260707);

        // Course + enrolled target student + two acting reviewers (both editingteachers hold
        // quizaccess/proctoring:manageoverrides) so audit rows carry varied actorids, plus a quiz
        // module to build a module context.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $teacher2 = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        $statecolumns = array_values(override_resolver::STATE_COLUMNS);

        for ($iteration = 0; $iteration < self::ITERATIONS; $iteration++) {
            // Create the override as the first reviewer: this appends exactly one 'create' row.
            $this->setUser($teacher);
            $overrideid = override_manager::create($context, $this->build_varied_create_data($student->id));

            // The running list of snapshots taken after each operation, plus a label for messages.
            $snapshots = [];
            $snapshots[] = ['label' => 'after create', 'rows' => $this->snapshot_audit_rows($overrideid)];

            // Apply a bounded random sequence of edits, alternating the acting reviewer.
            $editcount = mt_rand(1, 5);
            for ($e = 0; $e < $editcount; $e++) {
                $this->setUser(mt_rand(0, 1) === 0 ? $teacher : $teacher2);

                $existing = $this->load_editable_values($overrideid, $statecolumns);
                $editdata = $this->build_random_edit_subset($existing, $statecolumns);
                override_manager::edit($context, $overrideid, $editdata);

                $snapshots[] = [
                    'label' => 'after edit ' . $e,
                    'rows' => $this->snapshot_audit_rows($overrideid),
                ];
            }

            // Revoke as the second reviewer: this appends exactly one 'revoke' row.
            $this->setUser($teacher2);
            override_manager::revoke($context, $overrideid);
            $snapshots[] = ['label' => 'after revoke', 'rows' => $this->snapshot_audit_rows($overrideid)];

            // Assert the append-only + monotonic invariants across the whole sequence.
            $this->assert_audit_append_only_and_monotonic($snapshots, 'iteration=' . $iteration);
        }
    }

    /**
     * Snapshot every audit row for one override, ordered by id ascending, capturing all columns
     * relevant to the append-only invariant. Each row is a plain array so snapshots can be compared
     * byte-for-byte across operations without shared object references.
     *
     * @param int $overrideid Override id whose audit rows to snapshot.
     * @return array<int, array<string, mixed>> Ordered list of audit rows (id, action, fieldname,
     *                                           oldvalue, newvalue, actorid, timecreated).
     */
    private function snapshot_audit_rows(int $overrideid): array {
        global $DB;

        $rows = $DB->get_records('quizaccess_proctoring_override_audit',
            ['overrideid' => $overrideid], 'id ASC');

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[] = [
                'id' => (int)$row->id,
                'overrideid' => (int)$row->overrideid,
                'action' => (string)$row->action,
                'fieldname' => $row->fieldname === null ? null : (string)$row->fieldname,
                'oldvalue' => $row->oldvalue === null ? null : (string)$row->oldvalue,
                'newvalue' => $row->newvalue === null ? null : (string)$row->newvalue,
                'actorid' => (int)$row->actorid,
                'timecreated' => (int)$row->timecreated,
            ];
        }
        return $snapshot;
    }

    /**
     * Assert the append-only and monotonic invariants over an ordered list of audit-trail
     * snapshots taken after successive operations on one override:
     *
     *  (1) the audit row count is non-decreasing from each snapshot to the next;
     *  (2) every row present in an earlier snapshot is still present and byte-for-byte identical in
     *      the later snapshot (append-only: no existing row is mutated or deleted) -- because rows
     *      are keyed by id and compared whole, this catches both deletion (missing id) and
     *      mutation (changed content) of any previously written row;
     *  (3) within every snapshot the audit row ids are strictly increasing in insertion order;
     *  (4) within every snapshot timecreated is non-decreasing when ordered by id.
     *
     * @param array<int, array{label: string, rows: array}> $snapshots Ordered snapshots to check.
     * @param string $where Human-readable case label included in every assertion message.
     * @return void
     */
    private function assert_audit_append_only_and_monotonic(array $snapshots, string $where): void {
        $previous = null;
        $previouslabel = null;

        foreach ($snapshots as $snapshot) {
            $rows = $snapshot['rows'];
            $label = $snapshot['label'];

            // (3) + (4): within this snapshot, ids strictly increase and timecreated is
            // non-decreasing when ordered by id (rows are already id-ascending).
            $lastid = null;
            $lasttime = null;
            foreach ($rows as $row) {
                if ($lastid !== null) {
                    $this->assertGreaterThan($lastid, $row['id'],
                        'Audit row ids must be strictly increasing in insertion order. '
                        . $where . ' ' . $label . ' id=' . $row['id']);
                    $this->assertGreaterThanOrEqual($lasttime, $row['timecreated'],
                        'Audit timecreated must be non-decreasing by id. '
                        . $where . ' ' . $label . ' id=' . $row['id']);
                }
                $lastid = $row['id'];
                $lasttime = $row['timecreated'];
            }

            if ($previous !== null) {
                // (1): the row count never decreases from one operation to the next.
                $this->assertGreaterThanOrEqual(count($previous), count($rows),
                    'The audit row count must be non-decreasing (append-only). '
                    . $where . ' from ' . $previouslabel . ' to ' . $label);

                // (2): every earlier row survives unchanged. Key the current snapshot by id, then
                // require each earlier row's id to still exist with byte-for-byte identical content.
                $currentbyid = [];
                foreach ($rows as $row) {
                    $currentbyid[$row['id']] = $row;
                }
                foreach ($previous as $oldrow) {
                    $this->assertArrayHasKey($oldrow['id'], $currentbyid,
                        'A previously written audit row must never be deleted. '
                        . $where . ' from ' . $previouslabel . ' to ' . $label
                        . ' missingid=' . $oldrow['id']);
                    $this->assertSame($oldrow, $currentbyid[$oldrow['id']],
                        'A previously written audit row must remain byte-for-byte unchanged '
                        . '(no mutation). ' . $where . ' from ' . $previouslabel . ' to ' . $label
                        . ' id=' . $oldrow['id']);
                }
            }

            $previous = $rows;
            $previouslabel = $label;
        }
    }

    // -------------------------------------------------------------------------------------------
    // Example / unit tests (Task 4.10): capability gating, target validation, and recordkeeping.
    //
    // These are concrete example-based tests (not property tests): each asserts one specific
    // scenario, so there is no seeded generator loop and no Property tag. They reuse the same
    // course/quiz/enrolled-student fixture style as the property tests above and the shared
    // build_valid_create_data() helper.
    // -------------------------------------------------------------------------------------------

    /**
     * Feature: per-student-proctoring-overrides (example test)
     *
     * Capability gating (allowed): a reviewer holding quizaccess/proctoring:manageoverrides (the
     * editingteacher archetype) can successfully create, edit, and revoke an override. Each
     * operation persists its effect and appends the expected audit rows.
     *
     * Validates: Requirements 1.1, 1.2, 6.1, 7.1, 7.2
     */
    public function test_capable_reviewer_can_create_edit_and_revoke(): void {
        global $DB;

        $this->resetAfterTest(true);

        $fixture = $this->make_fixture();
        $context = $fixture['context'];
        $student = $fixture['student'];
        $teacher = $fixture['teacher'];

        // Act as the capable reviewer (editingteacher holds manageoverrides).
        $this->setUser($teacher);

        // --- create() succeeds and appends one 'create' audit row. ---
        $overrideid = override_manager::create($context, $this->build_valid_create_data($student->id));
        $this->assertGreaterThan(0, $overrideid,
            'A capable reviewer should be able to create an override.');
        $this->assertTrue(
            $DB->record_exists('quizaccess_proctoring_overrides', ['id' => $overrideid]),
            'The created override row should exist.');
        $this->assertSame(1, $this->count_audit_rows($overrideid),
            'create() should append exactly one audit row.');

        // --- edit() succeeds: change a tri-state and the justification. ---
        $editdata = new \stdClass();
        $editdata->captchastate = override_resolver::STATE_ENABLED;
        $editdata->justification = 'Capable reviewer edited justification';
        override_manager::edit($context, $overrideid, $editdata);

        $edited = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);
        $this->assertSame((int)override_resolver::STATE_ENABLED, (int)$edited->captchastate,
            'edit() should persist the changed tri-state.');
        $this->assertSame('Capable reviewer edited justification', (string)$edited->justification,
            'edit() should persist the changed justification.');
        $this->assertGreaterThanOrEqual(2, $this->count_audit_rows($overrideid),
            'edit() should append at least one further audit row for the changed fields.');

        // --- revoke() succeeds and records the acting reviewer. ---
        override_manager::revoke($context, $overrideid);
        $revoked = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$revoked->revoked,
            'revoke() should mark the override revoked.');
        $this->assertSame((int)$teacher->id, (int)$revoked->revokedby,
            'revoke() should record the acting reviewer as revokedby.');
    }

    /**
     * Feature: per-student-proctoring-overrides (example test)
     *
     * Capability gating (denied) for create(): a user WITHOUT manageoverrides (a plain enrolled
     * student) attempting create() is rejected by the capability check, and crucially the denied
     * attempt writes NOTHING: no override row and no audit row are created.
     *
     * Validates: Requirements 1.2, 7.7
     */
    public function test_uncapable_user_cannot_create_and_writes_no_audit(): void {
        global $DB;

        $this->resetAfterTest(true);

        $fixture = $this->make_fixture();
        $context = $fixture['context'];
        $student = $fixture['student'];

        // A plain student has no manageoverrides capability.
        $unprivileged = $this->getDataGenerator()->create_and_enrol($fixture['course'], 'student');
        $this->setUser($unprivileged);

        $overridesbefore = $DB->count_records('quizaccess_proctoring_overrides');
        $auditbefore = $DB->count_records('quizaccess_proctoring_override_audit');

        $threw = false;
        try {
            override_manager::create($context, $this->build_valid_create_data($student->id));
        } catch (\moodle_exception $e) {
            // require_capability() throws required_capability_exception, which extends moodle_exception.
            $threw = true;
        }
        $this->assertTrue($threw,
            'create() by an uncapable user must throw a capability exception.');

        $this->assertSame($overridesbefore, $DB->count_records('quizaccess_proctoring_overrides'),
            'A denied create() must not insert any override row.');
        $this->assertSame($auditbefore, $DB->count_records('quizaccess_proctoring_override_audit'),
            'A denied create() must not append any audit row.');
    }

    /**
     * Feature: per-student-proctoring-overrides (example test)
     *
     * Capability gating (denied) for edit() and revoke(): an existing override is created by the
     * capable reviewer; then an uncapable user attempts to edit and to revoke it. Both attempts
     * throw, the override is left unchanged, and NO audit row is appended by either denied attempt.
     *
     * Validates: Requirements 7.1, 7.2, 7.7
     */
    public function test_uncapable_user_cannot_edit_or_revoke_and_writes_no_audit(): void {
        global $DB;

        $this->resetAfterTest(true);

        $fixture = $this->make_fixture();
        $context = $fixture['context'];
        $student = $fixture['student'];
        $teacher = $fixture['teacher'];

        // Create a baseline override as the capable reviewer.
        $this->setUser($teacher);
        $overrideid = override_manager::create($context, $this->build_valid_create_data($student->id));

        // Snapshot the override row and audit count before the denied attempts.
        $before = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);
        $auditbefore = $DB->count_records('quizaccess_proctoring_override_audit');

        // Switch to an uncapable enrolled student.
        $unprivileged = $this->getDataGenerator()->create_and_enrol($fixture['course'], 'student');
        $this->setUser($unprivileged);

        // --- denied edit() ---
        $editdata = new \stdClass();
        $editdata->captchastate = override_resolver::STATE_ENABLED;
        $editdata->justification = 'Unprivileged edit attempt';

        $threw = false;
        try {
            override_manager::edit($context, $overrideid, $editdata);
        } catch (\moodle_exception $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'edit() by an uncapable user must throw a capability exception.');

        // --- denied revoke() ---
        $threw = false;
        try {
            override_manager::revoke($context, $overrideid);
        } catch (\moodle_exception $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'revoke() by an uncapable user must throw a capability exception.');

        // The override row is byte-for-byte unchanged by the denied attempts.
        $after = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);
        $this->assertEquals($before, $after,
            'A denied edit/revoke must leave the override row unchanged.');

        // No audit row was appended by either denied attempt.
        $this->assertSame($auditbefore, $DB->count_records('quizaccess_proctoring_override_audit'),
            'A denied edit/revoke must not append any audit row.');
    }

    /**
     * Feature: per-student-proctoring-overrides (example test)
     *
     * Target validation edge cases for create(): a zero/missing userid, a non-existent userid, and
     * a user who exists but is NOT enrolled in the course are each rejected with
     * error:invalidtarget, and none of them inserts an override row.
     *
     * Validates: Requirements 1.3, 1.4
     */
    public function test_create_rejects_invalid_targets(): void {
        global $DB;

        $this->resetAfterTest(true);

        $fixture = $this->make_fixture();
        $context = $fixture['context'];
        $teacher = $fixture['teacher'];

        $this->setUser($teacher);

        // A user who exists but is NOT enrolled in the course.
        $notenrolled = $this->getDataGenerator()->create_user();

        $cases = [
            ['userid' => 0, 'label' => 'userid 0 (missing target)'],
            ['userid' => 9999999, 'label' => 'non-existent userid'],
            ['userid' => (int)$notenrolled->id, 'label' => 'existing but non-enrolled user'],
        ];

        foreach ($cases as $case) {
            $countbefore = $DB->count_records('quizaccess_proctoring_overrides');

            $data = $this->build_valid_create_data($case['userid']);

            $threw = false;
            try {
                override_manager::create($context, $data);
            } catch (\moodle_exception $e) {
                $threw = true;
                $this->assertSame('error:invalidtarget', $e->errorcode,
                    'An invalid target should reject with error:invalidtarget. case=' . $case['label']
                    . ' errorcode=' . $e->errorcode);
            }
            $this->assertTrue($threw,
                'create() must throw for an invalid target. case=' . $case['label']);
            $this->assertSame($countbefore, $DB->count_records('quizaccess_proctoring_overrides'),
                'A rejected invalid target must not insert any override row. case=' . $case['label']);
        }
    }

    /**
     * Feature: per-student-proctoring-overrides (example test)
     *
     * Recordkeeping / review: after a successful create(), the stored override exposes ALL recorded
     * fields for review, matching exactly what was submitted -- the acting reviewer (grantedby), a
     * positive creation timestamp, the scope (quizid) and target (userid), the five tri-states, the
     * justification (stored trimmed), and the expiry. This is the concrete "review exposes recorded
     * fields" example complementing the create/read round-trip property.
     *
     * Validates: Requirements 6.1, 6.3
     */
    public function test_created_override_exposes_all_recorded_fields_for_review(): void {
        global $DB;

        $this->resetAfterTest(true);

        $fixture = $this->make_fixture();
        $context = $fixture['context'];
        $course = $fixture['course'];
        $student = $fixture['student'];
        $teacher = $fixture['teacher'];
        $quiz = $fixture['quiz'];

        $this->setUser($teacher);

        // Build a create() input with fully specified, known values across every recorded field.
        $expiry = time() + 86400; // Strictly future.
        $data = new \stdClass();
        $data->quizid = (int)$quiz->id; // Quiz-scoped.
        $data->userid = (int)$student->id;
        $data->captchastate = override_resolver::STATE_ENABLED;
        $data->webcamstate = override_resolver::STATE_DISABLED;
        $data->idverificationstate = override_resolver::STATE_INHERIT;
        $data->screensharestate = override_resolver::STATE_ENABLED;
        $data->multimonitorstate = override_resolver::STATE_DISABLED;
        $data->justification = '  Documented accommodation reason  ';
        $data->expiry = $expiry;

        $before = time();
        $overrideid = override_manager::create($context, $data);
        $after = time();

        $record = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid], '*', MUST_EXIST);

        // Scope + target expose exactly what was submitted; courseid derives from the context.
        $this->assertSame((int)$quiz->id, (int)$record->quizid,
            'Review should expose the recorded quiz scope.');
        $this->assertSame((int)$student->id, (int)$record->userid,
            'Review should expose the recorded target student.');
        $this->assertSame((int)$course->id, (int)$record->courseid,
            'Review should expose the recorded course.');

        // All five recorded tri-states are exposed as submitted.
        $this->assertSame((int)override_resolver::STATE_ENABLED, (int)$record->captchastate,
            'Review should expose the recorded captcha state.');
        $this->assertSame((int)override_resolver::STATE_DISABLED, (int)$record->webcamstate,
            'Review should expose the recorded webcam state.');
        $this->assertSame((int)override_resolver::STATE_INHERIT, (int)$record->idverificationstate,
            'Review should expose the recorded ID-verification state.');
        $this->assertSame((int)override_resolver::STATE_ENABLED, (int)$record->screensharestate,
            'Review should expose the recorded screen-share state.');
        $this->assertSame((int)override_resolver::STATE_DISABLED, (int)$record->multimonitorstate,
            'Review should expose the recorded multi-monitor state.');

        // Justification is exposed stored-trimmed; expiry is exposed exactly.
        $this->assertSame('Documented accommodation reason', (string)$record->justification,
            'Review should expose the recorded justification (trimmed).');
        $this->assertSame($expiry, (int)$record->expiry,
            'Review should expose the recorded expiry timestamp.');

        // The granting reviewer identity and creation timestamp are exposed for audit/compliance.
        $this->assertSame((int)$teacher->id, (int)$record->grantedby,
            'Review should expose the granting reviewer identity.');
        $this->assertGreaterThan(0, (int)$record->timecreated,
            'Review should expose a positive creation timestamp.');
        $this->assertGreaterThanOrEqual($before, (int)$record->timecreated,
            'The recorded creation timestamp should be no earlier than the call start.');
        $this->assertLessThanOrEqual($after, (int)$record->timecreated,
            'The recorded creation timestamp should be no later than the call end.');

        // A freshly created override is exposed as not revoked.
        $this->assertSame(0, (int)$record->revoked,
            'A freshly created override should be exposed as not revoked.');
    }

    /**
     * Build the standard test fixture: a course, an enrolled target student, a capable reviewer
     * (editingteacher archetype holds quizaccess/proctoring:manageoverrides), a quiz module, and
     * its module context. Mirrors the setup used by the property tests above.
     *
     * @return array{course: \stdClass, student: \stdClass, teacher: \stdClass, quiz: \stdClass, context: \context_module}
     */
    private function make_fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = context_module::instance($quiz->cmid);

        return [
            'course' => $course,
            'student' => $student,
            'teacher' => $teacher,
            'quiz' => $quiz,
            'context' => $context,
        ];
    }

    /**
     * Count the audit rows currently stored for a given override.
     *
     * @param int $overrideid Override id.
     * @return int Number of audit rows for that override.
     */
    private function count_audit_rows(int $overrideid): int {
        global $DB;

        return $DB->count_records('quizaccess_proctoring_override_audit', ['overrideid' => $overrideid]);
    }
}
