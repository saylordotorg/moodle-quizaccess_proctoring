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
 * Per-student proctoring override write/validation/audit service.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Handles all writes for per-student proctoring overrides: create, edit, revoke, plus the
 * input validation and append-only audit-record writes that guard them.
 *
 * This class is the single, capability-gated write path for the
 * `quizaccess_proctoring_overrides` and `quizaccess_proctoring_override_audit` tables. Every
 * mutating operation validates its input up front — before any DB write — so that an invalid
 * request leaves all stored override state unchanged (Requirements 2.7, 6.2, 8.4).
 *
 * The validation helpers below are the reusable building blocks the public `create()`/`edit()`
 * methods call before persisting. Each throws a `moodle_exception` whose error identifier maps
 * to the design's Error Handling table:
 *
 * | Failure                                              | Error identifier             | Requirements |
 * |------------------------------------------------------|------------------------------|--------------|
 * | Target not exactly one existing enrolled student     | `error:invalidtarget`        | 1.3, 1.4     |
 * | Tri-state value outside `{-1, 0, 1}`                 | `error:invalidstate`         | 2.7          |
 * | Justification blank after trim, or longer than 2000  | `error:invalidjustification` | 6.2          |
 * | Expiry at or before "now"                            | `error:expiryinpast`         | 8.4          |
 * | Edit/revoke of a nonexistent override id             | `error:overridenotfound`     | 7.1, 7.2     |
 */
class override_manager {
    /** @var string Language component for this plugin's strings. */
    const COMPONENT = 'quizaccess_proctoring';

    /** @var int Maximum allowed justification length, in characters. */
    const MAX_JUSTIFICATION_LENGTH = 2000;

    /**
     * Validate that the override target identifies exactly one existing student enrolled in the
     * course context in which the override is created.
     *
     * A valid target is a single, positive user id that resolves to an existing (non-deleted)
     * user who is enrolled in the given course. Anything else — a zero/negative id, a missing or
     * deleted user, or a user not enrolled in the course — is rejected without any write, so no
     * override is created for an invalid target (Requirements 1.3, 1.4).
     *
     * @param int $courseid Course context in which the override is being created.
     * @param int $userid Target student id.
     * @return void
     * @throws \moodle_exception If the target is not exactly one existing enrolled student.
     */
    private static function validate_target_student(int $courseid, int $userid): void {
        global $DB;

        if ($courseid <= 0 || $userid <= 0) {
            throw new \moodle_exception('error:invalidtarget', self::COMPONENT);
        }

        // The target must be an existing, non-deleted user.
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('error:invalidtarget', self::COMPONENT);
        }

