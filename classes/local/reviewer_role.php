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

use stdClass;

/**
 * The proctoring reviewer role for a central integrity office such as Student Affairs (CPIT-474).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reviewer_role {
    /**
     * What the role may do: review and decide proctoring holds, read the proctoring and quiz
     * reports, manage per-student overrides, and open the courses it reviews without being
     * enrolled. Nothing here changes site configuration, enrolments, grades directly or content.
     *
     * @var string[]
     */
    public const CAPABILITIES = [
        'quizaccess/proctoring:reviewacrosscourses',
        'quizaccess/proctoring:viewreport',
        'quizaccess/proctoring:reviewriskholds',
        'quizaccess/proctoring:manageoverrides',
        'mod/quiz:viewreports',
        'moodle/course:view',
        'moodle/course:viewhiddencourses',
        'moodle/course:viewhiddenactivities',
        'moodle/course:ignoreavailabilityrestrictions',
        // Attempt reviews in quizzes with separate groups, which the reviewer is in none of.
        'moodle/site:accessallgroups',
    ];

    /** @var int[] Where the role can be assigned: the whole site, or one category. */
    public const CONTEXT_LEVELS = [CONTEXT_SYSTEM, CONTEXT_COURSECAT];

    /**
     * Create the role, or reset an existing one to exactly these capabilities.
     *
     * @param string $shortname Role short name.
     * @return stdClass The role record.
     */
    public static function ensure(string $shortname = 'proctoringreviewer'): stdClass {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $shortname]);
        if (!$role) {
            $roleid = create_role(
                get_string('reviewerrole:name', 'quizaccess_proctoring'),
                $shortname,
                get_string('reviewerrole:description', 'quizaccess_proctoring')
            );
            $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
        }
        set_role_contextlevels((int)$role->id, self::CONTEXT_LEVELS);

        $system = \context_system::instance();
        $current = $DB->get_records_menu(
            'role_capabilities',
            ['roleid' => $role->id, 'contextid' => $system->id],
            '',
            'capability, permission'
        );
        foreach (array_keys($current) as $capability) {
            if (!in_array($capability, self::CAPABILITIES, true)) {
                unassign_capability($capability, (int)$role->id, $system->id);
            }
        }
        foreach (self::CAPABILITIES as $capability) {
            if (get_capability_info($capability)) {
                assign_capability($capability, CAP_ALLOW, (int)$role->id, $system->id, true);
            }
        }
        $system->mark_dirty();
        return $role;
    }
}
