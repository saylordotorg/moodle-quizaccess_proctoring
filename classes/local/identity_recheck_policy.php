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

namespace quizaccess_proctoring\local;

/**
 * Determines when an ID check must be repeated and binds fresh checks to new attempts.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class identity_recheck_policy {
    /** @var int Fresh preflight evidence may be consumed for ten minutes. */
    public const PREFLIGHT_TTL = 600;

    /**
     * Read the optional recheck controls. Existing sites retain unlimited reuse by default.
     *
     * @return array
     */
    public static function config(): array {
        return [
            'maxage' => max(0, (int)get_config('quizaccess_proctoring', 'idverificationmaxage')),
            'eachattempt' => (bool)get_config('quizaccess_proctoring', 'idverificationeachattempt'),
            'namechange' => (bool)get_config('quizaccess_proctoring', 'idverificationnamechange'),
            'policychange' => (bool)get_config('quizaccess_proctoring', 'idverificationpolicychange'),
        ];
    }

    /**
     * Snapshot the identity inputs used by a provider request, without storing credentials.
     *
     * @param \stdClass $user Current user record.
     * @return array
     */
    public static function snapshot(\stdClass $user): array {
        $names = [];
        foreach (['firstname', 'lastname', 'middlename', 'alternatename', 'firstnamephonetic', 'lastnamephonetic'] as $field) {
            $names[$field] = \core_text::strtolower(trim(preg_replace('/\s+/u', ' ', (string)($user->$field ?? ''))));
        }
        $policy = [
            'contract' => 1,
            'decision' => id_verification_decision::config(),
            'requireback' => (bool)get_config('quizaccess_proctoring', 'idverificationrequireback'),
            'endpoint' => trim((string)get_config('quizaccess_proctoring', 'idverificationendpoint')),
        ];
        return [
            'profilehash' => hash('sha256', json_encode($names)),
            'policyhash' => hash('sha256', json_encode($policy)),
        ];
    }

    /**
     * Evaluate a saved result against explicit current inputs.
     *
     * @param \stdClass|null $record Most recent successful verification in the relevant scope.
     * @param array $snapshot Current profile and policy fingerprints.
     * @param array $config Recheck controls.
     * @param int $attemptid Existing attempt, or zero for a new attempt.
     * @param int $freshid ID of fresh, unconsumed session evidence, or zero.
     * @param int $now Server time.
     * @return array
     */
    public static function evaluate(
        ?\stdClass $record,
        array $snapshot,
        array $config,
        int $attemptid,
        int $freshid,
        int $now
    ): array {
        $verifiedat = (int)($record->verifiedat ?? 0);
        if (!$verifiedat) {
            // Legacy evidence has no completion time. Never extend it using a later edit timestamp.
            $verifiedat = (int)($record->timecreated ?? 0);
        }
        $maxage = max(0, (int)($config['maxage'] ?? 0));
        $result = [
            'passed' => false,
            'reason' => 'missing',
            'verificationid' => (int)($record->id ?? 0),
            'verifiedat' => $verifiedat,
            'expiresat' => $maxage > 0 && $verifiedat > 0 ? $verifiedat + $maxage : 0,
        ];
        if (!$record || $record->status !== 'pass') {
            return $result;
        }
        if ($maxage > 0 && ($verifiedat <= 0 || $verifiedat > $now || $verifiedat + $maxage <= $now)) {
            $result['reason'] = 'expired';
        } else if (
            !empty($config['namechange']) &&
                !hash_equals((string)$snapshot['profilehash'], (string)($record->profilehash ?? ''))
        ) {
            $result['reason'] = 'namechanged';
        } else if (
            !empty($config['policychange']) &&
                !hash_equals((string)$snapshot['policyhash'], (string)($record->policyhash ?? ''))
        ) {
            $result['reason'] = 'policychanged';
        } else if (
            !empty($config['eachattempt']) && (
                ($attemptid > 0 && (int)$record->attemptid !== $attemptid) ||
                ($attemptid === 0 && ((int)$record->attemptid !== 0 || (int)$record->id !== $freshid)))
        ) {
            $result['reason'] = 'newattempt';
        } else {
            $result['passed'] = true;
            $result['reason'] = 'none';
        }
        return $result;
    }

    /**
     * Return the current decision and a student-facing explanation when rechecking is required.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course module ID.
     * @param int $userid User being checked.
     * @param int $attemptid Existing attempt, or zero for a new attempt.
     * @return array
     */
    public static function status(int $courseid, int $cmid, int $userid, int $attemptid = 0): array {
        global $DB, $SESSION, $USER;

        $config = self::config();
        $record = null;
        $user = null;
        if ($courseid > 0 && $cmid > 0 && $userid > 0) {
            $conditions = ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid, 'status' => 'pass'];
            if ($config['eachattempt'] && $attemptid > 0) {
                $conditions['attemptid'] = $attemptid;
            }
            $records = $DB->get_records('quizaccess_proctoring_idv', $conditions, 'id DESC', '*', 0, 1);
            $record = $records ? reset($records) : null;
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        }
        $fresh = $SESSION->quizaccess_proctoring_idchecks[$cmid] ?? [];
        $freshid = 0;
        $now = time();
        if (
            (int)($USER->id ?? 0) === $userid && (int)($fresh['userid'] ?? 0) === $userid &&
                (int)($fresh['timecreated'] ?? 0) <= $now &&
                (int)($fresh['timecreated'] ?? 0) > $now - self::PREFLIGHT_TTL
        ) {
            $freshid = (int)($fresh['verificationid'] ?? 0);
        }
        $result = self::evaluate(
            $user ? $record : null,
            self::snapshot($user ?: new \stdClass()),
            $config,
            max(0, $attemptid),
            $freshid,
            $now
        );
        // A pass from before a staff reset no longer lets the student start (CPIT-487).
        if ($result['passed'] && $record && self::reset_after($courseid, $cmid, $userid, (int)$record->timemodified)) {
            $result['passed'] = false;
            $result['reason'] = 'reset';
        }
        $result['message'] = $result['passed'] ? '' : get_string('identityrecheck:' . $result['reason'], 'quizaccess_proctoring');
        return $result;
    }

    /**
     * Staff reset the student's photo ID verification on a quiz (CPIT-487).
     *
     * The checks themselves are left as they were, so they still show which earlier attempts were
     * verified. A marker event makes any pass from before it unusable for a new start.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student.
     */
    public static function reset(int $courseid, int $cmid, int $userid): void {
        global $DB;
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => 0,
            'reportid' => 0,
            'eventtype' => 'id_verification_reset',
            'eventdetail' => '{}',
            'timemodified' => time(),
        ]);
    }

    /**
     * Whether staff reset the verification at or after a given time.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course-module ID.
     * @param int $userid Student.
     * @param int $since Time of the pass.
     * @return bool
     */
    private static function reset_after(int $courseid, int $cmid, int $userid, int $since): bool {
        global $DB;
        return $DB->record_exists_select(
            'quizaccess_proctoring_events',
            'courseid = :courseid AND quizid = :cmid AND userid = :userid AND eventtype = :type AND timemodified >= :since',
            ['courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'type' => 'id_verification_reset', 'since' => $since]
        );
    }

    /**
     * Remember only an actual successful server verification in this user's session.
     *
     * @param \stdClass $record Verification record after its successful update.
     */
    public static function record_success(\stdClass $record): void {
        global $SESSION, $USER;

        if ($record->status !== 'pass' || (int)$record->userid !== (int)$USER->id || (int)$record->attemptid !== 0) {
            return;
        }
        $SESSION->quizaccess_proctoring_idchecks[(int)$record->quizid] = [
            'userid' => (int)$record->userid,
            'verificationid' => (int)$record->id,
            'timecreated' => time(),
        ];
    }

    /**
     * Consume fresh evidence before a new attempt starts and reserve it for the start observer.
     *
     * A second tab cannot pass a second preflight using the consumed evidence. If Moodle cannot
     * create the attempt, each-attempt policy deliberately requires a new verification.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course module ID.
     * @param int $userid Current user ID.
     * @return bool Whether the preflight may proceed.
     */
    public static function prepare_attempt(int $courseid, int $cmid, int $userid): bool {
        global $SESSION, $USER;

        if ((int)($USER->id ?? 0) !== $userid) {
            return false;
        }
        $status = self::status($courseid, $cmid, $userid);
        if (!$status['passed']) {
            return false;
        }
        unset($SESSION->quizaccess_proctoring_idchecks[$cmid]);
        $SESSION->quizaccess_proctoring_pendingid[$cmid] = [
            'userid' => $userid,
            'courseid' => $courseid,
            'verificationid' => $status['verificationid'],
            'timecreated' => time(),
        ];
        return true;
    }

    /**
     * Bind a reserved preflight to the attempt actually created by Moodle.
     *
     * @param \mod_quiz\event\attempt_started $event The attempt start event.
     */
    public static function attempt_started(\mod_quiz\event\attempt_started $event): void {
        global $DB, $SESSION, $USER;

        $cmid = (int)$event->contextinstanceid;
        $pending = $SESSION->quizaccess_proctoring_pendingid[$cmid] ?? [];
        if (!$pending) {
            return;
        }
        unset($SESSION->quizaccess_proctoring_pendingid[$cmid]);
        $userid = (int)($pending['userid'] ?? 0);
        $now = time();
        if (
            $userid !== (int)($USER->id ?? 0) || $userid !== (int)$event->relateduserid ||
                (int)$pending['courseid'] !== (int)$event->courseid ||
                (int)$pending['timecreated'] > $now || (int)$pending['timecreated'] <= $now - self::PREFLIGHT_TTL
        ) {
            return;
        }
        $cm = get_coursemodule_from_id('quiz', $cmid, (int)$event->courseid, false, IGNORE_MISSING);
        if (
            !$cm || !$DB->record_exists('quiz_attempts', [
                'id' => (int)$event->objectid, 'userid' => $userid, 'quiz' => (int)$cm->instance, 'preview' => 0])
        ) {
            return;
        }
        $DB->set_field_select(
            'quizaccess_proctoring_idv',
            'attemptid',
            (int)$event->objectid,
            'id = :id AND courseid = :courseid AND quizid = :cmid AND userid = :userid AND attemptid = 0 AND status = :status',
            [
                'id' => (int)$pending['verificationid'], 'courseid' => (int)$event->courseid,
                'cmid' => $cmid, 'userid' => $userid, 'status' => 'pass',
            ]
        );
    }
}
