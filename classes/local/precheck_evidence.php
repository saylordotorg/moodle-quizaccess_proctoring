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
 * What happens to the face captures and reference photo a precheck leaves behind (CPIT-464).
 *
 * A precheck face capture is written before any attempt exists, so its log row has no attempt
 * (status 0). Nothing used to attach it to the attempt or delete it: a student who stopped at
 * the photo step kept a "report" with photos for good, and the per-quiz report could even show
 * that abandoned precheck in place of the student's real attempt.
 *
 *  - The capture that passes the precheck is remembered in the session and, when the student
 *    starts the attempt, becomes part of that attempt's evidence (the same way an ID check is
 *    bound to its attempt).
 *  - Every capture still without an attempt a day later is abandoned, and is queued for the
 *    image deletion task, which removes its files and everything that refers to it.
 *  - A first precheck saves the student's reference photo. If no proctored attempt follows
 *    within a day, that self-registered photo is removed too; staff-uploaded photos never are.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class precheck_evidence {
    /** @var int How long precheck evidence waits for an attempt before it is abandoned. */
    public const RETENTION = DAYSECS;

    /** @var int How long after passing the precheck its capture can still be bound to an attempt. */
    public const CLAIM_TTL = 10 * MINSECS;

    /** @var int Most rows or photos handled per task run. */
    private const BATCH = 500;

    /**
     * Remember the capture that passed the precheck, so the attempt that follows can claim it.
     *
     * @param int $cmid Quiz course module id.
     * @param int $reportid The precheck's log row id.
     * @return void
     */
    public static function remember_passed_capture(int $cmid, int $reportid): void {
        global $SESSION, $USER;

        $SESSION->quizaccess_proctoring_precheckcapture[$cmid] = [
            'userid' => (int)$USER->id,
            'reportid' => $reportid,
            'timecreated' => time(),
        ];
    }

    /**
     * Bind the remembered precheck capture to the attempt Moodle actually created.
     *
     * @param \mod_quiz\event\attempt_started $event The attempt start event.
     * @return void
     */
    public static function attempt_started(\mod_quiz\event\attempt_started $event): void {
        global $DB, $SESSION, $USER;

        self::mark_reference_used($event);
        if ((int)($USER->id ?? 0) === (int)$event->relateduserid) {
            self::claim_for_attempt((int)$event->contextinstanceid, (int)$event->objectid);
        }
    }

    /**
     * Bind the remembered precheck capture to an attempt of the current user.
     *
     * Called when an attempt starts, and when the precheck lets the student resume an existing
     * attempt (Moodle raises no attempt_started event for a resume).
     *
     * @param int $cmid Quiz course module id.
     * @param int $attemptid The attempt the precheck led into.
     * @return void
     */
    public static function claim_for_attempt(int $cmid, int $attemptid): void {
        global $DB, $SESSION, $USER;

        $pending = $SESSION->quizaccess_proctoring_precheckcapture[$cmid] ?? [];
        if (!$pending) {
            return;
        }
        unset($SESSION->quizaccess_proctoring_precheckcapture[$cmid]);

        $userid = (int)($pending['userid'] ?? 0);
        $created = (int)($pending['timecreated'] ?? 0);
        $now = time();
        if ($userid <= 0 || $userid !== (int)($USER->id ?? 0) || $created > $now || $created <= $now - self::CLAIM_TTL) {
            return;
        }
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        if (
            !$cm || !$DB->record_exists('quiz_attempts', [
                'id' => $attemptid, 'userid' => $userid, 'quiz' => (int)$cm->instance, 'preview' => 0])
        ) {
            return;
        }

        // Only an unclaimed capture of this student on this quiz, not already queued for deletion.
        $DB->set_field_select(
            'quizaccess_proctoring_logs',
            'status',
            $attemptid,
            'id = :id AND courseid = :courseid AND quizid = :cmid AND userid = :userid AND status = 0
                AND deletionprogress = 0',
            [
                'id' => (int)($pending['reportid'] ?? 0),
                'courseid' => (int)$cm->course,
                'cmid' => $cmid,
                'userid' => $userid,
            ]
        );
    }

    /**
     * Record that a proctored attempt started with the student's reference photo.
     *
     * Kept on the photo itself, so the photo stays protected from the abandoned-photo cleanup
     * even if proctoring is later turned off on that quiz, the attempt is deleted or the course
     * is reset. Registering a new photo clears it.
     *
     * @param \mod_quiz\event\attempt_started $event The attempt start event.
     * @return void
     */
    private static function mark_reference_used(\mod_quiz\event\attempt_started $event): void {
        global $DB;

        $userid = (int)$event->relateduserid;
        $attempt = $DB->get_record('quiz_attempts', ['id' => (int)$event->objectid], 'id, quiz, userid, preview');
        if (!$attempt || (int)$attempt->userid !== $userid || !empty($attempt->preview)) {
            return;
        }
        if (!$DB->record_exists('quizaccess_proctoring', ['quizid' => (int)$attempt->quiz, 'proctoringrequired' => 1])) {
            return;
        }
        self::mark_reference_used_by($userid);
    }

    /**
     * Record that the student's current reference photo is in use.
     *
     * Called when a proctored attempt starts, and when the precheck lets a student resume an
     * attempt - which can follow a fresh registration (for example after an unusable photo was
     * replaced) and raises no attempt_started event.
     *
     * @param int $userid Student id.
     * @return void
     */
    public static function mark_reference_used_by(int $userid): void {
        global $DB;

        $DB->set_field_select(
            'quizaccess_proctoring_user_images',
            'timeused',
            time(),
            'user_id = :userid AND timeused = 0',
            ['userid' => $userid]
        );
    }

    /**
     * Restart the abandoned-photo clock for a self-registered photo that just matched the student.
     *
     * A successful precheck match is not yet an attempt, so it must not protect the photo for
     * good: it moves the photo's registration time forward, giving the student another full day
     * to start the attempt. If none follows, the photo is removed a day after its last match.
     * Done under the student's reference lock, which the cleanup also holds while it re-checks
     * that time and removes the photo, so the two never interleave.
     *
     * @param int $userid Student id.
     * @return void
     */
    public static function protect_matched_reference(int $userid): void {
        global $DB;

        $lock = quizaccess_proctoring_get_reference_lock($userid);
        if (!$lock) {
            return;
        }
        try {
            $image = $DB->get_record('quizaccess_proctoring_user_images', ['user_id' => $userid]);
            if (!$image || (int)$image->photo_draft_id !== 0 || (int)$image->timeused !== 0) {
                return;
            }
            // Only photos the cleanup already covers: moving an older photo's time forward would
            // bring it into scope, and its history cannot show whether it was ever used.
            $since = (int)get_config('quizaccess_proctoring', 'abandonedreferencesince');
            $DB->set_field_select(
                'quizaccess_proctoring_face_images',
                'timemodified',
                time(),
                'parentid = :parentid AND parent_type = :parenttype AND timemodified >= :since',
                ['parentid' => $image->id, 'parenttype' => 'admin_image', 'since' => max(1, $since)]
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue precheck captures that never became part of an attempt for deletion.
     *
     * The image deletion task then removes each row's picture, face crop, face-match warning and
     * queued face-match job, exactly as it does for expired attempt images.
     *
     * @param int $now Current time.
     * @return int Number of rows queued.
     */
    public static function queue_abandoned_captures(int $now): int {
        global $DB;

        $ids = array_keys($DB->get_records_select(
            'quizaccess_proctoring_logs',
            'status = 0 AND deletionprogress = 0 AND timemodified <= :cutoff',
            ['cutoff' => $now - self::RETENTION],
            'id ASC',
            'id',
            0,
            self::BATCH
        ));
        if (!$ids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $DB->set_field_select('quizaccess_proctoring_logs', 'deletionprogress', 1, "id $insql", $params);
        return count($ids);
    }

    /**
     * Remove self-registered reference photos that no proctored attempt used.
     *
     * A photo is used once a proctored attempt starts with it (timeused, set when the attempt
     * starts and kept whatever later happens to the quiz or attempt). Only photos registered
     * since this rule was introduced are considered (the abandonedreferencesince setting,
     * recorded at install or by the upgrade): an older photo may have been used by attempts that
     * have since been deleted, and nothing recorded that use.
     *
     * @param int $now Current time.
     * @return int Number of photos removed.
     */
    public static function retire_abandoned_references(int $now): int {
        global $DB;

        $since = (int)get_config('quizaccess_proctoring', 'abandonedreferencesince');
        if ($since <= 0) {
            // Install and upgrade both record the cut-off; without one, nothing is known to be
            // new enough, so start counting now.
            set_config('abandonedreferencesince', $now, 'quizaccess_proctoring');
            return 0;
        }

        $sql = "SELECT f.id, ui.user_id, f.timemodified AS registered
                  FROM {quizaccess_proctoring_user_images} ui
                  JOIN {quizaccess_proctoring_face_images} f
                       ON f.parentid = ui.id AND f.parent_type = :parenttype
                 WHERE ui.photo_draft_id = 0
                   AND ui.timeused = 0
                   AND f.timemodified >= :since
                   AND f.timemodified <= :cutoff
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {quiz_attempts} qa
                         JOIN {quizaccess_proctoring} p ON p.quizid = qa.quiz AND p.proctoringrequired = 1
                        WHERE qa.userid = ui.user_id
                          AND qa.preview = 0
                          AND qa.timestart >= f.timemodified
                   )
              ORDER BY f.timemodified ASC";
        $candidates = $DB->get_records_sql($sql, [
            'parenttype' => 'admin_image',
            'since' => $since,
            'cutoff' => $now - self::RETENTION,
        ], 0, self::BATCH);

        $retired = 0;
        foreach ($candidates as $candidate) {
            $userid = (int)$candidate->user_id;
            $lock = quizaccess_proctoring_get_reference_lock($userid);
            if (!$lock) {
                continue;
            }
            try {
                // Re-check under the lock: the student may have registered again, or started an
                // attempt, since the query ran.
                $current = $DB->get_record_sql(
                    "SELECT f.timemodified
                       FROM {quizaccess_proctoring_user_images} ui
                       JOIN {quizaccess_proctoring_face_images} f
                            ON f.parentid = ui.id AND f.parent_type = :parenttype
                      WHERE ui.user_id = :userid AND ui.photo_draft_id = 0 AND ui.timeused = 0",
                    ['parenttype' => 'admin_image', 'userid' => $userid],
                    IGNORE_MULTIPLE
                );
                if (!$current || (int)$current->timemodified !== (int)$candidate->registered) {
                    continue;
                }
                if (self::attempt_started_since($userid, (int)$candidate->registered)) {
                    continue;
                }
                $url = quizaccess_proctoring_get_image_url($userid);
                if ($url && quizaccess_proctoring_retire_self_registered_reference($userid, (string)$url, true)) {
                    $retired++;
                }
            } finally {
                $lock->release();
            }
        }
        return $retired;
    }

    /**
     * Whether the student started a proctored, non-preview attempt at or after a time.
     *
     * @param int $userid Student id.
     * @param int $since Time the reference photo was registered.
     * @return bool
     */
    private static function attempt_started_since(int $userid, int $since): bool {
        global $DB;

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {quiz_attempts} qa
               JOIN {quizaccess_proctoring} p ON p.quizid = qa.quiz AND p.proctoringrequired = 1
              WHERE qa.userid = :userid AND qa.preview = 0 AND qa.timestart >= :since",
            ['userid' => $userid, 'since' => $since]
        );
    }
}
