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
 * Legacy webcam/ID exemption migration and single-source-of-truth tests.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use context_module;
use xmldb_table;
use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_resolver.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/local/override_manager.php');

/**
 * Migration tests for task 10 (migrate and subsume the legacy webcam/ID exemption).
 *
 * Feature: per-student-proctoring-overrides
 *
 * IMPORTANT — no legacy storage ever shipped.
 * -------------------------------------------
 * Task 10.1 investigated the `proctoring-feedback-improvements` spec's Requirement 9 webcam/ID
 * "Override_Exemption" and conclusively found it was NEVER implemented: there is no legacy table,
 * no legacy column, and no legacy config, and `rule.php` resolves all five proctoring requirements
 * exclusively through {@see override_resolver::resolve_all()} (see the 2026062406 block in
 * `db/upgrade.php`, which records "No legacy exemption data to migrate"). Because there is no prior
 * storage or old consultation path, there is no data migration to exercise.
 *
 * These tests therefore LOCK IN the design decision that `quizaccess_proctoring_overrides` is the
 * single source of truth for per-student waivers (Requirements 3.1, 3.2). Rather than testing a
 * data migration that does not exist, they assert the observable facts that make the "single
 * source of truth, no legacy path" invariant hold:
 *
 *   1. No legacy exemption storage exists — the override tables the design defines are present and
 *      a plausibly-named legacy exemption table is absent.
 *   2. The webcam and ID_Verification effective states flow solely through the overrides layer: a
 *      disabling override flips them via `resolve_all()` (the same seam `rule.php` uses), and with
 *      no override the base states are returned unchanged (no phantom legacy path alters them).
 *   3. A representative "if a legacy exemption HAD existed" webcam+ID waiver maps cleanly onto a
 *      single override row (carrying grantedby/timecreated + a migration-style justification),
 *      round-trips through the store, and resolves as a webcam+ID waiver — documenting the shape
 *      the migration WOULD have produced without inventing any legacy storage.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @coversNothing
 */
final class legacy_exemption_migration_test extends advanced_testcase {

    /** @var string Main per-student override table (the single source of truth). */
    private const TABLE_OVERRIDES = 'quizaccess_proctoring_overrides';

    /** @var string Append-only audit trail table. */
    private const TABLE_AUDIT = 'quizaccess_proctoring_override_audit';

    /**
     * A plausibly-named legacy webcam/ID exemption table. It must NOT exist: the narrower
     * exemption mechanism never shipped its own storage, so the overrides table is the only
     * per-student waiver store.
     *
     * @var string
     */
    private const TABLE_LEGACY_EXEMPTIONS = 'quizaccess_proctoring_exemptions';

    /** @var \stdClass Generated course. */
    private $course;

    /** @var \stdClass Enrolled target student. */
    private $student;

    /** @var \stdClass Acting reviewer (editingteacher holds manageoverrides). */
    private $teacher;

    /** @var \stdClass Generated quiz module. */
    private $quiz;

    /** @var context_module The quiz module context. */
    private $context;

    /**
     * Build a course + enrolled student + editingteacher reviewer + quiz module for the
     * resolution tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $this->context = context_module::instance($this->quiz->cmid);
    }

    /**
     * No legacy exemption storage exists; the overrides layer is the single source of truth.
     *
     * The two tables the design defines (the overrides table and its append-only audit trail)
     * must exist, and the plausibly-named legacy webcam/ID exemption table must NOT exist. This
     * proves there is no separate legacy store for `rule.php` to consult, so the webcam/ID waiver
     * concept lives entirely in `quizaccess_proctoring_overrides`.
     *
     * Validates: Requirements 3.1, 3.2
     */
    public function test_no_legacy_exemption_storage_single_source_of_truth(): void {
        global $DB;

        $dbman = $DB->get_manager();

        // The design's two tables are the only per-student waiver storage, and both exist.
        $this->assertTrue($dbman->table_exists(new xmldb_table(self::TABLE_OVERRIDES)),
            'The overrides table (single source of truth) must exist.');
        $this->assertTrue($dbman->table_exists(new xmldb_table(self::TABLE_AUDIT)),
            'The override audit table must exist.');

        // No legacy webcam/ID exemption table ever shipped, so there is nothing to migrate from
        // and no old path to consult.
        $this->assertFalse($dbman->table_exists(new xmldb_table(self::TABLE_LEGACY_EXEMPTIONS)),
            'No legacy webcam/ID exemption table should exist: the overrides table subsumes it.');
    }

