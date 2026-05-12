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
 * Library function for the quizaccess_proctoring plugin.
 *
 * @package     quizaccess_proctoring
 * @author      Saylor Academy <saylor.org>
 * @copyright   2024 Saylor Academy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/filelib.php'); // Required for Moodle's cURL class.

$token = "";

/**
 * Serves files for the quizaccess proctoring plugin.
 *
 * This function handles the process of serving files that are stored in the file storage for the quizaccess proctoring plugin.
 * It retrieves the requested file based on the file area, item ID, and path, and then sends the file to the user.
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module object.
 * @param context $context The context within which the file is being served.
 * @param string $filearea The name of the file area where the file is stored.
 * @param array $args Extra arguments used to locate the file, including itemid and the path.
 * @param bool $forcedownload Whether or not the file should be forced to download.
 * @param array $options Additional options affecting the file serving.
 *
 * @return bool Returns false if the file cannot be found.
 */
function quizaccess_proctoring_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    $itemid = array_shift($args);
    $filename = array_pop($args);

    if (!$args) {
        $filepath = '/';
    } else {
        $filepath = '/' . implode('/', $args) . '/';
    }

    $fs = get_file_storage();

    $file = $fs->get_file($context->id, 'quizaccess_proctoring', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Returns the image URL of a specific user from the quizaccess proctoring plugin.
 *
 * This function retrieves the image associated with a specific user by searching the `user_photo`
 * file area within the context of the system.
 * It then constructs and returns the image URL for that user, if the image exists.
 *
 * @param int $userid The user ID for which the image URL is to be fetched.
 *
 * @return string|false The image URL if the image is found, or false if no image is found.
 */
function quizaccess_proctoring_get_image_url($userid) {
    $context = context_system::instance();
    $fs = get_file_storage();

    if ($files = $fs->get_area_files($context->id, 'quizaccess_proctoring', 'user_photo')) {
        foreach ($files as $file) {
            if ($userid == $file->get_itemid() && $file->get_filename() != '.') {
                $fileurl = moodle_url::make_pluginfile_url(
                    $file->get_contextid(), $file->get_component(), $file->get_filearea(),
                    $file->get_itemid(), $file->get_filepath(), $file->get_filename(), true);
                return $fileurl->out(false); // Properly formatted URL without trailing slash.
            }
        }
    }

    return false;
}


/**
 * Returns the image file of a specific user.
 *
 * This function retrieves the image file associated with a specific user by searching the `user_photo` file area
 * in the `quizaccess_proctoring` context. If an image is found, it also deletes the corresponding records from
 * the `quizaccess_proctoring_user_images` and `quizaccess_proctoring_face_images` tables, ensuring that the
 * image is removed from the database and the related image records are cleaned up.
 *
 * @param int $userid The user ID for which the image file is to be fetched.
 *
 * @return mixed The image file object if the image is found, or false if no image is found for the user.
 */
function quizaccess_proctoring_get_image_file($userid) {
    global $DB;
    $context = context_system::instance();

    $fs = get_file_storage();
    if ($files = $fs->get_area_files($context->id, 'quizaccess_proctoring', 'user_photo')) {

        foreach ($files as $file) {
            if ($userid == $file->get_itemid() && $file->get_filename() != '.') {

                // Get the record ID from the database.
                $recordid = $DB->get_field('quizaccess_proctoring_user_images', 'id', ['user_id' => $userid]);

                // Delete the record from the database.
                $DB->delete_records('quizaccess_proctoring_user_images', ['user_id' => $userid]);

                // Delete associated row from proctoring_face_images table.
                $DB->delete_records('quizaccess_proctoring_face_images', ['parentid' => $recordid]);

                return $file;
            }
        }
    }
    return false;
}


/**
 * Updates match result.
 *
 * This function updates the match result for a specific report in the `quizaccess_proctoring_logs` table.
 * It takes the report ID, the similarity match result, and an AWS flag indicating the status of the analyzed images.
 * The match result (similarity) is stored as an integer score, and the AWS flag indicates the result of the analysis.
 *
 * @param int $rowid The report ID (`rowid`) of the record to be updated.
 * @param string $matchresult The similarity score, which will be converted to an integer.
 * @param int $awsflag Flag indicating the status of the analyzed images (1/2/3).
 *
 * @return void This function does not return any value.
 */
function quizaccess_proctoring_update_match_result($rowid, $matchresult, $awsflag) {
    global $DB;
    $score = (int)$matchresult;

    // Prepare the record with fields to be updated.
    $record = new stdClass();
    $record->id = $rowid;
    $record->awsflag = $awsflag;
    $record->awsscore = $score;

    // Update the record using Moodle's update_record method.
    $DB->update_record('quizaccess_proctoring_logs', $record);
}

/**
 * Determines whether an event detail JSON contains a specific shortcut.
 *
 * @param string $eventdetail JSON event detail.
 * @param string $shortcut Shortcut text to match.
 * @return bool True when the shortcut matches.
 */
function quizaccess_proctoring_event_has_shortcut(string $eventdetail, string $shortcut): bool {
    $decoded = json_decode($eventdetail, true);
    if (!is_array($decoded) || empty($decoded['shortcut'])) {
        return false;
    }

    return strtoupper((string)$decoded['shortcut']) === strtoupper($shortcut);
}

/**
 * Count attempt events for one or more event types.
 *
 * @param string $eventwhere Base event WHERE clause.
 * @param array $eventparams Base event query params.
 * @param array $eventtypes Event types to count.
 * @param bool $requirescreenshot True to only count events with a desktop screenshot.
 * @return int Number of matching events.
 */
function quizaccess_proctoring_count_risk_events(
    string $eventwhere,
    array $eventparams,
    array $eventtypes,
    bool $requirescreenshot = false
): int {
    global $DB;

    if (empty($eventtypes)) {
        return 0;
    }

    [$insql, $inparams] = $DB->get_in_or_equal($eventtypes, SQL_PARAMS_NAMED, 'riskevent');
    $where = $eventwhere . " AND eventtype {$insql}";
    if ($requirescreenshot) {
        $where .= " AND COALESCE(screenshoturl, '') <> ''";
    }

    return $DB->count_records_select('quizaccess_proctoring_events', $where, array_merge($eventparams, $inparams));
}

/**
 * Count shortcut events matching the requested shortcut.
 *
 * @param string $eventwhere Base event WHERE clause.
 * @param array $eventparams Base event query params.
 * @param string $shortcut Shortcut to match.
 * @return int Number of matching shortcut events.
 */
function quizaccess_proctoring_count_risk_shortcuts(string $eventwhere, array $eventparams, string $shortcut): int {
    global $DB;

    $shortcutrecords = $DB->get_records_select(
        'quizaccess_proctoring_events',
        $eventwhere . ' AND eventtype = :riskshortcuttype',
        $eventparams + ['riskshortcuttype' => 'shortcut'],
        '',
        'id, eventdetail'
    );

    $count = 0;
    foreach ($shortcutrecords as $shortcutrecord) {
        if (quizaccess_proctoring_event_has_shortcut($shortcutrecord->eventdetail, $shortcut)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Build one risk factor for the risk score details table.
 *
 * @param string $label Factor label.
 * @param int $count Evidence count.
 * @param int $pointsperevent Points for each event.
 * @param int $maxpoints Maximum points this factor can add.
 * @return array Factor data.
 */
function quizaccess_proctoring_build_risk_factor(
    string $label,
    int $count,
    int $pointsperevent,
    int $maxpoints
): array {
    $points = min($maxpoints, max(0, $count) * $pointsperevent);

    return [
        'label' => $label,
        'count' => $count,
        'points' => $points,
        'haspoints' => $points > 0,
    ];
}

/**
 * Get risk-level presentation details for a score.
 *
 * @param int $score Score from 0 to 100.
 * @return array Risk-level template data.
 */
function quizaccess_proctoring_get_risk_level(int $score): array {
    if ($score >= 80) {
        return [
            'label' => get_string('riskscore:critical', 'quizaccess_proctoring'),
            'class' => 'proctoring-risk-critical',
        ];
    }
    if ($score >= 50) {
        return [
            'label' => get_string('riskscore:high', 'quizaccess_proctoring'),
            'class' => 'proctoring-risk-high',
        ];
    }
    if ($score >= 20) {
        return [
            'label' => get_string('riskscore:moderate', 'quizaccess_proctoring'),
            'class' => 'proctoring-risk-moderate',
        ];
    }

    return [
        'label' => get_string('riskscore:low', 'quizaccess_proctoring'),
        'class' => 'proctoring-risk-low',
    ];
}

/**
 * Calculate a proctoring risk score for one quiz attempt.
 *
 * @param int $courseid Course id.
 * @param int $cmid Quiz course-module id.
 * @param int $studentid Student id.
 * @param int $reportid A quizaccess_proctoring_logs id for the attempt.
 * @return array Risk score template data.
 */
function quizaccess_proctoring_calculate_attempt_risk(int $courseid, int $cmid, int $studentid, int $reportid): array {
    global $DB;

    $attemptid = (int)$DB->get_field('quizaccess_proctoring_logs', 'status', ['id' => $reportid]);
    $threshold = max(1, (int)quizaccess_proctoring_get_proctoring_settings('threshold'));

    $eventwhere = 'courseid = :riskcourseid AND quizid = :riskcmid AND userid = :riskstudentid';
    $eventparams = [
        'riskcourseid' => $courseid,
        'riskcmid' => $cmid,
        'riskstudentid' => $studentid,
    ];
    if ($attemptid > 0) {
        $eventwhere .= ' AND attemptid = :riskattemptid';
        $eventparams['riskattemptid'] = $attemptid;
    }

    $logwhere = 'courseid = :risklogcourseid AND quizid = :risklogcmid AND userid = :risklogstudentid
        AND deletionprogress = :riskdeletionprogress';
    $logparams = [
        'risklogcourseid' => $courseid,
        'risklogcmid' => $cmid,
        'risklogstudentid' => $studentid,
        'riskdeletionprogress' => 0,
    ];
    if ($attemptid > 0) {
        $logwhere .= ' AND status = :risklogattemptid';
        $logparams['risklogattemptid'] = $attemptid;
    } else if ($reportid > 0) {
        $logwhere .= ' AND id = :risklogreportid';
        $logparams['risklogreportid'] = $reportid;
    }

    $faceimagewhere = 'l.courseid = :riskfacecourseid AND l.quizid = :riskfacecmid
        AND l.userid = :riskfacestudentid AND l.deletionprogress = :riskfacedeletionprogress';
    $faceimageparams = [
        'riskfacecourseid' => $courseid,
        'riskfacecmid' => $cmid,
        'riskfacestudentid' => $studentid,
        'riskfacedeletionprogress' => 0,
        'riskfacefound' => '1',
    ];
    if ($attemptid > 0) {
        $faceimagewhere .= ' AND l.status = :riskfaceattemptid';
        $faceimageparams['riskfaceattemptid'] = $attemptid;
    } else if ($reportid > 0) {
        $faceimagewhere .= ' AND l.id = :riskfacereportid';
        $faceimageparams['riskfacereportid'] = $reportid;
    }

    $webcamcount = $DB->count_records_select(
        'quizaccess_proctoring_logs',
        $logwhere . " AND COALESCE(webcampicture, '') <> ''",
        $logparams
    );

    $facemismatchcount = $DB->count_records_select(
        'quizaccess_proctoring_logs',
        $logwhere . ' AND awsflag = :riskawschecked AND awsscore < :riskthreshold',
        $logparams + [
            'riskawschecked' => 2,
            'riskthreshold' => $threshold,
        ]
    );
    $facefailedcount = $DB->count_records_select(
        'quizaccess_proctoring_logs',
        $logwhere . ' AND awsflag = :riskawsfailed',
        $logparams + ['riskawsfailed' => 3]
    );

    $nofaceimagecount = $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {quizaccess_proctoring_face_images} fi
           JOIN {quizaccess_proctoring_logs} l ON l.id = fi.parentid
          WHERE {$faceimagewhere}
            AND fi.facefound <> :riskfacefound",
        $faceimageparams
    );

    $tabactivitycount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['focus_lost', 'tab_hidden', 'page_exit']
    );
    $clipboardcount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['clipboard_copy', 'clipboard_cut', 'clipboard_paste', 'contextmenu']
    );
    $screenissuecount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['screen_marker_missing', 'screen_share_stopped']
    );
    $aitoolcount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['possible_ai_tool']
    );
    $aitoolscreenshotcount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['possible_ai_tool'],
        true
    );
    $f12count = quizaccess_proctoring_count_risk_shortcuts($eventwhere, $eventparams, 'F12');
    $multiplefacescount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['multiple_faces_detected']
    );
    $audioactivitycount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['audio_detected']
    );
    $nofaceeventcount = quizaccess_proctoring_count_risk_events(
        $eventwhere,
        $eventparams,
        ['face_missing', 'no_face_detected']
    );

    $factors = [
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:facemismatch', 'quizaccess_proctoring'),
            $facemismatchcount,
            35,
            35
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:multiplefaces', 'quizaccess_proctoring'),
            $multiplefacescount,
            30,
            30
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:noface', 'quizaccess_proctoring'),
            max($nofaceimagecount, $facefailedcount) + $nofaceeventcount,
            8,
            24
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:screenshare', 'quizaccess_proctoring'),
            $screenissuecount,
            18,
            36
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:aitool', 'quizaccess_proctoring'),
            $aitoolcount,
            20,
            30
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:aitoolscreenshot', 'quizaccess_proctoring'),
            $aitoolscreenshotcount,
            15,
            30
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:clipboard', 'quizaccess_proctoring'),
            $clipboardcount,
            8,
            24
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:tabactivity', 'quizaccess_proctoring'),
            $tabactivitycount,
            5,
            20
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:f12', 'quizaccess_proctoring'),
            $f12count,
            15,
            15
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:audio', 'quizaccess_proctoring'),
            $audioactivitycount,
            6,
            18
        ),
        quizaccess_proctoring_build_risk_factor(
            get_string('riskscore:webcammissing', 'quizaccess_proctoring'),
            $webcamcount > 0 ? 0 : 1,
            15,
            15
        ),
    ];

    $score = 0;
    foreach ($factors as $factor) {
        $score += (int)$factor['points'];
    }
    $score = min(100, $score);
    $level = quizaccess_proctoring_get_risk_level($score);

    return [
        'score' => $score,
        'level' => $level['label'],
        'badgeclass' => 'proctoring-risk-badge ' . $level['class'],
        'cardclass' => 'proctoring-risk-card ' . $level['class'],
        'factors' => $factors,
        'attemptid' => $attemptid,
    ];
}

