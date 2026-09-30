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
 * Device tests outside the timed quiz attempt.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
$context = context_module::instance($cmid);
require_login($course, false, $cm);
$settings = \quizaccess_proctoring\local\readiness::require_access($cmid);
$requirements = \quizaccess_proctoring\local\readiness::requirements($cm, $settings, (int)$USER->id);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/proctoring/readiness.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('readiness:title', 'quizaccess_proctoring'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->strings_for_js([
    'readiness:secure', 'readiness:unsupported', 'readiness:checking', 'readiness:timeout',
    'readiness:unavailable', 'readiness:wrongscreen', 'readiness:screenunknown', 'readiness:passed',
    'readiness:stopped', 'readiness:permission', 'readiness:missing', 'readiness:inuse', 'readiness:reachable',
    'readiness:notrequired', 'readiness:notconfigured', 'readiness:authentication', 'readiness:busy',
    'readiness:slow', 'readiness:roundtrip', 'readiness:networkerror', 'readiness:notchecked',
], 'quizaccess_proctoring');
$PAGE->requires->js_call_amd('quizaccess_proctoring/deviceReadiness', 'init', [['cmid' => $cmid]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('readiness:title', 'quizaccess_proctoring'));
echo $OUTPUT->render_from_template('quizaccess_proctoring/readiness', [
    'quizurl' => (new moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false),
    'screenrequired' => !empty($requirements['screenshare']),
]);
echo $OUTPUT->footer();
