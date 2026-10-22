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
 * Event observers for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\mod_quiz\\event\\attempt_started',
        'callback' => '\\quizaccess_proctoring\\local\\monitoring_coverage::attempt_started',
    ],
    [
        'eventname' => '\\mod_quiz\\event\\attempt_started',
        'callback' => '\\quizaccess_proctoring\\local\\identity_recheck_policy::attempt_started',
    ],
    [
        // Bind the capture that passed the precheck to the attempt it led to (CPIT-464).
        'eventname' => '\\mod_quiz\\event\\attempt_started',
        'callback' => '\\quizaccess_proctoring\\local\\precheck_evidence::attempt_started',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\quizaccess_proctoring\proctoring_observer::handle_quiz_attempt_submitted',
    ],
    [
        // Moodle 5.0+ only (the event does not exist in 4.5): an attempt the face strikes ended is
        // failed once graded, as Moodle 5.0 grades after submitting (CPIT-510).
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback' => '\quizaccess_proctoring\proctoring_observer::handle_quiz_attempt_graded',
    ],
    [
        // Re-apply risk holds when a quiz regrade, recompute or manual edit changes a held grade.
        'eventname' => '\core\event\user_graded',
        'callback' => '\quizaccess_proctoring\local\risk_hold_enforcer::handle_user_graded',
    ],
    [
        // A regrade recreates the quiz-level grade row, and the locked gradebook grade swallows
        // the user_graded event that would re-apply the hold, so the regrade event itself
        // re-enforces (CPIT-463, PR review).
        'eventname' => '\mod_quiz\event\attempt_regraded',
        'callback' => '\quizaccess_proctoring\local\risk_hold_enforcer::handle_attempt_regraded',
    ],
    [
        // Manually grading a question recomputes the attempt's final grade the same silent way.
        'eventname' => '\mod_quiz\event\question_manually_graded',
        'callback' => '\quizaccess_proctoring\local\risk_hold_enforcer::handle_question_manually_graded',
    ],
    [
        // A later submission recomputes the quiz-level grade beside a held attempt.
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\quizaccess_proctoring\local\risk_hold_enforcer::handle_attempt_submitted',
    ],
    [
        // A deleted attempt takes its proctoring records with it: the captures are queued for
        // the image deletion task, and an active hold on it is dropped (CPIT-464).
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback' => '\quizaccess_proctoring\local\attempt_cleanup::attempt_deleted',
    ],
    [
        // Deleting an account removes the user's proctoring data too, not only a privacy request (CPIT-472).
        'eventname' => '\core\event\user_deleted',
        'callback' => '\quizaccess_proctoring\local\user_data_purge::user_deleted',
    ],
];