/**
 * Get effective risk review settings for a course-module quiz.
 *
 * @param int $cmid Quiz course-module id.
 * @return array Effective settings.
 */
function quizaccess_proctoring_get_effective_risk_review_settings(int $cmid): array {
    global $DB;

    $siteenabled = (int)get_config('quizaccess_proctoring', 'riskreviewenabled');
    $sitethreshold = (int)get_config('quizaccess_proctoring', 'riskreviewthreshold');
    if ($sitethreshold <= 0) {
        $sitethreshold = 80;
    }
    $sitethreshold = max(1, min(100, $sitethreshold));
    $quizsetting = $DB->get_record('quizaccess_proctoring', ['quizid' => $cmid]);
    $mode = isset($quizsetting->riskreviewmode) ? (int)$quizsetting->riskreviewmode : -1;
    $threshold = isset($quizsetting->riskreviewthreshold) && (int)$quizsetting->riskreviewthreshold > 0
        ? max(1, min(100, (int)$quizsetting->riskreviewthreshold))
        : $sitethreshold;

    return [
        'enabled' => $mode === -1 ? $siteenabled === 1 : $mode === 1,
        'threshold' => $threshold,
        'mode' => $mode,
    ];
}

/**
 * Get the active or latest risk hold for an attempt.
 *
 * @param int $courseid Course id.
 * @param int $cmid Quiz course-module id.
 * @param int $userid User id.
 * @param int $attemptid Quiz attempt id.
 * @param int $reportid Proctoring report id.
 * @param bool $activeonly True to only return active holds.
 * @return stdClass|false Hold record or false.
 */