    /**
     * The webcam and ID_Verification effective states resolve solely through the overrides layer.
     *
     * With webcam and ID required at base, a single override that disables both flips their
     * effective states off via `override_resolver::resolve_all()` — the exact seam `rule.php`
     * consults. Removing the override (by revoking it) returns the base states unchanged, proving
     * no phantom legacy path alters webcam/ID: when the overrides layer says nothing, the base
     * states stand verbatim.
     *
     * Validates: Requirements 3.1, 3.2
     */
    public function test_webcam_and_id_resolve_only_through_overrides_layer(): void {
        $this->setUser($this->student);

        // Base: webcam and ID both required; the other requirements arbitrary but fixed.
        $basestates = [
            override_resolver::REQ_CAPTCHA => true,
            override_resolver::REQ_WEBCAM => true,
            override_resolver::REQ_IDVERIFICATION => true,
            override_resolver::REQ_SCREENSHARE => true,
            override_resolver::REQ_MULTIMONITOR => true,
            override_resolver::REQ_PHONEDETECTION => true,
        ];

        // With no override, resolve_all() returns the base states unchanged — no legacy path
        // touches webcam or ID.
        $resolvednooverride = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );
        $this->assertSame($basestates, $resolvednooverride,
            'With no override, webcam/ID (and all requirements) must equal the base states.');

        // Insert a webcam + ID disabling override through the real write path.
        $overrideid = $this->create_waiver_override([
            'webcamstate' => override_resolver::STATE_DISABLED,
            'idverificationstate' => override_resolver::STATE_DISABLED,
        ]);

