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
    /** @var string User preference recording the last reset request, to limit them to one a day. */
    public const REQUEST_PREFERENCE = 'quizaccess_proctoring_referenceresetrequested';

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
     * @return bool False when another change to the photo held the lock, so nothing was reset.
     */
    public static function reset(int $userid, string $reason, \context $context): bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

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
        return true;
    }

    /**
     * Whether the student may send another reset request now.
     *
     * @param int $userid Student.
     * @return bool
     */
    public static function can_request(int $userid): bool {
        $last = (int)get_user_preferences(self::REQUEST_PREFERENCE, 0, $userid);
        return $last < time() - DAYSECS;
    }

    /**
     * Ask the proctoring reviewers to reset the student's photo.
     *
     * Goes to everyone holding the cross-course review capability site-wide, which is the Student
     * Affairs reviewer role; to the site administrators when nobody does.
     *
     * @param int $userid Student asking.
     * @param string $reason What is wrong with the photo, in the student's words.
     * @return int Number of reviewers notified.
     */
    public static function request_reset(int $userid, string $reason): int {
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
            $message->fullmessagehtml = text_to_html($body);
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $a->profileurl;
            $message->contexturlname = $a->student;
            if (message_send($message)) {
                $sent++;
            }
        }
        set_user_preference(self::REQUEST_PREFERENCE, time(), $userid);
        \quizaccess_proctoring\event\reference_reset_requested::create([
            'context' => \context_user::instance($userid),
            'relateduserid' => $userid,
        ])->trigger();
        return $sent;
    }
}