function quizaccess_proctoring_get_risk_hold(
    int $courseid,
    int $cmid,
    int $userid,
    int $attemptid = 0,
    int $reportid = 0,
    bool $activeonly = false
) {
    global $DB;

    $where = 'courseid = :courseid AND quizid = :cmid AND userid = :userid';
    $params = [
        'courseid' => $courseid,
        'cmid' => $cmid,
        'userid' => $userid,
    ];
    if ($attemptid > 0) {
        $where .= ' AND attemptid = :attemptid';
        $params['attemptid'] = $attemptid;
    } else if ($reportid > 0) {
        $where .= ' AND reportid = :reportid';
        $params['reportid'] = $reportid;
    }
    if ($activeonly) {
        $where .= ' AND status = :status';
        $params['status'] = 0;
    }

    $records = $DB->get_records_select('quizaccess_proctoring_risk_holds', $where, $params, 'status ASC, id DESC', '*', 0, 1);
    return $records ? reset($records) : false;
}

/**
 * Apply a proctoring review hold and suppress the quiz gradebook grade while active.
 *
 * @param int $courseid Course id.
 * @param int $cmid Quiz course-module id.
 * @param int $userid User id.
 * @param int $attemptid Quiz attempt id.
 * @param int $reportid Proctoring report id.
 * @param int $riskscore Risk score.
 * @param int $threshold Configured threshold.
 * @return int Hold id.
 */
