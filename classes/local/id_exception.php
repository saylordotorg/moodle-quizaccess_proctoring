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

use context_module;
use core_user;
use moodle_url;
use stdClass;

/**
 * Pending ID verification exception requests and the decisions staff take on them.
 *
 * Requests live in quizaccess_proctoring_events as id_exemption_requested rows whose
 * eventdetail JSON carries what the student told the ID step: which path they took
 * ('capture' or 'noid'), and for a no-ID request the category they picked plus the
 * explanation they typed. A request stays pending until an approve/decline event is
 * recorded at or after its own timestamp, so a student can ask again after a decline.
 *
 * Both the per-exam Manage overrides panel and the site-wide Proctoring reports tab read
 * and act through here, so the two views cannot drift apart.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class id_exception {
    /** @var string Path taken by a student who cannot photograph an ID they hold. */
    const REASON_CAPTURE = 'capture';

    /** @var string Path taken by a student who has no photo ID at all. */
    const REASON_NOID = 'noid';

    /** @var string[] Categories offered to a student with no photo ID. */
    const CATEGORIES = ['never', 'lostorstolen', 'expired', 'displaced', 'withheld', 'other'];

    /** @var int Longest explanation stored, in characters. */
    const DETAIL_MAX = 1000;

    /** @var int Longest alternative-documentation note stored, in characters. */
    const ALTERNATIVES_MAX = 500;

    /**
     * Every pending exception request, newest first.
     *
     * @param int|null $cmid Restrict to one quiz course module, or null for site-wide.
     * @return array List of request records: cmid, courseid, userid, student, coursename,
     *     quizname, reason, category, detail, alternatives, timerequested.
     */
    public static function pending_requests(?int $cmid = null): array {
        global $DB;

        $conditions = ['eventtype' => 'id_exemption_requested'];
        if ($cmid !== null) {
            $conditions['quizid'] = $cmid;
        }
        $requests = $DB->get_records('quizaccess_proctoring_events', $conditions, 'timemodified ASC');
        if (empty($requests)) {
            return [];
        }

        // Latest decision per student and quiz closes every request made before it. Rows are
        // ordered by timestamp and then row id, because a student can re-request within the
        // same second that a decision was recorded and timestamps alone cannot separate the
        // two - which would silently swallow the new request.
        [$insql, $params] = $DB->get_in_or_equal(['id_exemption_approved', 'id_exemption_declined'], SQL_PARAMS_NAMED);
        $where = "eventtype $insql";
        if ($cmid !== null) {
            $where .= ' AND quizid = :quizid';
            $params['quizid'] = $cmid;
        }
        $lastdecision = [];
        foreach ($DB->get_records_select('quizaccess_proctoring_events', $where, $params) as $decision) {
            $key = (int)$decision->quizid . ':' . (int)$decision->userid;
            $at = [(int)$decision->timemodified, (int)$decision->id];
            if (($lastdecision[$key] ?? [0, 0]) < $at) {
                $lastdecision[$key] = $at;
            }
        }

        // One entry per student and quiz: the most recent request wins.
        $pending = [];
        foreach ($requests as $request) {
            $key = (int)$request->quizid . ':' . (int)$request->userid;
            if (($lastdecision[$key] ?? [0, 0]) > [(int)$request->timemodified, (int)$request->id]) {
                unset($pending[$key]);
                continue;
            }
            $detail = json_decode((string)$request->eventdetail, true);
            $detail = is_array($detail) ? $detail : [];
            $pending[$key] = [
                'cmid' => (int)$request->quizid,
                'courseid' => (int)$request->courseid,
                'userid' => (int)$request->userid,
                'reason' => (string)($detail['reason'] ?? ''),
                'category' => (string)($detail['category'] ?? ''),
                'detail' => (string)($detail['detail'] ?? ''),
                'alternatives' => (string)($detail['alternatives'] ?? ''),
                'timerequested' => (int)$request->timemodified,
            ];
        }

        // Names come from the course module, which may since have been deleted.
        foreach ($pending as $key => $request) {
            $student = core_user::get_user($request['userid']);
            [$coursename, $quizname] = self::names($request['cmid'], $request['courseid']);
            $pending[$key]['student'] = $student ? fullname($student) : (string)$request['userid'];
            $pending[$key]['email'] = $student ? $student->email : '';
            $pending[$key]['coursename'] = $coursename;
            $pending[$key]['quizname'] = $quizname;
        }

        // Newest first: staff work the freshest requests, and the per-exam panel used to
        // show them oldest first purely as a side effect of the query.
        uasort($pending, function (array $a, array $b) {
            return $b['timerequested'] <=> $a['timerequested'];
        });

        return array_values($pending);
    }

    /**
     * Approve or decline one request.
     *
     * Approving creates the quiz-scoped override that waives ID verification; declining
     * changes nothing. Either way the decision is logged for audit and the student is
     * emailed. The override manager enforces the manageoverrides capability on the quiz.
     *
     * @param int $cmid Quiz course module id the request belongs to.
     * @param int $userid Student the decision is about.
     * @param bool $approved True to approve, false to decline.
     * @return void
     */
    public static function decide(int $cmid, int $userid, bool $approved): void {
        global $DB, $USER;

        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cmid);
        require_capability('quizaccess/proctoring:manageoverrides', $context);
        $student = core_user::get_user($userid, '*', MUST_EXIST);
        [$coursename, $quizname] = self::names($cmid, (int)$cm->course);

        if ($approved) {
            override_manager::create($context, (object)[
                'quizid' => (int)$cm->instance,
                'userid' => $userid,
                'idverificationstate' => override_resolver::STATE_DISABLED,
                'justification' => get_string('idexemption:justification', 'quizaccess_proctoring'),
            ]);
        }

        $DB->insert_record('quizaccess_proctoring_events', (object)[
            'courseid' => (int)$cm->course,
            'quizid' => $cmid,
            'userid' => $userid,
            'attemptid' => 0,
            'reportid' => 0,
            'eventtype' => $approved ? 'id_exemption_approved' : 'id_exemption_declined',
            'eventdetail' => json_encode(['decidedby' => (int)$USER->id]),
            'pagevisibility' => 'visible',
            'currenturl' => '',
            'screenshoturl' => '',
            'timemodified' => time(),
        ]);

        // The decision email names the request it answers, so a student with more than one
        // pending exam knows which is which.
        $requestedat = (int)$DB->get_field_sql(
            "SELECT MAX(timemodified)
               FROM {quizaccess_proctoring_events}
              WHERE quizid = :cmid AND userid = :userid AND eventtype = 'id_exemption_requested'",
            ['cmid' => $cmid, 'userid' => $userid]
        );

        exemption_email::notify_student_decision(
            $student,
            $approved,
            $coursename,
            $quizname,
            $cmid,
            trim((string)get_config('quizaccess_proctoring', 'idexemptioncontactemail')),
            $requestedat
        );
    }

    /**
     * Human-readable summary of why a student says they cannot verify.
     *
     * @param array $request One record from pending_requests().
     * @return string Plain-text label, never empty.
     */
    public static function reason_label(array $request): string {
        $component = 'quizaccess_proctoring';
        $reason = (string)($request['reason'] ?? '');
        $category = (string)($request['category'] ?? '');

        if ($reason === self::REASON_CAPTURE) {
            return get_string('idexemption:reasonlabel_capture', $component);
        }
        if ($reason !== self::REASON_NOID) {
            return get_string('idexemption:reasonlabel_unknown', $component);
        }
        if (in_array($category, self::CATEGORIES, true)) {
            return get_string('idexemption:category_' . $category, $component);
        }

        return get_string('idexemption:reasonlabel_noid', $component);
    }

    /**
     * The quiz's Manage overrides page.
     *
     * @param int $cmid Quiz course module id.
     * @return moodle_url Link to the per-exam override page.
     */
    public static function overrides_url(int $cmid): moodle_url {
        return new moodle_url('/mod/quiz/accessrule/proctoring/manage_overrides.php', ['cmid' => $cmid]);
    }

    /**
     * Formatted course and quiz names for a course module.
     *
     * @param int $cmid Quiz course module id.
     * @param int $courseid Course id recorded with the request.
     * @return array [coursename, quizname]; either may be empty if the record is gone.
     */
    private static function names(int $cmid, int $courseid): array {
        global $DB;

        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
        $coursename = (string)$DB->get_field('course', 'fullname', ['id' => $cm ? $cm->course : $courseid]);

        return [format_string($coursename), $cm ? format_string($cm->name) : ''];
    }
}