        // The target must be enrolled in the course context the override is created in.
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || !is_enrolled($context, $userid)) {
            throw new \moodle_exception('error:invalidtarget', self::COMPONENT);
        }
    }

    /**
     * Validate that every supplied per-requirement tri-state value is one of the allowed states
     * (`-1` inherit, `0` disabled, `1` enabled).
     *
     * Validation is atomic: the entire set is checked before any caller applies it, and a single
     * invalid value rejects the whole assignment so the stored state of every requirement is left
     * unchanged (Requirement 2.7). Values are accepted only when they are an integer (or a string
     * of an integer) equal to one of the allowed tri-states; loosely-typed junk such as `"abc"`
     * or `1.5` is rejected rather than silently coerced.
     *
     * @param array $states Tri-state values (typically the five per-requirement states).
     * @return void
     * @throws \moodle_exception If any value is not a valid tri-state.
     */
    private static function validate_states(array $states): void {
        $valid = [
            override_resolver::STATE_INHERIT,
            override_resolver::STATE_DISABLED,
            override_resolver::STATE_ENABLED,
        ];

        foreach ($states as $state) {
            // Accept only true integers or exact integer strings; reject floats, "abc", null, etc.
            $isintegerlike = is_int($state) || (is_string($state) && preg_match('/^-?\d+$/', $state) === 1);
            if (!$isintegerlike || !in_array((int)$state, $valid, true)) {
                throw new \moodle_exception('error:invalidstate', self::COMPONENT);
            }
        }
    }

    /**
     * Validate the override justification: non-blank after trimming and no longer than 2000
     * characters (Requirement 6.2).
     *
     * @param string $text Justification text as submitted.
     * @return void
     * @throws \moodle_exception If the justification is blank after trim or exceeds the maximum length.
     */
    private static function validate_justification(string $text): void {
        $trimmed = trim($text);
        if ($trimmed === '' || \core_text::strlen($trimmed) > self::MAX_JUSTIFICATION_LENGTH) {
            throw new \moodle_exception('error:invalidjustification', self::COMPONENT);
        }
    }

    /**
     * Validate the override expiry: when set, it must be strictly in the future relative to the
     * supplied "now" (Requirement 8.4). A null expiry means "no expiry" and is always valid.
     *
     * @param int|null $expiry Expiry unix timestamp, or null for no expiry.
     * @param int $now Current unix timestamp to compare against.
     * @return void
     * @throws \moodle_exception If the expiry is at or before now.
     */
    private static function validate_expiry(?int $expiry, int $now): void {
        if ($expiry !== null && $expiry <= $now) {
            throw new \moodle_exception('error:expiryinpast', self::COMPONENT);
        }
    }

    /**
     * The set of override columns that an edit may change, and that the audit trail diffs
     * field-by-field: the five per-requirement tri-states plus the justification and expiry.
     *
     * The immutable creation fields (`grantedby`, `timecreated`) are deliberately excluded so
     * they can never be part of an edit's update set or audit diff (Requirement 6.4).
     *
     * @return string[] Editable column names.
     */
    private static function editable_columns(): array {
        return array_merge(array_values(override_resolver::STATE_COLUMNS), ['justification', 'expiry']);
    }

    /**
     * Normalise a submitted expiry into either a positive unix timestamp or null ("no expiry").
     *
     * The reviewer form's optional `date_time_selector` yields `0` when the reviewer leaves the
     * expiry disabled; a null/absent/zero/negative value therefore all mean "no expiry".
     *
     * @param mixed $value Raw submitted expiry value.
     * @return int|null Positive timestamp, or null for no expiry.
     */
    private static function normalise_expiry($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $expiry = (int)$value;
        return $expiry > 0 ? $expiry : null;
    }

    /**
     * Format an override field value for storage in an audit row's oldvalue/newvalue text column.
     *
     * @param mixed $value Field value (int tri-state, string justification, int|null expiry).
     * @return string|null String representation, or null when the value is null.
     */
    private static function audit_value($value): ?string {
        return $value === null ? null : (string)$value;
    }

    /**
     * Create a new per-student proctoring override.
     *
     * Requires the `manageoverrides` capability (checked before any DB write, so a denied caller
     * never creates a row or an audit record — Requirements 1.2, 7.7). The submitted data is fully
     * validated up front; only if every check passes is the override inserted and a single
     * `create` audit row appended, both inside one DB transaction so they commit together
     * (Requirements 1.1, 6.1, 8.1). The immutable `grantedby`/`timecreated` fields are stamped from
     * the acting user and current time (Requirement 6.4).
     *
     * Expected `$data` fields: optional `quizid` (0/absent = course-scoped), `userid`, the five
     * tri-state columns (`captchastate`, `webcamstate`, `idverificationstate`, `screensharestate`,
     * `multimonitorstate`; absent = inherit), `justification`, and optional `expiry`. The
     * `courseid` is derived from the module context the override is created in.
     *
     * @param \context_module $context Module context the override is created in.
     * @param \stdClass $data Submitted override data.
     * @return int The new override id.
     * @throws \required_capability_exception If the acting user lacks `manageoverrides`.
     * @throws \moodle_exception On invalid target, state, justification, or expiry.
     */
    public static function create(\context_module $context, \stdClass $data): int {
        global $DB, $USER;

        require_capability('quizaccess/proctoring:manageoverrides', $context);

        $now = time();
        $courseid = (int)$context->get_course_context()->instanceid;
        $quizid = isset($data->quizid) ? (int)$data->quizid : 0;
        $userid = isset($data->userid) ? (int)$data->userid : 0;

        // Collect the five per-requirement tri-states, defaulting unset ones to inherit (R2.6).
        $states = [];
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $states[$column] = isset($data->$column) ? $data->$column : override_resolver::STATE_INHERIT;
        }

        $justification = isset($data->justification) ? (string)$data->justification : '';
        $expiry = self::normalise_expiry($data->expiry ?? null);

        // Validate everything before touching the DB so an invalid request creates nothing.
        self::validate_target_student($courseid, $userid);
        self::validate_states(array_values($states));
        self::validate_justification($justification);
        self::validate_expiry($expiry, $now);

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->quizid = $quizid;
        $record->userid = $userid;
        foreach ($states as $column => $state) {
            $record->$column = (int)$state;
        }
        $record->justification = trim($justification);
        $record->expiry = $expiry;
        $record->revoked = 0;
        $record->revokedby = null;
        $record->timerevoked = null;
        $record->grantedby = (int)$USER->id;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $transaction = $DB->start_delegated_transaction();
        try {
            $overrideid = (int)$DB->insert_record('quizaccess_proctoring_overrides', $record);
            self::audit($overrideid, (int)$USER->id, 'create');
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }

        return $overrideid;
    }

    /**
     * Edit an existing per-student proctoring override.
     *
     * Requires the `manageoverrides` capability (checked before any write — Requirements 7.1, 7.7).
     * The override row is reloaded inside a DB transaction; a missing id throws
     * `error:overridenotfound` (Requirement 7.1). New values are validated, then compared
     * field-by-field against the stored row for the editable fields (the five states,
     * justification, and expiry). Only changed fields are written, and exactly one `edit` audit
     * row is appended per changed field capturing its previous and new value; the override update
     * and audit rows commit atomically (Requirements 7.5, 7.6). The immutable `grantedby` and
     * `timecreated` are never modified (Requirement 6.4). If nothing changed, no update or audit
     * row is written.
     *
     * Any field absent from `$data` retains its stored value. Present fields replace it.
     *
     * @param \context_module $context Module context the override belongs to.
     * @param int $overrideid Id of the override to edit.
     * @param \stdClass $data New override data.
     * @return void
     * @throws \required_capability_exception If the acting user lacks `manageoverrides`.
     * @throws \moodle_exception On a missing override id or invalid state/justification/expiry.
     */
    public static function edit(\context_module $context, int $overrideid, \stdClass $data): void {
        global $DB, $USER;

        require_capability('quizaccess/proctoring:manageoverrides', $context);

        $now = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            $existing = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid]);
            if (!$existing) {
                throw new \moodle_exception('error:overridenotfound', self::COMPONENT);
            }

            // Build the candidate new values: use submitted values, else keep the stored ones.
            $states = [];
            foreach (override_resolver::STATE_COLUMNS as $column) {
                $states[$column] = isset($data->$column) ? $data->$column : (int)$existing->$column;
            }

            $justification = isset($data->justification) ? (string)$data->justification : (string)$existing->justification;

            if (property_exists($data, 'expiry')) {
                $expiry = self::normalise_expiry($data->expiry);
            } else {
                $expiry = ($existing->expiry === null || $existing->expiry === '') ? null : (int)$existing->expiry;
            }

            // Validate the candidate values before writing anything.
            self::validate_states(array_values($states));
            self::validate_justification($justification);
            self::validate_expiry($expiry, $now);
            $justification = trim($justification);

            // Assemble the normalised new value for each editable field.
            $newvalues = [];
            foreach (override_resolver::STATE_COLUMNS as $column) {
                $newvalues[$column] = (int)$states[$column];
            }
            $newvalues['justification'] = $justification;
            $newvalues['expiry'] = $expiry;

            // Diff editable fields against the stored row; collect changes and the update set.
            $update = new \stdClass();
            $update->id = (int)$existing->id;
            $fieldchanges = [];
            foreach (self::editable_columns() as $column) {
                if ($column === 'justification') {
                    $oldnorm = (string)$existing->justification;
                    $newnorm = (string)$newvalues[$column];
                    $different = ($oldnorm !== $newnorm);
                } else if ($column === 'expiry') {
                    $oldnorm = ($existing->expiry === null || $existing->expiry === '') ? null : (int)$existing->expiry;
                    $newnorm = $newvalues[$column];
                    $different = ($oldnorm !== $newnorm);
                } else {
                    $oldnorm = (int)$existing->$column;
                    $newnorm = (int)$newvalues[$column];
                    $different = ($oldnorm !== $newnorm);
                }

                if ($different) {
                    $update->$column = $newvalues[$column];
                    $fieldchanges[] = [
                        'fieldname' => $column,
                        'oldvalue' => self::audit_value($oldnorm),
                        'newvalue' => self::audit_value($newnorm),
                    ];
                }
            }

            // Only write (and audit) when something actually changed. Immutable creation fields
            // (grantedby, timecreated) are never part of the update set.
            if (!empty($fieldchanges)) {
                $update->timemodified = $now;
                $DB->update_record('quizaccess_proctoring_overrides', $update);
                self::audit($overrideid, (int)$USER->id, 'edit', $fieldchanges);
            }

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Revoke an existing per-student proctoring override.
     *
     * Requires the `manageoverrides` capability (checked before any write — Requirements 7.2, 7.7).
     * The override is reloaded inside a DB transaction; a missing id throws
     * `error:overridenotfound` (Requirement 7.2). The row is marked revoked (recording the acting
     * reviewer and revocation time) and a single `revoke` audit row is appended, both committing
     * atomically (Requirement 7.6). The immutable `grantedby`/`timecreated` are left untouched
     * (Requirement 6.4).
     *
     * @param \context_module $context Module context the override belongs to.
     * @param int $overrideid Id of the override to revoke.
     * @return void
     * @throws \required_capability_exception If the acting user lacks `manageoverrides`.
     * @throws \moodle_exception On a missing override id.
     */
    public static function revoke(\context_module $context, int $overrideid): void {
        global $DB, $USER;

        require_capability('quizaccess/proctoring:manageoverrides', $context);

        $now = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            $existing = $DB->get_record('quizaccess_proctoring_overrides', ['id' => $overrideid]);
            if (!$existing) {
                throw new \moodle_exception('error:overridenotfound', self::COMPONENT);
            }

            $update = new \stdClass();
            $update->id = (int)$existing->id;
            $update->revoked = 1;
            $update->revokedby = (int)$USER->id;
            $update->timerevoked = $now;
            $update->timemodified = $now;
            $DB->update_record('quizaccess_proctoring_overrides', $update);

            self::audit($overrideid, (int)$USER->id, 'revoke');

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Append one or more rows to the append-only override audit trail.
     *
     * This is the sole writer of `quizaccess_proctoring_override_audit`, and it only ever inserts:
     * there is no update or delete path, enforcing the append-only invariant in code
     * (Requirement 7.6). For `create` and `revoke` a single row is inserted with null
     * `fieldname`/`oldvalue`/`newvalue`; for `edit` one row is inserted per entry in
     * `$fieldchanges`, each recording the changed field name and its previous and new values
     * (Requirement 7.5).
     *
     * @param int $overrideid Id of the override the action applied to.
     * @param int $actorid Id of the acting reviewer.
     * @param string $action One of `create`, `edit`, `revoke`.
     * @param array $fieldchanges For `edit`: list of ['fieldname' => ..., 'oldvalue' => ..., 'newvalue' => ...].
     * @return void
     */
    private static function audit(int $overrideid, int $actorid, string $action, array $fieldchanges = []): void {
        global $DB;

        $now = time();

        if ($action === 'edit') {
            foreach ($fieldchanges as $change) {
                $record = new \stdClass();
                $record->overrideid = $overrideid;
                $record->actorid = $actorid;
                $record->action = 'edit';
                $record->fieldname = $change['fieldname'];
                $record->oldvalue = $change['oldvalue'];
                $record->newvalue = $change['newvalue'];
                $record->timecreated = $now;
                $DB->insert_record('quizaccess_proctoring_override_audit', $record);
            }
            return;
        }

        // create / revoke: a single row with no per-field delta.
        $record = new \stdClass();
        $record->overrideid = $overrideid;
        $record->actorid = $actorid;
        $record->action = $action;
        $record->fieldname = null;
        $record->oldvalue = null;
        $record->newvalue = null;
        $record->timecreated = $now;
        $DB->insert_record('quizaccess_proctoring_override_audit', $record);
    }
}
