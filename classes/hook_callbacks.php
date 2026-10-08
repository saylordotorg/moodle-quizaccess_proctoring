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

namespace quizaccess_proctoring;

use core\hook\navigation\primary_extend;
use core\hook\output\after_standard_main_region_html_generation;

/**
 * Hook callbacks for quizaccess_proctoring.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add the proctoring panel below the quiz attempt-review page's main region.
     *
     * Replaces the legacy standard_after_main_region_html callback (CPIT-475). The panel decides
     * for itself whether this is the review page and whether the viewer may see it.
     *
     * @param after_standard_main_region_html_generation $hook The hook.
     */
    public static function after_standard_main_region_html(after_standard_main_region_html_generation $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $html = quizaccess_proctoring_attempt_review_panel_html();
        if ($html !== '') {
            $hook->add_html($html);
        }
    }

    /**
     * Add "Proctoring reviews" to the top navigation for anyone who can review across courses.
     *
     * The profile page link alone was too hard to find (CPIT-492). Students and teachers without
     * the capability see nothing; the check is cached for the session so it costs no query per page.
     *
     * @param primary_extend $hook The hook.
     */
    public static function extend_primary_navigation(primary_extend $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        if (!isloggedin() || isguestuser() || during_initial_install()) {
            return;
        }
        if (!quizaccess_proctoring_can_review_across_courses_cached()) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('reviewqueue:navlink', 'quizaccess_proctoring'),
            new \moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'quizaccess_proctoring_reviewqueue'
        );
    }
}
