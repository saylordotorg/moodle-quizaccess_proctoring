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
 * Escalating "face out of frame" warnings that end the attempt on the Nth time (CPIT-510).
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Counts the times a student's face left the webcam frame and ends the attempt at the limit.
 *
 * A strike is a face pause (the quiz blurred because no face was in view, CPIT-470) that lasted
 * at least the configured number of seconds: the page logs a face_strike event when a pause
 * reaches that length, once per pause. The count is kept here, from the logged events, so a
 * reload or a new page cannot reset it. When the count reaches the limit the server records a
 * face_strikes_ended event - only the server writes it - and from then on the attempt is over:
 * the page submits it, and if the page never does, the attempt page and a scheduled task submit
 * it server-side. The submission observer then applies the configured outcome.
 */
final class face_strikes {
    /** @var string Logged by the page when a face pause reaches the minimum length. */
    const EVENT_STRIKE = 'face_strike';

    /** @var string Logged by the server, never the page, when the strikes reach the limit. */
    const EVENT_ENDED = 'face_strikes_ended';

    /** @var string Logged by the server with the rule an attempt runs under: its limit and outcome. */
    const EVENT_STARTED = 'face_strikes_started';

    /** @var string Hold the attempt for review: grade and certificate withheld, retake lockout. */
    const OUTCOME_HOLD = 'hold';

    /** @var string Fail the attempt automatically. */
    const OUTCOME_AUTOFAIL = 'autofail';

    /** @var string Only end the attempt; no grade action. */
    const OUTCOME_NONE = 'none';

    /** @var int Strike limit used when a per-student override forces the rule on a quiz without one. */
    const DEFAULT_LIMIT = 4;

    /** @var int Fewest strikes a limit can be: one warning before the attempt ends. */
    const MIN_LIMIT = 2;

    /** @var int Most strikes a limit can be. */
    const MAX_LIMIT = 10;

    /** @var int Seconds a pause must last to count, when nothing is configured. */
    const DEFAULT_MIN_SECONDS = 5;

    /** @var int Seconds the page has, after the attempt ended, to submit it before the server does. */
    const FINISH_GRACE_SECONDS = 120;

    /**
     * The site's strike limit: 0 when the rule is off.
     *
     * @return int 0, or MIN_LIMIT to MAX_LIMIT.
     */
    public static function site_limit(): int {
        return self::clamp_limit((int)get_config('quizaccess_proctoring', 'facestrikes'));
    }

    /**
     * How long a pause must last, in seconds, before it counts.
     *
     * @return int 1 to 60.
     */
    public static function min_seconds(): int {
        $configured = get_config('quizaccess_proctoring', 'facestrikeminseconds');
        $seconds = $configured === false || $configured === '' ? self::DEFAULT_MIN_SECONDS : (int)$configured;
        return max(1, min(60, $seconds));
    }

    /**
     * What happens to an attempt that reached the limit.
     *
     * @return string One of the OUTCOME_* constants.
     */
    public static function outcome(): string {
        $outcome = (string)get_config('quizaccess_proctoring', 'facestrikesoutcome');
        return in_array($outcome, [self::OUTCOME_HOLD, self::OUTCOME_AUTOFAIL, self::OUTCOME_NONE], true)
            ? $outcome
            : self::OUTCOME_HOLD;
    }

    /**
     * Clamp a configured limit: 0 or below is off, and anything else lies within the bounds.
     *
     * @param int $limit Configured limit.
     * @return int 0, or MIN_LIMIT to MAX_LIMIT.
     */
    public static function clamp_limit(int $limit): int {
        return $limit <= 0 ? 0 : max(self::MIN_LIMIT, min(self::MAX_LIMIT, $limit));
    }

