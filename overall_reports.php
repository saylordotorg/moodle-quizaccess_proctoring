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
$view = optional_param('view', 'attempts', PARAM_ALPHA);
$queue = optional_param('queue', 'needs', PARAM_ALPHA);
if (!in_array($queue, ['needs', 'all', 'reviewed'], true)) {
    $queue = 'needs';
}

// The cross-course held-certificate dashboard is a site-wide review surface, so it is guarded by
// the review capability at the system context. Reviewers without it never see the toggle or view.
$canviewheld = has_capability('quizaccess/proctoring:reviewriskholds', context_system::instance());
if (!in_array($view, ['attempts', 'held'], true) || ($view === 'held' && !$canviewheld)) {
    $view = 'attempts';
}

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

// Build a two-tab view toggle (attempts report vs held-certificate dashboard). The held tab is
// only offered to reviewers holding the system-context review capability.
$viewtoggle = '';
if ($canviewheld) {
    $attemptsurl = new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', [
        'courseid' => $courseid,
        'range' => $range,
        'minviolations' => $minviolations,
        'sort' => $sort,
        'view' => 'attempts',
    ]);
    $heldurl = new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', ['view' => 'held']);
    $tabs = [
        new tabobject(
            'attempts',
            $attemptsurl->out(false),
            get_string('heldcertificates:attemptsviewtoggle', 'quizaccess_proctoring')
        ),
        new tabobject(
            'held',
            $heldurl->out(false),
            get_string('heldcertificates:viewtoggle', 'quizaccess_proctoring')
        ),
    ];
    $viewtoggle = $OUTPUT->tabtree($tabs, $view);
}

if ($view === 'held') {
    $data = \quizaccess_proctoring\local\overall_report::held_certificates($page);

    $baseurl = new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', ['view' => 'held']);
    $pagingbar = $OUTPUT->paging_bar($data['total'], $data['page'], $data['perpage'], $baseurl);

    $templatecontext = [
        'intro' => get_string('heldcertificates:intro', 'quizaccess_proctoring'),
        'rows' => $data['rows'],
        'hasrows' => $data['hasrows'],
        'truncated' => $data['truncated'],
        'truncatednotice' => get_string(
            'heldcertificates:truncated',
            'quizaccess_proctoring',
            \quizaccess_proctoring\local\overall_report::MAX_ATTEMPTS
        ),
        'pagingbar' => $pagingbar,
    ];

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('heldcertificates:heading', 'quizaccess_proctoring'));
    echo $viewtoggle;
    echo $OUTPUT->render_from_template('quizaccess_proctoring/held_certificates', $templatecontext);
    echo $OUTPUT->footer();
    return;
}

$data = \quizaccess_proctoring\local\overall_report::build($courseid, $range, $minviolations, $sort, $page, $queue);

$baseurl = new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', [
    'courseid' => $courseid,
    'range' => $range,
    'minviolations' => $minviolations,
    'sort' => $sort,
    'queue' => $queue,
]);
$pagingbar = $OUTPUT->paging_bar($data['total'], $data['page'], $data['perpage'], $baseurl);

// Build the clickable pulse cards and the review-queue view pills, preserving the active filters.
$queueurl = function (string $q) use ($courseid, $range, $minviolations, $sort) {
    return (new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', [
        'courseid' => $courseid,
        'range' => $range,
        'minviolations' => $minviolations,
        'sort' => $sort,
        'queue' => $q,
    ]))->out(false);
};
$summary = $data['summary'];
$pulse = [
    [
        'num' => $summary['needsreview'],
        'label' => get_string('overallreport:pulse_needs', 'quizaccess_proctoring'),
        'hint' => get_string('overallreport:pulse_needs_hint', 'quizaccess_proctoring'),
        'url' => $queueurl('needs'),
        'iscritical' => $summary['needsreview'] > 0,
        'isactive' => $queue === 'needs',
    ],
    [
        'num' => $summary['totalattempts'],
        'label' => get_string('overallreport:pulse_attempts', 'quizaccess_proctoring'),
        'hint' => get_string('overallreport:pulse_attempts_hint', 'quizaccess_proctoring'),
        'url' => $queueurl('all'),
        'iscritical' => false,
        'isactive' => $queue === 'all',
    ],
    [
        'num' => $summary['clean'],
        'label' => get_string('overallreport:pulse_clean', 'quizaccess_proctoring'),
        'hint' => get_string('overallreport:pulse_clean_hint', 'quizaccess_proctoring'),
        'url' => $queueurl('all'),
        'iscritical' => false,
        'isactive' => false,
    ],
    [
        'num' => $summary['escalated'],
        'label' => get_string('overallreport:pulse_escalated', 'quizaccess_proctoring'),
        'hint' => get_string('overallreport:pulse_escalated_hint', 'quizaccess_proctoring'),
        'url' => $queueurl('reviewed'),
        'iscritical' => false,
        'isactive' => false,
    ],
];
$views = [
    [
        'label' => get_string('overallreport:view_needs', 'quizaccess_proctoring', $summary['needsreview']),
        'url' => $queueurl('needs'),
        'isactive' => $queue === 'needs',
    ],
    [
        'label' => get_string('overallreport:view_all', 'quizaccess_proctoring'),
        'url' => $queueurl('all'),
        'isactive' => $queue === 'all',
    ],
    [
        'label' => get_string('overallreport:view_reviewed', 'quizaccess_proctoring'),
        'url' => $queueurl('reviewed'),
        'isactive' => $queue === 'reviewed',
    ],
];

$templatecontext = [
    'formurl' => (new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php'))->out(false),
    'intro' => get_string('overallreport:intro', 'quizaccess_proctoring'),
    'courseoptions' => \quizaccess_proctoring\local\overall_report::course_options($courseid),
    'rangeoptions' => \quizaccess_proctoring\local\overall_report::range_options($range),
    'sortoptions' => \quizaccess_proctoring\local\overall_report::sort_options($sort),
    'minviolations' => $minviolations,
    'queue' => $queue,
    'pulse' => $pulse,
    'views' => $views,
    'countnote' => get_string('overallreport:countnote', 'quizaccess_proctoring', (object)[
        'shown' => count($data['rows']),
        'total' => $summary['totalattempts'],
    ]),
    'summary' => $summary,
    'rows' => $data['rows'],
    'hasrows' => $data['hasrows'],
    'emptyqueue' => !$data['hasrows'] && $queue === 'needs',
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
echo $viewtoggle;
echo $OUTPUT->render_from_template('quizaccess_proctoring/overall_reports', $templatecontext);
echo $OUTPUT->footer();