function quizaccess_proctoring_apply_risk_hold(
    int $courseid,
    int $cmid,
    int $userid,
    int $attemptid,
    int $reportid,
    int $riskscore,
    int $threshold
): int {
    global $CFG, $DB, $USER;

    require_once($CFG->dirroot . '/mod/quiz/lib.php');

    $cm = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    $quiz->cmidnumber = $cm->idnumber;
    $quiz->visible = $cm->visible;

    quiz_update_grades($quiz, $userid, false);
    $quizgrade = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $userid]);
    $now = time();

    $hold = quizaccess_proctoring_get_risk_hold($courseid, $cmid, $userid, $attemptid, $reportid, true);
    if ($hold) {
        $hold->riskscore = $riskscore;
        $hold->threshold = $threshold;
        $hold->timemodified = $now;
        $DB->update_record('quizaccess_proctoring_risk_holds', $hold);
        $holdid = (int)$hold->id;
    } else {
        $hold = (object)[
            'courseid' => $courseid,
            'quizid' => $cmid,
            'quizinstance' => $quiz->id,
            'userid' => $userid,
            'attemptid' => $attemptid,
            'reportid' => $reportid,
            'riskscore' => $riskscore,
            'threshold' => $threshold,
            'originalgrade' => $quizgrade ? $quizgrade->grade : null,
            'status' => 0,
            'reviewerid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timereviewed' => 0,
        ];
        $holdid = $DB->insert_record('quizaccess_proctoring_risk_holds', $hold, true);
    }

    $grade = (object)[
        'userid' => $userid,
        'rawgrade' => 0,
        'feedback' => get_string('riskreview:gradefeedback', 'quizaccess_proctoring', $riskscore),
        'feedbackformat' => FORMAT_PLAIN,
        'usermodified' => !empty($USER->id) ? $USER->id : 0,
        'dategraded' => $now,
    ];
    quiz_grade_item_update($quiz, $grade);

    return $holdid;
}

/**
 * Release a risk hold and restore the quiz gradebook grade from Moodle quiz data.
 *
 * @param int $holdid Hold id.
 * @param int $reviewerid Reviewer user id.
 * @return bool True when released.
 */
function quizaccess_proctoring_release_risk_hold(int $holdid, int $reviewerid): bool {
    global $CFG, $DB;

    $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
    if ((int)$hold->status !== 0) {
        return true;
    }

    require_once($CFG->dirroot . '/mod/quiz/lib.php');
    $cm = get_coursemodule_from_id('quiz', $hold->quizid, $hold->courseid, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $hold->quizinstance], '*', MUST_EXIST);
    $quiz->cmidnumber = $cm->idnumber;
    $quiz->visible = $cm->visible;

    quiz_update_grades($quiz, $hold->userid, false);

    $hold->status = 1;
    $hold->reviewerid = $reviewerid;
    $hold->timereviewed = time();
    $hold->timemodified = $hold->timereviewed;
    $DB->update_record('quizaccess_proctoring_risk_holds', $hold);

    return true;
}

/**
 * Execute face recognition task.
 *
 * This function fetches up to 5 tasks from the `quizaccess_proctoring_facematch_task` table, processes each task
 * by performing a face recognition operation, and deletes the processed tasks. The face matching is done using the
 * method specified in the `fcmethod` setting.
 *
 * The function calls the configured Saylor AI API for face matching. After processing, the task is removed from the table.
 *
 * @return bool Returns false if no records are found to process, otherwise performs the task and deletes processed records.
 */
function quizaccess_proctoring_execute_fm_task() {
    global $DB;

    // Fetch up to 5 face match tasks.
    $tasks = $DB->get_records('quizaccess_proctoring_facematch_task', null, '', '*', 0, 5);

    // Get face match method from plugin settings.
    $facematchmethod = quizaccess_proctoring_get_proctoring_settings('fcmethod');

    // If there are no tasks, exit early.
    if (empty($tasks)) {
        mtrace('No face match tasks found.');
        return;
    }

    // Validate face match method.
    if (!quizaccess_proctoring_is_facematch_method_enabled($facematchmethod)) {
        mtrace("Invalid face match method: {$facematchmethod}");
        return;
    }

    // Process each task.
    foreach ($tasks as $row) {
        $rowid = $row->id;
        $reportid = $row->reportid;

        $userfaceimageurl = $row->refimageurl;
        $webcamfaceimageurl = $row->targetimageurl;

        mtrace('Profile Image URL: ' . $userfaceimageurl);
        mtrace('Target Image URL: ' . $webcamfaceimageurl);
        if (!empty($userfaceimageurl) && !empty($webcamfaceimageurl)) {
            // Perform face matching operation.
            quizaccess_proctoring_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid);

            // Execute the query.
            $result = $DB->get_record(
                'quizaccess_proctoring_logs',
                ['id' => $reportid],
                'awsscore, awsflag',
                MUST_EXIST
            );
            mtrace('Face match result: ' . $result->awsscore);

            if ((int)$result->awsflag !== 1) {
                // Delete the task if processed successfully.
                $DB->delete_records('quizaccess_proctoring_facematch_task', ['id' => $rowid]);
                mtrace("Successfully processed and deleted task ID {$rowid} (Report ID: {$reportid}).");
            } else {
                mtrace("Face match failed for report ID {$reportid}.");
            }
        } else {
            mtrace("Missing image URLs for report ID {$reportid}.");
        }
    }
}

/**
 * Execute face recognition logging task.
 *
 * This function fetches distinct records from the `quizaccess_proctoring_logs` table where the `awsflag` is 0, and then processes
 * each record by logging specific quiz details for the corresponding user, course, and quiz ID. After logging the information,
 * a success message is displayed.
 *
 * @return bool Returns false if no records are found to process, otherwise processes the records and logs the data.
 */
