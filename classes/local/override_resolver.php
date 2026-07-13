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
 * Per-student proctoring override resolution service.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Pure resolution logic for per-student proctoring overrides.
 *
 * Resolution model
 * ----------------
 * Each proctoring requirement is resolved through three layers of precedence:
 * site default -> per-quiz tri-state -> per-student override. The first two layers are
 * collapsed by the caller (`rule.php`) into a single "base state" boolean per requirement.
 * This class layers the highest-precedence per-student override on top of that base state.
 *
 * Every override assigns one of three tri-state values to each of the five in-scope
 * requirements, reusing the plugin's existing `-1`/`0`/`1` semantics:
 *
 * - {@see override_resolver::STATE_INHERIT} (`-1`): no override influence; the base state stands.
 * - {@see override_resolver::STATE_DISABLED} (`0`): the requirement is waived.
 * - {@see override_resolver::STATE_ENABLED} (`1`): the requirement is forced on.
 *
 * The class is a pure function of the data passed in (and the override rows read via `$DB`),
 * with no side effects, so it can be exhaustively property-tested in isolation.
 *
 * Requirement-to-column mapping
 * -----------------------------
 * Each requirement key maps to a dedicated tri-state column on
 * `quizaccess_proctoring_overrides`:
 *
 * | Requirement key                                  | Column                |
 * |--------------------------------------------------|-----------------------|
 * | {@see override_resolver::REQ_CAPTCHA}            | `captchastate`        |
 * | {@see override_resolver::REQ_WEBCAM}             | `webcamstate`         |
 * | {@see override_resolver::REQ_IDVERIFICATION}     | `idverificationstate` |
 * | {@see override_resolver::REQ_SCREENSHARE}        | `screensharestate`    |
 * | {@see override_resolver::REQ_MULTIMONITOR}       | `multimonitorstate`   |
 */
class override_resolver {
    /** @var string CAPTCHA (security check) requirement key. */
    const REQ_CAPTCHA = 'captcha';

    /** @var string Webcam (face register/validate) requirement key. */
    const REQ_WEBCAM = 'webcam';

    /** @var string ID verification requirement key. */
    const REQ_IDVERIFICATION = 'idverification';

    /** @var string Screen-share (entire screen) requirement key. */
    const REQ_SCREENSHARE = 'screenshare';

    /** @var string Multi-monitor check requirement key. */
    const REQ_MULTIMONITOR = 'multimonitor';

    /** @var int Inherit: no override influence; base state stands. */
    const STATE_INHERIT = -1;

    /** @var int Disabled: the requirement is waived. */
    const STATE_DISABLED = 0;

    /** @var int Enabled: the requirement is forced on. */
    const STATE_ENABLED = 1;

    /**
     * Map of requirement key => override table tri-state column.
     *
     * @var array<string, string>
     */
    const STATE_COLUMNS = [
        self::REQ_CAPTCHA => 'captchastate',
        self::REQ_WEBCAM => 'webcamstate',
        self::REQ_IDVERIFICATION => 'idverificationstate',
        self::REQ_SCREENSHARE => 'screensharestate',
        self::REQ_MULTIMONITOR => 'multimonitorstate',
    ];

    /**
     * The full, ordered set of in-scope requirement keys.
     *
     * @return string[] The five requirement keys.
     */
    public static function requirement_keys(): array {
        return array_keys(self::STATE_COLUMNS);
    }

    /**
     * Given the base (site+quiz) boolean and the winning override state, return the
     * effective boolean state for a single requirement.
     *
     * An inherit winner is a no-op (the base state stands); a non-inherit winner replaces
     * the base state regardless of its value.
     *
     * @param bool $basestate Result of site-default -> per-quiz resolution.
     * @param int $overridestate One of STATE_* selected by pick_winner() for this requirement.
     * @return bool Effective enabled/disabled state.
     */
    public static function apply_override(bool $basestate, int $overridestate): bool {
        if ($overridestate === self::STATE_ENABLED) {
            return true;
        }
        if ($overridestate === self::STATE_DISABLED) {
            return false;
        }

        // STATE_INHERIT (or any non-decisive value): the base state stands.
        return $basestate;
    }

