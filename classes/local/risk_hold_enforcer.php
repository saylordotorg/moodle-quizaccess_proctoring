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

use grade_grade;
use grade_item;
use stdClass;

/**
 * Keeps a student's quiz gradebook entry in line with their proctoring risk holds.
 *
 * A hold used to push a zero to the gradebook once, at submission. That did not hold anything:
 * every later regrade, recompute or new attempt wrote the real grade back while the hold row
 * still said "held", and a zero still completes a quiz whose completion is "receive a grade",
 * so certificates gated on that completion were issued anyway (CPIT-463).
 *
 * The gradebook is now derived from the holds every time either side changes:
 *  - While any hold for the student and quiz is active, the grade is empty ("no grade") and
 *    locked. An empty grade fails every grade- and completion-based certificate restriction
 *    (minimum grade, passing grade, receive a grade, must be complete), and a locked grade is
 *    left alone by quiz regrades and recomputes.
 *  - Once no hold is active, attempts with a confirmed or automatically failed hold count as
 *    scoring zero, and the quiz's own grading method (highest, average, first, last) picks the
 *    grade. A later honest attempt still counts, and a regrade cannot revive a voided attempt.
 *  - With no enforced hold left, the quiz grade is recomputed normally.
 *
 * {@see self::handle_user_graded()} reapplies this whenever anything else changes the grade, so
 * the gradebook cannot drift from the holds even if someone unlocks the grade by hand.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class risk_hold_enforcer {
    /** @var bool True while this class is writing grades, so its own grade events are ignored. */
    private static $running = false;

    /** @var bool[] Hold locks this request already holds, keyed by quiz and student. */
    private static $heldlocks = [];

    /**
     * Hold statuses that affect the gradebook.
     *
     * @return int[]
     */
    public static function enforced_statuses(): array {
        return [
            QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED,
            QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED,
        ];
    }

    /**
     * Whether a hold of this status keeps the grade empty and locked.
     *
     * @param int $status Hold status.
     * @return bool
     */
    public static function status_holds_grade(int $status): bool {
        return $status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
    }

    /**
     * Whether a hold of this status voids its attempt (scores it as zero).
     *
     * @param int $status Hold status.
     * @return bool
     */
    public static function status_voids_attempt(int $status): bool {
        return $status === QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED ||
            $status === QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED;
    }

    /**
     * Bring one student's gradebook grade for one quiz in line with their holds.
     *
     * @param int $quizinstance Quiz instance id (quiz.id).
     * @param int $userid Student id.
     * @param bool $clearfeedback True when a hold was just lifted, so its gradebook feedback is
     *                            removed even if no enforced hold remains.
     * @return bool False when an active hold could not empty and lock the grade (logged).
     */
    public static function enforce(int $quizinstance, int $userid, bool $clearfeedback = false): bool {
        return self::with_lock($quizinstance, $userid, function () use ($quizinstance, $userid, $clearfeedback) {
            return self::enforce_locked($quizinstance, $userid, $clearfeedback);
        });
    }

    /**
     * Run code while holding the lock for one student's holds on one quiz.
     *
     * Hold decisions (release, confirm) and every enforcement take this lock, so enforcement never
     * acts on a hold a concurrent decision is changing. Decisions keep it until they commit. It is
     * re-entrant within a request: a decision that enforces does not wait on itself.
     *
     * @param int $quizinstance Quiz instance id (quiz.id).
     * @param int $userid Student id.
     * @param callable $work The work to run under the lock.
     * @return mixed The work's result.
     */
    public static function with_lock(int $quizinstance, int $userid, callable $work) {
        $key = 'quiz' . $quizinstance . 'user' . $userid;
        if (!empty(self::$heldlocks[$key])) {
            return $work();
        }

        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring_riskhold');
        $lock = $factory->get_lock($key, 30);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'moodle');
        }
        self::$heldlocks[$key] = true;
        try {
            return $work();
        } finally {
            unset(self::$heldlocks[$key]);
            $lock->release();
        }
    }

    /**
     * {@see self::enforce()}, with the student's hold lock already held.
     *
     * @param int $quizinstance Quiz instance id (quiz.id).
     * @param int $userid Student id.
     * @param bool $clearfeedback True when a hold was just lifted.
     * @return bool False when an active hold could not empty and lock the grade (logged).
     */
    private static function enforce_locked(int $quizinstance, int $userid, bool $clearfeedback): bool {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $quiz = $DB->get_record('quiz', ['id' => $quizinstance]);
        if (!$quiz) {
            return true;
        }
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        if (!$cm) {
            return true;
        }
        $quiz->cmidnumber = $cm->idnumber;
        $quiz->visible = $cm->visible;

        [$insql, $inparams] = $DB->get_in_or_equal(self::enforced_statuses(), SQL_PARAMS_NAMED);
        $holds = $DB->get_records_select(
            'quizaccess_proctoring_risk_holds',
            "quizinstance = :quiz AND userid = :userid AND status $insql",
            ['quiz' => $quiz->id, 'userid' => $userid] + $inparams,
            'timecreated DESC, id DESC'
        );
        if (!$holds && !$clearfeedback) {
            // Nothing to enforce, and no hold was just lifted: leave the quiz's own grade alone.
            return true;
        }

        $wasrunning = self::$running;
        self::$running = true;
        try {
            $active = null;
            $voided = [];
            foreach ($holds as $hold) {
                if (self::status_holds_grade((int)$hold->status)) {
                    $active = $active ?? $hold;
                } else if (self::status_voids_attempt((int)$hold->status)) {
                    $voided[(int)$hold->attemptid] = $hold;
                }
            }

            if ($active) {
                return self::hold_grade($quiz, $userid, $active);
            }

            self::unlock_grade($quiz, $userid);
            self::recompute_grade($quiz, $userid, array_keys($voided));

            if ($voided) {
                self::set_feedback($quiz, $userid, self::feedback_for(reset($voided)));
            } else if ($clearfeedback) {
                self::set_feedback($quiz, $userid, '');
            }
            return true;
        } finally {
            self::$running = $wasrunning;
        }
    }

    /**
     * Reapply holds after any grade change to a held student's quiz.
     *
     * Quiz regrades, recomputes, new attempts and manual gradebook edits all end in this event.
     * Grade writes made by {@see self::enforce()} itself are skipped.
     *
     * @param \core\event\user_graded $event Grade event.
     * @return void
     */
    public static function handle_user_graded(\core\event\user_graded $event): void {
        global $CFG, $DB;

        if (self::$running) {
            return;
        }
        $userid = (int)$event->relateduserid;
        $itemid = (int)($event->other['itemid'] ?? 0);
        if ($userid <= 0 || $itemid <= 0) {
            return;
        }

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        try {
            // Cheap check first: this runs for every grade change on the site.
            [$insql, $inparams] = $DB->get_in_or_equal(self::enforced_statuses(), SQL_PARAMS_NAMED);
            if (!$DB->record_exists_select(
                'quizaccess_proctoring_risk_holds',
                "userid = :userid AND status $insql",
                ['userid' => $userid] + $inparams
            )) {
                return;
            }

            $item = $DB->get_record('grade_items', ['id' => $itemid], 'id, itemtype, itemmodule, iteminstance, itemnumber');
            if (
                !$item || $item->itemtype !== 'mod' || $item->itemmodule !== 'quiz' ||
                (int)$item->itemnumber !== 0
            ) {
                return;
            }

            self::enforce((int)$item->iteminstance, $userid);
        } catch (\Throwable $e) {
            quizaccess_proctoring_log_failure('re-applying a risk hold after a grade change', $e);
        }
    }

    /**
     * Whether this class is currently writing grades.
     *
     * @return bool
     */
    public static function is_running(): bool {
        return self::$running;
    }

    /**
     * Empty and lock the student's gradebook grade for the quiz.
     *
     * @param stdClass $quiz Quiz record with cmidnumber and visible.
     * @param int $userid Student id.
     * @param stdClass $hold The active hold.
     * @return bool False when the grade could not be emptied and locked (logged).
     */
    private static function hold_grade(stdClass $quiz, int $userid, stdClass $hold): bool {
        if ((float)$quiz->grade <= 0) {
            // A quiz with no grade has nothing in the gradebook to hold.
            return true;
        }

        // A locked grade refuses every write, including this one, so unlock first.
        self::unlock_grade($quiz, $userid);

        // The gradebook keeps an overridden final grade whatever the quiz sends. A grade typed in
        // by hand while the hold is active would otherwise be locked in, passing or not; the hold
        // wins, and staff release the hold to restore a grade.
        $grade = self::get_grade($quiz, $userid);
        if ($grade && $grade->is_overridden()) {
            $grade->set_overridden(false, false);
        }

        $result = quiz_grade_item_update($quiz, (object)[
            'userid' => $userid,
            'rawgrade' => null,
            'feedback' => self::feedback_for($hold),
            'feedbackformat' => FORMAT_PLAIN,
            'usermodified' => 0,
            'dategraded' => time(),
        ]);
        if ($result !== GRADE_UPDATE_OK) {
            quizaccess_proctoring_log_failure(
                'holding the gradebook grade for quiz ' . $quiz->id . ' user ' . $userid,
                'grade update result ' . $result
            );
        }

        $grade = self::get_grade($quiz, $userid);
        if (!$grade) {
            // Nothing is published for this student, so there is nothing to hold.
            return true;
        }
        if ($grade->finalgrade !== null) {
            // Never lock a grade in place: if it is not empty, leave it unlocked so the next
            // grade change re-applies the hold, and make the failure visible.
            quizaccess_proctoring_log_failure(
                'emptying the held gradebook grade for quiz ' . $quiz->id . ' user ' . $userid,
                'the final grade is still ' . $grade->finalgrade . ' (is the grade item locked?)'
            );
            return false;
        }
        if (!$grade->is_locked() && !$grade->set_locked(1, false, false)) {
            quizaccess_proctoring_log_failure(
                'locking the held gradebook grade for quiz ' . $quiz->id . ' user ' . $userid,
                'the grade item needs a gradebook update'
            );
            return false;
        }
        return true;
    }

    /**
     * Unlock the student's own gradebook grade, without pulling grades from the quiz.
     *
     * @param stdClass $quiz Quiz record.
     * @param int $userid Student id.
     * @return void
     */
    private static function unlock_grade(stdClass $quiz, int $userid): void {
        $grade = self::get_grade($quiz, $userid);
        if ($grade && !empty($grade->locked)) {
            $grade->set_locked(0, false, false);
        }
    }

    /**
     * Recompute the quiz grade, scoring voided attempts as zero, and publish it.
     *
     * The grade is computed here and written once, rather than through the quiz's own
     * recompute, which would first publish a different value. Moodle queues grade events raised
     * inside an event observer and delivers them after the observer returns, so an intermediate
     * value would reach {@see self::handle_user_graded()} and start the cycle again. Writing the
     * final value directly means a repeat run changes nothing, and the gradebook raises no event.
     *
     * @param stdClass $quiz Quiz record with cmidnumber and visible.
     * @param int $userid Student id.
     * @param int[] $voidedattemptids Attempts with a confirmed or automatically failed hold.
     * @return void
     */
    private static function recompute_grade(stdClass $quiz, int $userid, array $voidedattemptids): void {
        global $DB;

        $attempts = quiz_get_user_attempts($quiz->id, $userid, 'finished');
        $grade = self::final_grade($quiz, $attempts, $voidedattemptids);

        $existing = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $userid]);
        if ($grade === null) {
            if ($existing) {
                $DB->delete_records('quiz_grades', ['id' => $existing->id]);
            }
        } else if (!$existing) {
            $DB->insert_record('quiz_grades', (object)[
                'quiz' => $quiz->id,
                'userid' => $userid,
                'grade' => $grade,
                'timemodified' => time(),
            ]);
        } else if (grade_floats_different((float)$existing->grade, $grade)) {
            $existing->grade = $grade;
            $existing->timemodified = time();
            $DB->update_record('quiz_grades', $existing);
        }

        // Publishes the stored grade, or "no grade" when there is none. The gradebook raises a
        // grade event only when the final grade actually changes.
        quiz_update_grades($quiz, $userid);
    }

    /**
     * The quiz grade for a set of attempts, with voided attempts scored as zero.
     *
     * Follows the quiz's grading method like core's grade calculator, except that a voided
     * attempt is a mark of zero rather than a missing mark: core's "highest grade" starts from
     * null and keeps a mark only if it is greater, and 0 > null is false in PHP, so attempts that
     * all score zero would otherwise produce no grade at all.
     *
     * @param stdClass $quiz Quiz record (grade, sumgrades, grademethod).
     * @param stdClass[] $attempts Finished attempts in attempt order.
     * @param int[] $voidedattemptids Attempts with a confirmed or automatically failed hold.
     * @return float|null The rescaled quiz grade, or null when no attempt has a mark.
     */
    public static function final_grade(stdClass $quiz, array $attempts, array $voidedattemptids): ?float {
        $voided = array_map('intval', $voidedattemptids);
        $marks = [];
        foreach ($attempts as $attempt) {
            if (in_array((int)$attempt->id, $voided, true)) {
                $marks[] = 0.0;
            } else {
                $marks[] = $attempt->sumgrades === null ? null : (float)$attempt->sumgrades;
            }
        }
        if (!$marks) {
            return null;
        }

        $graded = array_values(array_filter($marks, function ($mark) {
            return $mark !== null;
        }));
        switch ((int)$quiz->grademethod) {
            case QUIZ_ATTEMPTFIRST:
                $raw = reset($marks);
                break;
            case QUIZ_ATTEMPTLAST:
                $raw = end($marks);
                break;
            case QUIZ_GRADEAVERAGE:
                $raw = $graded ? array_sum($graded) / count($graded) : null;
                break;
            case QUIZ_GRADEHIGHEST:
            default:
                $raw = $graded ? max($graded) : null;
                break;
        }

        return $raw === null ? null : (float)quiz_rescale_grade($raw, $quiz, false);
    }

    /**
     * Set the gradebook feedback for the student's quiz grade without changing the grade.
     *
     * @param stdClass $quiz Quiz record with cmidnumber and visible.
     * @param int $userid Student id.
     * @param string $feedback Feedback text; empty clears it.
     * @return void
     */
    private static function set_feedback(stdClass $quiz, int $userid, string $feedback): void {
        $grade = self::get_grade($quiz, $userid);
        if (!$grade || (string)$grade->feedback === $feedback) {
            return;
        }
        quiz_grade_item_update($quiz, (object)[
            'userid' => $userid,
            'feedback' => $feedback === '' ? null : $feedback,
            'feedbackformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Gradebook feedback describing a hold.
     *
     * @param stdClass $hold Hold record.
     * @return string
     */
    private static function feedback_for(stdClass $hold): string {
        switch ((int)$hold->status) {
            case QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED:
                return get_string('riskreview:autofailgradefeedback', 'quizaccess_proctoring', (int)$hold->riskscore);
            case QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED:
                return get_string('riskreview:confirmedgradefeedback', 'quizaccess_proctoring', (int)$hold->riskscore);
            default:
                return get_string('riskreview:gradefeedback', 'quizaccess_proctoring', (int)$hold->riskscore);
        }
    }

    /**
     * Load the student's gradebook grade for the quiz's main grade item.
     *
     * @param stdClass $quiz Quiz record.
     * @param int $userid Student id.
     * @return grade_grade|null
     */
    private static function get_grade(stdClass $quiz, int $userid): ?grade_grade {
        $item = grade_item::fetch([
            'courseid' => $quiz->course,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
        ]);
        if (!$item) {
            return null;
        }
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        return $grade ?: null;
    }

    /**
     * Whether a course certificate was issued that the hold should have stopped.
     *
     * Holds now keep certificates from being issued, but certificates issued before this
     * change, or through a restriction that does not depend on the quiz, are flagged so staff
     * can revoke them. A confirmed or failed attempt does not taint a certificate the student
     * earned anyway: one issued when their quiz grade, with voided attempts scored as zero,
     * already reached the pass mark.
     *
     * @param int $courseid Course id.
     * @param int $userid Student id.
     * @param int $attemptid Held attempt id.
     * @param bool $active True for an active hold, false for a confirmed or failed one.
     * @return bool
     */
    public static function certificate_issued_during_hold(
        int $courseid,
        int $userid,
        int $attemptid,
        bool $active
    ): bool {
        global $CFG, $DB;

        try {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            if (!$DB->get_manager()->table_exists('tool_certificate_issues')) {
                return false;
            }
            $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, timestart');
            $issuetimes = $DB->get_fieldset_select(
                'tool_certificate_issues',
                'timecreated',
                'userid = :userid AND courseid = :courseid AND timecreated >= :since',
                ['userid' => $userid, 'courseid' => $courseid, 'since' => $attempt ? (int)$attempt->timestart : 0]
            );
            if (!$issuetimes) {
                return false;
            }

            if ($active || !$attempt) {
                // While a hold is active no grade is published, so no certificate should exist.
                return self::certificate_conflicts(true, new \stdClass(), 0.0, $issuetimes, [], []);
            }

            $voided = $DB->get_fieldset_select(
                'quizaccess_proctoring_risk_holds',
                'attemptid',
                'quizinstance = :quiz AND userid = :userid AND (status = :confirmed OR status = :autofailed)',
                [
                    'quiz' => $attempt->quiz,
                    'userid' => $userid,
                    'confirmed' => QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED,
                    'autofailed' => QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED,
                ]
            );
            $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], 'id, grade, sumgrades, grademethod', MUST_EXIST);
            $gradepass = (float)$DB->get_field('grade_items', 'gradepass', [
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $quiz->id,
                'itemnumber' => 0,
            ]);
            $attempts = quiz_get_user_attempts($quiz->id, $userid, 'finished');

            return self::certificate_conflicts(false, $quiz, $gradepass, $issuetimes, $attempts, $voided);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Decide whether certificates issued since a held attempt started conflict with its hold.
     *
     * While a hold is active no grade is published, so any certificate conflicts. After a
     * violation is confirmed, a certificate is legitimate only if the quiz grade the student had
     * when it was issued - from the attempts finished by then, voided ones scored as zero, under
     * the quiz's grading method - reached the pass mark.
     *
     * @param bool $active True for an active hold, false for a confirmed or failed one.
     * @param stdClass $quiz Quiz record (grade, sumgrades, grademethod); unused for an active hold.
     * @param float $gradepass The quiz grade item's pass mark; with none (0), every certificate conflicts.
     * @param int[] $issuetimes Times certificates were issued since the held attempt started.
     * @param stdClass[] $attempts The student's finished attempts in attempt order.
     * @param int[] $voidedattemptids Attempts with a confirmed or automatically failed hold.
     * @return bool
     */
    public static function certificate_conflicts(
        bool $active,
        stdClass $quiz,
        float $gradepass,
        array $issuetimes,
        array $attempts,
        array $voidedattemptids
    ): bool {
        if (!$issuetimes) {
            return false;
        }
        if ($active) {
            return true;
        }
        foreach ($issuetimes as $issuetime) {
            $finished = array_filter($attempts, function ($attempt) use ($issuetime) {
                return (int)$attempt->timefinish <= (int)$issuetime;
            });
            $grade = self::final_grade($quiz, $finished, $voidedattemptids);
            // Without a pass mark the quiz cannot show the certificate was earned (its own
            // restriction may ask for any minimum grade), so the conflict stays for staff to judge.
            $passed = $grade !== null && $gradepass > 0 && $grade >= $gradepass - 0.00001;
            if (!$passed) {
                return true;
            }
        }
        return false;
    }
}