function quizaccess_proctoring_log_facematch_task() {
    global $DB;

    // Fetch distinct records where awsflag is 0 using Moodle's get_records_sql.
    $sql = 'SELECT DISTINCT id, courseid, quizid, userid
             FROM {quizaccess_proctoring_logs}
             WHERE awsflag = :awsflag';
    $params = ['awsflag' => 0];
    $records = $DB->get_records_sql($sql, $params);
    // Process each record.
    foreach ($records as $record) {
        $courseid = $record->courseid;
        $quizid = $record->quizid;
        $userid = $record->userid;

        // Log specific quiz details.
        quizaccess_proctoring_log_specific_quiz($courseid, $quizid, $userid);
    }

    // Use Moodle's notification API for success messages.
    mtrace('Log success');

}

/**
 * Log the analysis of a specific quiz for a student.
 *
 * This function fetches the user's profile image and updates the `awsflag` field to mark records as attempted.
 * It then queries the `quizaccess_proctoring_logs` table to retrieve specific records for the quiz and student,
 * checks a random limit for the number of records, and logs the results for each match task.
 *
 * @param int $courseid The ID of the course.
 * @param int $cmid The ID of the course module.
 * @param int $studentid The ID of the student.
 *
 * @return bool Returns `true` if records were processed, `false` if no record was found.
 */
function quizaccess_proctoring_log_specific_quiz($courseid, $cmid, $studentid) {
    global $DB;

    // Get user profile image.
    $profileimageurl = quizaccess_proctoring_get_image_url($studentid);
    if (empty($profileimageurl)) {
        mtrace("No profile image found for user ID {$studentid}.");
        return false;
    }

    // Update all logs to mark as processed.
    $updateparams = [
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $studentid,
    ];
    $DB->set_field('quizaccess_proctoring_logs', 'awsflag', 1, $updateparams);

    // Get limit from settings or default.
    $defaultlimit = 5;
    $awschecknumber = quizaccess_proctoring_get_proctoring_settings('awschecknumber');

    if ($awschecknumber == '') {
        $limit = $defaultlimit;
    } else if ($awschecknumber > 0) {
        $limit = (int)$awschecknumber;
    } else {
        $limit = $defaultlimit;
    }

    mtrace("Limit for face match task: {$limit}");

    // First get all matching IDs (only IDs for performance).
    $idparams = [
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $studentid,
    ];
    $idsql = "SELECT id
              FROM {quizaccess_proctoring_logs}
              WHERE courseid = :courseid
              AND quizid = :quizid
              AND userid = :userid
              AND webcampicture != ''";
    $allrecords = $DB->get_fieldset_sql($idsql, $idparams);

    if (empty($allrecords)) {
        mtrace("No snapshots found for user ID {$studentid}");
        return false;
    }

    // Shuffle and slice IDs for randomness.
    shuffle($allrecords);
    $selectedids = array_slice($allrecords, 0, $limit);

    // Avoid proceeding if selected IDs are empty.
    if (empty($selectedids)) {
        mtrace("No selected snapshot IDs to process for user ID {$studentid}");
        return false;
    }

    // Now fetch full data for those selected IDs.
    list($insql, $inparams) = $DB->get_in_or_equal($selectedids, SQL_PARAMS_NAMED);
    $finalsql = "SELECT id, webcampicture
                 FROM {quizaccess_proctoring_logs}
                 WHERE id $insql";
    $records = $DB->get_records_sql($finalsql, $inparams);

    // Insert each snapshot into facematch task table.
    foreach ($records as $record) {
        $facematch = new stdClass();
        $facematch->refimageurl = $profileimageurl;
        $facematch->targetimageurl = $record->webcampicture;
        $facematch->reportid = $record->id;
        $facematch->timemodified = time();

        $DB->insert_record('quizaccess_proctoring_facematch_task', $facematch);
        mtrace("Facematch task created for report ID {$record->id}");
    }

    return true;
}



/**
 * Analyze specific quiz images for face matching.
 *
 * This function fetches the user's profile image, redirects if not available,
 * and processes the quiz records for the student. It fetches the webcam face
 * images for the student, compares them with the profile image, and updates
 * the face match status in the database. The function also handles logging
 * of warnings and updating the `awsflag` status based on the results.
 *
 * @param int $courseid The ID of the course.
 * @param int $cmid The ID of the course module.
 * @param int $studentid The ID of the student.
 * @param mixed $reportpageurl The URL to redirect to in case the reportpage .
 *
 * @return bool Returns `true` if records were processed successfully, `false` if no records found.
 */
