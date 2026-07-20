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
 * The path to the report file for the quizaccess_proctoring plugin.
 *
 * This constant holds the relative path to the report.php file used by the
 * quiz access rule for proctoring. It is utilized in the plugin to access
 * the report generation functionality.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->libdir . '/tablelib.php');

// Parameters.
$courseid = required_param('courseid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);
$studentid = optional_param('studentid', null, PARAM_INT);
$searchkey = optional_param('searchKey', null, PARAM_TEXT);
$submittype = optional_param('submitType', null, PARAM_TEXT);
$reportid = optional_param('reportid', null, PARAM_INT);
$logaction = optional_param('logaction', null, PARAM_TEXT);
$riskaction = optional_param('riskaction', null, PARAM_ALPHA);
$holdid = optional_param('holdid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
// Sort and filter parameters (Requirement 13). Sort keys are validated by
// quizaccess_proctoring_report_order_by(); initial filters by quizaccess_proctoring_name_matches_initials().
$sort = optional_param('sort', '', PARAM_ALPHA);
$dir = optional_param('dir', '', PARAM_ALPHA);
$firstnameinitial = optional_param('firstnameinitial', '', PARAM_ALPHA);
$lastnameinitial = optional_param('lastnameinitial', '', PARAM_ALPHA);

$analyzebtn = get_string('analyzbtn', 'quizaccess_proctoring');
$analyzebtnconfirm = get_string('analyzbtnconfirm', 'quizaccess_proctoring');
$searchplaceholder = get_string('report_search_placeholder', 'quizaccess_proctoring');
$searchbuttontext = get_string('report_search_submit', 'quizaccess_proctoring');
$clearbuttontext = get_string('report_search_clear', 'quizaccess_proctoring');


// Context and validation.
$context = context_module::instance($cmid, MUST_EXIST);
require_capability('quizaccess/proctoring:viewreport', $context);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, true, $cm);
$courseid = (int)$course->id;
$cmid = (int)$cm->id;

// Course and quiz data.
$coursedata = $course;
$quiz = $DB->get_record('quiz', ['id' => $cm->instance]);

// URL setup.
$params = [
    'courseid' => $courseid,
    'userid' => $studentid,
    'cmid' => $cmid,
];
// Pagination set.
$perpage = 30;
$offset = $page * $perpage;
$totalrecords = 0;

if ($studentid) {
    $params['studentid'] = $studentid;
}
if ($reportid) {
    $params['reportid'] = $reportid;
}


$url = new moodle_url('/mod/quiz/accessrule/proctoring/report.php', ['courseid' => $courseid, 'cmid' => $cmid]);
$fcmethod = get_config('quizaccess_proctoring', 'fcmethod');

/**
 * Gets a readable suspicious activity event label.
 *
 * @param string $eventtype Event type.
 * @return string Event label.
 */
function quizaccess_proctoring_get_event_label(string $eventtype): string {
    $key = 'eventtype:' . $eventtype;
    if (get_string_manager()->string_exists($key, 'quizaccess_proctoring')) {
        return get_string($key, 'quizaccess_proctoring');
    }

    return ucfirst(str_replace('_', ' ', $eventtype));
}

/**
 * Formats stored event JSON for the report table.
 *
 * @param string $eventdetail JSON event detail.
 * @return string Formatted detail.
 */
function quizaccess_proctoring_format_event_detail(string $eventdetail): string {
    $decoded = json_decode($eventdetail, true);
    if (!is_array($decoded)) {
        return substr($eventdetail, 0, 300);
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $parts[] = $key . ': ' . substr((string)$value, 0, 160);
    }

    return implode('; ', $parts);
}

// Page setup.
$PAGE->set_url($url);
$PAGE->set_pagelayout('course');
$PAGE->set_title($coursedata->shortname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->set_heading($coursedata->fullname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->navbar->add(get_string('quizaccess_proctoring', 'quizaccess_proctoring'), $url);
$PAGE->requires->js_call_amd('quizaccess_proctoring/lightbox2', 'init', [$fcmethod, [
    'analyzebtn' => $analyzebtn,
    'analyzebtnconfirm' => $analyzebtnconfirm,
    'sesskey' => sesskey(),
]]);
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');
// Add navbar for studnet report.
if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
    $PAGE->navbar->add(get_string('studentreport', 'quizaccess_proctoring') . " - $studentid", $url);
}

// Button logic.
$settingsbtn = has_capability('quizaccess/proctoring:viewreport', $context, $USER->id);
$showclearbutton = ($submittype === 'Search' && !empty($searchkey));

if (!empty($logaction)) {
    if ($logaction !== 'delete' || $studentid === null || $reportid === null) {
        throw new moodle_exception('invalidrequest', 'error');
    }

    require_capability('quizaccess/proctoring:deletecamshots', $context);

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidrequest', 'error');
    }
    require_sesskey();

    $report = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid], '*', MUST_EXIST);
    if (
        (int)$report->courseid !== (int)$courseid || (int)$report->quizid !== (int)$cmid ||
            (int)$report->userid !== (int)$studentid
    ) {
        throw new moodle_exception('invalidrequest', 'error');
    }

    $deletewhere = 'courseid = :courseid AND quizid = :cmid AND userid = :studentid';
    $deleteparams = [
        'courseid' => $courseid,
        'cmid' => $cmid,
        'studentid' => $studentid,
    ];
    if ((int)$report->status > 0) {
        $deletewhere .= ' AND status = :attemptid';
        $deleteparams['attemptid'] = (int)$report->status;
    } else {
        $deletewhere .= ' AND id = :reportid';
        $deleteparams['reportid'] = (int)$reportid;
    }

    $logids = $DB->get_fieldset_select('quizaccess_proctoring_logs', 'id', $deletewhere, $deleteparams);
    if (!empty($logids)) {
        $logs = $DB->get_records_list('quizaccess_proctoring_logs', 'id', $logids, '', 'id, webcampicture');
        foreach ($logs as $log) {
            quizaccess_proctoring_delete_pluginfile_url((string)$log->webcampicture);
        }

        $events = $DB->get_records_list('quizaccess_proctoring_events', 'reportid', $logids, '', 'id, screenshoturl');
        foreach ($events as $event) {
            quizaccess_proctoring_delete_pluginfile_url((string)$event->screenshoturl);
        }

        $faceimages = $DB->get_records_list('quizaccess_proctoring_face_images', 'parentid', $logids, '', 'id, faceimage');
        foreach ($faceimages as $faceimage) {
            quizaccess_proctoring_delete_pluginfile_url((string)$faceimage->faceimage);
        }

        $DB->delete_records_list('quizaccess_proctoring_fm_warnings', 'reportid', $logids);
        $DB->delete_records_list('quizaccess_proctoring_events', 'reportid', $logids);
        $DB->delete_records_list('quizaccess_proctoring_ai_reviews', 'reportid', $logids);
        $DB->delete_records_list('quizaccess_proctoring_face_images', 'parentid', $logids);
        $DB->delete_records_list('quizaccess_proctoring_logs', 'id', $logids);
    }

    redirect(new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
        'courseid' => $courseid,
        'cmid' => $cmid,
    ]), get_string('settings:deleteallsuccess', 'quizaccess_proctoring'), -11);
}

