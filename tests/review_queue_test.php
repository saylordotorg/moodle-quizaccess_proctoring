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
 * Tests for the cross-course review queue for Student Affairs (CPIT-474).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\overall_report;
use quizaccess_proctoring\local\reviewer_role;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Review queue tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\overall_report
 * @covers \quizaccess_proctoring\local\reviewer_role
 */
final class review_queue_test extends advanced_testcase {

    /** @var \stdClass[] Courses, keyed a and b, each in its own category. */
    private $courses = [];

    /** @var \stdClass[] Categories, keyed a and b. */
    private $categories = [];

    /** @var \stdClass[] Quiz course modules, keyed a and b. */
    private $cms = [];

    /**
     * Two categories with one proctored quiz each.
     */
    private function two_categories(): void {
        foreach (['a', 'b'] as $key) {
            $this->categories[$key] = $this->getDataGenerator()->create_category();
            $this->courses[$key] = $this->getDataGenerator()->create_course(['category' => $this->categories[$key]->id]);
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->courses[$key]->id]);
            $this->cms[$key] = get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST);
        }
    }

    /**
     * Add an active hold.
     *
     * @param string $key Course key.
     * @param int $age Seconds since the hold opened.
     * @param int $riskscore Score the hold was created with.
     * @return int Hold id.
     */
    private function hold(string $key, int $age, int $riskscore = 70): int {
        global $DB;
        static $attempt = 1000;
        $student = $this->getDataGenerator()->create_user();
        return (int)$DB->insert_record('quizaccess_proctoring_risk_holds', (object)[
            'courseid' => $this->courses[$key]->id,
            'quizid' => $this->cms[$key]->id,
            'quizinstance' => $this->cms[$key]->instance,
            'userid' => $student->id,
            'attemptid' => ++$attempt,
            'reportid' => 0,
            'riskscore' => $riskscore,
            'threshold' => 0,
            'originalgrade' => null,
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'reviewerid' => 0,
            'timecreated' => time() - $age,
            'timemodified' => time(),
            'timereviewed' => 0,
            'autoreleaseblockedscore' => 0,
            'autoreleaseblockedreason' => null,
        ]);
    }

    /**
     * A reviewer assigned on one category sees that category's holds only, and no site settings.
     */
    public function test_category_reviewer_sees_only_their_courses(): void {
        $this->resetAfterTest();
        $this->two_categories();
        $holda = $this->hold('a', DAYSECS);
        $this->hold('b', DAYSECS);

        $role = reviewer_role::ensure();
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign($role->id, $reviewer->id, \context_coursecat::instance($this->categories['a']->id));
        $this->setUser($reviewer);

        $this->assertTrue(quizaccess_proctoring_can_review_across_courses());
        $this->assertFalse(quizaccess_proctoring_can_manage_admin_settings());
        $this->assertFalse(has_capability('moodle/site:config', \context_system::instance()));

        $scope = overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds']);
        $this->assertSame([(int)$this->cms['a']->id], $scope);
        $queue = overall_report::held_certificates(0, $scope);
        $this->assertSame(1, $queue['total']);
        $this->assertStringContainsString('holdid=' . $holda, $queue['rows'][0]['releaseurl']);
        $this->assertSame(1, array_sum(array_intersect_key(
            overall_report::review_backlog(time(), $scope),
            ['critical' => 0, 'high' => 0, 'lower' => 0]
        )));
    }

    /**
     * A system-level reviewer sees every course except one where the capability is prohibited; a
     * user without the role sees nothing; only a site administrator skips the per-course check.
     */
    public function test_system_reviewer_and_outsider(): void {
        $this->resetAfterTest();
        $this->two_categories();
        $this->hold('a', DAYSECS);
        $this->hold('b', DAYSECS);

        $role = reviewer_role::ensure();
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign($role->id, $reviewer->id, \context_system::instance());
        $this->setUser($reviewer);
        $scope = overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds']);
        $this->assertEqualsCanonicalizing([(int)$this->cms['a']->id, (int)$this->cms['b']->id], $scope);
        $this->assertSame(2, overall_report::held_certificates(0, $scope)['total']);

        // A prohibition in one course keeps it out, although the role is held site-wide.
        assign_capability(
            'quizaccess/proctoring:reviewriskholds',
            CAP_PROHIBIT,
            $role->id,
            \context_course::instance($this->courses['b']->id)->id,
            true
        );
        $scope = overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds']);
        $this->assertSame([(int)$this->cms['a']->id], $scope);
        $this->assertSame(1, overall_report::held_certificates(0, $scope)['total']);

        // So does a prohibition on a single quiz.
        assign_capability(
            'quizaccess/proctoring:reviewriskholds',
            CAP_PROHIBIT,
            $role->id,
            \context_module::instance($this->cms['a']->id)->id,
            true
        );
        $this->assertSame([], overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds']));

        $this->setAdminUser();
        $this->assertNull(overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds']));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(quizaccess_proctoring_can_review_across_courses());
        $this->assertSame([], overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds']));
        $this->assertSame(0, overall_report::held_certificates(0, [])['total']);
    }

    /**
     * A reviewer assigned to a hidden category can still open the queue.
     */
    public function test_hidden_category_reviewer_can_open_the_queue(): void {
        $this->resetAfterTest();
        $this->two_categories();
        \core_course_category::get($this->categories['a']->id)->hide();

        $role = reviewer_role::ensure();
        $reviewer = $this->getDataGenerator()->create_user();
        role_assign($role->id, $reviewer->id, \context_coursecat::instance($this->categories['a']->id));
        $this->setUser($reviewer);

        $this->assertTrue(quizaccess_proctoring_can_review_across_courses());
        $this->assertSame(
            [(int)$this->cms['a']->id],
            overall_report::scoped_quiz_cmids(['quizaccess/proctoring:reviewriskholds'])
        );
    }

    /**
     * The queue reads oldest first and says when each hold is released without a review.
     */
    public function test_queue_is_oldest_first_with_auto_release(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->two_categories();
        set_config('riskreviewautoreleasedays', 14, 'quizaccess_proctoring');
        $newer = $this->hold('a', DAYSECS);
        $older = $this->hold('a', 10 * DAYSECS);

        $rows = overall_report::held_certificates(0, null)['rows'];
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('holdid=' . $older, $rows[0]['releaseurl']);
        $this->assertStringContainsString('holdid=' . $newer, $rows[1]['releaseurl']);
        $this->assertTrue($rows[0]['canact']);
        if (quizaccess_proctoring_get_risk_review_auto_release_days() > 0) {
            $this->assertSame(
                get_string('heldcertificates:autoreleasedays', 'quizaccess_proctoring', 4),
                $rows[0]['autorelease']
            );
        }
        $this->assertCount(2, overall_report::held_certificates(-1, null)['rows']);
    }

    /**
     * Running the role setup again resets the role to exactly its list, with no site configuration.
     */
    public function test_reviewer_role_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        $role = reviewer_role::ensure();
        assign_capability('moodle/site:config', CAP_ALLOW, $role->id, \context_system::instance()->id, true);
        $again = reviewer_role::ensure();

        $this->assertSame((int)$role->id, (int)$again->id);
        $caps = $DB->get_fieldset_select('role_capabilities', 'capability', 'roleid = ?', [$role->id]);
        sort($caps);
        $expected = reviewer_role::CAPABILITIES;
        sort($expected);
        $this->assertSame($expected, $caps);
        $this->assertEqualsCanonicalizing(
            reviewer_role::CONTEXT_LEVELS,
            array_map('intval', get_role_contextlevels($role->id))
        );
    }
}
