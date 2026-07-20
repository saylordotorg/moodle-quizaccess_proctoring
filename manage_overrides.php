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
 * Reviewer admin page for managing per-student proctoring overrides.
 *
 * Renders a capability-gated create/edit form (via {@see \quizaccess_proctoring\form\override_form})
 * and a confirmed revoke action, plus the reviewer coordination view: a list of the proctoring
 * overrides applicable to this quiz (quiz-scoped or course-scoped) showing the target student, the
 * affected requirements with their tri-state values, and a "native quiz override exists" indicator
 * cross-referenced against Moodle core's {@see quiz_overrides} table (per-user and per-group). All
 * writes are delegated to {@see \quizaccess_proctoring\local\override_manager}.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

use quizaccess_proctoring\form\override_form;
use quizaccess_proctoring\local\override_manager;
use quizaccess_proctoring\local\override_resolver;

// Parameters.
$cmid = required_param('cmid', PARAM_INT);
$action = optional_param('action', 'list', PARAM_ALPHA);
$overrideid = optional_param('overrideid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if (!in_array($action, ['list', 'create', 'edit', 'revoke'], true)) {
    $action = 'list';
}

// Context and access control.
$context = context_module::instance($cmid, MUST_EXIST);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, false, $cm);
require_capability('quizaccess/proctoring:manageoverrides', $context);

$courseid = (int)$course->id;
$coursecontext = context_course::instance($courseid);
$component = 'quizaccess_proctoring';

// Page setup.
$baseurl = new moodle_url('/mod/quiz/accessrule/proctoring/manage_overrides.php', ['cmid' => $cmid]);
$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('override_manageheading', $component));
$PAGE->set_heading(format_string($course->fullname));

// Build the selector maps shared by the form: enrolled students and quizzes in the course.
$students = [];
foreach (get_enrolled_users($coursecontext) as $enrolled) {
    $students[(int)$enrolled->id] = fullname($enrolled);
}
$quizzes = [];
foreach (get_all_instances_in_course('quiz', $course) as $quizinstance) {
    $quizzes[(int)$quizinstance->id] = format_string($quizinstance->name);
}

$customdata = [
    'courseid' => $courseid,
    'cmid' => $cmid,
    'context' => $context,
    'students' => $students,
    'quizzes' => $quizzes,
];