function quizaccess_proctoring_analyze_specific_quiz($courseid, $cmid, $studentid, $reportpageurl) {
    global $DB;

    // Get user profile image.
    $profileimageurl = quizaccess_proctoring_get_image_url($studentid);
    $redirecturl = new moodle_url('/mod/quiz/accessrule/proctoring/upload_image.php', ['id' => $studentid]);

    // Redirect if profile image is not available.
    if (!$profileimageurl) {
        redirect(
            $redirecturl,
            get_string('user_image_not_uploaded', 'mod_quiz'),
            1,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Update all as attempted.
    $DB->set_field_select(
        'quizaccess_proctoring_logs',
        'awsflag',
        1,
        "courseid = :courseid AND quizid = :quizid AND userid = :userid AND awsflag = :awsflag",
        [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $studentid,
            'awsflag' => 0,
        ]
    );

    // Check random limit.
    $limit = 5;
    $awschecknumber = quizaccess_proctoring_get_proctoring_settings('awschecknumber');

    if ($awschecknumber > 0) {
        $limit = (int)$awschecknumber;
    }

    // Prepare SQL query and parameters.
    $basequery = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture as webcampicture,
        e.status as status, e.timemodified as timemodified, u.firstname as firstname,
        u.lastname as lastname, u.email as email
        FROM {quizaccess_proctoring_logs} e
        INNER JOIN {user} u ON u.id = e.userid
        WHERE e.courseid = :courseid AND e.quizid = :quizid AND u.id = :userid AND e.webcampicture != ''";

    $params = [
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $studentid,
    ];

    if ($limit > 0) {
        $basequery .= " ORDER BY RAND() LIMIT " . (int)$limit; // Ensure $limit is sanitized.
    }
    // Execute the query.
    $sqlexecuted = $DB->get_recordset_sql($basequery, $params);

    // Process each record.
    foreach ($sqlexecuted as $row) {
        $reportid = $row->reportid;
        $userfaceimageurl = $profileimageurl;
        $webcamfaceimageurl = $row->webcampicture;

        if (!$userfaceimageurl || !$webcamfaceimageurl) {
            // Log warning if faces are not found.
            quizaccess_proctoring_log_fm_warning($reportid);

            // Set awsflag = 3 if face not found.
            quizaccess_proctoring_update_match_result($reportid, 0, 3);
            continue;
        }

        // Perform face extraction and comparison.
        quizaccess_proctoring_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid, $reportpageurl);
    }

    // Close the recordset.
    $sqlexecuted->close();

    return true;
}


/**
 * Get proctoring settings values from the database.
 *
 * This function retrieves the value of a specific proctoring setting for the
 * plugin `quizaccess_proctoring` from the Moodle configuration table.
 * If the setting is not found, it returns an empty string.
 *
 * @param string $settingtype The name of the setting to retrieve (e.g., 'awschecknumber').
 *
 * @return string The value of the specified setting, or an empty string if the setting is not found.
 */
function quizaccess_proctoring_get_proctoring_settings($settingtype) {
    global $DB;

    // Query the settings table for the specified setting type.
    $record = $DB->get_record('config_plugins', [
        'plugin' => 'quizaccess_proctoring',
        'name' => $settingtype,
    ], 'value', IGNORE_MISSING);

    // Return the value or an empty string if the setting is not found.
    return $record ? $record->value : '';
}

/**
 * Checks whether a face match method is enabled.
 *
 * @param string|null $method Face match method, or null to read plugin config.
 * @return bool True when the method can perform face matching.
 */
function quizaccess_proctoring_is_facematch_method_enabled(?string $method = null): bool {
    $method = $method ?? quizaccess_proctoring_get_proctoring_settings('fcmethod');
    return $method === 'customapi';
}

/**
 * Checks whether the Saylor AI endpoint is the selected face match method.
 *
 * @param string|null $method Face match method, or null to read plugin config.
 * @return bool True when the Saylor AI endpoint is selected.
 */
function quizaccess_proctoring_is_custom_ai_method(?string $method = null): bool {
    $method = $method ?? quizaccess_proctoring_get_proctoring_settings('fcmethod');
    return $method === 'customapi';
}

/**
 * Checks whether the selected face match method has its required credentials configured.
 *
 * @param string|null $method Face match method, or null to read plugin config.
 * @return bool True when the selected method has enough configuration to call its API.
 */
function quizaccess_proctoring_facematch_credentials_available(?string $method = null): bool {
    $method = $method ?? quizaccess_proctoring_get_proctoring_settings('fcmethod');

    if ($method === 'customapi') {
        return !empty(quizaccess_proctoring_get_proctoring_settings('custom_ai_endpoint')) &&
            !empty(quizaccess_proctoring_get_proctoring_settings('custom_api_key'));
    }

    return false;
}

/**
 * Analyze a specific image for face match and logging.
 *
 * This function performs analysis on a specific image associated with a report.
 * It retrieves face images, performs a face match operation, and updates the database with the results.
 * If the face images are not found, an error is logged, and the user is redirected with an error message.
 *
 * @param int $reportid The ID of the proctoring report record to analyze.
 * @param mixed $redirecturl The URL to redirect to if an error occurs.
 *
 * @return bool Returns true if the analysis was successful, false if no record is found or if an error occurs.
 */
function quizaccess_proctoring_analyze_specific_image($reportid, $redirecturl) {
    global $DB;

    // Fetch the record for the specific report ID.
    $reportdata = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid], 'id, courseid, quizid, userid, webcampicture');

    if (!$reportdata) {
        redirect(
            $redirecturl,
            get_string('error_invalid_report', 'quizaccess_proctoring'),
            1,
            \core\output\notification::NOTIFY_ERROR
        );
        return false;
    }

    $studentid = $reportdata->userid;
    $courseid = $reportdata->courseid;
    $cmid = $reportdata->quizid;

    $userfaceimageurl = quizaccess_proctoring_get_image_url($studentid);
    $webcamfaceimageurl = $reportdata->webcampicture;

    if (!$userfaceimageurl || !$webcamfaceimageurl) {
        // Log a face match warning.
        quizaccess_proctoring_log_fm_warning($reportid);

        // Update the match result with an error flag (awsflag = 3).
        quizaccess_proctoring_update_match_result($reportid, 0, 3);

        // Redirect with an error message.
        redirect(
            $redirecturl,
            get_string('error_face_not_found', 'quizaccess_proctoring'),
            1,
            \core\output\notification::NOTIFY_ERROR
        );
        return true;
    }

    // Update logs to mark all as attempted.
    $DB->execute(
        "UPDATE {quizaccess_proctoring_logs}
         SET awsflag = 1
         WHERE courseid = :courseid AND quizid = :quizid AND userid = :userid AND awsflag = 0",
        [
            'courseid' => $courseid,
            'quizid' => $cmid,
            'userid' => $studentid,
        ]
    );

    // Perform face extraction analysis.
    quizaccess_proctoring_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid, $redirecturl);
    redirect(
        $redirecturl,
        get_string('facematch', 'quizaccess_proctoring'),
        1,
        \core\output\notification::NOTIFY_SUCCESS
    );

    return true;
}


/**
 * Analyze a specific image for face match and logging.
 *
 * This function performs analysis on a specific image associated with a report.
 * It retrieves face images, performs a face match operation, and updates the database with the results.
 * If the face images are not found, an error is logged, and the user is redirected with an error message.
 *
 * @param int $reportid The ID of the proctoring report record to analyze.
 *
 * @return bool Returns true if the analysis was successful, false if no record is found or if an error occurs.
 */