if ($riskaction === 'release' && $holdid > 0) {
    require_sesskey();
    require_capability('quizaccess/proctoring:reviewriskholds', $context);

    $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
    if ((int)$hold->courseid !== (int)$courseid || (int)$hold->quizid !== (int)$cmid) {
        throw new moodle_exception('invalidrequest', 'error');
    }

    if (!quizaccess_proctoring_release_risk_hold($holdid, $USER->id)) {
        throw new moodle_exception('invalidrequest', 'error');
    }
    redirect(new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
        'courseid' => $courseid,
        'cmid' => $cmid,
        'studentid' => $studentid ?: $hold->userid,
        'reportid' => $reportid ?: $hold->reportid,
    ]), get_string('riskreview:releasednotice', 'quizaccess_proctoring'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($riskaction === 'confirm' && $holdid > 0) {
    require_sesskey();
    require_capability('quizaccess/proctoring:reviewriskholds', $context);

    $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
    if ((int)$hold->courseid !== (int)$courseid || (int)$hold->quizid !== (int)$cmid) {
        throw new moodle_exception('invalidrequest', 'error');
    }

    if (!quizaccess_proctoring_confirm_risk_hold($holdid, $USER->id)) {
        throw new moodle_exception('invalidrequest', 'error');
    }

    redirect(new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
        'courseid' => $courseid,
        'cmid' => $cmid,
        'studentid' => $studentid ?: $hold->userid,
        'reportid' => $reportid ?: $hold->reportid,
    ]), get_string('riskreview:confirmednotice', 'quizaccess_proctoring'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

$backbutton = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);

// Print report.
if (
    has_capability('quizaccess/proctoring:viewreport', $context, $USER->id) &&
    $cmid != null && $courseid != null
) {
     // Show specific student report.
    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
         // Set backButton.
        $backbutton = new moodle_url(
            '/mod/quiz/accessrule/proctoring/report.php?',
            ['courseid' => $courseid, 'cmid' => $cmid ]
        );
        // Report for this user.
        $sql = "SELECT
                    e.id AS reportid,
                    e.userid AS studentid,
                    e.webcampicture AS webcampicture,
                    e.status AS status,
                    e.timemodified AS timemodified,
                    u.firstname AS firstname,
                    u.lastname AS lastname,
                    u.email AS email,
                    pfw.reportid AS warningid
                FROM
                    {quizaccess_proctoring_logs} e
                INNER JOIN
                    {user} u
                    ON u.id = e.userid
                LEFT JOIN
                    {quizaccess_proctoring_fm_warnings} pfw
                    ON e.courseid = pfw.courseid
                    AND e.quizid = pfw.quizid
                    AND e.userid = pfw.userid
                WHERE
                    e.courseid = :courseid
                    AND e.quizid = :cmid
                    AND u.id = :studentid
                    AND e.id = :reportid ";
    }

    if ($studentid == null && $cmid != null && $courseid != null) {
        // Report for all users.
        $sql = "SELECT DISTINCT
                    e.userid AS studentid,
                    u.firstname AS firstname,
                    u.lastname AS lastname,
                    u.email AS email,
                    pfw.reportid AS warningid,
                    MAX(e.webcampicture) AS webcampicture,
                    MAX(e.id) AS reportid,
                    MAX(e.status) AS status,
                    MAX(e.timemodified) AS timemodified
                FROM
                    {quizaccess_proctoring_logs} e
                INNER JOIN
                    {user} u
                    ON u.id = e.userid
                LEFT JOIN
                    {quizaccess_proctoring_fm_warnings} pfw
                    ON e.courseid = pfw.courseid
                    AND e.quizid = pfw.quizid
                    AND e.userid = pfw.userid
                WHERE
                    e.courseid = :courseid
                    AND e.quizid = :cmid
                GROUP BY
                    e.userid, u.firstname, u.lastname, u.email, pfw.reportid ";
    }

    if ($studentid == null && $cmid != null && $searchkey != null && $submittype == 'clear') {
        // Report for searched users.
        $sql = "SELECT DISTINCT e.userid AS studentid,
                                u.firstname AS firstname,
                                u.lastname AS lastname,
                                u.email AS email,
                                pfw.reportid AS warningid,
                                MAX(e.webcampicture) AS webcampicture,
                                MAX(e.id) AS reportid,
                                MAX(e.status) AS status,
                                MAX(e.timemodified) AS timemodified
                        FROM {quizaccess_proctoring_logs} e
                        INNER JOIN {user} u ON u.id = e.userid
                        LEFT JOIN {quizaccess_proctoring_fm_warnings} pfw ON e.courseid = pfw.courseid
                        AND e.quizid = pfw.quizid
                        AND e.userid = pfw.userid
                        WHERE e.courseid = :courseid
                        AND e.quizid = :quizid
                        GROUP BY e.userid, u.firstname, u.lastname, u.email, pfw.reportid";
    }

    if ($studentid == null && $cmid != null && $searchkey != null && $submittype == 'Search') {
        $sql = "SELECT DISTINCT e.userid AS studentid,
                                u.firstname AS firstname,
                                u.lastname AS lastname,
                                u.email AS email,
                                pfw.reportid AS warningid,
                                MAX(e.webcampicture) AS webcampicture,
                                MAX(e.id) AS reportid,
                                MAX(e.status) AS status,
                                                        MAX(e.timemodified) AS timemodified
                        FROM {quizaccess_proctoring_logs} e
                        INNER JOIN {user} u ON u.id = e.userid
                        LEFT JOIN {quizaccess_proctoring_fm_warnings} pfw
                        ON e.courseid = pfw.courseid
                        AND e.quizid = pfw.quizid
                        AND e.userid = pfw.userid
                        WHERE (e.courseid = :courseid1 AND e.quizid = :quizid1 AND
                              " . $DB->sql_like('u.firstname', ':firstnamelike', false) . ")
                                OR (e.courseid = :courseid2 AND e.quizid = :quizid2 AND "
                                . $DB->sql_like('u.email', ':emaillike', false) . ")
                                OR (e.courseid = :courseid3 AND e.quizid = :quizid3 AND "
                                . $DB->sql_like('u.lastname', ':lastnamelike', false) . ")
                                GROUP BY e.userid, u.firstname, u.lastname, u.email, pfw.reportid";
    }


    if ($studentid == null && $cmid != null && $searchkey != null && $submittype == 'Search') {
        $params = ['firstnamelike' => "%$searchkey%",
                'lastnamelike' => "%$searchkey%",
                'emaillike' => "%$searchkey%",
                'courseid1' => $courseid,
                'courseid2' => $courseid,
                'courseid3' => $courseid,
                'quizid1' => $cmid,
                'quizid2' => $cmid,
                'quizid3' => $cmid];
    } else {
        $params = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'studentid' => $studentid,
            'reportid' => $reportid,
        ];
    }

    // Default newest-first ordering at the SQL level (Requirement 13.1). The final ordering is
    // applied in PHP below because the risk score and violation counts are computed per row in PHP
    // (not available as SQL columns), so a single consistent PHP sort is used for every sort key.
    $sql .= ' ORDER BY timemodified DESC';

    // Fetch every matching record; filtering (name initials), sorting (selected column), and
    // pagination are all applied in PHP so they operate over the full result set consistently.
    $sqlexecuted = $DB->get_records_sql($sql, $params);

       // Print report.
    $islistview = ($studentid == null);
    $rows = [];
    foreach ($sqlexecuted as $info) {
            // Apply the A–Z name-initial filter on the all-users list view (Requirement 13.3).
            if ($islistview && !quizaccess_proctoring_name_matches_initials(
                (string)$info->firstname,
                (string)$info->lastname,
                $firstnameinitial,
                $lastnameinitial
            )) {
                continue;
            }
            $row = [];
            $row['userlink'] = $CFG->wwwroot . '/user/view.php?id=' . $info->studentid . '&course=' . $courseid;
            $row['fullname'] = $info->firstname . ' ' . $info->lastname;
            $row['email'] = $info->email;
            // Use Moodle's locale/timezone-aware date formatting so the proctoring report matches
            // the quiz results report (Requirement 18.1).
            $row['timemodified'] = userdate((int)$info->timemodified);
            // Raw values used only for the PHP-side column sort (Requirement 13.2); harmless in the template.
            $row['sortlastname'] = \core_text::strtolower((string)$info->lastname);
            $row['sortfirstname'] = \core_text::strtolower((string)$info->firstname);
            $row['sorttimemodified'] = (int)$info->timemodified;
            $row['warningicon'] = ($info->warningid == '') ? true : false;
            // Identity Mismatch rendered as a localized Yes/No (Requirement 18.2). A present
            // face-match warning row (non-empty warningid) means the identity did not match.
            $row['identitymismatch'] = quizaccess_proctoring_identity_mismatch_label($info->warningid);
            $row['eventcount'] = $DB->count_records('quizaccess_proctoring_events', [
                'courseid' => $courseid,
                'quizid' => $cmid,
                'userid' => $info->studentid,
            ]);
            $row['eventwarning'] = $row['eventcount'] > 0;
            $risk = quizaccess_proctoring_calculate_attempt_risk(
                (int)$courseid,
                (int)$cmid,
                (int)$info->studentid,
                (int)$info->reportid
            );
            $row['riskscore'] = $risk['score'];
            $row['risklevel'] = $risk['level'];
            $row['riskbadgeclass'] = $risk['badgeclass'];
            $row['timetaken'] = $risk['durationformatted'];
            // Raw values used only for the PHP-side column sort (Requirement 13.2).
            $row['sortriskscore'] = (int)$risk['score'];
            $row['sorteventcount'] = (int)$row['eventcount'];
            $hold = quizaccess_proctoring_get_risk_hold(
                (int)$courseid,
                (int)$cmid,
                (int)$info->studentid,
                (int)$risk['attemptid'],
                (int)$info->reportid
            );
        if ($hold) {
            $cert = quizaccess_proctoring_resolve_certificate_label(
                (int)$courseid,
                (int)$cmid,
                (int)$info->studentid,
                (int)$risk['attemptid'],
                (int)$info->reportid
            );
            if ($cert['label'] !== '') {
                $row['riskholdstatus'] = $cert['label'];
                $row['riskholdactive'] = $cert['state'] === 'held';
            }
        }
            $aireview = quizaccess_proctoring_get_ai_review(
                (int)$courseid,
                (int)$cmid,
                (int)$info->studentid,
                (int)$risk['attemptid'],
                (int)$info->reportid
            );
        if ($aireview) {
            $row['aireview'] = quizaccess_proctoring_format_ai_review_for_template($aireview);
        }

            $viewurl = new moodle_url($PAGE->url, [
                'courseid' => $courseid,
                'quizid' => $cmid,
                'cmid' => $cmid,
                'studentid' => $info->studentid,
                'reportid' => $info->reportid,
            ]);

            // View images is the primary, emphasized action (Requirement 18.3): rendered as a
            // prominent primary button rather than hidden inside the kebab menu.
            $viewbutton = html_writer::link(
                $viewurl,
                $OUTPUT->pix_icon('e/insert_edit_image', '', 'moodle') . ' '
                    . get_string('viewimages', 'quizaccess_proctoring'),
                [
                    'class' => 'btn btn-primary btn-sm',
                    'role' => 'button',
                ]
            );

            $deleteform = '';
        if (has_capability('quizaccess/proctoring:deletecamshots', $context, $USER->id)) {
            $deleteurl = new moodle_url('/mod/quiz/accessrule/proctoring/report.php');
            $deleteparams = [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'studentid' => $info->studentid,
                'reportid' => $info->reportid,
                'logaction' => 'delete',
                'sesskey' => sesskey(),
            ];
            $deleteform = html_writer::start_tag('form', [
                'method' => 'post',
                'action' => $deleteurl->out(false),
                'class' => 'd-inline ml-2',
            ]);
            foreach ($deleteparams as $name => $value) {
                $deleteform .= html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => $name,
                    'value' => $value,
                ]);
            }
            $deleteform .= html_writer::tag(
                'button',
                $OUTPUT->pix_icon('t/delete', '') . ' ' . get_string('delete'),
                [
                    'type' => 'submit',
                    // De-emphasized (Requirement 18.3): a muted link, not a prominent/danger button.
                    // The destructive confirm() guard is retained.
                    'class' => 'btn btn-link btn-sm text-muted p-0',
                    'onclick' => 'return confirm(' . json_encode(get_string(
                        'areyousure_delete_record',
                        'quizaccess_proctoring'
                    )) . ');',
                ]
            );
            $deleteform .= html_writer::end_tag('form');
        }

            // Add rendered HTML to template context: View images primary/emphasized first, Delete
            // de-emphasized after it (Requirement 18.3).
            $row['actionmenu'] = $viewbutton . $deleteform;
            $rows[] = $row;
    }

    // Apply the selected column sort over the full result set (Requirement 13.2). The safe
    // ORDER BY fragment comes from the allowlist helper and is translated into an in-PHP sort so
    // that PHP-computed columns (risk score, violation count) sort consistently with SQL columns
    // (name, date). Unknown/blank sort keys fall back to newest-first (Requirement 13.1).
    $orderby = quizaccess_proctoring_report_order_by($sort, $dir);
    $sortfieldmap = [
        'lastname' => 'sortlastname',
        'firstname' => 'sortfirstname',
        'timemodified' => 'sorttimemodified',
        'riskscore' => 'sortriskscore',
        'eventcount' => 'sorteventcount',
    ];
    $sortpairs = [];
    foreach (explode(',', $orderby) as $fragment) {
        $bits = preg_split('/\s+/', trim($fragment));
        if (empty($bits[0]) || !isset($sortfieldmap[$bits[0]])) {
            continue;
        }
        $direction = (isset($bits[1]) && strtoupper($bits[1]) === 'ASC') ? 'ASC' : 'DESC';
        $sortpairs[] = [$sortfieldmap[$bits[0]], $direction];
    }
    if (!empty($sortpairs)) {
        usort($rows, function ($a, $b) use ($sortpairs) {
            foreach ($sortpairs as [$key, $direction]) {
                $avalue = $a[$key] ?? null;
                $bvalue = $b[$key] ?? null;
                if (is_string($avalue) || is_string($bvalue)) {
                    $cmp = strcmp((string)$avalue, (string)$bvalue);
                } else {
                    $cmp = $avalue <=> $bvalue;
                }
                if ($cmp !== 0) {
                    return $direction === 'ASC' ? $cmp : -$cmp;
                }
            }
            return 0;
        });
    }

    // Paginate in PHP over the filtered/sorted rows.
    $totalrecords = count($rows);
    $rows = array_slice($rows, $offset, $perpage);

    // Build column-sort headers and the A–Z initial bars for the list view.
    $sortkey = strtolower(trim($sort));
    $currentdir = (strtolower(trim($dir)) === 'asc') ? 'asc' : 'desc';
    $preserve = ['courseid' => $courseid, 'cmid' => $cmid];
    if ($submittype === 'Search' && !empty($searchkey)) {
        $preserve['searchKey'] = $searchkey;
        $preserve['submitType'] = 'Search';
    }
    if ($firstnameinitial !== '') {
        $preserve['firstnameinitial'] = $firstnameinitial;
    }
    if ($lastnameinitial !== '') {
        $preserve['lastnameinitial'] = $lastnameinitial;
    }

    $makesortheader = function (string $key, string $labelkey) use ($url, $preserve, $sortkey, $currentdir) {
        $isactive = ($sortkey === $key);
        // Toggle direction when re-clicking the active column, otherwise start ascending.
        $nextdir = ($isactive && $currentdir === 'asc') ? 'desc' : 'asc';
        $sorturl = new moodle_url($url, $preserve + ['sort' => $key, 'dir' => $nextdir, 'page' => 0]);
        return [
            'label' => get_string($labelkey, 'quizaccess_proctoring'),
            'url' => $sorturl->out(false),
            'active' => $isactive,
            'asc' => $isactive && $currentdir === 'asc',
            'desc' => $isactive && $currentdir === 'desc',
        ];
    };

    $sortheaders = [
        'name' => $makesortheader('name', 'user'),
        'date' => $makesortheader('date', 'dateverified'),
        'violations' => $makesortheader('violations', 'suspiciousactivity'),
        'risk' => $makesortheader('risk', 'riskscore'),
    ];

    // A–Z initial bars (Requirement 13.3): one for first name, one for last name.
    $initialbarparams = ['courseid' => $courseid, 'cmid' => $cmid];
    if ($submittype === 'Search' && !empty($searchkey)) {
        $initialbarparams['searchKey'] = $searchkey;
        $initialbarparams['submitType'] = 'Search';
    }
    if ($sortkey !== '') {
        $initialbarparams['sort'] = $sortkey;
        $initialbarparams['dir'] = $currentdir;
    }

    $makeinitialbar = function (string $param, string $selected, array $otherparam)
            use ($url, $initialbarparams) {
        $items = [];
        // "All" resets this initial while preserving the other one.
        $allparams = $initialbarparams + $otherparam;
        $items[] = [
            'label' => get_string('report_filter_all', 'quizaccess_proctoring'),
            'url' => (new moodle_url($url, $allparams + ['page' => 0]))->out(false),
            'active' => ($selected === ''),
        ];
        foreach (range('A', 'Z') as $letter) {
            $isactive = (strtoupper($selected) === $letter);
            $letterparams = $initialbarparams + $otherparam + [$param => $letter, 'page' => 0];
            $items[] = [
                'label' => $letter,
                'url' => (new moodle_url($url, $letterparams))->out(false),
                'active' => $isactive,
            ];
        }
        return $items;
    };

    $firstinitialother = ($lastnameinitial !== '') ? ['lastnameinitial' => $lastnameinitial] : [];
    $lastinitialother = ($firstnameinitial !== '') ? ['firstnameinitial' => $firstnameinitial] : [];

    $templatecontext = (object)[
        'quizname'        => get_string('eprotroringreports', 'quizaccess_proctoring') . $quiz->name,
        'settingsbtn'     => $settingsbtn,
        'settingspageurl'  => $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/proctoringsummary.php?cmid=' . $cmid,
        'proctoringsummary' => get_string('eprotroringreportsdesc', 'quizaccess_proctoring'),
        'url' => $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/report.php',
        'courseid' => $courseid,
        'cmid' => $cmid,
        'searchkey' => ($submittype == "Clear") ? '' : $searchkey,
        'searchplaceholder' => $searchplaceholder,
        'searchbuttontext' => $searchbuttontext,
        'clearbuttontext' => $clearbuttontext,
        'showclearbutton' => $showclearbutton,
        'checkrow' => (!empty($rows)) ? true : false,
        'rows' => $rows,
        'showfilters' => $islistview,
        'sortheaders' => $sortheaders,
        'firstinitialbar' => $makeinitialbar('firstnameinitial', $firstnameinitial, $firstinitialother),
        'lastinitialbar' => $makeinitialbar('lastnameinitial', $lastnameinitial, $lastinitialother),
        'firstinitiallabel' => get_string('report_filter_firstname', 'quizaccess_proctoring'),
        'lastinitiallabel' => get_string('report_filter_lastname', 'quizaccess_proctoring'),
        'backbutton' => preg_replace('/&amp;/', '&', $backbutton),
    ];
    echo $OUTPUT->render_from_template('quizaccess_proctoring/report', $templatecontext);

    // Pagination added.
    $currenturl = new moodle_url(qualified_me());
    // If user search the  specific value.
    if (!empty($searchkey) && empty($submittype)) {
        $currenturl->param('searchKey', $searchkey);
        $currenturl->param('submitType', $submittype);
    }
    $currenturl->param('page', $page);
    $pagingbar = new paging_bar($totalrecords, $page, $perpage, $currenturl);
    echo $OUTPUT->render($pagingbar);
    // Print image results.
    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
        $profileimageurl = quizaccess_proctoring_get_image_url($studentid);
        $redirecturl = new moodle_url('/mod/quiz/accessrule/proctoring/upload_image.php', ['id' => $studentid]);
        $attemptid = (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $reportid]);

        $sql = "SELECT e.id AS reportid,
               e.userid AS studentid,
               e.webcampicture AS webcampicture,
               e.status AS status,
               e.timemodified AS timemodified,
               u.firstname AS firstname,
               u.lastname AS lastname,
               u.email AS email,
               e.awsscore,
               e.awsflag
        FROM {quizaccess_proctoring_logs} e
        INNER JOIN {user} u ON u.id = e.userid
        WHERE e.courseid = :courseid
          AND e.quizid = :cmid
          AND u.id = :studentid
          AND e.deletionprogress = :deletionprogress";
        $params = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'studentid' => $studentid,
            'deletionprogress' => 0,
        ];
        if ($attemptid > 0) {
            $sql .= ' AND e.status = :attemptid';
            $params['attemptid'] = $attemptid;
        } else {
            $sql .= ' AND e.id = :reportid';
            $params['reportid'] = $reportid;
        }
        $sqlexecuted = $DB->get_recordset_sql($sql, $params);

        $user = core_user::get_user($studentid);
        $thresholdvalue = (int) quizaccess_proctoring_get_proctoring_settings('threshold');
        $studentdata = [];
        foreach ($sqlexecuted as $info) {
                $row = [];
                $row['firstname'] = $info->firstname;
                $row['lastname'] = $info->lastname;
                $row['name'] = userdate((int)$info->timemodified);
                $row['image_url'] = $info->webcampicture;
                $row['border_color'] = $info->awsflag == 2 && $info->awsscore >= $thresholdvalue ? 'green' :
                                        ($info->awsflag == 2 && $info->awsscore < $thresholdvalue ? 'red' :
                                        ($info->awsflag == 3 && $info->awsscore < $thresholdvalue ? 'yellow' : 'none'));
                $row['face_match_status'] = quizaccess_proctoring_get_face_match_status_label(
                    (int)$info->awsflag,
                    (int)$info->awsscore
                );
                $row['face_match_status_class'] = quizaccess_proctoring_get_face_match_status_class(
                    (int)$info->awsflag,
                    (int)$info->awsscore,
                    $thresholdvalue
                );
                $row['img_id'] = 'reportid-' . $info->reportid;
                $row['lightbox_data'] = basename($info->webcampicture, '.png');
                $studentdata[] = $row;
        }
        $eventwhere = 'courseid = :courseid AND quizid = :cmid AND userid = :studentid';
        $eventparams = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'studentid' => $studentid,
        ];
        if (!empty($attemptid)) {
            $eventwhere .= ' AND attemptid = :attemptid';
            $eventparams['attemptid'] = $attemptid;
        }
        $riskscore = quizaccess_proctoring_calculate_attempt_risk(
            (int)$courseid,
            (int)$cmid,
            (int)$studentid,
            (int)$reportid
        );
        $hold = quizaccess_proctoring_get_risk_hold(
            (int)$courseid,
            (int)$cmid,
            (int)$studentid,
            (int)$riskscore['attemptid'],
            (int)$reportid
        );
        if ($hold) {
            $riskscore['holdstatus'] = quizaccess_proctoring_get_risk_hold_status_label($hold);
            $riskscore['holdactive'] = (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
            $riskscore['thresholdlabel'] = get_string('riskreview:thresholdlabel', 'quizaccess_proctoring', $hold->threshold);
            $lockout = quizaccess_proctoring_get_active_cheating_lockout(
                (int)$courseid,
                (int)$cmid,
                (int)$studentid,
                time()
            );
            if ($lockout) {
                $riskscore['lockoutuntil'] = get_string(
                    'riskreview:lockoutuntil',
                    'quizaccess_proctoring',
                    userdate((int)$lockout['until'])
                );
            }
            if (
                (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE &&
                    has_capability('quizaccess/proctoring:reviewriskholds', $context, $USER->id)
            ) {
                $riskscore['canreleasehold'] = true;
                $riskscore['releaseurl'] = (new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
                    'courseid' => $courseid,
                    'cmid' => $cmid,
                    'studentid' => $studentid,
                    'reportid' => $reportid,
                    'riskaction' => 'release',
                    'holdid' => $hold->id,
                    'sesskey' => sesskey(),
                ]))->out(false);
                $riskscore['canconfirmhold'] = true;
                $riskscore['confirmurl'] = (new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
                    'courseid' => $courseid,
                    'cmid' => $cmid,
                    'studentid' => $studentid,
                    'reportid' => $reportid,
                    'riskaction' => 'confirm',
                    'holdid' => $hold->id,
                    'sesskey' => sesskey(),
                ]))->out(false);
            }
        }

        $aireviewdata = null;
        $aireview = quizaccess_proctoring_get_ai_review(
            (int)$courseid,
            (int)$cmid,
            (int)$studentid,
            (int)$riskscore['attemptid'],
            (int)$reportid
        );
        if ($aireview) {
            $aireviewdata = quizaccess_proctoring_format_ai_review_for_template($aireview);
        }

        // Plain-language session summary (Requirement 15.1): a short, telemetry-free
        // description a reviewer can read without parsing raw event detail.
        $sessionsummary = quizaccess_proctoring_build_session_summary($riskscore, $aireview ?: false);

        $eventrecords = $DB->get_records_select(
            'quizaccess_proctoring_events',
            $eventwhere,
            $eventparams,
            'timemodified DESC',
            'id, eventtype, eventdetail, pagevisibility, currenturl, screenshoturl, timemodified',
            0,
            200
        );
        $eventreviews = [];
        if (!empty($eventrecords)) {
            [$eventsql, $eventsqlparams] = $DB->get_in_or_equal(
                array_keys($eventrecords),
                SQL_PARAMS_NAMED,
                'airevent'
            );
            $reviewrecords = $DB->get_records_select(
                'quizaccess_proctoring_ai_reviews',
                "eventid {$eventsql} AND reviewtype = :reviewtype",
                $eventsqlparams + ['reviewtype' => 'event'],
                'id DESC'
            );
            foreach ($reviewrecords as $reviewrecord) {
                $eventid = (int)$reviewrecord->eventid;
                if (!isset($eventreviews[$eventid])) {
                    $eventreviews[$eventid] = quizaccess_proctoring_format_ai_review_for_template($reviewrecord);
                }
            }
        }
        $events = [];
        foreach ($eventrecords as $event) {
            $eventreview = $eventreviews[(int)$event->id] ?? null;
            $events[] = [
                'timemodified' => userdate((int)$event->timemodified),
                'eventtype' => quizaccess_proctoring_get_event_label($event->eventtype),
                'eventdetail' => quizaccess_proctoring_format_event_detail($event->eventdetail),
                'pagevisibility' => $event->pagevisibility,
                'currenturl' => $event->currenturl,
                'screenshoturl' => $event->screenshoturl,
                'hasscreenshot' => !empty($event->screenshoturl),
                'eventaireview' => $eventreview,
                'haseventaireview' => !empty($eventreview),
            ];
        }
        $analyzeurl = new moodle_url('/mod/quiz/accessrule/proctoring/analyzeimage.php');
        $userimageurl = quizaccess_proctoring_get_image_url($user->id);
        if (!$userimageurl) {
            $userimageurl = $OUTPUT->image_url('u/f2');
        }
        $templatecontext = (object)[
            'issiteadmin' => (is_siteadmin() && !$profileimageurl ? true : false),
            'redirecturl' => $redirecturl,
            'data' => $studentdata,
            'userimageurl' => $userimageurl,
            'firstname' => $info->firstname,
            'lastname' => $info->lastname,
            'email' => $info->email,
            'fcmethod' => quizaccess_proctoring_is_facematch_method_enabled($fcmethod),
            'analyzeurl' => $analyzeurl->out(false),
            'analyzecourseid' => $courseid,
            'analyzecmid' => $cmid,
            'analyzestudentid' => $studentid,
            'analyzereportid' => $reportid,
            'sesskey' => sesskey(),
            'riskscore' => $riskscore,
            'sessionsummary' => $sessionsummary,
            'hassessionsummary' => ($sessionsummary !== ''),
            'aireview' => $aireviewdata,
            'events' => $events,
            'hasevents' => !empty($events),
        ];
        echo $OUTPUT->render_from_template('quizaccess_proctoring/studentreport', $templatecontext);
    }
} else {
    echo $OUTPUT->notify(get_string('notpermissionreport', 'quizaccess_proctoring'), 'notifyproblem');
}

echo $OUTPUT->footer();
