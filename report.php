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

require_once(__DIR__.'/../../../../config.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->libdir.'/tablelib.php');

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

$analyzebtn = get_string('analyzbtn', 'quizaccess_proctoring');
$analyzebtnconfirm = get_string('analyzbtnconfirm', 'quizaccess_proctoring');
$searchplaceholder = get_string('report_search_placeholder', 'quizaccess_proctoring');
$searchbuttontext = get_string('report_search_submit', 'quizaccess_proctoring');
$clearbuttontext = get_string('report_search_clear', 'quizaccess_proctoring');


// Context and validation.
$context = context_module::instance($cmid, MUST_EXIST);
require_capability('quizaccess/proctoring:viewreport', $context);

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, true, $cm);

// Course and quiz data.
$coursedata = $DB->get_record('course', ['id' => $courseid]);
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

/**
 * Builds one overview row for the student report.
 *
 * @param string $label Report row label.
 * @param int $count Number of matching events.
 * @return array Template row data.
 */
function quizaccess_proctoring_build_overview_row(string $label, int $count): array {
    return [
        'label' => $label,
        'status' => $count > 0
            ? get_string('reportoverview:logfound', 'quizaccess_proctoring', $count)
            : get_string('reportoverview:nologfound', 'quizaccess_proctoring'),
        'statusclass' => $count > 0
            ? 'proctoring-overview-status proctoring-overview-status-warning'
            : 'proctoring-overview-status proctoring-overview-status-ok',
    ];
}

// Page setup.
$PAGE->set_url($url);
$PAGE->set_pagelayout('course');
$PAGE->set_title($coursedata->shortname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->set_heading($coursedata->fullname . ': ' . get_string('pluginname', 'quizaccess_proctoring'));
$PAGE->navbar->add(get_string('quizaccess_proctoring', 'quizaccess_proctoring'), $url);
$PAGE->requires->js_call_amd('quizaccess_proctoring/lightbox2', 'init', [$fcmethod , [
    'analyzebtn' => $analyzebtn,
    'analyzebtnconfirm' => $analyzebtnconfirm,
]]);
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');
// Add navbar for studnet report.
if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
    $PAGE->navbar->add(get_string('studentreport', 'quizaccess_proctoring') . " - $studentid", $url);
}

// Button logic.
$settingsbtn = has_capability('quizaccess/proctoring:viewreport', $context, $USER->id);
$showclearbutton = ($submittype === 'Search' && !empty($searchkey));

