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
 * Example/interaction tests for the inline attempt-review proctoring panel.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\attempt_panel;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Example tests for the inline attempt-review integration (Requirement 14).
 *
 * These are worked examples (not properties) covering the design's Testing Strategy for
 * Requirement 14.1/14.2: the attempt-review hook renders the proctoring fragment for an authorized
 * reviewer so the exam attempt data and the proctoring data appear together, and the fragment is
 * hidden for users who lack the review capability.
 *
 * Two surfaces are exercised:
 *  - {@see attempt_panel::build_context()} builds the embeddable fragment context from real attempt
 *    data (risk score, resolved certificate label, AI review status, plain-language summary), and
 *  - {@see quizaccess_proctoring_standard_after_main_region_html()} drives the review-page callback
 *    end to end: it self-scopes to the `mod-quiz-review` page, reads the reviewed attempt from the
 *    `attempt` parameter, and renders the fragment only for a capable reviewer.
 *
 * Feature: proctoring-feedback-improvements
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\attempt_panel
 * @covers ::quizaccess_proctoring_standard_after_main_region_html
 */
final class inline_attempt_panel_test extends advanced_testcase {

    /**
     * build_context() builds the embeddable fragment from the attempt's proctoring data.
     *
     * Given a proctored quiz with a student attempt that has a proctoring log row, the builder must
     * return a template context carrying every review-critical signal the inline panel surfaces:
     * the heading, the risk score, and the presence flags/data for the certificate label, AI review
     * status, and plain-language session summary. This is the fragment that renders "alongside the
     * attempt data" on the review page.
     *
     * Validates: Requirements 14.1, 14.2
     */
    public function test_build_context_returns_attempt_panel_fragment(): void {
        $this->resetAfterTest();

        [$course, $quiz, $cm] = $this->create_proctored_quiz_fixture();
        $student = $this->create_enrolled_user($course, 'student');
        $attemptid = $this->create_quiz_attempt($quiz, $cm, $student);
        $this->create_proctoring_log($course, $cm, $student, $attemptid);

        $context = attempt_panel::build_context(
            (int)$course->id,
            (int)$cm->id,
            (int)$student->id,
            $attemptid
        );

        // The fragment is built with the attempt data: it must expose every review-critical key.
        $this->assertArrayHasKey('heading', $context);
        $this->assertArrayHasKey('riskscore', $context);
        $this->assertArrayHasKey('certificate', $context);
        $this->assertArrayHasKey('hascertificate', $context);
        $this->assertArrayHasKey('aireview', $context);
        $this->assertArrayHasKey('hasaireview', $context);
        $this->assertArrayHasKey('sessionsummary', $context);
        $this->assertArrayHasKey('hassessionsummary', $context);

        // The heading is the localized panel heading, and the risk score is a structured payload.
        $this->assertSame(get_string('attemptpanel:heading', 'quizaccess_proctoring'), $context['heading']);
        $this->assertIsArray($context['riskscore'], 'the panel must bundle the attempt risk score');

        // The builder resolves the caller-supplied attempt id through to the fragment context.
        $this->assertSame($attemptid, (int)$context['attemptid']);

        // The presence flags are booleans that agree with the data they describe.
        $this->assertIsBool($context['hascertificate']);
        $this->assertIsBool($context['hasaireview']);
        $this->assertIsBool($context['hassessionsummary']);
        $this->assertSame($context['hasaireview'], $context['aireview'] !== null);
    }

    /**
     * The review-page callback renders the panel inline for an authorized reviewer.
     *
     * With the page self-scoped to `mod-quiz-review` and the reviewed attempt supplied via the
     * `attempt` parameter, an editing teacher (who holds the review capabilities by default) sees
     * the proctoring fragment rendered inline, so the exam attempt data and proctoring data appear
     * together on the same page.
     *
     * Validates: Requirements 14.1, 14.2
     */
    public function test_inline_panel_renders_for_authorized_reviewer(): void {
        $this->resetAfterTest();

        [$course, $quiz, $cm] = $this->create_proctored_quiz_fixture();
        $student = $this->create_enrolled_user($course, 'student');
        $reviewer = $this->create_enrolled_user($course, 'editingteacher');
        $attemptid = $this->create_quiz_attempt($quiz, $cm, $student);
        $this->create_proctoring_log($course, $cm, $student, $attemptid);

        // The capable reviewer really does hold the review capability on the module context.
        $modulecontext = \context_module::instance((int)$cm->id);
        $this->assertTrue(has_any_capability(
            ['quizaccess/proctoring:reviewriskholds', 'quizaccess/proctoring:viewreport'],
            $modulecontext,
            $reviewer
        ), 'the editing teacher must hold a review capability for this test to be meaningful');

        $this->setup_review_page($course, $cm, $attemptid);
        $this->setUser($reviewer);

        $html = quizaccess_proctoring_standard_after_main_region_html();

        $this->assertNotSame('', trim($html),
            'the inline panel must render for an authorized reviewer on the review page');
        $this->assertStringContainsString(
            get_string('attemptpanel:heading', 'quizaccess_proctoring'),
            $html,
            'the rendered fragment must be the proctoring summary panel'
        );
    }

