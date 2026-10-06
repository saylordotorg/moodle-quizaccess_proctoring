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

        $cmid = (int)$event->contextinstanceid;
        $pending = $SESSION->quizaccess_proctoring_precheckcapture[$cmid] ?? [];
        if (!$pending) {
            return;
        }
        unset($SESSION->quizaccess_proctoring_precheckcapture[$cmid]);

        $userid = (int)($pending['userid'] ?? 0);
        $created = (int)($pending['timecreated'] ?? 0);
        $now = time();
        if (
            $userid <= 0 || $userid !== (int)($USER->id ?? 0) || $userid !== (int)$event->relateduserid ||
                $created > $now || $created <= $now - self::CLAIM_TTL
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

        // Only an unclaimed capture of this student on this quiz, not already queued for deletion.
        $DB->set_field_select(
            'quizaccess_proctoring_logs',
            'status',
            (int)$event->objectid,
            'id = :id AND courseid = :courseid AND quizid = :cmid AND userid = :userid AND status = 0
                AND deletionprogress = 0',
            [
                'id' => (int)($pending['reportid'] ?? 0),
                'courseid' => (int)$event->courseid,
                'cmid' => $cmid,
                'userid' => $userid,
            ]
        );
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
     * Remove self-registered reference photos that no proctored attempt followed.
     *
     * Only photos registered since this rule was introduced are considered (the
     * abandonedreferencesince setting, recorded by the upgrade, or by the first run on a fresh
     * install): an older photo may belong to attempts that have since been deleted, so its
     * history cannot show it was abandoned.
     *
     * @param int $now Current time.
     * @return int Number of photos removed.
     */
    public static function retire_abandoned_references(int $now): int {
        global $DB;

        $since = (int)get_config('quizaccess_proctoring', 'abandonedreferencesince');
        if ($since <= 0) {
            // A fresh install has no upgrade step to record the cut-off: start counting now.
            set_config('abandonedreferencesince', $now, 'quizaccess_proctoring');
            return 0;
        }

        $sql = "SELECT f.id, ui.user_id, f.timemodified AS registered
                  FROM {quizaccess_proctoring_user_images} ui
                  JOIN {quizaccess_proctoring_face_images} f
                       ON f.parentid = ui.id AND f.parent_type = :parenttype
                 WHERE ui.photo_draft_id = 0
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
                      WHERE ui.user_id = :userid AND ui.photo_draft_id = 0",
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
                if ($url && quizaccess_proctoring_retire_self_registered_reference($userid, (string)$url)) {
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