        $resolvedwithoverride = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );

        // The overrides layer is the only thing that can flip webcam/ID off.
        $this->assertFalse($resolvedwithoverride[override_resolver::REQ_WEBCAM],
            'A disabling override must waive the webcam requirement via the overrides layer.');
        $this->assertFalse($resolvedwithoverride[override_resolver::REQ_IDVERIFICATION],
            'A disabling override must waive the ID verification requirement via the overrides layer.');

        // Requirements the override left at inherit keep their base value (per-requirement
        // independence, no cross-talk from a legacy path).
        foreach ([
            override_resolver::REQ_CAPTCHA,
            override_resolver::REQ_SCREENSHARE,
            override_resolver::REQ_MULTIMONITOR,
        ] as $requirement) {
            $this->assertTrue($resolvedwithoverride[$requirement],
                'Requirement ' . $requirement . ' left at inherit must keep its base value.');
        }

        // Revoke: with the override gone, webcam/ID fall back to the base states — confirming the
        // overrides layer is the sole influence and nothing else remembers the waiver.
        $this->setUser($this->teacher);
        override_manager::revoke($this->context, $overrideid);
        $this->setUser($this->student);

        $resolvedafterrevoke = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );
        $this->assertSame($basestates, $resolvedafterrevoke,
            'After revoke, webcam/ID must return to base: the overrides layer is the only source.');
    }

    /**
     * Representative equivalence: a legacy webcam/ID exemption, had one existed, maps onto exactly
     * one override row.
     *
     * This documents the mapping the migration WOULD have produced (per the design's Migration and
     * Coexistence section): the narrower exemption's recordkeeping — scope (course/quiz/user),
     * granting reviewer, and creation timestamp — maps directly onto the override row's
     * `grantedby`/`timecreated`, with `webcamstate` = `idverificationstate` = 0 and a synthetic
     * migration justification. The row round-trips through the store unchanged and resolves as a
     * webcam + ID waiver, demonstrating no recordkeeping is lost by subsuming the exemption into
     * this layer. No legacy storage is invented — the row is created through the normal write path.
     *
     * Validates: Requirements 3.1, 3.2
     */
    public function test_legacy_exemption_shape_maps_onto_override_row(): void {
        global $DB;

        // Fields a legacy webcam/ID exemption would have carried, mapped onto override columns.
        $grantedby = (int)$this->teacher->id;
        $migrationjustification = 'Migrated from legacy webcam/ID exemption (proctoring-feedback-improvements R9).';

        $overrideid = $this->create_waiver_override([
            'webcamstate' => override_resolver::STATE_DISABLED,
            'idverificationstate' => override_resolver::STATE_DISABLED,
        ], $migrationjustification);

        // Round-trip: the stored row reflects the waiver shape and preserves scope + provenance.
        $stored = $DB->get_record(self::TABLE_OVERRIDES, ['id' => $overrideid], '*', MUST_EXIST);

        $this->assertSame(override_resolver::STATE_DISABLED, (int)$stored->webcamstate,
            'Migrated webcam exemption maps to webcamstate = 0 (disabled/waived).');
        $this->assertSame(override_resolver::STATE_DISABLED, (int)$stored->idverificationstate,
            'Migrated ID exemption maps to idverificationstate = 0 (disabled/waived).');

        // Requirements the legacy exemption never covered stay at inherit.
        $this->assertSame(override_resolver::STATE_INHERIT, (int)$stored->captchastate,
            'A webcam/ID exemption never touched CAPTCHA; it stays at inherit.');
        $this->assertSame(override_resolver::STATE_INHERIT, (int)$stored->screensharestate,
            'A webcam/ID exemption never touched screen share; it stays at inherit.');
        $this->assertSame(override_resolver::STATE_INHERIT, (int)$stored->multimonitorstate,
            'A webcam/ID exemption never touched multi-monitor; it stays at inherit.');

        // Scope + provenance carried over (the exemption's user/course/quiz + granting reviewer).
        $this->assertSame((int)$this->student->id, (int)$stored->userid);
        $this->assertSame((int)$this->course->id, (int)$stored->courseid);
        $this->assertSame(0, (int)$stored->quizid, 'Course-scoped waiver, as a broad exemption would be.');
        $this->assertSame($grantedby, (int)$stored->grantedby,
            'The granting reviewer identity is preserved on the migrated row.');
        $this->assertGreaterThan(0, (int)$stored->timecreated,
            'The creation timestamp is recorded on the migrated row.');
        $this->assertSame($migrationjustification, $stored->justification,
            'The synthetic migration justification is preserved verbatim.');

        // And it resolves as a webcam + ID waiver against a base where both are required.
        $this->setUser($this->student);
        $basestates = [
            override_resolver::REQ_CAPTCHA => true,
            override_resolver::REQ_WEBCAM => true,
            override_resolver::REQ_IDVERIFICATION => true,
            override_resolver::REQ_SCREENSHARE => true,
            override_resolver::REQ_MULTIMONITOR => true,
        ];
        $resolved = override_resolver::resolve_all(
            (int)$this->course->id,
            (int)$this->quiz->id,
            (int)$this->student->id,
            time(),
            $basestates
        );

        $this->assertFalse($resolved[override_resolver::REQ_WEBCAM],
            'The migrated row resolves as a webcam waiver.');
        $this->assertFalse($resolved[override_resolver::REQ_IDVERIFICATION],
            'The migrated row resolves as an ID verification waiver.');
        $this->assertTrue($resolved[override_resolver::REQ_CAPTCHA],
            'Requirements outside the exemption keep their base (required) value.');
        $this->assertTrue($resolved[override_resolver::REQ_SCREENSHARE]);
        $this->assertTrue($resolved[override_resolver::REQ_MULTIMONITOR]);
    }

    /**
     * Create a course-scoped per-student override for the fixture student through the real write
     * path, acting as the editingteacher reviewer (who holds
     * quizaccess/proctoring:manageoverrides).
     *
     * @param array<string, int> $states Map of state column => tri-state value; unset columns inherit.
     * @param string $justification Justification text to record.
     * @return int The new override id.
     */
    private function create_waiver_override(array $states, string $justification = 'Legacy exemption equivalence test'): int {
        $this->setUser($this->teacher);

        $data = new \stdClass();
        $data->quizid = 0;
        $data->userid = (int)$this->student->id;
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $data->$column = $states[$column] ?? override_resolver::STATE_INHERIT;
        }
        $data->justification = $justification;
        $data->expiry = null;

        $overrideid = override_manager::create($this->context, $data);

        // Resolution runs as the student in production; switch back for the resolution assertions.
        $this->setUser($this->student);
        return $overrideid;
    }
}