function quizaccess_proctoring_analyze_specific_image_from_validate($reportid) {
    global $DB;

    // Fetch report data from the database based on the provided report ID.
    $reportdata = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid], 'id, courseid, quizid, userid, webcampicture');

    // If the report data exists, proceed with analysis.
    if ($reportdata) {
        $studentid = $reportdata->userid;
        $courseid = $reportdata->courseid;
        $cmid = $reportdata->quizid;

        $userfaceimageurl = quizaccess_proctoring_get_image_url($studentid);
        $webcamfaceimageurl = $reportdata->webcampicture;

        // If either face image is not found, log the warning and update the result.
        if (!$userfaceimageurl || !$webcamfaceimageurl) {
            // Log the warning for face match.
            quizaccess_proctoring_log_fm_warning($reportid);

            // Update the match result with flag indicating face match failure (awsflag = 3).
            $awsflag = 3;
            quizaccess_proctoring_update_match_result($reportid, 0, $awsflag);
            return;
        }

        // Update all logs as attempted by setting awsflag to 1.
        $DB->execute(
            "UPDATE {quizaccess_proctoring_logs}
             SET awsflag = 1
             WHERE courseid = :courseid AND quizid = :quizid AND userid = :userid AND awsflag = 0",
            [
                'courseid' => $courseid,
                'quizid' => $cmid,
                'userid' => $studentid,
            ]
        );

        if (quizaccess_proctoring_facematch_credentials_available()) {
            quizaccess_proctoring_extracted($userfaceimageurl, $webcamfaceimageurl, $reportid);
        } else {
            quizaccess_proctoring_update_match_result($reportid, 0, 101); // If api is not set.
            return;
        }
    }

    return true;
}


/**
 * Retrieve the face images for a specific report.
 *
 * This function fetches both the user's face image and the webcam face image associated with
 * a given proctoring report. If the user's image is not uploaded, it redirects to the image upload page.
 * If no images are found, the function returns `null` for both face images.
 *
 * @param int $reportid The ID of the proctoring report to fetch the images for.
 *
 * @return array An array containing the user's face image URL and the webcam face image URL.
 *               Both values will be `null` if no images are found.
 */