    /**
     * Select the winning override state for a requirement from a set of applicable overrides.
     *
     * The tie-break is: most specific scope (quiz-scoped over course-scoped), then most
     * recently created (greatest timecreated, then greatest id). This method relies on the
     * incoming $overrides array already being ordered by that preference (as produced by
     * {@see override_resolver::applicable_overrides()}), so it returns the first non-inherit
     * value it encounters. Returns STATE_INHERIT if no applicable override assigns a
     * non-inherit value to this requirement.
     *
     * @param array $overrides Applicable override records, pre-ordered for tie-break.
     * @param string $requirement One of REQ_*.
     * @return int One of STATE_*.
     */
    public static function pick_winner(array $overrides, string $requirement): int {
        $column = self::STATE_COLUMNS[$requirement] ?? null;
        if ($column === null) {
            return self::STATE_INHERIT;
        }

        foreach ($overrides as $override) {
            $state = (int)($override->$column ?? self::STATE_INHERIT);
            if ($state === self::STATE_DISABLED || $state === self::STATE_ENABLED) {
                return $state;
            }
        }

        return self::STATE_INHERIT;
    }

    /**
     * Return the overrides applicable to a {userid, quizid} attempt at time $now.
     *
     * An override is applicable when the target userid and courseid match, its quizid is
     * either 0 (course-scoped) or equal to the attempt's quiz (quiz-scoped), it is not
     * revoked, and it has no expiry or an expiry strictly greater than $now. The returned
     * array is ordered for a deterministic tie-break: quiz-scoped overrides first, then by
     * most recently created (timecreated descending, then id descending).
     *
     * @param int $courseid Course context id of the attempt.
     * @param int $quizid Quiz id of the attempt.
     * @param int $userid Target student id.
     * @param int $now Evaluation time (unix timestamp), typically the attempt start time.
     * @return array Override records ordered for deterministic tie-break.
     */
    public static function applicable_overrides(int $courseid, int $quizid, int $userid, int $now): array {
        global $DB;

        $where = 'userid = :userid
            AND courseid = :courseid
            AND (quizid = :quizid OR quizid = 0)
            AND revoked = 0
            AND (expiry IS NULL OR expiry > :now)';
        $params = [
            'userid' => $userid,
            'courseid' => $courseid,
            'quizid' => $quizid,
            'now' => $now,
        ];

        $records = array_values($DB->get_records_select('quizaccess_proctoring_overrides', $where, $params));

        usort($records, static function ($a, $b) {
            // Quiz-scoped (quizid != 0) is more specific than course-scoped (quizid = 0); it sorts first.
            $aspecific = ((int)$a->quizid !== 0) ? 1 : 0;
            $bspecific = ((int)$b->quizid !== 0) ? 1 : 0;
            if ($aspecific !== $bspecific) {
                return $bspecific <=> $aspecific;
            }

            // Most recently created first.
            if ((int)$a->timecreated !== (int)$b->timecreated) {
                return (int)$b->timecreated <=> (int)$a->timecreated;
            }

            // Stable, deterministic final tie-break: greatest id first.
            return (int)$b->id <=> (int)$a->id;
        });

        return $records;
    }

    /**
     * Resolve all five requirements at once for an attempt start.
     *
     * @param int $courseid Course context id of the attempt.
     * @param int $quizid Quiz id of the attempt.
     * @param int $userid Target student id.
     * @param int $now Evaluation time (unix timestamp), typically the attempt start time.
     * @param array $basestates Map REQ_* => bool from site+quiz resolution.
     * @return array Map REQ_* => bool effective state.
     */
    public static function resolve_all(int $courseid, int $quizid, int $userid, int $now, array $basestates): array {
        $overrides = self::applicable_overrides($courseid, $quizid, $userid, $now);

        $effective = [];
        foreach (self::requirement_keys() as $requirement) {
            $winner = self::pick_winner($overrides, $requirement);
            $base = !empty($basestates[$requirement]);
            $effective[$requirement] = self::apply_override($base, $winner);
        }

        return $effective;
    }
}
