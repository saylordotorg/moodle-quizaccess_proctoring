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
 * Tests for the review queue link in the top navigation (CPIT-492).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\reviewer_role;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Review queue navigation tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\hook_callbacks
 * @covers ::quizaccess_proctoring_can_review_across_courses_cached
 */
final class review_queue_navigation_test extends advanced_testcase {

    /**
     * Whether the top navigation built for the current user links the review queue.
     *
     * @return bool
     */
    private function has_link(): bool {
        global $SESSION;
        unset($SESSION->quizaccess_proctoring_canreview);
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url('/my/');
        $primary = new \core\navigation\views\primary($page);
        $primary->initialise();
        return (bool)$primary->get('quizaccess_proctoring_reviewqueue');
    }

    /**
     * A reviewer and an administrator see "Proctoring reviews"; a student does not.
     */
    public function test_link_shows_only_for_reviewers(): void {
        $this->resetAfterTest();

        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $this->assertFalse($this->has_link());

        $reviewer = $this->getDataGenerator()->create_user();
        role_assign(reviewer_role::ensure()->id, $reviewer->id, \context_system::instance());
        $this->setUser($reviewer);
        $this->assertTrue($this->has_link());

        $this->setAdminUser();
        $this->assertTrue($this->has_link());

        $this->setGuestUser();
        $this->assertFalse($this->has_link());
    }

    /**
     * The access check is remembered for the session, per user.
     */
    public function test_check_is_cached_per_user(): void {
        global $SESSION;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        unset($SESSION->quizaccess_proctoring_canreview);
        $this->assertFalse(quizaccess_proctoring_can_review_across_courses_cached());

        // A role given now shows once the remembered answer expires.
        role_assign(reviewer_role::ensure()->id, $user->id, \context_system::instance());
        $this->assertFalse(quizaccess_proctoring_can_review_across_courses_cached());
        $SESSION->quizaccess_proctoring_canreview['time'] = time() - 301;
        $this->assertTrue(quizaccess_proctoring_can_review_across_courses_cached());

        // Another user in the same session is checked afresh.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(quizaccess_proctoring_can_review_across_courses_cached());
    }
}
