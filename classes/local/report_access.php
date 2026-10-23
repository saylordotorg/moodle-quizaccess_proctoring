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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Validates the target of staff report actions.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Checks record ownership and capabilities in the module being changed.
 */
final class report_access {
    /**
     * Bind submitted identifiers to one stored proctoring report before writing a review.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int $userid Student id.
     * @param int $reportid Proctoring report id.
     * @param int|null $attemptid Submitted attempt id, when supplied by the caller.
     * @return \stdClass The validated report, including its authoritative attempt id in status.
     */
    public static function require_report(
        int $courseid,
        int $cmid,
        int $userid,
        int $reportid,
        ?int $attemptid = null
    ): \stdClass {
        global $DB;

        $report = $DB->get_record('quizaccess_proctoring_logs', [
            'id' => $reportid,
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $userid,
        ]);
        if (!$report || ($attemptid !== null && (int)$report->status !== $attemptid)) {
            throw new \moodle_exception('invalidrequest', 'error');
        }

        return $report;
    }

    /**
     * Check the actual quiz, including module-level capability overrides.
     *
     * The caller has already logged the user in. This deliberately does not call
     * require_login($course): the cross-course review page has set its own page up - for a site
     * administrator that loads the theme - and moving the page to the quiz's course afterwards is
     * a coding error, which is what "Escalate to integrity case" showed administrators (CPIT-513).
     * The capability on the quiz itself is what authorises the decision.
     *
     * @param int $courseid Course id expected by the action.
     * @param int $cmid Quiz course-module id being changed.
     * @return \context_module Authorized quiz context.
     */
    public static function require_review(int $courseid, int $cmid): \context_module {
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
        if ((int)$course->id !== $courseid) {
            throw new \moodle_exception('invalidrequest', 'error');
        }

        $context = \context_module::instance((int)$cm->id);
        require_capability('quizaccess/proctoring:reviewriskholds', $context);

        return $context;
    }
}