if (has_capability('quizaccess/proctoring:deletecamshots', $context, $USER->id) && $studentid != null
    && $cmid != null && $courseid != null && $reportid != null&& !empty($logaction)) {

        $DB->delete_records('quizaccess_proctoring_logs', [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $studentid,
        ]);
        $DB->delete_records('quizaccess_proctoring_fm_warnings', [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $studentid,
        ]);
        $DB->delete_records('quizaccess_proctoring_events', [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $studentid,
        ]);
        $fs = get_file_storage();
        foreach (['picture', 'violation_screenshot'] as $filearea) {
            $params = [
                'userid' => $studentid,
                'contextid' => $context->id,
                'component' => 'quizaccess_proctoring',
                'filearea'  => $filearea,
            ];

            $usersfile = $DB->get_records('files', $params);
            foreach ($usersfile as $file) {
                $fileinfo = [
                    'component' => 'quizaccess_proctoring',
                    'filearea' => $filearea,
                    'itemid' => $file->itemid,
                    'contextid' => $context->id,
                    'filepath' => '/',
                    'filename' => $file->filename,
                ];
                $storedfile = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'],
                            $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
                if ($storedfile) {
                    $storedfile->delete();
                }
            }
        }

        redirect(new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]), 'Images deleted!', -11);
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
    $cmid != null && $courseid != null) {
     // Show specific student report.
    if ($studentid != null && $cmid != null && $courseid != null && $reportid != null) {
         // Set backButton.
        $backbutton = new moodle_url('/mod/quiz/accessrule/proctoring/report.php?',
                    ['courseid' => $courseid , 'cmid' => $cmid ]);
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

        // Calculate total records for pagination.
        $totalrecordssql = "SELECT COUNT(DISTINCT e.userid)
                            FROM {quizaccess_proctoring_logs} e
                            INNER JOIN {user} u ON u.id = e.userid
                            LEFT JOIN {quizaccess_proctoring_fm_warnings} pfw
                            ON e.courseid = pfw.courseid AND e.quizid = pfw.quizid AND e.userid = pfw.userid
                            WHERE (e.courseid = :courseid1 AND e.quizid = :quizid1 AND
                            " . $DB->sql_like('u.firstname', ':firstnamelike', false) . ")
                            OR (e.courseid = :courseid2 AND e.quizid = :quizid2 AND
                            " . $DB->sql_like('u.email', ':emaillike', false) . ")
                            OR (e.courseid = :courseid3 AND e.quizid = :quizid3 AND "
                            . $DB->sql_like('u.lastname', ':lastnamelike', false) . ")";
        $totalrecords = $DB->count_records_sql($totalrecordssql, $params);

        // Fetch paginated results.
        $sqlexecuted = $DB->get_records_sql($sql, $params, $offset, $perpage);
    } else {
        $params = [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'studentid' => $studentid,
            'reportid' => $reportid,
        ];
        $totalrecordssql = "SELECT COUNT(1) FROM ({$sql}) as subquery";
        $totalrecords = $DB->count_records_sql($totalrecordssql, $params);
        $sqlexecuted = $DB->get_records_sql($sql, $params, $offset, $perpage);
    }

       // Print report.
    $rows = [];
    foreach ($sqlexecuted as $info) {
            $row = [];
            $row['userlink'] = $CFG->wwwroot.'/user/view.php?id=' . $info->studentid . '&course=' . $courseid;
            $row['fullname'] = $info->firstname . ' ' . $info->lastname;
            $row['email'] = $info->email;
            $row['timemodified'] = date('Y/M/d H:i:s', $info->timemodified);
            $row['warningicon'] = ($info->warningid == '') ? true : false;
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
            $hold = quizaccess_proctoring_get_risk_hold(
                (int)$courseid,
                (int)$cmid,
                (int)$info->studentid,
                (int)$risk['attemptid'],
                (int)$info->reportid
            );
            if ($hold) {
                $row['riskholdstatus'] = quizaccess_proctoring_get_risk_hold_status_label($hold);
                $row['riskholdactive'] = (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
            }

            $actionmenu = new action_menu();
            $actionmenu->set_kebab_trigger(get_string('actions'));

            $viewurl = new moodle_url($PAGE->url, [
                'courseid' => $courseid,
                'quizid' => $cmid,
                'cmid' => $cmid,
                'studentid' => $info->studentid,
                'reportid' => $info->reportid,
            ]);

            $viewaction = new action_menu_link_secondary(
                $viewurl,
                new pix_icon('e/insert_edit_image', get_string('viewimages', 'quizaccess_proctoring'), 'moodle'),
                get_string('viewimages', 'quizaccess_proctoring')
            );
            $actionmenu->add($viewaction);

            $deleteurl = new moodle_url($PAGE->url, [
                'courseid' => $courseid,
                'quizid' => $cmid,
                'cmid' => $cmid,
                'studentid' => $info->studentid,
                'reportid' => $info->reportid,
                'logaction' => 'delete',
                'sesskey' => sesskey(),
            ]);

            // Prepare attributes for the delete action.
            $attributes = [
                'data-confirmation' => 'modal',
                'data-confirmation-type' => 'delete',
                'data-confirmation-title-str' => json_encode(['delete', 'core']),
                'data-confirmation-content-str' => json_encode(['areyousure_delete_record', 'quizaccess_proctoring']),
                'data-confirmation-yes-button-str' => json_encode(['delete', 'core']),
                'data-confirmation-action-url' => $deleteurl->out(false),
                'data-confirmation-destination' => $deleteurl->out(false),
                'class' => 'text-danger',
            ];

            $deleteaction = new action_menu_link_secondary(
                $deleteurl,
                new pix_icon('t/delete', '', 'moodle'),
                get_string('delete'),
                $attributes
            );

            $actionmenu->add($deleteaction);

            // Add rendered HTML to template context.
            $row['actionmenu'] = $OUTPUT->render($actionmenu);
            $rows[] = $row;
    }
    $templatecontext = (object)[
        'quizname'        => get_string('eprotroringreports', 'quizaccess_proctoring') . $quiz->name,
        'settingsbtn'     => $settingsbtn,
        'settingspageurl'  => $CFG->wwwroot.'/mod/quiz/accessrule/proctoring/proctoringsummary.php?cmid='.$cmid,
        'proctoringsummary' => get_string('eprotroringreportsdesc', 'quizaccess_proctoring'),
        'url' => $CFG->wwwroot. '/mod/quiz/accessrule/proctoring/report.php',
        'courseid' => $courseid,
        'cmid' => $cmid,
        'searchkey' => ($submittype == "Clear") ? '' : $searchkey,
        'searchplaceholder' => $searchplaceholder,
        'searchbuttontext' => $searchbuttontext,
        'clearbuttontext' => $clearbuttontext,
        'showclearbutton' => $showclearbutton,
        'checkrow' => (!empty($row)) ? true : false,
        'rows' => $rows,
        'backbutton' => preg_replace('/&amp;/', '&', $backbutton),
    ];
    echo $OUTPUT->render_from_template('quizaccess_proctoring/report', $templatecontext);

    // Pagination added.
    $currenturl = new moodle_url(qualified_me());
    // If user search the  specific value.
    if (!empty($searchkey) && empty($submittype) ) {
        $currenturl->param('searchKey' , $searchkey);
        $currenturl->param('submitType' , $submittype);
    }
    $currenturl->param('page' , $page);
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
                $row['image_url'] = $info->webcampicture;
                $row['border_color'] = $info->awsflag == 2 && $info->awsscore > $thresholdvalue ? 'green' :
                                        ($info->awsflag == 2 && $info->awsscore < $thresholdvalue ? 'red' :
                                        ($info->awsflag == 3 && $info->awsscore < $thresholdvalue ? 'yellow' : 'none'));
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
            if ((int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE &&
                    has_capability('quizaccess/proctoring:reviewriskholds', $context, $USER->id)) {
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

        $overviewcounts = [
            'focus' => $DB->count_records_select(
                'quizaccess_proctoring_events',
                $eventwhere . " AND eventtype IN ('focus_lost', 'tab_hidden', 'page_exit')",
                $eventparams
            ),
            'screen' => $DB->count_records_select(
                'quizaccess_proctoring_events',
                $eventwhere . " AND eventtype IN ('screen_marker_missing', 'screen_share_stopped')",
                $eventparams
            ),
            'clipboard' => $DB->count_records_select(
                'quizaccess_proctoring_events',
                $eventwhere . " AND eventtype IN ('clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu')",
                $eventparams
            ),
            'f12' => 0,
            'aitool' => $DB->count_records_select(
                'quizaccess_proctoring_events',
                $eventwhere . ' AND eventtype = :eventtype_aitool',
                $eventparams + ['eventtype_aitool' => 'possible_ai_tool']
            ),
        ];

        $shortcutrecords = $DB->get_records_select(
            'quizaccess_proctoring_events',
            $eventwhere . ' AND eventtype = :eventtype_shortcut',
            $eventparams + ['eventtype_shortcut' => 'shortcut'],
            '',
            'id, eventdetail'
        );
        foreach ($shortcutrecords as $shortcutrecord) {
            if (quizaccess_proctoring_event_has_shortcut($shortcutrecord->eventdetail, 'F12')) {
                $overviewcounts['f12']++;
            }
        }

        $eventrecords = $DB->get_records_select(
            'quizaccess_proctoring_events',
            $eventwhere,
            $eventparams,
            'timemodified DESC',
            'id, eventtype, eventdetail, pagevisibility, currenturl, screenshoturl, timemodified',
            0,
            200
        );
        $events = [];
        foreach ($eventrecords as $event) {
            $events[] = [
                'timemodified' => date('Y/M/d H:i:s', $event->timemodified),
                'eventtype' => quizaccess_proctoring_get_event_label($event->eventtype),
                'eventdetail' => quizaccess_proctoring_format_event_detail($event->eventdetail),
                'pagevisibility' => $event->pagevisibility,
                'currenturl' => $event->currenturl,
                'screenshoturl' => $event->screenshoturl,
                'hasscreenshot' => !empty($event->screenshoturl),
            ];
        }
        $overviewrows = [
            quizaccess_proctoring_build_overview_row(
                get_string('reportoverview:webcamenabled', 'quizaccess_proctoring'),
                count($studentdata) > 0 ? 0 : 1
            ),
            quizaccess_proctoring_build_overview_row(
                get_string('reportoverview:screenfocuslost', 'quizaccess_proctoring'),
                $overviewcounts['focus']
            ),
            quizaccess_proctoring_build_overview_row(
                get_string('reportoverview:screenshareissue', 'quizaccess_proctoring'),
                $overviewcounts['screen']
            ),
            quizaccess_proctoring_build_overview_row(
                get_string('reportoverview:clipboardactivity', 'quizaccess_proctoring'),
                $overviewcounts['clipboard']
            ),
            quizaccess_proctoring_build_overview_row(
                get_string('reportoverview:f12pressed', 'quizaccess_proctoring'),
                $overviewcounts['f12']
            ),
            quizaccess_proctoring_build_overview_row(
                get_string('reportoverview:possibleaitool', 'quizaccess_proctoring'),
                $overviewcounts['aitool']
            ),
        ];

        $analyzeparam = ['studentid' => $studentid, 'cmid' => $cmid, 'courseid' => $courseid, 'reportid' => $reportid];
        $analyzeurl = new moodle_url('/mod/quiz/accessrule/proctoring/analyzeimage.php', $analyzeparam);
        $analyzeurl = preg_replace('/&amp;/', '&', $analyzeurl);
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
            'analyzeurl' => $analyzeurl,
            'riskscore' => $riskscore,
            'overviewrows' => $overviewrows,
            'events' => $events,
            'hasevents' => !empty($events),
        ];
        echo $OUTPUT->render_from_template('quizaccess_proctoring/studentreport', $templatecontext);
    }
} else {
    echo $OUTPUT->notify(get_string('notpermissionreport', 'quizaccess_proctoring'), 'notifyproblem');
}

echo $OUTPUT->footer();