    /**
     * The strike limit for one student on one quiz: 0 when the rule does not apply.
     *
     * Strikes are face pauses, so the rule only runs where the quiz blurs without a face. The
     * quiz's own setting wins over the site's, and a per-student override wins over both: a
     * waiver turns it off (an accommodation), and forcing it on where neither sets a limit uses
     * DEFAULT_LIMIT.
     *
     * @param int $courseid Course id.
     * @param int $quizid Quiz instance id.
     * @param int $userid Student id.
     * @param int $now Time the overrides are resolved at: the attempt start, so the limit cannot
     *                 change under a running attempt.
     * @return int 0, or MIN_LIMIT to MAX_LIMIT.
     */
    public static function limit_for(int $courseid, int $quizid, int $userid, int $now): int {
        global $DB;

        if ((int)get_config('quizaccess_proctoring', 'blurquizwithoutface') !== 1) {
            return 0;
        }
        $quizlimit = $DB->get_field('quizaccess_proctoring', 'facestrikes', ['quizid' => $quizid]);
        $quizlimit = $quizlimit === false ? -1 : (int)$quizlimit;
        $base = $quizlimit === -1 ? self::site_limit() : self::clamp_limit($quizlimit);

        $resolved = override_resolver::resolve_all(
            $courseid,
            $quizid,
            $userid,
            $now,
            [override_resolver::REQ_FACESTRIKES => $base > 0]
        );
        if (empty($resolved[override_resolver::REQ_FACESTRIKES])) {
            return 0;
        }
        return $base > 0 ? $base : (self::site_limit() ?: self::DEFAULT_LIMIT);
    }

    /**
     * Number of strikes logged for an attempt.
     *
     * @param int $attemptid Attempt id.
     * @param int $userid Student id.
     * @return int Strikes.
     */
    public static function count(int $attemptid, int $userid): int {
        global $DB;

        if ($attemptid <= 0) {
            return 0;
        }
        return $DB->count_records('quizaccess_proctoring_events', [
            'attemptid' => $attemptid,
            'userid' => $userid,
            'eventtype' => self::EVENT_STRIKE,
        ]);
    }

    /**
     * The event that ended the attempt, if the strikes reached the limit.
     *
     * @param int $attemptid Attempt id.
     * @param int $userid Student id.
     * @return \stdClass|null The face_strikes_ended event.
     */
    public static function ended_event(int $attemptid, int $userid): ?\stdClass {
        global $DB;

        if ($attemptid <= 0) {
            return null;
        }
        $events = $DB->get_records('quizaccess_proctoring_events', [
            'attemptid' => $attemptid,
            'userid' => $userid,
            'eventtype' => self::EVENT_ENDED,
        ], 'id ASC', '*', 0, 1);
        return $events ? reset($events) : null;
    }

    /**
     * The outcome decided when the strikes ended this attempt, or '' when they did not.
     *
     * The outcome is recorded with the ending, so changing the site setting afterwards cannot
     * change what happens to an attempt that has already ended.
     *
     * @param int $attemptid Attempt id.
     * @param int $userid Student id.
     * @return string One of the OUTCOME_* constants, or ''.
     */
    public static function ended_outcome(int $attemptid, int $userid): string {
        $ended = self::ended_event($attemptid, $userid);
        if (!$ended) {
            return '';
        }
        $detail = json_decode((string)$ended->eventdetail, true);
        $outcome = is_array($detail) ? (string)($detail['outcome'] ?? '') : '';
        return in_array($outcome, [self::OUTCOME_HOLD, self::OUTCOME_AUTOFAIL, self::OUTCOME_NONE], true)
            ? $outcome
            : self::outcome();
    }

