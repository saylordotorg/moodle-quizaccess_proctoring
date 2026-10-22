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

use moodle_url;

/**
 * A student's reference photo: reset by staff, and reset requests from the student (CPIT-476).
 *
 * A student cannot replace their own reference photo: that would let someone else sit the exam
 * and register their own face. They can see it, and ask Student Affairs for a new one; staff who
 * review proctoring can reset it, with a reason that is logged.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reference_photo {
    /** @var string User preference recording the last reset request (legacy; requests are rows now). */
    public const REQUEST_PREFERENCE = 'quizaccess_proctoring_referenceresetrequested';

    /** @var int A reset request waiting for a reviewer. */
    public const REQUEST_OPEN = 0;

    /** @var int The request was answered by resetting the photo. */
    public const REQUEST_RESET = 1;

    /** @var int The request was declined; the photo on file stands. */
    public const REQUEST_DECLINED = 2;

    /**
     * Whether the user has a reference photo on file.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function exists(int $userid): bool {
        global $DB;
        return $DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $userid]);
    }

    /**
     * Delete a student's reference photo so they take a new one at their next exam.
     *
     * The caller has checked the reviewer's capability. The reset is logged with who did it and why.
     *
     * @param int $userid Student whose photo to reset.
     * @param string $reason Why, as entered by the reviewer.
     * @param \context $context Where the reviewer reset it from.
     * @param int|null $requestid When approving a queue row: the photo is only deleted while that
     *        request is still open, so a concurrent decline cannot be overridden (Codex review).
     * @return bool False when a lock was held elsewhere or the named request was already decided,
     *        so nothing was reset.
     */
    public static function reset(int $userid, string $reason, \context $context, ?int $requestid = null): bool {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        // The per-student decision lock is the outer lock everywhere (requests, declines, resets):
        // the recheck, the deletion and the request close are one decision, so a decline racing
        // this reset can neither be overridden nor reopen the outcome (Codex review).
        $decisionlock = self::decision_lock($userid);
        if (!$decisionlock) {
            return false;
        }
        try {
            if ($requestid !== null) {
                $request = $DB->get_record('quizaccess_proctoring_ref_requests', ['id' => $requestid]);
                if (!$request || (int)$request->userid !== $userid
                        || (int)$request->status !== self::REQUEST_OPEN) {
                    return false;
                }
            }
            $lock = quizaccess_proctoring_get_reference_lock($userid);
            if (!$lock) {
                return false;
            }
            try {
                \quizaccess_proctoring\privacy\provider::delete_reference_photos([$userid]);
            } finally {
                $lock->release();
            }
            \quizaccess_proctoring\event\reference_photo_reset::create([
                'context' => $context,
                'relateduserid' => $userid,
                'other' => ['reason' => \core_text::substr(trim($reason), 0, 1000)],
            ])->trigger();

            // Every reset closes the student's open request, so the queue cannot show work that
            // is already done, and tells the student: without the notice, Student Affairs had to
            // reach out by hand to close the loop (CPIT-476, Stephanie).
            self::close_open_request_locked($userid, (int)$USER->id);
        } finally {
            $decisionlock->release();
        }
        self::notify_student($userid, 'done');
        return true;
    }

    /**
     * The per-student lock serializing request creation and decisions (reset, decline).
     *
     * @param int $userid Student the request belongs to.
     * @return \core\lock\lock|false The held lock, or false when it could not be taken in time.
     */
    private static function decision_lock(int $userid) {
        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring_resetrequest');
        return $factory->get_lock('user' . $userid, 10);
    }

    /**
     * Run a staff photo replacement under both locks, then close the open request (Codex review).
     *
     * The decision lock is taken before the reference lock, the order every queue decision uses:
     * an approval racing this upload either finishes first (the request is closed, the upload
     * proceeds against the fresh start) or waits until the request is marked answered, so it can
     * never delete the replacement this upload is saving.
     *
     * @param int $userid Student whose photo is being replaced.
     * @param int $decidedby Staff member uploading.
     * @param callable $replace Saves the replacement; runs while both locks are held.
     * @return bool False when a lock could not be taken, so nothing ran.
     */
    public static function replace_photo(int $userid, int $decidedby, callable $replace): bool {
        return self::change_photo($userid, $decidedby, 'replaced', function () use ($replace): bool {
            $replace();
            return true;
        }) !== null;
    }

    /**
     * Run an automatic photo retirement under both locks, then close the open request.
     *
     * Same serialization as replace_photo(): a queue approval cannot slip between the photo
     * going away and its request being closed, so it can never delete a replacement registered
     * in that window (Codex review).
     *
     * @param int $userid Student whose photo may be retired.
     * @param callable $retire Runs while both locks are held; returns true when it removed the
     *        photo, false when it left it alone.
     * @return bool True when the photo was retired; false when it was kept or a lock timed out.
     */
    public static function retire_photo(int $userid, callable $retire): bool {
        return (bool)self::change_photo($userid, 0, 'done', $retire);
    }

    /**
     * The shared engine: decision lock, then reference lock, the change, then the request close.
     *
     * @param int $userid Student.
     * @param int $decidedby Who answers the open request; 0 for automatic retirement.
     * @param string $outcome Message the student gets when a request was closed.
     * @param callable $change Runs while both locks are held; returns whether the photo changed.
     * @return bool|null Null when a lock could not be taken (nothing ran); otherwise the
     *         callable's result.
     */
    private static function change_photo(int $userid, int $decidedby, string $outcome, callable $change): ?bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $decisionlock = self::decision_lock($userid);
        if (!$decisionlock) {
            return null;
        }
        $closed = false;
        try {
            $referencelock = quizaccess_proctoring_get_reference_lock($userid);
            if (!$referencelock) {
                return null;
            }
            try {
                $changed = (bool)$change();
            } finally {
                $referencelock->release();
            }
            if ($changed) {
                $closed = self::close_open_request_locked($userid, $decidedby);
            }
        } finally {
            $decisionlock->release();
        }
        if ($closed) {
            self::notify_student($userid, $outcome);
        }
        return $changed;
    }

    /**
     * The close itself; the caller holds the student's decision lock.
     *
     * @param int $userid Student.
     * @param int $decidedby Who answered it; 0 for automatic retirement.
     * @return bool True when an open request was closed.
     */
    private static function close_open_request_locked(int $userid, int $decidedby): bool {
        global $DB;
        $open = self::open_request($userid);
        if (!$open) {
            return false;
        }
        $DB->update_record('quizaccess_proctoring_ref_requests', (object)[
            'id' => (int)$open->id,
            'status' => self::REQUEST_RESET,
            'decidedby' => $decidedby,
            'timedecided' => time(),
        ]);
        return true;
    }

    /**
     * The student's open reset request, if any.
     *
     * @param int $userid Student.
     * @return \stdClass|null
     */
    public static function open_request(int $userid): ?\stdClass {
        global $DB;
        $open = $DB->get_records('quizaccess_proctoring_ref_requests', [
            'userid' => $userid,
            'status' => self::REQUEST_OPEN,
        ], 'id DESC', '*', 0, 1);
        return $open ? reset($open) : null;
    }

    /**
     * Every open reset request, oldest first, for the reviewers' queue (CPIT-476).
     *
     * @return array<int, \stdClass> Request rows with the student's name fields joined on.
     */
    public static function open_requests(): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u')->selects;
        return $DB->get_records_sql(
            "SELECT r.*, u.email{$userfields}
               FROM {quizaccess_proctoring_ref_requests} r
               JOIN {user} u ON u.id = r.userid
              WHERE r.status = :open AND u.deleted = 0
           ORDER BY r.timecreated ASC, r.id ASC",
            ['open' => self::REQUEST_OPEN]
        );
    }

    /**
     * The most recently decided requests, for the queue's handled section (PR #79 review).
     *
     * Without it, a decided row vanished from the only staff-facing view, so nobody could see
     * who handled a request or how - the very bookkeeping the queue exists to provide.
     *
     * @param int $limit How many to return.
     * @return array<int, \stdClass> Request rows, newest decision first, with the student's name
     *         fields joined on and the decider's prefixed "decider" (null for automatic resets).
     */
    public static function recent_decisions(int $limit = 25): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u')->selects;
        $deciderfields = \core_user\fields::for_name()->get_sql('d', false, 'decider')->selects;
        return $DB->get_records_sql(
            "SELECT r.*, u.email{$userfields}{$deciderfields}
               FROM {quizaccess_proctoring_ref_requests} r
               JOIN {user} u ON u.id = r.userid
          LEFT JOIN {user} d ON d.id = r.decidedby
              WHERE r.status <> :open AND u.deleted = 0
           ORDER BY r.timedecided DESC, r.id DESC",
            ['open' => self::REQUEST_OPEN],
            0,
            $limit
        );
    }

    /**
     * Decline an open request: the photo on file stands, and the student is told.
     *
     * @param int $requestid Request row id.
     * @param int $reviewerid Reviewer deciding.
     * @return bool False when the request was not open (someone else got there first).
     */
    public static function decline_request(int $requestid, int $reviewerid): bool {
        global $DB;
        $request = $DB->get_record('quizaccess_proctoring_ref_requests', ['id' => $requestid]);
        if (!$request) {
            return false;
        }
        // Recheck under the per-student decision lock: a decline must not overwrite a reset that
        // another reviewer completed between the queue page loading and this click (Codex review).
        $lock = self::decision_lock((int)$request->userid);
        if (!$lock) {
            return false;
        }
        try {
            $request = $DB->get_record('quizaccess_proctoring_ref_requests', ['id' => $requestid]);
            if (!$request || (int)$request->status !== self::REQUEST_OPEN) {
                return false;
            }
            $DB->update_record('quizaccess_proctoring_ref_requests', (object)[
                'id' => $requestid,
                'status' => self::REQUEST_DECLINED,
                'decidedby' => $reviewerid,
                'timedecided' => time(),
            ]);
        } finally {
            $lock->release();
        }
        self::notify_student((int)$request->userid, 'declined');
        return true;
    }

    /**
     * Tell the student how their photo request ended (CPIT-476).
     *
     * Also sent when staff reset the photo without a request: the student finds out from the
     * system, not from Student Affairs chasing them by hand.
     *
     * @param int $userid Student.
     * @param string $outcome One of 'done' (photo reset), 'declined', or 'replaced' (staff upload).
     */
    private static function notify_student(int $userid, string $outcome): void {
        $student = \core_user::get_user($userid);
        if (!$student || $student->deleted) {
            return;
        }
        $a = (object)['contact' => support_contact::address()];
        // The wording Student Affairs set in Moodle, where they changed it (CPIT-512).
        $subject = message_texts::get("referencereset:{$outcome}subject", $a);
        $body = message_texts::get("referencereset:{$outcome}body", $a);
        $message = new \core\message\message();
        $message->component = 'quizaccess_proctoring';
        $message->name = 'referencereset';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $student;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = message_texts::html($body);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new moodle_url('/mod/quiz/accessrule/proctoring/my_photo.php'))->out(false);
        $message->contexturlname = get_string('myphoto:title', 'quizaccess_proctoring');
        message_send($message);
    }

    /**
     * Whether the student may send another reset request now.
     *
     * One at a time while a request is open (CPIT-476, Stephanie): repeat requests while the
     * first waits only multiply the queue's rows for the same photo.
     *
     * @param int $userid Student.
     * @return bool
     */
    public static function can_request(int $userid): bool {
        return self::open_request($userid) === null;
    }

    /**
     * Ask the proctoring reviewers to reset the student's photo.
     *
     * Goes to everyone holding the cross-course review capability site-wide, which is the Student
     * Affairs reviewer role; to the site administrators when nobody does.
     *
     * @param int $userid Student asking.
     * @param string $reason What is wrong with the photo, in the student's words.
     * @return int Number of reviewers notified; -1 when no request was created (one is already
     *         open, the photo is gone, or the lock was held elsewhere).
     */
    public static function request_reset(int $userid, string $reason): int {
        global $DB;

        // The open request is claimed under a lock before anyone is notified, so two sessions
        // submitting at once send one request, not two (PR #46 review).
        $lock = self::decision_lock($userid);
        if (!$lock) {
            return -1;
        }
        try {
            if (!self::can_request($userid)) {
                return -1;
            }
            // Recheck under the lock: a reset landing after the page's own check would leave this
            // request pending against whatever photo comes next (Codex review).
            if (!self::exists($userid)) {
                return -1;
            }
            $DB->insert_record('quizaccess_proctoring_ref_requests', (object)[
                'userid' => $userid,
                'reason' => \core_text::substr(trim($reason), 0, 1000),
                'status' => self::REQUEST_OPEN,
                'timecreated' => time(),
            ]);
        } finally {
            $lock->release();
        }

        $student = \core_user::get_user($userid, '*', MUST_EXIST);
        $recipients = get_users_by_capability(
            \context_system::instance(),
            'quizaccess/proctoring:reviewacrosscourses',
            'u.*',
            '',
            '',
            '',
            '',
            '',
            false
        );
        if (!$recipients) {
            $recipients = get_admins();
        }

        $a = (object)[
            'student' => fullname($student),
            'email' => $student->email,
            'reason' => trim($reason) !== '' ? \core_text::substr(trim($reason), 0, 1000) : '-',
            'profileurl' => (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false),
            // The place to work the request from (CPIT-476): the queue marks who handled what,
            // where the old profile link left reviewers hunting for a course and an attempt.
            'queueurl' => (new moodle_url(
                '/mod/quiz/accessrule/proctoring/overall_reports.php',
                ['view' => 'photoresets']
            ))->out(false),
        ];
        $subject = get_string('referencerequest:subject', 'quizaccess_proctoring', $a);
        $body = get_string('referencerequest:body', 'quizaccess_proctoring', $a);
        $sent = 0;
        foreach ($recipients as $recipient) {
            $message = new \core\message\message();
            $message->component = 'quizaccess_proctoring';
            $message->name = 'referenceresetrequest';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $recipient;
            $message->subject = $subject;
            $message->fullmessage = $body;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = message_texts::html($body);
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $a->queueurl;
            $message->contexturlname = get_string('referencerequest:queuename', 'quizaccess_proctoring');
            if (message_send($message)) {
                $sent++;
            }
        }
        \quizaccess_proctoring\event\reference_reset_requested::create([
            'context' => \context_user::instance($userid),
            'relateduserid' => $userid,
        ])->trigger();
        return $sent;
    }
}
