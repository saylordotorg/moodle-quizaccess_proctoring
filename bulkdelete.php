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
 * Bulk Delete for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/lib/tablelib.php');
require_once(__DIR__ . '/classes/additional_settings_helper.php');
use quizaccess_proctoring\additional_settings_helper;

// Get parameters.
$cmid = required_param('cmid', PARAM_INT);
$type = required_param('type', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    throw new moodle_exception('invalidrequest', 'error');
}
require_sesskey();

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, true, $cm);

// Make sure debugging is not interfering with redirection.
$context = context_module::instance($cmid, MUST_EXIST);
// Ensure the user has the required capability to delete camshots.
require_capability('quizaccess/proctoring:deletecamshots', $context);

$params = [
    'cmid' => $cmid,
    'type' => $type,
    'id' => $id,
];

// Check the type and prepare URL for redirect.
if ($type == 'course' || $type == 'quiz') {
    $helper = new additional_settings_helper();
    $rowids = [];

    if ($type == 'course') {
        if ((int)$id !== (int)$course->id) {
            throw new moodle_exception('invalidrequest', 'error');
        }

        $camshotdata = $helper->searchbycourseid((int)$course->id);
        $targetcmids = [];
        foreach ($camshotdata as $row) {
            $rowids[] = $row->id;
            $targetcmids[(int)$row->quizid] = (int)$row->quizid;
        }
        $camshotdata->close();

        foreach ($targetcmids as $targetcmid) {
            $targetcontext = context_module::instance($targetcmid, MUST_EXIST);
            require_capability('quizaccess/proctoring:deletecamshots', $targetcontext);
        }
    } else if ($type == 'quiz') {
        [$targetcourse, $targetcm] = get_course_and_cm_from_cmid($id, 'quiz');
        if ((int)$targetcourse->id !== (int)$course->id) {
            throw new moodle_exception('invalidrequest', 'error');
        }

        $targetcontext = context_module::instance($targetcm->id, MUST_EXIST);
        require_capability('quizaccess/proctoring:deletecamshots', $targetcontext);

        $camshotdata = $helper->searchbyquizid((int)$targetcm->id);
        foreach ($camshotdata as $row) {
            $rowids[] = $row->id;
        }
        $camshotdata->close();
    }

    if (empty($rowids)) {
        throw new moodle_exception('nodata', 'quizaccess_proctoring');
    }

    $rowidstring = implode(',', $rowids);
    $helper->deletelogs($rowidstring);

    // Redirect before any output is made.
    $params = [
        'cmid' => $cmid,
    ];
    $url = new moodle_url('/mod/quiz/accessrule/proctoring/proctoringsummary.php', $params);
    redirect($url, get_string('settings:deleteallsuccess', 'quizaccess_proctoring'), -11, 'success');
} else {
    // Invalid type, show error message.
    throw new moodle_exception('invalidtype', 'quizaccess_proctoring');
}