    /**
     * The rule as it stands for one attempt: fixed the first time it is asked, so a setting
     * changed while the attempt runs cannot change the warnings the student has already been
     * given, or end the attempt sooner than they said.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Attempt id.
     * @param int $reportid Proctoring report id.
     * @return array 'limit' (0 when the rule does not apply) and 'outcome'.
     */
    public static function attempt_rule(int $courseid, int $cmid, int $userid, int $attemptid, int $reportid): array {
        global $DB;

        $rule = ['limit' => 0, 'outcome' => self::outcome()];
        $attempt = $attemptid > 0
            ? $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, userid, timestart')
            : false;
        if (!$attempt || (int)$attempt->userid !== $userid) {
            return $rule;
        }
        $events = $DB->get_records('quizaccess_proctoring_events', [
            'attemptid' => $attemptid,
            'userid' => $userid,
            'eventtype' => self::EVENT_STARTED,
        ], 'id ASC', 'id, eventdetail', 0, 1);
        if ($events) {
            $detail = json_decode((string)reset($events)->eventdetail, true);
            if (is_array($detail)) {
                $outcome = (string)($detail['outcome'] ?? '');
                return [
                    'limit' => self::clamp_limit((int)($detail['limit'] ?? 0)),
                    'outcome' => in_array($outcome, [self::OUTCOME_HOLD, self::OUTCOME_AUTOFAIL, self::OUTCOME_NONE], true)
                        ? $outcome
                        : self::outcome(),
                ];
            }
        }

        // Recorded even when the rule does not apply, so turning it on later cannot reach an
        // attempt that started without it.
        $rule['limit'] = self::limit_for($courseid, (int)$attempt->quiz, $userid, (int)$attempt->timestart);
        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'eventtype' => self::EVENT_STARTED,
            'eventdetail' => json_encode($rule),
            'pagevisibility' => '',
            'currenturl' => '',
            'screenshoturl' => '',
            'timemodified' => time(),
        ]);
        return $rule;
    }

    /**
     * Whether the strikes ended this attempt.
     *
     * @param int $attemptid Attempt id.
     * @param int $userid Student id.
     * @return bool True when the attempt reached the limit.
     */
    public static function ended(int $attemptid, int $userid): bool {
        return self::ended_event($attemptid, $userid) !== null;
    }

    /**
     * Count a strike the page just logged, and end the attempt when it reaches the limit.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Attempt id.
     * @param int $reportid Proctoring report id.
     * @return array 'strikes', 'limit' (0 when the rule is off) and 'ended'.
     */
    public static function record_strike(int $courseid, int $cmid, int $userid, int $attemptid, int $reportid): array {
        global $DB;

        $state = ['strikes' => self::count($attemptid, $userid), 'limit' => 0, 'ended' => false];
        if ($attemptid <= 0) {
            return $state;
        }
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, userid, state, timestart');
        if (!$attempt || (int)$attempt->userid !== $userid) {
            return $state;
        }
        $state['ended'] = self::ended($attemptid, $userid);
        $rule = self::attempt_rule($courseid, $cmid, $userid, $attemptid, $reportid);
        $state['limit'] = $rule['limit'];
        if (
            $state['ended'] || $state['limit'] <= 0 || $state['strikes'] < $state['limit']
                || !in_array((string)$attempt->state, ['inprogress', 'overdue'], true)
        ) {
            return $state;
        }

        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'eventtype' => self::EVENT_ENDED,
            'eventdetail' => json_encode([
                'strikes' => $state['strikes'],
                'limit' => $state['limit'],
                'outcome' => $rule['outcome'],
            ]),
            'pagevisibility' => '',
            'currenturl' => '',
            'screenshoturl' => '',
            'timemodified' => time(),
        ]);
        $state['ended'] = true;
        return $state;
    }

    /**
     * Submit an attempt the strikes ended, when it is still open.
     *
     * The page submits the attempt itself as soon as the server says it has ended, which keeps the
     * answers on the current page. This is for a page that never does: one closed, crashed or
     * reloaded first.
     *
     * @param int $attemptid Attempt id.
     * @param bool $studentisonline Whether the student is on a page right now.
     * @return bool True when the attempt was submitted here.
     */
    public static function finish_ended_attempt(int $attemptid, bool $studentisonline): bool {
        global $CFG, $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, userid, state');
        if (
            !$attempt || !in_array((string)$attempt->state, ['inprogress', 'overdue'], true)
                || !self::ended($attemptid, (int)$attempt->userid)
        ) {
            return false;
        }

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        $now = time();
        if (method_exists($attemptobj, 'process_submit')) {
            // Moodle 5.0 splits submitting from grading (MDL-68806).
            $attemptobj->process_submit($now, false, null, $studentisonline);
            $attemptobj->process_grade_submission($now);
        } else {
            $attemptobj->process_finish($now, false, null, $studentisonline);
        }
        return true;
    }

    /**
     * Submit every attempt the strikes ended whose page has not submitted it in time.
     *
     * @param int $now Current time.
     * @return int Attempts submitted.
     */
    public static function finish_abandoned_attempts(int $now): int {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
        $attempts = $DB->get_fieldset_sql(
            "SELECT DISTINCT e.attemptid
               FROM {quizaccess_proctoring_events} e
               JOIN {quiz_attempts} qa ON qa.id = e.attemptid AND qa.userid = e.userid
              WHERE e.eventtype = :eventtype
                AND e.timemodified <= :before
                AND qa.state IN ('inprogress', 'overdue')",
            ['eventtype' => self::EVENT_ENDED, 'before' => $now - self::FINISH_GRACE_SECONDS]
        );
        $finished = 0;
        foreach ($attempts as $attemptid) {
            try {
                if (self::finish_ended_attempt((int)$attemptid, false)) {
                    $finished++;
                }
            } catch (\Throwable $e) {
                quizaccess_proctoring_log_failure('submitting attempt ' . $attemptid . ' after face strikes', $e);
            }
        }
        return $finished;
    }

    /**
     * What the student is told will happen when the attempt ends, as a clause after "your attempt
     * will end and ...".
     *
     * @param string $outcome One of the OUTCOME_* constants.
     * @return string Plain text.
     */
    public static function consequence(string $outcome): string {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
        $lockoutdays = quizaccess_proctoring_get_cheating_lockout_days();
        $key = 'facestrike:consequence_' . $outcome;
        if ($outcome !== self::OUTCOME_NONE && $lockoutdays > 0) {
            $key .= '_lockout';
        }
        return get_string($key, 'quizaccess_proctoring', $lockoutdays);
    }

    /**
     * What the attempt page needs to run the rule.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $attemptid Attempt id.
     * @param int $reportid Proctoring report id.
     * @return array 'limit' (0 when off), 'strikes', 'minseconds' and 'outcome'.
     */
    public static function page_state(int $courseid, int $cmid, int $userid, int $attemptid, int $reportid): array {
        $rule = self::attempt_rule($courseid, $cmid, $userid, $attemptid, $reportid);
        return [
            'limit' => $rule['limit'],
            'strikes' => $rule['limit'] > 0 ? self::count($attemptid, $userid) : 0,
            'minseconds' => self::min_seconds(),
            'outcome' => $rule['outcome'],
        ];
    }

    /**
     * One-line summary for the report, or '' when the attempt had no strikes.
     *
     * @param int $userid Student id.
     * @param int $attemptid Attempt id.
     * @return string Summary.
     */
    public static function summary(int $userid, int $attemptid): string {
        $strikes = self::count($attemptid, $userid);
        if ($strikes === 0) {
            return '';
        }
        $ended = self::ended_event($attemptid, $userid);
        if ($ended) {
            $detail = json_decode((string)$ended->eventdetail, true);
            $limit = is_array($detail) ? (int)($detail['limit'] ?? $strikes) : $strikes;
            return get_string('verdict:facestrikesended', 'quizaccess_proctoring', $limit);
        }
        return get_string($strikes === 1 ? 'verdict:facestrikes_one' : 'verdict:facestrikes', 'quizaccess_proctoring', $strikes);
    }
}
