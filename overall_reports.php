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
 * Site-wide aggregate proctoring report ("Overall reports").
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('quizaccess_proctoring_overall_reports');
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');

$courseid = optional_param('courseid', 0, PARAM_INT);
$range = optional_param('range', '7days', PARAM_ALPHANUM);
$minviolations = optional_param('minviolations', 0, PARAM_INT);
$sort = optional_param('sort', 'violations', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$holdid = optional_param('holdid', 0, PARAM_INT);

if (!array_key_exists($range, \quizaccess_proctoring\local\overall_report::range_seconds())) {
    $range = '7days';
}
if (!in_array($sort, ['violations', 'recent'], true)) {
    $sort = 'violations';
}
$minviolations = max(0, $minviolations);

// Release or confirm a risk hold inline, then return to the same filtered view.
if (($action === 'release' || $action === 'confirm') && $holdid > 0) {
    require_sesskey();
    $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
    require_capability('quizaccess/proctoring:reviewriskholds', context_course::instance((int)$hold->courseid));

    $returnurl = new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', [
        'courseid' => $courseid,
        'range' => $range,
        'minviolations' => $minviolations,
        'sort' => $sort,
        'page' => $page,
    ]);

    if ((int)$hold->status !== QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
        redirect(
            $returnurl,
            get_string('overallreport:holdnotactive', 'quizaccess_proctoring'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }

    if ($action === 'release') {
        quizaccess_proctoring_release_risk_hold($holdid, $USER->id);
        redirect(
            $returnurl,
            get_string('riskreview:releasednotice', 'quizaccess_proctoring'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    quizaccess_proctoring_confirm_risk_hold($holdid, $USER->id);
    redirect(
        $returnurl,
        get_string('riskreview:confirmednotice', 'quizaccess_proctoring'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$data = \quizaccess_proctoring\local\overall_report::build($courseid, $range, $minviolations, $sort, $page);

$baseurl = new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', [
    'courseid' => $courseid,
    'range' => $range,
    'minviolations' => $minviolations,
    'sort' => $sort,
]);
$pagingbar = $OUTPUT->paging_bar($data['total'], $data['page'], $data['perpage'], $baseurl);

$templatecontext = [
    'formurl' => (new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php'))->out(false),
    'intro' => get_string('overallreport:intro', 'quizaccess_proctoring'),
    'courseoptions' => \quizaccess_proctoring\local\overall_report::course_options($courseid),
    'rangeoptions' => \quizaccess_proctoring\local\overall_report::range_options($range),
    'sortoptions' => \quizaccess_proctoring\local\overall_report::sort_options($sort),
    'minviolations' => $minviolations,
    'summary' => $data['summary'],
    'rows' => $data['rows'],
    'hasrows' => $data['hasrows'],
    'truncated' => $data['truncated'],
    'truncatednotice' => get_string(
        'overallreport:truncated',
        'quizaccess_proctoring',
        \quizaccess_proctoring\local\overall_report::MAX_ATTEMPTS
    ),
    'pagingbar' => $pagingbar,
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overallreport:heading', 'quizaccess_proctoring'));
echo $OUTPUT->render_from_template('quizaccess_proctoring/overall_reports', $templatecontext);
echo $OUTPUT->footer();