    /**
     * The review-page callback hides the panel from users lacking the review capability.
     *
     * The same review page, viewed by a plain student (the attempt owner) who does not hold the
     * review capability, must not surface the proctoring fragment: the callback returns an empty
     * string so nothing is contributed to the page.
     *
     * Validates: Requirements 14.1, 14.2
     */
    public function test_inline_panel_hidden_for_user_without_capability(): void {
        $this->resetAfterTest();

        [$course, $quiz, $cm] = $this->create_proctored_quiz_fixture();
        $student = $this->create_enrolled_user($course, 'student');
        $attemptid = $this->create_quiz_attempt($quiz, $cm, $student);
        $this->create_proctoring_log($course, $cm, $student, $attemptid);

        // The plain student must not hold either review capability.
        $modulecontext = \context_module::instance((int)$cm->id);
        $this->assertFalse(has_any_capability(
            ['quizaccess/proctoring:reviewriskholds', 'quizaccess/proctoring:viewreport'],
            $modulecontext,
            $student
        ), 'a plain student must not hold a review capability');

        $this->setup_review_page($course, $cm, $attemptid);
        $this->setUser($student);

        $html = quizaccess_proctoring_standard_after_main_region_html();

        $this->assertSame('', $html,
            'the inline panel must be hidden for a user lacking the review capability');
    }

    /**
     * Configures the global $PAGE and request so the callback resolves the reviewed attempt.
     *
     * The callback self-scopes to the quiz attempt-review page (`mod-quiz-review`) and reads the
     * attempt being reviewed from the `attempt` request parameter, so both are set here to drive the
     * callback exactly as the core review page would.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $cm Quiz course module.
     * @param int $attemptid Reviewed quiz attempt id.
     * @return void
     */
    private function setup_review_page(\stdClass $course, \stdClass $cm, int $attemptid): void {
        global $PAGE;

        $PAGE = new \moodle_page();
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url(new \moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid]));
        $PAGE->set_pagetype('mod-quiz-review');

        // The callback reads the reviewed attempt via optional_param('attempt').
        $_POST['attempt'] = $attemptid;
        $_GET['attempt'] = $attemptid;
    }

    /**
     * Creates a course + quiz whose proctoring row requires proctoring.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} [course, quiz, cm].
     */
    private function create_proctored_quiz_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);

        // Ensure the quiz is flagged as requiring proctoring.
        if ($DB->record_exists('quizaccess_proctoring', ['quizid' => (int)$quiz->id])) {
            $DB->set_field('quizaccess_proctoring', 'proctoringrequired', 1, ['quizid' => (int)$quiz->id]);
        } else {
            $DB->insert_record('quizaccess_proctoring', (object)[
                'quizid' => (int)$quiz->id,
                'proctoringrequired' => 1,
            ]);
        }

        return [$course, $quiz, $cm];
    }

    /**
     * Creates and enrols a user in the supplied course with the given role.
     *
     * @param \stdClass $course Course record.
     * @param string $role Role shortname.
     * @return \stdClass User record.
     */
    private function create_enrolled_user(\stdClass $course, string $role): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);

        return $user;
    }

    /**
     * Creates a minimal finished quiz attempt fixture.
     *
     * @param \stdClass $quiz Quiz instance.
     * @param \stdClass $cm Course module.
     * @param \stdClass $student Student user.
     * @return int Quiz attempt id.
     */
    private function create_quiz_attempt(\stdClass $quiz, \stdClass $cm, \stdClass $student): int {
        global $DB;

        return (int)$DB->insert_record('quiz_attempts', [
            'quiz' => (int)$quiz->id,
            'userid' => (int)$student->id,
            'attempt' => 1,
            'uniqueid' => $this->create_question_usage_id($cm),
            'layout' => '',
            'state' => 'finished',
            'timestart' => time() - 100,
            'timefinish' => time(),
            'timemodified' => time(),
            'timecheckstate' => 0,
        ]);
    }

    /**
     * Creates an empty question usage for a quiz attempt fixture.
     *
     * @param \stdClass $cm Course module.
     * @return int Question usage id.
     */
    private function create_question_usage_id(\stdClass $cm): int {
        $quba = \question_engine::make_questions_usage_by_activity(
            'mod_quiz',
            \context_module::instance((int)$cm->id)
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);

        return (int)$quba->get_id();
    }

    /**
     * Creates a proctoring log row keyed to the given attempt.
     *
     * The logs table stores the quiz attempt id in its `status` column, which is how the panel
     * resolves a representative report id for the attempt.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $cm Course module.
     * @param \stdClass $student Student user.
     * @param int $attemptid Quiz attempt id.
     * @return int Proctoring log (report) id.
     */
    private function create_proctoring_log(\stdClass $course, \stdClass $cm, \stdClass $student, int $attemptid): int {
        global $DB;

        return (int)$DB->insert_record('quizaccess_proctoring_logs', (object)[
            'courseid' => (int)$course->id,
            'quizid' => (int)$cm->id,
            'userid' => (int)$student->id,
            'webcampicture' => '',
            'status' => $attemptid,
            'awsscore' => 0,
            'awsflag' => 0,
            'deletionprogress' => 0,
            'timemodified' => time(),
        ]);
    }
}