// ---------------------------------------------------------------------------
// Create / edit an override.
// ---------------------------------------------------------------------------
if ($action === 'create' || $action === 'edit') {
    $actionurl = new moodle_url($baseurl, ['action' => $action, 'overrideid' => $overrideid]);
    $mform = new override_form($actionurl, $customdata);

    if ($mform->is_cancelled()) {
        redirect($baseurl);
    }

    if ($data = $mform->get_data()) {
        $editid = isset($data->overrideid) ? (int)$data->overrideid : 0;
        if ($editid > 0) {
            override_manager::edit($context, $editid, $data);
            $notice = get_string('override_updatednotice', $component);
        } else {
            override_manager::create($context, $data);
            $notice = get_string('override_creatednotice', $component);
        }
        redirect($baseurl, $notice, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Preload an existing override for editing (only on the initial GET, not on a failed submit).
    if ($action === 'edit' && $overrideid > 0 && !$mform->is_submitted()) {
        $existing = $DB->get_record(
            'quizaccess_proctoring_overrides',
            ['id' => $overrideid, 'courseid' => $courseid],
            '*',
            MUST_EXIST
        );
        $existing->cmid = $cmid;
        $existing->courseid = $courseid;
        // Populate the form's hidden routing field so a submitted edit is not misrouted as a create.
        $existing->overrideid = (int)$existing->id;
        // A null/empty expiry must present as "no expiry" (0) to the optional date_time_selector.
        $existing->expiry = empty($existing->expiry) ? 0 : (int)$existing->expiry;
        $mform->set_data($existing);
    }

    $headingkey = ($action === 'edit') ? 'override_editheading' : 'override_createheading';

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($headingkey, $component));
    $mform->display();
    echo $OUTPUT->footer();
    return;
}

// ---------------------------------------------------------------------------
// Revoke an override (confirmed POST).
// ---------------------------------------------------------------------------
if ($action === 'revoke') {
    if ($overrideid <= 0) {
        redirect($baseurl);
    }

    $override = $DB->get_record(
        'quizaccess_proctoring_overrides',
        ['id' => $overrideid, 'courseid' => $courseid],
        '*',
        MUST_EXIST
    );

    if ($confirm && confirm_sesskey()) {
        override_manager::revoke($context, $overrideid);
        redirect(
            $baseurl,
            get_string('override_revokednotice', $component),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $confirmurl = new moodle_url($baseurl, [
        'action' => 'revoke',
        'overrideid' => $overrideid,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('override_manageheading', $component));
    echo $OUTPUT->confirm(
        get_string('override_revokeconfirm', $component),
        $confirmurl,
        $baseurl
    );
    echo $OUTPUT->footer();
    return;
}

// ---------------------------------------------------------------------------
// Default: coordination view.
//
// List the proctoring overrides applicable to this quiz (either quiz-scoped to this quiz's
// instance or course-scoped with quizid = 0), showing the target student, the affected
// requirements with their states, and a cross-reference indicator for any native Moodle quiz
// override (from core `quiz_overrides`) that exists for the same student + quiz.
//
// Note: `quiz_overrides.quiz` and `quizaccess_proctoring_overrides.quizid` both store the quiz
// INSTANCE id (not the course-module id), so they can be compared directly.
// ---------------------------------------------------------------------------
$quizinstanceid = (int)$cm->instance;

$overrides = $DB->get_records_select(
    'quizaccess_proctoring_overrides',
    'courseid = :courseid AND (quizid = :quizid OR quizid = 0)',
    ['courseid' => $courseid, 'quizid' => $quizinstanceid],
    'timecreated DESC'
);

// Map tri-state values to their display labels once.
$statelabels = [
    override_resolver::STATE_INHERIT => get_string('override_state_inherit', $component),
    override_resolver::STATE_DISABLED => get_string('override_state_disabled', $component),
    override_resolver::STATE_ENABLED => get_string('override_state_enabled', $component),
];

// Map each override tri-state column to its display label (used for the affected-requirements
// summary column).
$requirementcolumns = [
    'captchastate' => get_string('override_captchastate', $component),
    'webcamstate' => get_string('override_webcamstate', $component),
    'idverificationstate' => get_string('override_idverificationstate', $component),
    'screensharestate' => get_string('override_screensharestate', $component),
    'multimonitorstate' => get_string('override_multimonitorstate', $component),
    'phonedetectionstate' => get_string('override_phonedetectionstate', $component),
];

// All quiz instance ids in the course, used for course-scoped native-override checks.
$coursequizids = array_map('intval', array_keys($quizzes));

/**
 * Determine whether a native Moodle quiz override exists for a student on any of the given quizzes.
 *
 * Checks both per-user overrides (`quiz_overrides.userid`) and per-group overrides
 * (`quiz_overrides.groupid`) for groups the student belongs to in the course.
 *
 * @param int $userid Target student id.
 * @param int[] $quizinstanceids Quiz instance ids to check.
 * @return bool True when at least one native quiz override applies to the student.
 */
$nativeoverrideexists = function (int $userid, array $quizinstanceids) use ($DB, $courseid): bool {
    if (empty($quizinstanceids)) {
        return false;
    }

    [$quizsql, $quizparams] = $DB->get_in_or_equal($quizinstanceids, SQL_PARAMS_NAMED, 'quiz');

    // Per-user native override.
    $userparams = array_merge($quizparams, ['userid' => $userid]);
    if ($DB->record_exists_select('quiz_overrides', "quiz $quizsql AND userid = :userid", $userparams)) {
        return true;
    }

    // Per-group native override: only relevant if the student belongs to at least one group.
    $groupids = array_keys(groups_get_all_groups($courseid, $userid));
    if (!empty($groupids)) {
        [$groupsql, $groupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
        $params = array_merge($quizparams, $groupparams);
        if ($DB->record_exists_select('quiz_overrides', "quiz $quizsql AND groupid $groupsql", $params)) {
            return true;
        }
    }

    return false;
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('override_manageheading', $component));

// Create button.
$createurl = new moodle_url($baseurl, ['action' => 'create']);
echo $OUTPUT->single_button($createurl, get_string('override_createbutton', $component), 'get');

if (empty($overrides)) {
    echo $OUTPUT->notification(get_string('override_none', $component), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    return;
}

$table = new html_table();
$table->head = [
    get_string('override_targetstudent', $component),
    get_string('override_targetquiz', $component),
    get_string('override_nativeoverride', $component),
    get_string('override_affectedrequirements', $component),
    get_string('override_captchastate', $component),
    get_string('override_webcamstate', $component),
    get_string('override_idverificationstate', $component),
    get_string('override_screensharestate', $component),
    get_string('override_multimonitorstate', $component),
    get_string('override_phonedetectionstate', $component),
    get_string('override_expiry', $component),
    get_string('override_status', $component),
    get_string('override_actions', $component),
];
$table->data = [];

foreach ($overrides as $override) {
    $student = $DB->get_record('user', ['id' => $override->userid]);
    $studentname = $student ? fullname($student) : (string)$override->userid;

    $quizscope = ((int)$override->quizid === 0)
        ? get_string('override_scopecoursewide', $component)
        : ($quizzes[(int)$override->quizid] ?? (string)$override->quizid);

    $statecell = function ($value) use ($statelabels) {
        return $statelabels[(int)$value] ?? (string)$value;
    };

    // Summarise which requirements this override actually affects (non-inherit states only).
    $affected = [];
    foreach ($requirementcolumns as $column => $label) {
        $state = (int)$override->$column;
        if ($state !== override_resolver::STATE_INHERIT) {
            $affected[] = $label . ': ' . ($statelabels[$state] ?? (string)$state);
        }
    }
    $affectedcell = empty($affected)
        ? get_string('override_affectednone', $component)
        : implode(html_writer::empty_tag('br'), $affected);

    // Native quiz override indicator: for a quiz-scoped override check that exact quiz; for a
    // course-scoped override (quizid = 0) check every quiz in the course for the student.
    $native = ((int)$override->quizid === 0)
        ? $nativeoverrideexists((int)$override->userid, $coursequizids)
        : $nativeoverrideexists((int)$override->userid, [(int)$override->quizid]);
    $nativecell = $native ? get_string('override_nativeexists', $component) : '-';

    $expirycell = empty($override->expiry) ? '-' : userdate((int)$override->expiry);

    if ((int)$override->revoked === 1) {
        $status = get_string('override_status_revoked', $component);
        $actions = '';
    } else {
        $status = get_string('override_status_active', $component);
        $editurl = new moodle_url($baseurl, ['action' => 'edit', 'overrideid' => (int)$override->id]);
        $revokeurl = new moodle_url($baseurl, ['action' => 'revoke', 'overrideid' => (int)$override->id]);
        $actions = html_writer::link($editurl, get_string('edit'))
            . ' | '
            . html_writer::link($revokeurl, get_string('override_revoke', $component));
    }

    $table->data[] = [
        $studentname,
        $quizscope,
        $nativecell,
        $affectedcell,
        $statecell($override->captchastate),
        $statecell($override->webcamstate),
        $statecell($override->idverificationstate),
        $statecell($override->screensharestate),
        $statecell($override->multimonitorstate),
        $statecell($override->phonedetectionstate),
        $expirycell,
        $status,
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