function quizaccess_proctoring_get_face_images($reportid, bool $redirectmissing = true) {
    global $DB;

    // Fetch report data for the given report ID.
    $reportdata = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid]);

    if (!$reportdata) {
        return [null, null];
    }

    $studentid = $reportdata->userid;

    // Fetch webcam face images associated with the report.
    $webcamfaceimage = $DB->get_records(
        'quizaccess_proctoring_face_images',
        [
            'parentid' => $reportid,
            'parent_type' => 'camshot_image',
            'facefound' => 1,
        ]
    );

    $webcamfaceimageurl = '';
    if ($webcamfaceimage) {
        // If there are multiple webcam images, use the first one.
        $firstwebcamimage = reset($webcamfaceimage);
        $webcamfaceimageurl = $firstwebcamimage->faceimage;
    }

    // Fetch user image data.
    $userimagerow = $DB->get_record('quizaccess_proctoring_user_images', ['user_id' => $studentid]);

    $redirecturl = new moodle_url('/mod/quiz/accessrule/proctoring/upload_image.php', ['id' => $studentid]);

    // If user image is not uploaded, redirect to upload page with a warning.
    if (!$userimagerow) {
        if ($redirectmissing) {
            redirect(
                $redirecturl,
                get_string('userimagenotuploaded', 'quizaccess_proctoring'),
                1,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        return [null, $webcamfaceimageurl];
    }

    // Fetch the face image associated with the user's image.
    $userfaceimageurl = '';
    if ($userimagerow) {
        $userfaceimagerow = $DB->get_record(
            'quizaccess_proctoring_face_images',
            ['parentid' => $userimagerow->id, 'parent_type' => 'admin_image']
        );

        if ($userfaceimagerow) {
            $userfaceimageurl = $userfaceimagerow->faceimage;
        }
    }

    return [$userfaceimageurl, $webcamfaceimageurl];
}

/**
 * Compares face images and updates the similarity result in the database.
 *
 * This function compares two face images using a similarity function and evaluates the result
 * against a threshold value specified in the configuration. If the similarity is below the threshold,
 * a warning is logged. The result is then updated in the database.
 *
 * @param string $profileimageurl The URL of the profile image to compare.
 * @param string $targetimage The URL of the target image to compare against.
 * @param int $reportid The ID of the report associated with the image comparison.
 * @param string|null $redirecturl The URL to redirect to if an error occurs (optional).
 *
 * @return void
 */
function quizaccess_proctoring_extracted(
    string $profileimageurl, string $targetimage,
    int $reportid, ?string $redirecturl = null): void {
    $method = quizaccess_proctoring_get_proctoring_settings('fcmethod');
    $threshold = (float) quizaccess_proctoring_get_proctoring_settings('threshold');
    $similarity = 0;

    if (!quizaccess_proctoring_facematch_credentials_available($method)) {
        if (!empty($redirecturl)) {
            redirect(
                $redirecturl,
                get_string('invalid_facematch_method', 'quizaccess_proctoring'),
                1,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        quizaccess_proctoring_update_match_result($reportid, 0, 101);
        return;
    }

    $similarityresult = quizaccess_proctoring_check_similarity_customapi($profileimageurl, $targetimage, $redirecturl, $reportid);
    $response = json_decode($similarityresult);

    if (!$similarityresult || !$response) {
        quizaccess_proctoring_log_fm_warning($reportid);
        quizaccess_proctoring_update_match_result($reportid, 0, 101);
        return;
    }

    if (isset($response->detail)) {
        if (!empty($redirecturl)) {
            redirect(
                $redirecturl,
                get_string('invalid_api', 'quizaccess_proctoring'),
                1,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        quizaccess_proctoring_update_match_result($reportid, 0, 101);
        return;
    }

    if (isset($response->match)) {
        $score = isset($response->score) ? (float)$response->score :
            (isset($response->similarity) ? (float)$response->similarity : 0);
        if (!empty($response->match)) {
            $similarity = $score > 0 ? $score : 100;
            if ($threshold > 0 && $similarity < $threshold) {
                quizaccess_proctoring_log_fm_warning($reportid);
            }
        } else {
            quizaccess_proctoring_log_fm_warning($reportid);
        }

        quizaccess_proctoring_update_match_result($reportid, $similarity, 2);
        return;
    }

    quizaccess_proctoring_log_fm_warning($reportid);
    quizaccess_proctoring_update_match_result($reportid, 0, 101);
}

/**
 * Returns face match similarity from the Saylor AI endpoint.
 *
 * @param string $referenceimageurl The URL of the saved reference image.
 * @param string $targetimageurl The URL of the current webcam image.
 * @param string|null $redirecturl The URL to redirect to if an error occurs.
 * @param int $reportid The ID of the report associated with the image comparison.
 *
 * @return bool|string The API response as a string, or false on failure.
 */
function quizaccess_proctoring_check_similarity_customapi(
    string $referenceimageurl,
    string $targetimageurl,
    $redirecturl,
    $reportid
) {
    $endpoint = trim(quizaccess_proctoring_get_proctoring_settings('custom_ai_endpoint'));
    $apikey = quizaccess_proctoring_get_proctoring_settings('custom_api_key');

    if (empty($endpoint) || empty($apikey)) {
        mtrace('Error: Missing Saylor AI endpoint URL or API key.');
        return false;
    }

    $referenceimage = @file_get_contents($referenceimageurl);
    $targetimage = @file_get_contents($targetimageurl);

    if ($referenceimage === false || $targetimage === false) {
        mtrace('Error: Unable to load images for the Saylor AI endpoint.');
        return false;
    }

    $payload = json_encode([
        'reference_image' => base64_encode($referenceimage),
        'current_snap' => base64_encode($targetimage),
    ]);

    if ($payload === false) {
        mtrace('Error: Unable to encode Saylor AI request payload.');
        return false;
    }

    $curl = new curl();
    $options = [
        'CURLOPT_TIMEOUT' => 30,
        'CURLOPT_FOLLOWLOCATION' => true,
        'CURLOPT_HTTPHEADER' => [
            'X-API-Key: ' . $apikey,
            'Content-Type: application/json',
        ],
    ];

    $response = $curl->post($endpoint, $payload, $options);

    if ($curl->get_errno()) {
        if (!empty($redirecturl)) {
            redirect(
                $redirecturl,
                get_string('invalid_service_api', 'quizaccess_proctoring'),
                1,
                \core\output\notification::NOTIFY_ERROR
            );
        } else {
            quizaccess_proctoring_update_match_result($reportid, 0, 101);
        }

        return false;
    }

    return $response;
}

/**
 * Logs a face matching warning for the given report ID.
 *
 * This function checks if a warning already exists for a particular user, course, and quiz.
 * If no warning exists, it inserts a new record into the `quizaccess_proctoring_fm_warnings` table.
 * If the report cannot be found, it logs an error message.
 *
 * @param int $reportid The report ID for which the warning is being logged.
 *
 * @return void
 */
function quizaccess_proctoring_log_fm_warning(int $reportid): void {
    global $DB;

    // Fetch the report data.
    $report = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid]);

    // Check if the report exists.
    if ($report) {
        // Extract necessary data.
        $userid = $report->userid;
        $courseid = $report->courseid;
        $quizid = $report->quizid;

        // Check if a warning already exists for this user, course, and quiz.
        $existingwarning = $DB->get_record('quizaccess_proctoring_fm_warnings', [
            'userid' => $userid,
            'courseid' => $courseid,
            'quizid' => $quizid,
        ]);

        // If no warning exists, insert a new record.
        if (!$existingwarning) {
            // Prepare a new warning object.
            $warning = new stdClass();
            $warning->reportid = $reportid;
            $warning->courseid = $courseid;
            $warning->quizid = $quizid;
            $warning->userid = $userid;

            // Insert the new warning record into the database.
            $DB->insert_record('quizaccess_proctoring_fm_warnings', $warning);
        }
    } else {
        // Log a message if the report cannot be found.
        mtrace('Error: Report ID ' . $reportid . ' not found.');
    }
}

/**
 * Saves the face image as a file and returns its URL.
 *
 * This function decodes a base64 string, saves the image as a file in Moodle's file system,
 * and returns a URL to access the file.
 *
 * @param string $data The base64 encoded image data.
 * @param int $userid The ID of the user who uploaded the image.
 * @param stdClass $record The file record that contains metadata.
 * @param context $context The context for the file (usually the course or activity context).
 * @param stored_file_system $fs The file storage system instance.
 * @return moodle_url The URL to access the saved face image.
 */
function quizaccess_proctoring_geturl_of_faceimage(string $data, int $userid, stdClass $record, $context, $fs): moodle_url {
    // Remove any metadata from the base64 string.
    list(, $data) = explode(',', $data);

    // Decode the base64 data into raw binary image data.
    $data = base64_decode($data);

    // Generate a unique filename for the image.
    $filename = 'faceimage-' . $userid . '-' . time() . random_int(1, 1000) . '.png';

    // Set the filename and context ID in the file record.
    $record->filename = $filename;
    $record->contextid = $context->id;
    $record->userid = $userid;

    // Ensure the file is created in Moodle's file storage system.
    try {
        $fs->create_file_from_string($record, $data);
    } catch (Exception $e) {
        // Handle any exceptions during file storage creation.
        throw new moodle_exception('filecreationerror', 'error', '', $e->getMessage());
    }

    // Return the URL to access the stored file.
    return moodle_url::make_pluginfile_url(
        $context->id,
        $record->component,
        $record->filearea,
        $record->itemid,
        $record->filepath,
        $record->filename,
        false
    );
}
