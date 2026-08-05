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
// Newest first is the default: the report is read to find out what just happened, and an ordering
// by detected events buries this morning's attempt behind a noisy one from last week.
$sort = optional_param('sort', 'recent', PARAM_ALPHA);
if (!in_array($sort, ['recent', 'oldest', 'violations', 'student', 'email'], true)) {
    $sort = 'recent';
}
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$holdid = optional_param('holdid', 0, PARAM_INT);
$view = optional_param('view', 'attempts', PARAM_ALPHA);
$queue = optional_param('queue', 'needs', PARAM_ALPHA);
if (!in_array($queue, ['needs', 'all', 'flagged', 'reviewed', 'escalated', 'clean'], true)) {
    $queue = 'needs';
}
// Student filters: a free-text search over name and email, plus Moodle's two initials bars.
$search = trim(optional_param('search', '', PARAM_TEXT));
$search = \core_text::substr($search, 0, 100);
$tifirst = \core_text::substr(optional_param('tifirst', '', PARAM_TEXT), 0, 1);
$tilast = \core_text::substr(optional_param('tilast', '', PARAM_TEXT), 0, 1);
// Risk filters: a band, or an explicit score range. An inverted range would match nothing, so the
// bounds are swapped rather than silently returning an empty report.
$risklevel = optional_param('risklevel', '', PARAM_ALPHA);
if (!in_array($risklevel, ['low', 'moderate', 'high', 'critical'], true)) {
    $risklevel = '';
}
// Bounds come from the highest score that can actually be reached: that is 100 only while the
// score cap is on, and the sum of the enabled factor caps when it is off. Clamping to a flat 100
// would make the worst attempts unaskable-for on an uncapped site.
$scoremax = \quizaccess_proctoring\local\risk_calculator::max_possible_score();
$riskmin = min($scoremax, max(0, optional_param('riskmin', 0, PARAM_INT)));
$riskmax = min($scoremax, max(0, optional_param('riskmax', $scoremax, PARAM_INT)));
if ($riskmin > $riskmax) {
    [$riskmin, $riskmax] = [$riskmax, $riskmin];
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
$minviolations = max(0, $minviolations);

// Every filter that narrows the attempts list, in one place: each link this page emits carries the
// whole set so paging, cards, pills, initials bars and hold decisions all return to the same view.
$activefilters = [
    'courseid' => $courseid,
    'range' => $range,
    'minviolations' => $minviolations,
    'sort' => $sort,
    'search' => $search,
    'tifirst' => $tifirst,
    'tilast' => $tilast,
    'risklevel' => $risklevel,
    'riskmin' => $riskmin,
    'riskmax' => $riskmax,
    'riskmaxbound' => $scoremax,
];

// Sign a flagged attempt off, or undo a sign-off. A flagged attempt has no hold to release, so this
// is the only decision available on it; it changes no grade and notifies nobody, which is why it
// needs no confirmation step in either direction.
if ($action === 'signoff' || $action === 'undosignoff') {
    require_sesskey();

    $returnurl = new moodle_url(
        '/mod/quiz/accessrule/proctoring/overall_reports.php',
        $activefilters + ['page' => $page, 'queue' => $queue]
    );

    if ($action === 'signoff') {
        $rowcourseid = required_param('rowcourseid', PARAM_INT);
        $rowcmid = required_param('rowcmid', PARAM_INT);
        $rowuserid = required_param('rowuserid', PARAM_INT);
        $rowattemptid = optional_param('rowattemptid', 0, PARAM_INT);
        $rowreportid = required_param('rowreportid', PARAM_INT);
        require_capability(
            'quizaccess/proctoring:reviewriskholds',
            context_course::instance($rowcourseid)
        );

        \quizaccess_proctoring\local\attempt_review::record(
            $rowcourseid,
            $rowcmid,
            $rowuserid,
            $rowattemptid,
            $rowreportid,
            (int)$USER->id
        );

        redirect(
            $returnurl,
            get_string('overallreport:signedoffnotice', 'quizaccess_proctoring'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $signoffid = required_param('signoffid', PARAM_INT);
    $signoff = $DB->get_record(
        \quizaccess_proctoring\local\attempt_review::TABLE,
        ['id' => $signoffid, 'verdict' => \quizaccess_proctoring\local\attempt_review::VERDICT],
        '*',
        MUST_EXIST
    );
    require_capability(
        'quizaccess/proctoring:reviewriskholds',
        context_course::instance((int)$signoff->courseid)
    );
    \quizaccess_proctoring\local\attempt_review::undo(
        $signoffid,
        (int)$signoff->courseid,
        (int)$signoff->quizid,
        (int)$USER->id
    );

    redirect(
        $returnurl,
        get_string('overallreport:signoffundonenotice', 'quizaccess_proctoring'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Release or confirm a risk hold inline, then return to the same filtered view.
if (($action === 'release' || $action === 'confirm') && $holdid > 0) {
    require_sesskey();
    $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
    require_capability('quizaccess/proctoring:reviewriskholds', context_course::instance((int)$hold->courseid));

    $returnurl = new moodle_url(
        '/mod/quiz/accessrule/proctoring/overall_reports.php',
        $activefilters + ['page' => $page, 'queue' => $queue]
    );

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
    $attemptsurl = new moodle_url(
        '/mod/quiz/accessrule/proctoring/overall_reports.php',
        $activefilters + ['view' => 'attempts']
    );
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

$data = \quizaccess_proctoring\local\overall_report::build(
    $courseid,
    $range,
    $minviolations,
    $sort,
    $page,
    $queue,
    $search,
    $tifirst,
    $tilast,
    $risklevel,
    $riskmin,
    $riskmax
);

$baseurl = new moodle_url(
    '/mod/quiz/accessrule/proctoring/overall_reports.php',
    $activefilters + ['queue' => $queue]
);
$pagingbar = $OUTPUT->paging_bar($data['total'], $data['page'], $data['perpage'], $baseurl);

// Build the clickable pulse cards and the review-queue view pills, preserving the active filters.
$queueurl = function (string $q) use ($activefilters) {
    return (new moodle_url(
        '/mod/quiz/accessrule/proctoring/overall_reports.php',
        $activefilters + ['queue' => $q]
    ))->out(false);
};
$summary = $data['summary'];

// Nothing reaches the review queue unless a submission can open an active hold, so when that route
// is switched off the queue's own card and empty state say which configuration is holding it shut
// rather than implying the queue is simply caught up.
$holdreachability = quizaccess_proctoring_risk_hold_reachability();
$needshints = [
    'reachable' => 'overallreport:pulse_needs_hint',
    'autofailonly' => 'overallreport:pulse_needs_hint_autofail',
    'off' => 'overallreport:pulse_needs_hint_off',
];
$emptytitles = [
    'reachable' => 'overallreport:queueclear',
    'autofailonly' => 'overallreport:queueautofail',
    'off' => 'overallreport:queueholdsoff',
];
$emptybodies = [
    'reachable' => 'overallreport:queueclearbody',
    'autofailonly' => 'overallreport:queueautofailbody',
    'off' => 'overallreport:queueholdsoffbody',
];
$risksettingsurl = (new moodle_url('/admin/settings.php', ['section' => 'modsettingsquizcatproctoring']))->out(false);
$pulse = [
    [
        'num' => $summary['needsreview'],
        'label' => get_string('overallreport:pulse_needs', 'quizaccess_proctoring'),
        'hint' => get_string($needshints[$holdreachability], 'quizaccess_proctoring'),
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
        'url' => $queueurl('clean'),
        'iscritical' => false,
        'isactive' => $queue === 'clean',
    ],
    [
        'num' => $summary['escalated'],
        'label' => get_string('overallreport:pulse_escalated', 'quizaccess_proctoring'),
        'hint' => get_string('overallreport:pulse_escalated_hint', 'quizaccess_proctoring'),
        'url' => $queueurl('escalated'),
        'iscritical' => false,
        'isactive' => $queue === 'escalated',
    ],
];
// One pill per review state, so every row is reachable by the state it displays and a card
// click always lights up a pill too.
$views = [
    [
        'label' => get_string('overallreport:view_needs', 'quizaccess_proctoring', $summary['needsreview']),
        'url' => $queueurl('needs'),
        'isactive' => $queue === 'needs',
    ],
    [
        'label' => get_string('overallreport:view_flagged', 'quizaccess_proctoring', $summary['flagged']),
        'url' => $queueurl('flagged'),
        'isactive' => $queue === 'flagged',
    ],
    [
        'label' => get_string('overallreport:view_reviewed', 'quizaccess_proctoring'),
        'url' => $queueurl('reviewed'),
        'isactive' => $queue === 'reviewed',
    ],
    [
        'label' => get_string('overallreport:view_escalated', 'quizaccess_proctoring'),
        'url' => $queueurl('escalated'),
        'isactive' => $queue === 'escalated',
    ],
    [
        'label' => get_string('overallreport:view_clean', 'quizaccess_proctoring'),
        'url' => $queueurl('clean'),
        'isactive' => $queue === 'clean',
    ],
    [
        'label' => get_string('overallreport:view_all', 'quizaccess_proctoring'),
        'url' => $queueurl('all'),
        'isactive' => $queue === 'all',
    ],
];

// Moodle's own initials bars, so filtering by student here works the way it does on the
// participants list. Their links carry the rest of the filters and reset paging.
// Each bar owns its own parameter, so it is dropped from that bar's base URL - initials_bar() adds
// it back per letter, and "All" has to be able to clear it.
$initialsurl = function (string $own) use ($activefilters, $queue) {
    $params = array_diff_key($activefilters, [$own => '']) + ['queue' => $queue];
    return new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php', $params);
};
$firstinitialbar = $OUTPUT->initials_bar(
    $tifirst,
    'proctoring-overview-initial',
    get_string('firstname'),
    'tifirst',
    $initialsurl('tifirst')
);
$lastinitialbar = $OUTPUT->initials_bar(
    $tilast,
    'proctoring-overview-initial',
    get_string('lastname'),
    'tilast',
    $initialsurl('tilast')
);

$hasstudentfilter = $search !== '' || $tifirst !== '' || $tilast !== '';
$hasriskfilter = $risklevel !== '' || $riskmin > 0 || $riskmax < $scoremax;

$templatecontext = [
    'formurl' => (new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php'))->out(false),
    'intro' => get_string('overallreport:intro', 'quizaccess_proctoring'),
    'courseoptions' => \quizaccess_proctoring\local\overall_report::course_options($courseid),
    'rangeoptions' => \quizaccess_proctoring\local\overall_report::range_options($range),
    'sortoptions' => \quizaccess_proctoring\local\overall_report::sort_options($sort),
    'risklevoptions' => \quizaccess_proctoring\local\overall_report::risk_level_options($risklevel),
    'riskmin' => $riskmin,
    'riskmax' => $riskmax,
    'minviolations' => $minviolations,
    'search' => $search,
    'tifirst' => $tifirst,
    'tilast' => $tilast,
    'firstinitialbar' => $firstinitialbar,
    'lastinitialbar' => $lastinitialbar,
    'hasstudentfilter' => $hasstudentfilter,
    'clearstudentfilterurl' => (new moodle_url(
        '/mod/quiz/accessrule/proctoring/overall_reports.php',
        array_diff_key($activefilters, ['search' => '', 'tifirst' => '', 'tilast' => ''])
            + ['queue' => $queue]
    ))->out(false),
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
    // A student or risk filter that matches nothing is not an empty queue, so it keeps the plain
    // "nothing matches these filters" message instead of claiming the queue is caught up.
    'emptyqueue' => !$data['hasrows'] && $queue === 'needs' && !$hasstudentfilter && !$hasriskfilter,
    'emptytitle' => get_string($emptytitles[$holdreachability], 'quizaccess_proctoring'),
    'emptybody' => get_string($emptybodies[$holdreachability], 'quizaccess_proctoring'),
    'emptyisnotice' => $holdreachability !== 'reachable',
    'emptyactionurl' => $holdreachability === 'reachable' ? '' : $risksettingsurl,
    'emptyactionlabel' => get_string('overallreport:queueopensettings', 'quizaccess_proctoring'),
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
