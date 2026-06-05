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

defined('QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE') ||
    define('QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE', 0);
defined('QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED') ||
    define('QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED', 1);
defined('QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED') ||
    define('QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED', 2);
defined('QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED') ||
    define('QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED', 0);
defined('QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING') ||
    define('QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING', 1);
defined('QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE') ||
    define('QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE', 2);
defined('QUIZACCESS_PROCTORING_AI_REVIEW_FAILED') ||
    define('QUIZACCESS_PROCTORING_AI_REVIEW_FAILED', 3);

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
    $allowedfileareas = [
        'picture',
        'face_image',
        'user_photo',
        'violation_screenshot',
        'id_document',
        'id_back_document',
        'id_live_image',
    ];
    if (!in_array($filearea, $allowedfileareas, true) || empty($args)) {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    if (empty($filename) || $filename === '.') {
        return false;
    }

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

    if ($file->is_directory() ||
            !quizaccess_proctoring_can_serve_pluginfile($course, $cm, $context, $filearea, (int)$itemid, $filename, $file)) {
        return false;
    }

    $options = array_merge(['cacheability' => 'private'], $options);
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Checks whether the current user can access a stored proctoring file.
 *
 * @param stdClass|null $course Course record.
 * @param cm_info|stdClass|null $cm Course module record.
 * @param context $context File context.
 * @param string $filearea Plugin file area.
 * @param int $itemid File item id.
 * @param string $filename Stored filename.
 * @param stored_file $file Stored file.
 * @return bool
 */
function quizaccess_proctoring_can_serve_pluginfile($course, $cm, $context, string $filearea, int $itemid,
        string $filename, stored_file $file): bool {
    global $USER;

    if ($context->contextlevel === CONTEXT_MODULE) {
        if (empty($course) || empty($cm) ||
                !in_array($filearea, [
                    'picture',
                    'face_image',
                    'violation_screenshot',
                    'id_document',
                    'id_back_document',
                    'id_live_image',
                ], true)) {
            return false;
        }

        require_login($course, true, $cm);

        if (!quizaccess_proctoring_module_file_has_record((int)$course->id, (int)$cm->id, $filearea, $filename)) {
            return false;
        }

        if (has_capability('quizaccess/proctoring:viewreport', $context)) {
            return true;
        }

        return (int)$file->get_userid() === (int)$USER->id;
    }

    if ($context->contextlevel === CONTEXT_SYSTEM) {
        if (!in_array($filearea, ['user_photo', 'face_image'], true)) {
            return false;
        }

        require_login();

        $ownerid = $filearea === 'user_photo' ? $itemid : (int)$file->get_itemid();
        if ($ownerid > 0 && (int)$USER->id === $ownerid) {
            return true;
        }

        if (has_capability('moodle/site:config', $context)) {
            return true;
        }

        return quizaccess_proctoring_user_has_report_access_for_user($ownerid);
    }

    return false;
}

/**
 * Verifies that a module-context proctoring file is referenced by a plugin record in the same module.
 *
 * @param int $courseid Course ID.
 * @param int $cmid Course module ID.
 * @param string $filearea File area.
 * @param string $filename Filename.
 * @return bool
 */
function quizaccess_proctoring_module_file_has_record(int $courseid, int $cmid, string $filearea, string $filename): bool {
    global $DB;

    $filenameparam = '%' . $DB->sql_like_escape($filename) . '%';

    if ($filearea === 'picture') {
        return $DB->record_exists_select(
            'quizaccess_proctoring_logs',
            "courseid = :courseid AND quizid = :cmid AND " .
                $DB->sql_like('webcampicture', ':filename', false),
            ['courseid' => $courseid, 'cmid' => $cmid, 'filename' => $filenameparam]
        );
    }

    if ($filearea === 'violation_screenshot') {
        return $DB->record_exists_select(
            'quizaccess_proctoring_events',
            "courseid = :courseid AND quizid = :cmid AND " .
                $DB->sql_like('screenshoturl', ':filename', false),
            ['courseid' => $courseid, 'cmid' => $cmid, 'filename' => $filenameparam]
        );
    }

    if ($filearea === 'face_image') {
        $sql = "SELECT fi.id
                  FROM {quizaccess_proctoring_face_images} fi
                  JOIN {quizaccess_proctoring_logs} l ON l.id = fi.parentid
                 WHERE l.courseid = :courseid
                   AND l.quizid = :cmid
                   AND " . $DB->sql_like('fi.faceimage', ':filename', false);

        return $DB->record_exists_sql($sql, [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'filename' => $filenameparam,
        ]);
    }

    if (in_array($filearea, ['id_document', 'id_back_document', 'id_live_image'], true)) {
        $fields = [
            'id_document' => 'idimageurl',
            'id_back_document' => 'idbackimageurl',
            'id_live_image' => 'liveimageurl',
        ];
        $field = $fields[$filearea];

        return $DB->record_exists_select(
            'quizaccess_proctoring_idv',
            "courseid = :courseid AND quizid = :cmid AND " . $DB->sql_like($field, ':filename', false),
            ['courseid' => $courseid, 'cmid' => $cmid, 'filename' => $filenameparam]
        );
    }

    return false;
}

/**
 * Checks whether the current user can view any proctoring report for a target user.
 *
 * This is used for system-context reference images because those files do not carry a module context in the URL.
 *
 * @param int $targetuserid Target user ID.
 * @return bool
 */
function quizaccess_proctoring_user_has_report_access_for_user(int $targetuserid): bool {
    global $DB;

    if ($targetuserid <= 0) {
        return false;
    }

    $recordset = $DB->get_recordset('quizaccess_proctoring_logs', ['userid' => $targetuserid], '', 'DISTINCT quizid');
    foreach ($recordset as $record) {
        try {
            $context = context_module::instance((int)$record->quizid, IGNORE_MISSING);
            if ($context && has_capability('quizaccess/proctoring:viewreport', $context)) {
                $recordset->close();
                return true;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    $recordset->close();

    return false;
}

/**
 * Checks whether a user has a passed ID verification for a quiz module.
 *
 * @param int $courseid Course ID.
 * @param int $cmid Quiz course module ID.
 * @param int $userid User ID.
 * @return bool True when a passing ID verification row exists.
 */
function quizaccess_proctoring_user_has_passed_id_verification(int $courseid, int $cmid, int $userid): bool {
    global $DB;

    if ($courseid <= 0 || $cmid <= 0 || $userid <= 0) {
        return false;
    }

    return $DB->record_exists('quizaccess_proctoring_idv', [
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $userid,
        'status' => 'pass',
    ]);
}

/**
 * Loads bytes for a pluginfile URL directly from Moodle file storage when possible.
 *
 * @param string $url Pluginfile URL.
 * @return string|false File bytes, or false when the URL is not a pluginfile URL for this plugin.
 */
function quizaccess_proctoring_pluginfile_url_to_bytes(string $url) {
    $file = quizaccess_proctoring_stored_file_from_pluginfile_url($url);
    if (!$file || $file->is_directory()) {
        return false;
    }

    return $file->get_content();
}

/**
 * Deletes a stored pluginfile URL when it belongs to this plugin.
 *
 * @param string $url Pluginfile URL.
 */
function quizaccess_proctoring_delete_pluginfile_url(string $url): void {
    $file = quizaccess_proctoring_stored_file_from_pluginfile_url($url);
    if ($file && !$file->is_directory()) {
        $file->delete();
    }
}

/**
 * Resolves a quizaccess_proctoring pluginfile URL to a stored_file.
 *
 * @param string $url Pluginfile URL.
 * @return stored_file|null Stored file, or null when it cannot be resolved.
 */
function quizaccess_proctoring_stored_file_from_pluginfile_url(string $url): ?stored_file {
    $parts = parse_url(str_replace('&amp;', '&', $url));
    if (empty($parts['path'])) {
        return null;
    }

    $queryparams = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $queryparams);
    }

    $fileargument = '';
    if (!empty($queryparams['file'])) {
        $fileargument = $queryparams['file'];
    } else {
        $marker = '/pluginfile.php/';
        $position = strpos($parts['path'], $marker);
        if ($position === false) {
            return null;
        }
        $fileargument = substr($parts['path'], $position + strlen($marker));
    }

    $segments = array_values(array_filter(explode('/', trim($fileargument, '/')), 'strlen'));
    if (count($segments) < 5) {
        return null;
    }

    $contextid = (int)array_shift($segments);
    $component = rawurldecode((string)array_shift($segments));
    $filearea = rawurldecode((string)array_shift($segments));
    $itemid = (int)array_shift($segments);
    $filename = rawurldecode((string)array_pop($segments));
    $filepath = empty($segments) ? '/' : '/' . implode('/', array_map('rawurldecode', $segments)) . '/';

    if ($component !== 'quizaccess_proctoring' || $contextid <= 0 || $itemid < 0 || $filename === '') {
        return null;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);

    return $file ?: null;
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
 * Get a human-readable face match status for a proctoring log row.
 *
 * @param int $awsflag Face match processing flag.
 * @param int $awsscore Stored face match score.
 * @return string Localized status label.
 */
function quizaccess_proctoring_get_face_match_status_label(int $awsflag, int $awsscore): string {
    switch ($awsflag) {
        case 2:
            return get_string('facematchstatus:score', 'quizaccess_proctoring', max(0, min(100, $awsscore)));
        case 3:
            return get_string('facematchstatus:noface', 'quizaccess_proctoring');
        case 101:
            return get_string('facematchstatus:apierror', 'quizaccess_proctoring');
        case 1:
            return get_string('facematchstatus:processing', 'quizaccess_proctoring');
        case 0:
        default:
            return get_string('facematchstatus:notchecked', 'quizaccess_proctoring');
    }
}

/**
 * Get a Bootstrap badge class for a proctoring log face match status.
 *
 * @param int $awsflag Face match processing flag.
 * @param int $awsscore Stored face match score.
 * @param int $threshold Configured match threshold.
 * @return string Badge CSS classes.
 */
function quizaccess_proctoring_get_face_match_status_class(int $awsflag, int $awsscore, int $threshold): string {
    if ($awsflag === 2) {
        return $awsscore >= $threshold ? 'badge badge-success' : 'badge badge-danger';
    }
    if ($awsflag === 3) {
        return 'badge badge-warning';
    }
    if ($awsflag === 101) {
        return 'badge badge-danger';
    }
    if ($awsflag === 1) {
        return 'badge badge-info';
    }

    return 'badge badge-secondary';
}

/**
 * Determines whether an event detail JSON contains a specific shortcut.
 *
 * @param string $eventdetail JSON event detail.
 * @param string $shortcut Shortcut text to match.
 * @return bool True when the shortcut matches.
 */
function quizaccess_proctoring_event_has_shortcut(string $eventdetail, string $shortcut): bool {
    return \quizaccess_proctoring\local\risk_calculator::event_has_shortcut($eventdetail, $shortcut);
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
    return \quizaccess_proctoring\local\risk_calculator::count_events(
        $eventwhere,
        $eventparams,
        $eventtypes,
        $requirescreenshot
    );
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
    return \quizaccess_proctoring\local\risk_calculator::count_shortcuts($eventwhere, $eventparams, $shortcut);
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
    return \quizaccess_proctoring\local\risk_calculator::build_factor($label, $count, $pointsperevent, $maxpoints);
}

/**
 * Get risk-level presentation details for a score.
 *
 * @param int $score Score from 0 to 100.
 * @return array Risk-level template data.
 */
function quizaccess_proctoring_get_risk_level(int $score): array {
    return \quizaccess_proctoring\local\risk_calculator::get_level($score);
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
    return \quizaccess_proctoring\local\risk_calculator::calculate_attempt($courseid, $cmid, $studentid, $reportid);
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
    $quizid = 0;
    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, IGNORE_MISSING);
    if ($cm) {
        $quizid = (int)$cm->instance;
    }
    $quizsetting = $quizid > 0 ? $DB->get_record('quizaccess_proctoring', ['quizid' => $quizid]) : false;
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
 * Determine whether students should see high-risk hold notices after submission.
 *
 * @return bool True when student notices are enabled.
 */
function quizaccess_proctoring_student_hold_notice_enabled(): bool {
    $enabled = get_config('quizaccess_proctoring', 'studentholdnoticeenabled');
    return $enabled === false ? true : (int)$enabled === 1;
}

/**
 * Get the configured Student Affairs review window.
 *
 * @return int Review window in days. Zero disables automatic release.
 */
function quizaccess_proctoring_get_risk_review_auto_release_days(): int {
    $configured = get_config('quizaccess_proctoring', 'riskreviewautoreleasedays');
    $days = $configured === false ? 7 : (int)$configured;
    return max(0, min(365, $days));
}

/**
 * Normalize an OpenAI-compatible endpoint to the chat completions route when only the service root is configured.
 *
 * @param string $endpoint Configured endpoint URL.
 * @return string Endpoint URL to call.
 */
function quizaccess_proctoring_normalize_compatible_ai_endpoint(string $endpoint): string {
    return \quizaccess_proctoring\local\outbound_endpoint_validator::normalize_compatible_endpoint($endpoint);
}

/**
 * Validates a configured outbound endpoint before the server sends proctoring images to it.
 *
 * @param string $endpoint Endpoint URL.
 * @return string Trimmed endpoint URL.
 * @throws moodle_exception If the endpoint is invalid or resolves to a blocked address.
 */
function quizaccess_proctoring_validate_outbound_endpoint(string $endpoint): string {
    return \quizaccess_proctoring\local\outbound_endpoint_validator::validate($endpoint);
}

/**
 * Resolves a host to IP addresses for outbound endpoint validation.
 *
 * @param string $host Hostname or IP address.
 * @return array IP addresses.
 */
function quizaccess_proctoring_resolve_outbound_host_ips(string $host): array {
    return \quizaccess_proctoring\local\outbound_endpoint_validator::resolve_host_ips($host);
}

/**
 * Get configured AI image review settings.
 *
 * @return array Normalized AI review settings.
 */
function quizaccess_proctoring_get_ai_review_settings(): array {
    $provider = (string)get_config('quizaccess_proctoring', 'aireviewprovider');
    if (!in_array($provider, ['openai', 'anthropic', 'compatible'], true)) {
        $provider = 'none';
    }

    $triggerthreshold = (int)get_config('quizaccess_proctoring', 'aireviewtriggerthreshold');
    if ($triggerthreshold <= 0) {
        $triggerthreshold = 80;
    }
    $decisionthreshold = (int)get_config('quizaccess_proctoring', 'aireviewdecisionthreshold');
    if ($decisionthreshold <= 0) {
        $decisionthreshold = 80;
    }
    $maximages = (int)get_config('quizaccess_proctoring', 'aireviewmaximages');
    if ($maximages <= 0) {
        $maximages = 6;
    }
    $desktopmode = (string)get_config('quizaccess_proctoring', 'aireviewdesktopmode');
    if (!in_array($desktopmode, ['off', 'threshold', 'aitool', 'all'], true)) {
        $desktopmode = 'threshold';
    }

    return [
        'enabled' => (int)get_config('quizaccess_proctoring', 'aireviewenabled') === 1,
        'provider' => $provider,
        'desktopmode' => $desktopmode,
        'openaiapikey' => (string)get_config('quizaccess_proctoring', 'aireviewopenaiapikey'),
        'openaimodel' => trim((string)get_config('quizaccess_proctoring', 'aireviewopenaimodel')) ?: 'gpt-4.1-mini',
        'anthropicapikey' => (string)get_config('quizaccess_proctoring', 'aireviewanthropicapikey'),
        'anthropicmodel' => trim((string)get_config('quizaccess_proctoring', 'aireviewanthropicmodel')) ?:
            'claude-sonnet-4-5-20250929',
        'compatibleendpoint' => quizaccess_proctoring_normalize_compatible_ai_endpoint(
            (string)get_config('quizaccess_proctoring', 'aireviewcompatibleendpoint')
        ),
        'compatibleapikey' => (string)get_config('quizaccess_proctoring', 'aireviewcompatibleapikey'),
        'compatiblemodel' => trim((string)get_config('quizaccess_proctoring', 'aireviewcompatiblemodel')),
        'triggerthreshold' => max(1, min(100, $triggerthreshold)),
        'decisionthreshold' => max(1, min(100, $decisionthreshold)),
        'maximages' => max(1, min(12, $maximages)),
    ];
}

/**
 * Determine whether AI review has enough configuration to run.
 *
 * @param array|null $settings Optional normalized settings.
 * @return bool True when AI image review can run.
 */
function quizaccess_proctoring_ai_review_configured(?array $settings = null): bool {
    $settings = $settings ?? quizaccess_proctoring_get_ai_review_settings();

    if (empty($settings['enabled'])) {
        return false;
    }

    return quizaccess_proctoring_ai_review_provider_configured((string)$settings['provider'], $settings);
}

/**
 * Determine whether a specific AI review provider has enough configuration to run.
 *
 * @param string $provider Provider key.
 * @param array|null $settings Optional normalized settings.
 * @return bool True when the provider can be called.
 */
function quizaccess_proctoring_ai_review_provider_configured(string $provider, ?array $settings = null): bool {
    $settings = $settings ?? quizaccess_proctoring_get_ai_review_settings();

    switch ($provider) {
        case 'openai':
            return !empty($settings['openaiapikey']) && !empty($settings['openaimodel']);
        case 'anthropic':
            return !empty($settings['anthropicapikey']) && !empty($settings['anthropicmodel']);
        case 'compatible':
            if (empty($settings['compatibleendpoint']) || empty($settings['compatiblemodel'])) {
                return false;
            }
            try {
                quizaccess_proctoring_validate_outbound_endpoint((string)$settings['compatibleendpoint']);
            } catch (moodle_exception $e) {
                return false;
            }
            return true;
        default:
            return false;
    }
}

/**
 * Get a readable AI review provider label.
 *
 * @param string $provider Provider key.
 * @return string Provider label.
 */
function quizaccess_proctoring_get_ai_review_provider_label(string $provider): string {
    $key = 'setting:aireviewprovider_' . $provider;
    if (get_string_manager()->string_exists($key, 'quizaccess_proctoring')) {
        return get_string($key, 'quizaccess_proctoring');
    }

    return strtoupper($provider);
}

/**
 * Get the configured model name for the active AI review provider.
 *
 * @param array $settings Normalized AI review settings.
 * @return string Active provider model.
 */
function quizaccess_proctoring_get_ai_review_model(array $settings): string {
    switch ($settings['provider']) {
        case 'anthropic':
            return (string)$settings['anthropicmodel'];
        case 'compatible':
            return (string)$settings['compatiblemodel'];
        case 'openai':
        default:
            return (string)$settings['openaimodel'];
    }
}

/**
 * Get the latest AI review record for an attempt or report.
 *
 * @param int $courseid Course id.
 * @param int $cmid Quiz course-module id.
 * @param int $userid User id.
 * @param int $attemptid Quiz attempt id.
 * @param int $reportid Proctoring report id.
 * @return stdClass|false AI review record or false.
 */
function quizaccess_proctoring_get_ai_review(
    int $courseid,
    int $cmid,
    int $userid,
    int $attemptid = 0,
    int $reportid = 0
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
    $where .= ' AND eventid = :eventid AND reviewtype = :reviewtype';
    $params['eventid'] = 0;
    $params['reviewtype'] = 'attempt';

    $records = $DB->get_records_select(
        'quizaccess_proctoring_ai_reviews',
        $where,
        $params,
        'id DESC',
        '*',
        0,
        1
    );

    return $records ? reset($records) : false;
}

/**
 * Get the latest AI review record for one suspicious activity event.
 *
 * @param int $eventid Proctoring event id.
 * @return stdClass|false AI review record or false.
 */
function quizaccess_proctoring_get_ai_event_review(int $eventid) {
    global $DB;

    if ($eventid <= 0) {
        return false;
    }

    $records = $DB->get_records(
        'quizaccess_proctoring_ai_reviews',
        ['eventid' => $eventid, 'reviewtype' => 'event'],
        'id DESC',
        '*',
        0,
        1
    );

    return $records ? reset($records) : false;
}

/**
 * Determine whether a desktop event should be queued for immediate AI review.
 *
 * @param stdClass $event Proctoring event row.
 * @param array $settings Normalized AI review settings.
 * @return bool True when the event should be queued.
 */
function quizaccess_proctoring_should_queue_event_ai_review(stdClass $event, array $settings): bool {
    if (empty($event->screenshoturl)) {
        return false;
    }

    $eventtype = (string)$event->eventtype;
    $aitoolrelevantevents = [
        'possible_ai_tool',
        'focus_lost',
        'tab_hidden',
        'page_exit',
    ];
    $mode = (string)($settings['desktopmode'] ?? 'threshold');
    if ($mode === 'all') {
        return true;
    }
    if ($mode === 'aitool' || $mode === 'threshold') {
        return in_array($eventtype, $aitoolrelevantevents, true);
    }

    return false;
}

/**
 * Queue an AI image review for a high-risk attempt.
 *
 * @param int $courseid Course id.
 * @param int $cmid Quiz course-module id.
 * @param int $userid User id.
 * @param int $attemptid Quiz attempt id.
 * @param int $reportid Proctoring report id.
 * @param int $holdid Risk hold id, or zero if no hold was applied.
 * @param int $riskscore Risk score that triggered review.
 * @param int $triggerthreshold AI review trigger threshold.
 * @return int AI review id, or zero when not queued.
 */
function quizaccess_proctoring_queue_ai_review(
    int $courseid,
    int $cmid,
    int $userid,
    int $attemptid,
    int $reportid,
    int $holdid,
    int $riskscore,
    int $triggerthreshold
): int {
    global $DB;

    $settings = quizaccess_proctoring_get_ai_review_settings();
    if (!quizaccess_proctoring_ai_review_configured($settings) || $riskscore < $settings['triggerthreshold']) {
        return 0;
    }
    $model = quizaccess_proctoring_get_ai_review_model($settings);

    $existing = quizaccess_proctoring_get_ai_review($courseid, $cmid, $userid, $attemptid, $reportid);
    if ($existing) {
        $existing->holdid = $holdid > 0 ? $holdid : (int)$existing->holdid;
        $existing->riskscore = $riskscore;
        $existing->triggerthreshold = $triggerthreshold;
        $existing->provider = $settings['provider'];
        $existing->model = $model;
        $existing->timemodified = time();
        if ((int)$existing->status === QUIZACCESS_PROCTORING_AI_REVIEW_FAILED) {
            $existing->status = QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED;
            $existing->errormessage = '';
        }
        $DB->update_record('quizaccess_proctoring_ai_reviews', $existing);
        return (int)$existing->id;
    }

    $now = time();
    $review = (object)[
        'courseid' => $courseid,
        'quizid' => $cmid,
        'userid' => $userid,
        'attemptid' => $attemptid,
        'reportid' => $reportid,
        'eventid' => 0,
        'reviewtype' => 'attempt',
        'holdid' => $holdid,
        'riskscore' => $riskscore,
        'triggerthreshold' => $triggerthreshold,
        'provider' => $settings['provider'],
        'model' => $model,
        'reviewscore' => 0,
        'decision' => '',
        'status' => QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED,
        'summary' => '',
        'evidence' => '',
        'rawresponse' => '',
        'errormessage' => '',
        'timecreated' => $now,
        'timemodified' => $now,
        'timereviewed' => 0,
    ];

    return (int)$DB->insert_record('quizaccess_proctoring_ai_reviews', $review, true);
}

/**
 * Queue an AI review for a single desktop violation screenshot.
 *
 * @param int $eventid Proctoring event id.
 * @return int AI review id, or zero when not queued.
 */
function quizaccess_proctoring_queue_event_ai_review(int $eventid): int {
    global $DB;

    if ($eventid <= 0) {
        return 0;
    }

    $settings = quizaccess_proctoring_get_ai_review_settings();
    if (!quizaccess_proctoring_ai_review_configured($settings)) {
        return 0;
    }

    $event = $DB->get_record('quizaccess_proctoring_events', ['id' => $eventid]);
    if (!$event || !quizaccess_proctoring_should_queue_event_ai_review($event, $settings)) {
        return 0;
    }

    $model = quizaccess_proctoring_get_ai_review_model($settings);
    $existing = quizaccess_proctoring_get_ai_event_review($eventid);
    if ($existing) {
        $existing->provider = $settings['provider'];
        $existing->model = $model;
        $existing->timemodified = time();
        if ((int)$existing->status === QUIZACCESS_PROCTORING_AI_REVIEW_FAILED) {
            $existing->status = QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED;
            $existing->errormessage = '';
        }
        $DB->update_record('quizaccess_proctoring_ai_reviews', $existing);
        return (int)$existing->id;
    }

    $now = time();
    $review = (object)[
        'courseid' => (int)$event->courseid,
        'quizid' => (int)$event->quizid,
        'userid' => (int)$event->userid,
        'attemptid' => (int)$event->attemptid,
        'reportid' => (int)$event->reportid,
        'eventid' => (int)$event->id,
        'reviewtype' => 'event',
        'holdid' => 0,
        'riskscore' => 0,
        'triggerthreshold' => (int)$settings['triggerthreshold'],
        'provider' => $settings['provider'],
        'model' => $model,
        'reviewscore' => 0,
        'decision' => '',
        'status' => QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED,
        'summary' => '',
        'evidence' => '',
        'rawresponse' => '',
        'errormessage' => '',
        'timecreated' => $now,
        'timemodified' => $now,
        'timereviewed' => 0,
    ];

    return (int)$DB->insert_record('quizaccess_proctoring_ai_reviews', $review, true);
}

/**
 * Get an AI review status label.
 *
 * @param int $status Review status.
 * @return string Status label.
 */
function quizaccess_proctoring_get_ai_review_status_label(int $status): string {
    switch ($status) {
        case QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING:
            return get_string('aireview:statusprocessing', 'quizaccess_proctoring');
        case QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE:
            return get_string('aireview:statuscomplete', 'quizaccess_proctoring');
        case QUIZACCESS_PROCTORING_AI_REVIEW_FAILED:
            return get_string('aireview:statusfailed', 'quizaccess_proctoring');
        case QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED:
        default:
            return get_string('aireview:statusqueued', 'quizaccess_proctoring');
    }
}

/**
 * Get an AI review decision label.
 *
 * @param string $decision Stored decision key.
 * @return string Decision label.
 */
function quizaccess_proctoring_get_ai_review_decision_label(string $decision): string {
    $key = 'aireview:decision:' . $decision;
    if (get_string_manager()->string_exists($key, 'quizaccess_proctoring')) {
        return get_string($key, 'quizaccess_proctoring');
    }

    return ucfirst(str_replace('_', ' ', $decision));
}

/**
 * Format an AI review record for report templates.
 *
 * @param stdClass $aireview AI review row.
 * @param array|null $settings Optional normalized settings.
 * @return array Template data.
 */
function quizaccess_proctoring_format_ai_review_for_template(stdClass $aireview, ?array $settings = null): array {
    $settings = $settings ?? quizaccess_proctoring_get_ai_review_settings();
    $evidence = json_decode((string)$aireview->evidence, true);
    if (!is_array($evidence)) {
        $evidence = [];
    }

    $evidenceitems = [];
    foreach ($evidence as $item) {
        if (trim((string)$item) !== '') {
            $evidenceitems[] = ['text' => (string)$item];
        }
    }

    $iscomplete = (int)$aireview->status === QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE;
    $isfailed = (int)$aireview->status === QUIZACCESS_PROCTORING_AI_REVIEW_FAILED;
    $isqueued = (int)$aireview->status === QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED;
    $isprocessing = (int)$aireview->status === QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING;
    $decisionthreshold = (int)($settings['decisionthreshold'] ?? 80);
    $isflagged = $iscomplete &&
        (int)$aireview->reviewscore >= $decisionthreshold &&
        (string)$aireview->decision === 'highly_suspicious';
    $decisionlabel = quizaccess_proctoring_get_ai_review_decision_label((string)$aireview->decision);
    $statuslabel = quizaccess_proctoring_get_ai_review_status_label((int)$aireview->status);
    $score = (int)$aireview->reviewscore;
    $badgeclass = 'badge badge-info';
    if ($iscomplete) {
        $badgeclass = $isflagged ? 'badge badge-danger' : 'badge badge-success';
    } else if ($isfailed) {
        $badgeclass = 'badge badge-danger';
    }

    return [
        'statuslabel' => $statuslabel,
        'badgeclass' => $badgeclass,
        'provider' => strtoupper((string)$aireview->provider),
        'providerlabel' => quizaccess_proctoring_get_ai_review_provider_label((string)$aireview->provider),
        'model' => $aireview->model,
        'reviewscore' => $score,
        'reviewscorelabel' => $iscomplete ? get_string('aireview:scorelabel', 'quizaccess_proctoring', $score) : '',
        'decisionlabel' => $decisionlabel,
        'summary' => $aireview->summary,
        'evidenceitems' => $evidenceitems,
        'hasevidence' => !empty($evidenceitems),
        'errormessage' => $aireview->errormessage,
        'haserror' => trim((string)$aireview->errormessage) !== '',
        'iscomplete' => $iscomplete,
        'isfailed' => $isfailed,
        'isqueued' => $isqueued,
        'isprocessing' => $isprocessing,
        'isflagged' => $isflagged,
        'thresholdlabel' => get_string(
            'aireview:thresholdlabel',
            'quizaccess_proctoring',
            $decisionthreshold
        ),
        'timereviewed' => !empty($aireview->timereviewed) ? userdate((int)$aireview->timereviewed) : '',
        'compactlabel' => $iscomplete
            ? $score . '/100 ' . $decisionlabel
            : $statuslabel,
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
 * Get a display label for a risk hold status.
 *
 * @param stdClass $hold Hold record.
 * @return string Localized status label.
 */
function quizaccess_proctoring_get_risk_hold_status_label(stdClass $hold): string {
    switch ((int)$hold->status) {
        case QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED:
            return get_string('riskreview:confirmed', 'quizaccess_proctoring');
        case QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED:
            return get_string('riskreview:released', 'quizaccess_proctoring');
        case QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE:
        default:
            return get_string('riskreview:active', 'quizaccess_proctoring');
    }
}

/**
 * Build the student-facing notice for an active high-risk review hold.
 *
 * @param stdClass $hold Active risk hold.
 * @return string Rendered Bootstrap alert HTML.
 */
function quizaccess_proctoring_get_student_risk_hold_notice_html(stdClass $hold): string {
    $days = quizaccess_proctoring_get_risk_review_auto_release_days();
    $deadline = '';
    if ($days > 0) {
        $deadline = (int)$hold->timecreated + ($days * DAYSECS);
    }

    $message = get_string('riskreview:studentnoticebody', 'quizaccess_proctoring', (object)[
        'score' => (int)$hold->riskscore,
        'threshold' => (int)$hold->threshold,
        'days' => $days,
    ]);
    if ($deadline) {
        $message .= ' ' . get_string('riskreview:studentnoticereviewwindow', 'quizaccess_proctoring', $days);
        $message .= ' ' . get_string('riskreview:studentnoticedeadline', 'quizaccess_proctoring', userdate($deadline));
    } else {
        $message .= ' ' . get_string('riskreview:studentnoticenorelease', 'quizaccess_proctoring');
    }

    $title = html_writer::tag(
        'strong',
        s(get_string('riskreview:studentnoticetitle', 'quizaccess_proctoring')),
        ['class' => 'd-block mb-1']
    );

    return html_writer::tag(
        'div',
        $title . html_writer::tag('div', s($message)),
        ['class' => 'alert alert-warning quizaccess-proctoring-risk-hold-notice', 'role' => 'alert']
    );
}

/**
 * Get the configured high-risk attempt lockout length in days.
 *
 * @return int Number of days to block retakes. Zero means disabled.
 */
function quizaccess_proctoring_get_cheating_lockout_days(): int {
    if ((int)get_config('quizaccess_proctoring', 'cheatinglockoutenabled') !== 1) {
        return 0;
    }

    $days = (int)get_config('quizaccess_proctoring', 'cheatinglockoutdays');
    return max(0, $days);
}

/**
 * Get the active high-risk retake lockout for a student and quiz.
 *
 * Pending high-risk holds block retakes immediately so students cannot use failed
 * or throwaway attempts to test the proctoring system. Releasing a hold clears
 * the lockout. Confirmed holds continue to block until the same high-risk window
 * expires.
 *
 * @param int $courseid Course id.
 * @param int $cmid Quiz course-module id.
 * @param int $userid User id.
 * @param int $now Current timestamp.
 * @return array|false Lockout details, or false when no lockout applies.
 */
function quizaccess_proctoring_get_active_cheating_lockout(
    int $courseid,
    int $cmid,
    int $userid,
    int $now = 0
) {
    global $DB;

    $days = quizaccess_proctoring_get_cheating_lockout_days();
    if ($days <= 0) {
        return false;
    }

    $now = $now > 0 ? $now : time();
    $records = $DB->get_records_select(
        'quizaccess_proctoring_risk_holds',
        'courseid = :courseid AND quizid = :cmid AND userid = :userid
            AND (status = :activestatus OR status = :confirmedstatus)',
        [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'userid' => $userid,
            'activestatus' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'confirmedstatus' => QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED,
        ],
        'timecreated DESC, id DESC'
    );
    if (!$records) {
        return false;
    }

    foreach ($records as $hold) {
        $anchor = (int)$hold->timecreated > 0 ? (int)$hold->timecreated : (int)$hold->timereviewed;
        if ($anchor <= 0) {
            continue;
        }
        $until = $anchor + ($days * DAYSECS);
        if ($until <= $now) {
            continue;
        }

        return [
            'hold' => $hold,
            'days' => $days,
            'until' => $until,
        ];
    }

    return false;
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
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
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
    if ((int)$hold->status !== QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
        return (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED;
    }

    require_once($CFG->dirroot . '/mod/quiz/lib.php');
    $cm = get_coursemodule_from_id('quiz', $hold->quizid, $hold->courseid, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $hold->quizinstance], '*', MUST_EXIST);
    $quiz->cmidnumber = $cm->idnumber;
    $quiz->visible = $cm->visible;

    quiz_update_grades($quiz, $hold->userid, false);

    $hold->status = QUIZACCESS_PROCTORING_RISK_HOLD_RELEASED;
    $hold->reviewerid = $reviewerid;
    $hold->timereviewed = time();
    $hold->timemodified = $hold->timereviewed;
    $DB->update_record('quizaccess_proctoring_risk_holds', $hold);

    return true;
}

/**
 * Automatically release active risk holds whose review window has expired.
 *
 * @param int $limit Maximum holds to release in one task run.
 * @return int Number of holds released.
 */
function quizaccess_proctoring_auto_release_expired_risk_holds(int $limit = 100): int {
    global $DB;

    $days = quizaccess_proctoring_get_risk_review_auto_release_days();
    if ($days <= 0) {
        return 0;
    }

    $limit = max(1, min(500, $limit));
    $cutoff = time() - ($days * DAYSECS);
    $holds = $DB->get_records_select(
        'quizaccess_proctoring_risk_holds',
        'status = :status AND timecreated > 0 AND timecreated <= :cutoff',
        [
            'status' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'cutoff' => $cutoff,
        ],
        'timecreated ASC, id ASC',
        '*',
        0,
        $limit
    );

    $released = 0;
    foreach ($holds as $hold) {
        if (quizaccess_proctoring_release_risk_hold((int)$hold->id, 0)) {
            $released++;
        }
    }

    return $released;
}

/**
 * Confirm a proctoring violation and keep the quiz grade held at zero.
 *
 * @param int $holdid Hold id.
 * @param int $reviewerid Reviewer user id.
 * @return bool True when confirmed or already confirmed.
 */
function quizaccess_proctoring_confirm_risk_hold(int $holdid, int $reviewerid): bool {
    global $CFG, $DB;

    $hold = $DB->get_record('quizaccess_proctoring_risk_holds', ['id' => $holdid], '*', MUST_EXIST);
    if ((int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED) {
        return true;
    }
    if ((int)$hold->status !== QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
        return false;
    }

    require_once($CFG->dirroot . '/mod/quiz/lib.php');
    $cm = get_coursemodule_from_id('quiz', $hold->quizid, $hold->courseid, false, MUST_EXIST);
    $quiz = $DB->get_record('quiz', ['id' => $hold->quizinstance], '*', MUST_EXIST);
    $quiz->cmidnumber = $cm->idnumber;
    $quiz->visible = $cm->visible;

    $now = time();
    $grade = (object)[
        'userid' => $hold->userid,
        'rawgrade' => 0,
        'feedback' => get_string('riskreview:confirmedgradefeedback', 'quizaccess_proctoring', $hold->riskscore),
        'feedbackformat' => FORMAT_PLAIN,
        'usermodified' => $reviewerid,
        'dategraded' => $now,
    ];
    quiz_grade_item_update($quiz, $grade);

    $hold->status = QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED;
    $hold->reviewerid = $reviewerid;
    $hold->timereviewed = $now;
    $hold->timemodified = $now;
    $DB->update_record('quizaccess_proctoring_risk_holds', $hold);

    return true;
}

/**
 * Execute queued AI image review tasks.
 *
 * @param int $limit Maximum reviews to process.
 * @return void
 */
function quizaccess_proctoring_execute_ai_review_task(int $limit = 3): void {
    global $DB;

    $settings = quizaccess_proctoring_get_ai_review_settings();
    if (!quizaccess_proctoring_ai_review_configured($settings)) {
        mtrace('Saylor Proctored Quiz AI image review is disabled or missing provider settings.');
        return;
    }

    $reviews = $DB->get_records(
        'quizaccess_proctoring_ai_reviews',
        ['status' => QUIZACCESS_PROCTORING_AI_REVIEW_QUEUED],
        'timecreated ASC',
        '*',
        0,
        max(1, $limit)
    );

    if (!$reviews) {
        mtrace('No Saylor Proctored Quiz AI image reviews queued.');
        return;
    }

    foreach ($reviews as $review) {
        quizaccess_proctoring_process_ai_review($review, $settings);
    }
}

/**
 * Process one AI image review record.
 *
 * @param stdClass $review AI review row.
 * @param array $settings Normalized AI review settings.
 * @return void
 */
function quizaccess_proctoring_process_ai_review(stdClass $review, array $settings): void {
    global $DB;

    $now = time();
    $review->status = QUIZACCESS_PROCTORING_AI_REVIEW_PROCESSING;
    $review->timemodified = $now;
    $DB->update_record('quizaccess_proctoring_ai_reviews', $review);

    try {
        $images = quizaccess_proctoring_collect_ai_review_images($review, (int)$settings['maximages'], $settings);
        if (empty($images)) {
            throw new moodle_exception('aireview:noimages', 'quizaccess_proctoring');
        }

        $result = quizaccess_proctoring_call_ai_review_provider((string)$settings['provider'], $review, $images, $settings);
        if (empty($result)) {
            throw new moodle_exception('aireview:providerempty', 'quizaccess_proctoring');
        }

        $review->reviewscore = max(0, min(100, (int)($result['review_score'] ?? 0)));
        $review->decision = substr((string)($result['decision'] ?? 'inconclusive'), 0, 40);
        $review->summary = substr((string)($result['summary'] ?? ''), 0, 4000);
        $review->evidence = json_encode(array_slice((array)($result['evidence'] ?? []), 0, 8));
        $review->rawresponse = json_encode($result);
        $review->errormessage = '';
        $review->status = QUIZACCESS_PROCTORING_AI_REVIEW_COMPLETE;
        $review->timereviewed = time();
        $review->timemodified = $review->timereviewed;
        $DB->update_record('quizaccess_proctoring_ai_reviews', $review);

        mtrace('Completed AI image review id ' . $review->id . ' with score ' . $review->reviewscore . '.');
    } catch (Throwable $e) {
        $review->status = QUIZACCESS_PROCTORING_AI_REVIEW_FAILED;
        $review->errormessage = substr($e->getMessage(), 0, 2000);
        $review->timemodified = time();
        $DB->update_record('quizaccess_proctoring_ai_reviews', $review);
        mtrace('AI image review failed for id ' . $review->id . ': ' . $e->getMessage());
    }
}

/**
 * Call one configured AI review provider.
 *
 * @param string $provider Provider key.
 * @param stdClass $review AI review row-like object.
 * @param array $images Image data rows.
 * @param array $settings Normalized AI review settings.
 * @return array|null Parsed structured result.
 */
function quizaccess_proctoring_call_ai_review_provider(
    string $provider,
    stdClass $review,
    array $images,
    array $settings
): ?array {
    $settings['provider'] = $provider;

    switch ($provider) {
        case 'anthropic':
            return quizaccess_proctoring_call_anthropic_image_review($review, $images, $settings);
        case 'compatible':
            return quizaccess_proctoring_call_openai_compatible_image_review($review, $images, $settings);
        case 'openai':
            return quizaccess_proctoring_call_openai_image_review($review, $images, $settings);
        default:
            throw new moodle_exception('aireview:providerempty', 'quizaccess_proctoring');
    }
}

/**
 * Collect representative screenshots and webcam captures for AI review.
 *
 * @param stdClass $review AI review row.
 * @param int $maximages Maximum image count.
 * @param array|null $settings Optional normalized AI review settings.
 * @return array Image data rows.
 */
function quizaccess_proctoring_collect_ai_review_images(
    stdClass $review,
    int $maximages,
    ?array $settings = null
): array {
    global $DB;

    $maximages = max(1, min(12, $maximages));
    $settings = $settings ?? quizaccess_proctoring_get_ai_review_settings();
    $images = [];
    $attemptid = (int)$review->attemptid;
    $eventid = (int)($review->eventid ?? 0);

    if ($eventid > 0 || (string)($review->reviewtype ?? '') === 'event') {
        if ((string)($settings['desktopmode'] ?? 'threshold') === 'off') {
            return [];
        }

        $event = $DB->get_record(
            'quizaccess_proctoring_events',
            ['id' => $eventid],
            'id, eventtype, screenshoturl, timemodified'
        );
        if (!$event || empty($event->screenshoturl)) {
            return [];
        }

        $dataurl = quizaccess_proctoring_url_to_data_url((string)$event->screenshoturl);
        if (!$dataurl) {
            return [];
        }

        return [[
            'label' => get_string('aireview:imageevent', 'quizaccess_proctoring', (object)[
                'eventtype' => quizaccess_proctoring_get_readable_ai_event_type((string)$event->eventtype),
                'time' => userdate((int)$event->timemodified),
            ]),
            'dataurl' => $dataurl,
        ]];
    }

    if ((string)($settings['desktopmode'] ?? 'threshold') !== 'off') {
        $eventwhere = "courseid = :courseid AND quizid = :quizid AND userid = :userid
            AND COALESCE(screenshoturl, '') <> ''";
        $eventparams = [
            'courseid' => (int)$review->courseid,
            'quizid' => (int)$review->quizid,
            'userid' => (int)$review->userid,
        ];
        if ($attemptid > 0) {
            $eventwhere .= ' AND attemptid = :attemptid';
            $eventparams['attemptid'] = $attemptid;
        } else if ((int)$review->reportid > 0) {
            $eventwhere .= ' AND reportid = :reportid';
            $eventparams['reportid'] = (int)$review->reportid;
        }

        $events = $DB->get_records_select(
            'quizaccess_proctoring_events',
            $eventwhere,
            $eventparams,
            "CASE WHEN eventtype = 'possible_ai_tool' THEN 0 ELSE 1 END, timemodified DESC",
            'id, eventtype, screenshoturl, timemodified',
            0,
            $maximages
        );

        foreach ($events as $event) {
            $dataurl = quizaccess_proctoring_url_to_data_url((string)$event->screenshoturl);
            if (!$dataurl) {
                continue;
            }
            $images[] = [
                'label' => get_string('aireview:imageevent', 'quizaccess_proctoring', (object)[
                    'eventtype' => quizaccess_proctoring_get_readable_ai_event_type((string)$event->eventtype),
                    'time' => userdate((int)$event->timemodified),
                ]),
                'dataurl' => $dataurl,
            ];
            if (count($images) >= $maximages) {
                return $images;
            }
        }
    }

    $remaining = $maximages - count($images);
    if ($remaining <= 0) {
        return $images;
    }

    $logwhere = "courseid = :courseid AND quizid = :quizid AND userid = :userid
        AND deletionprogress = :deletionprogress AND COALESCE(webcampicture, '') <> ''";
    $logparams = [
        'courseid' => (int)$review->courseid,
        'quizid' => (int)$review->quizid,
        'userid' => (int)$review->userid,
        'deletionprogress' => 0,
    ];
    if ($attemptid > 0) {
        $logwhere .= ' AND status = :attemptid';
        $logparams['attemptid'] = $attemptid;
    } else if ((int)$review->reportid > 0) {
        $logwhere .= ' AND id = :reportid';
        $logparams['reportid'] = (int)$review->reportid;
    }

    $logs = $DB->get_records_select(
        'quizaccess_proctoring_logs',
        $logwhere,
        $logparams,
        'timemodified ASC',
        'id, webcampicture, awsscore, awsflag, timemodified',
        0,
        max($remaining * 3, $remaining)
    );
    $logs = quizaccess_proctoring_sample_records(array_values($logs), $remaining);

    foreach ($logs as $log) {
        $dataurl = quizaccess_proctoring_url_to_data_url((string)$log->webcampicture);
        if (!$dataurl) {
            continue;
        }
        $images[] = [
            'label' => get_string('aireview:imagewebcam', 'quizaccess_proctoring', (object)[
                'time' => userdate((int)$log->timemodified),
                'facematchstatus' => quizaccess_proctoring_get_face_match_status_label(
                    (int)$log->awsflag,
                    (int)$log->awsscore
                ),
            ]),
            'dataurl' => $dataurl,
        ];
        if (count($images) >= $maximages) {
            break;
        }
    }

    return $images;
}

/**
 * Return first, middle, and last records when a set is larger than the limit.
 *
 * @param array $records Ordered records.
 * @param int $limit Maximum records.
 * @return array Sampled records.
 */
function quizaccess_proctoring_sample_records(array $records, int $limit): array {
    $count = count($records);
    if ($count <= $limit) {
        return $records;
    }

    $sampled = [];
    for ($i = 0; $i < $limit; $i++) {
        $index = (int)round($i * (($count - 1) / max(1, $limit - 1)));
        $sampled[$index] = $records[$index];
    }

    return array_values($sampled);
}

/**
 * Convert an image URL into a data URL suitable for OpenAI image input.
 *
 * @param string $url Image URL.
 * @return string|null Data URL, or null when the image cannot be loaded.
 */
function quizaccess_proctoring_url_to_data_url(string $url): ?string {
    if (trim($url) === '') {
        return null;
    }
    if (strpos($url, 'data:image/') === 0) {
        $parts = quizaccess_proctoring_data_url_parts($url);
        if (!$parts) {
            return null;
        }
        $imagebytes = base64_decode($parts['data'], true);
        return $imagebytes ? quizaccess_proctoring_image_bytes_to_ai_data_url($imagebytes) : null;
    }

    $imagebytes = quizaccess_proctoring_pluginfile_url_to_bytes($url);
    if ($imagebytes === false || $imagebytes === '') {
        return null;
    }

    return quizaccess_proctoring_image_bytes_to_ai_data_url($imagebytes);
}

/**
 * Compress image bytes into a moderate-size JPEG data URL for AI review payloads.
 *
 * @param string $imagebytes Raw image bytes.
 * @return string|null Data URL, or null when the image cannot be processed.
 */
function quizaccess_proctoring_image_bytes_to_ai_data_url(string $imagebytes): ?string {
    $info = @getimagesizefromstring($imagebytes);
    $mime = $info['mime'] ?? 'image/jpeg';
    if (strpos($mime, 'image/') !== 0) {
        $mime = 'image/jpeg';
    }

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && !empty($info[0]) && !empty($info[1])) {
        $source = @imagecreatefromstring($imagebytes);
        if ($source) {
            $sourcewidth = (int)$info[0];
            $sourceheight = (int)$info[1];
            $maxdimension = 1280;
            $scale = min(1, $maxdimension / max($sourcewidth, $sourceheight));
            $targetwidth = max(1, (int)round($sourcewidth * $scale));
            $targetheight = max(1, (int)round($sourceheight * $scale));
            $target = imagecreatetruecolor($targetwidth, $targetheight);
            if ($target) {
                $white = imagecolorallocate($target, 255, 255, 255);
                imagefilledrectangle($target, 0, 0, $targetwidth, $targetheight, $white);
                imagecopyresampled(
                    $target,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetwidth,
                    $targetheight,
                    $sourcewidth,
                    $sourceheight
                );
                ob_start();
                imagejpeg($target, null, 72);
                $compressed = (string)ob_get_clean();
                imagedestroy($target);
                imagedestroy($source);
                if ($compressed !== '') {
                    return 'data:image/jpeg;base64,' . base64_encode($compressed);
                }
            } else {
                imagedestroy($source);
            }
        }
    }

    return 'data:' . $mime . ';base64,' . base64_encode($imagebytes);
}

/**
 * Get a readable event type for the AI prompt.
 *
 * @param string $eventtype Event type.
 * @return string Human-readable event type.
 */
function quizaccess_proctoring_get_readable_ai_event_type(string $eventtype): string {
    $key = 'eventtype:' . $eventtype;
    if (get_string_manager()->string_exists($key, 'quizaccess_proctoring')) {
        return get_string($key, 'quizaccess_proctoring');
    }

    return ucfirst(str_replace('_', ' ', $eventtype));
}

/**
 * Return compact event details for an AI prompt.
 *
 * @param string $eventdetail JSON event details.
 * @return string Readable detail string.
 */
function quizaccess_proctoring_format_ai_event_detail(string $eventdetail): string {
    $decoded = json_decode($eventdetail, true);
    if (!is_array($decoded)) {
        return substr($eventdetail, 0, 500);
    }

    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $parts[] = $key . ': ' . substr((string)$value, 0, 180);
    }

    return substr(implode('; ', $parts), 0, 700);
}

/**
 * Get the normalized JSON schema used by AI image review providers.
 *
 * @return array JSON schema array.
 */
function quizaccess_proctoring_ai_review_json_schema(): array {
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'review_score' => [
                'type' => 'integer',
            ],
            'decision' => [
                'type' => 'string',
                'enum' => ['no_visual_evidence', 'inconclusive', 'highly_suspicious'],
            ],
            'cheating_likely' => [
                'type' => 'boolean',
            ],
            'summary' => [
                'type' => 'string',
            ],
            'evidence' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
            'recommended_action' => [
                'type' => 'string',
                'enum' => ['release', 'manual_review', 'escalate'],
            ],
        ],
        'required' => [
            'review_score',
            'decision',
            'cheating_likely',
            'summary',
            'evidence',
            'recommended_action',
        ],
    ];
}

/**
 * Split a data URL into MIME type and base64 payload.
 *
 * @param string $dataurl Image data URL.
 * @return array|null Data URL parts, or null when malformed.
 */
function quizaccess_proctoring_data_url_parts(string $dataurl): ?array {
    if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $dataurl, $matches)) {
        return null;
    }

    return [
        'mime' => $matches[1],
        'data' => $matches[2],
    ];
}

/**
 * Extract the first JSON object from model text.
 *
 * @param string $text Model output.
 * @return array|null Decoded JSON object.
 */
function quizaccess_proctoring_extract_json_object(string $text): ?array {
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Call OpenAI Responses API for proctoring image review.
 *
 * @param stdClass $review AI review row.
 * @param array $images Image data rows.
 * @param array $settings Normalized AI review settings.
 * @return array|null Parsed structured result.
 */
function quizaccess_proctoring_call_openai_image_review(stdClass $review, array $images, array $settings): ?array {
    $content = [[
        'type' => 'input_text',
        'text' => quizaccess_proctoring_build_ai_review_prompt($review, count($images), $settings),
    ]];
    foreach ($images as $index => $image) {
        $content[] = [
            'type' => 'input_text',
            'text' => 'Image ' . ($index + 1) . ': ' . $image['label'],
        ];
        $content[] = [
            'type' => 'input_image',
            'image_url' => $image['dataurl'],
            'detail' => 'low',
        ];
    }

    $payload = [
        'model' => $settings['openaimodel'],
        'input' => [[
            'role' => 'user',
            'content' => $content,
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'saylor_proctoring_ai_review',
                'strict' => true,
                'schema' => quizaccess_proctoring_ai_review_json_schema(),
            ],
        ],
        'max_output_tokens' => 700,
    ];

    $curl = new curl();
    $options = [
        'CURLOPT_TIMEOUT' => 45,
        'CURLOPT_HTTPHEADER' => [
            'Authorization: Bearer ' . $settings['openaiapikey'],
            'Content-Type: application/json',
        ],
    ];
    $response = $curl->post('https://api.openai.com/v1/responses', json_encode($payload), $options);

    if ($curl->get_errno()) {
        throw new moodle_exception(
            'aireview:openaierror',
            'quizaccess_proctoring',
            '',
            'cURL error ' . $curl->get_errno()
        );
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new moodle_exception('aireview:openaiinvalidjson', 'quizaccess_proctoring');
    }
    if (!empty($decoded['error']['message'])) {
        throw new moodle_exception('aireview:openaierror', 'quizaccess_proctoring', '', $decoded['error']['message']);
    }

    $outputtext = quizaccess_proctoring_extract_openai_output_text($decoded);
    $result = json_decode($outputtext, true);
    if (!is_array($result)) {
        throw new moodle_exception('aireview:openaiinvalidjson', 'quizaccess_proctoring');
    }

    return $result;
}

/**
 * Call Anthropic Claude Messages API for proctoring image review.
 *
 * @param stdClass $review AI review row.
 * @param array $images Image data rows.
 * @param array $settings Normalized AI review settings.
 * @return array|null Parsed structured result.
 */
function quizaccess_proctoring_call_anthropic_image_review(stdClass $review, array $images, array $settings): ?array {
    $content = [[
        'type' => 'text',
        'text' => quizaccess_proctoring_build_ai_review_prompt($review, count($images), $settings)
            . "\n\nUse the record_proctoring_review tool exactly once with the advisory review result.",
    ]];
    $validimages = 0;
    foreach ($images as $index => $image) {
        $parts = quizaccess_proctoring_data_url_parts((string)$image['dataurl']);
        if (!$parts) {
            continue;
        }
        $validimages++;
        $content[] = [
            'type' => 'text',
            'text' => 'Image ' . ($index + 1) . ': ' . $image['label'],
        ];
        $content[] = [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $parts['mime'],
                'data' => $parts['data'],
            ],
        ];
    }
    if ($validimages === 0) {
        throw new moodle_exception('aireview:noimages', 'quizaccess_proctoring');
    }

    $payload = [
        'model' => $settings['anthropicmodel'],
        'max_tokens' => 700,
        'tools' => [[
            'name' => 'record_proctoring_review',
            'description' => 'Record the advisory proctoring image review result.',
            'input_schema' => quizaccess_proctoring_ai_review_json_schema(),
        ]],
        'tool_choice' => [
            'type' => 'tool',
            'name' => 'record_proctoring_review',
        ],
        'messages' => [[
            'role' => 'user',
            'content' => $content,
        ]],
    ];

    $curl = new curl();
    $options = [
        'CURLOPT_TIMEOUT' => 45,
        'CURLOPT_HTTPHEADER' => [
            'x-api-key: ' . $settings['anthropicapikey'],
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
    ];
    $response = $curl->post('https://api.anthropic.com/v1/messages', json_encode($payload), $options);

    if ($curl->get_errno()) {
        throw new moodle_exception(
            'aireview:anthropicerror',
            'quizaccess_proctoring',
            '',
            'cURL error ' . $curl->get_errno()
        );
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new moodle_exception('aireview:anthropicinvalidjson', 'quizaccess_proctoring');
    }
    if (!empty($decoded['error']['message'])) {
        throw new moodle_exception('aireview:anthropicerror', 'quizaccess_proctoring', '', $decoded['error']['message']);
    }

    $text = '';
    foreach ((array)($decoded['content'] ?? []) as $item) {
        if (($item['type'] ?? '') === 'tool_use'
            && ($item['name'] ?? '') === 'record_proctoring_review'
            && is_array($item['input'] ?? null)) {
            return $item['input'];
        }
        if (($item['type'] ?? '') === 'text' && isset($item['text']) && is_string($item['text'])) {
            $text .= "\n" . $item['text'];
        }
    }

    $result = quizaccess_proctoring_extract_json_object($text);
    if (!is_array($result)) {
        throw new moodle_exception('aireview:anthropicinvalidjson', 'quizaccess_proctoring');
    }

    return $result;
}

/**
 * Call an OpenAI-compatible chat completions endpoint for proctoring image review.
 *
 * @param stdClass $review AI review row.
 * @param array $images Image data rows.
 * @param array $settings Normalized AI review settings.
 * @return array|null Parsed structured result.
 */
function quizaccess_proctoring_call_openai_compatible_image_review(
    stdClass $review,
    array $images,
    array $settings
): ?array {
    $schema = quizaccess_proctoring_ai_review_json_schema();
    $content = [[
        'type' => 'text',
        'text' => quizaccess_proctoring_build_ai_review_prompt($review, count($images), $settings)
            . "\n\nReturn only one JSON object that matches this schema:\n"
            . json_encode($schema),
    ]];
    foreach ($images as $index => $image) {
        $content[] = [
            'type' => 'text',
            'text' => 'Image ' . ($index + 1) . ': ' . $image['label'],
        ];
        $content[] = [
            'type' => 'image_url',
            'image_url' => [
                'url' => $image['dataurl'],
            ],
        ];
    }

    $payload = [
        'model' => $settings['compatiblemodel'],
        'messages' => [[
            'role' => 'user',
            'content' => $content,
        ]],
        'max_tokens' => 700,
        'stream' => false,
    ];

    $headers = ['Content-Type: application/json'];
    if (!empty($settings['compatibleapikey'])) {
        $headers[] = 'Authorization: Bearer ' . $settings['compatibleapikey'];
    }

    $curl = new curl();
    $options = [
        'CURLOPT_TIMEOUT' => 45,
        'CURLOPT_FOLLOWLOCATION' => false,
        'CURLOPT_HTTPHEADER' => $headers,
    ];
    $endpoint = quizaccess_proctoring_validate_outbound_endpoint((string)$settings['compatibleendpoint']);
    $response = $curl->post($endpoint, json_encode($payload), $options);

    if ($curl->get_errno()) {
        throw new moodle_exception(
            'aireview:compatibleerror',
            'quizaccess_proctoring',
            '',
            'cURL error ' . $curl->get_errno()
        );
    }
    $httpcode = (int)($curl->get_info()['http_code'] ?? 0);
    if ($httpcode >= 400) {
        throw new moodle_exception(
            'aireview:compatibleerror',
            'quizaccess_proctoring',
            '',
            'HTTP ' . $httpcode
        );
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new moodle_exception('aireview:compatibleinvalidjson', 'quizaccess_proctoring');
    }
    if (!empty($decoded['error']['message'])) {
        throw new moodle_exception('aireview:compatibleerror', 'quizaccess_proctoring', '', $decoded['error']['message']);
    }
    if (!empty($decoded['error']) && is_string($decoded['error'])) {
        throw new moodle_exception('aireview:compatibleerror', 'quizaccess_proctoring', '', $decoded['error']);
    }
    // vLLM and some other OpenAI-compatible servers return errors at the top level
    // as {"object":"error","message":"...","type":"...","code":...}.
    if (($decoded['object'] ?? '') === 'error' && !empty($decoded['message'])) {
        throw new moodle_exception('aireview:compatibleerror', 'quizaccess_proctoring', '', $decoded['message']);
    }
    $message = $decoded['choices'][0]['message']['content'] ?? '';
    if (is_array($message)) {
        $parts = [];
        foreach ($message as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        $message = implode("\n", $parts);
    }
    if (!is_string($message)) {
        $message = '';
    }

    $result = quizaccess_proctoring_extract_json_object($message);
    if (!is_array($result)) {
        throw new moodle_exception('aireview:compatibleinvalidjson', 'quizaccess_proctoring');
    }

    return $result;
}

/**
 * Build the prompt used for AI image review.
 *
 * @param stdClass $review AI review row.
 * @param int $imagecount Number of images sent.
 * @param array $settings Normalized AI review settings.
 * @return string Prompt.
 */
function quizaccess_proctoring_build_ai_review_prompt(stdClass $review, int $imagecount, array $settings): string {
    global $DB;

    $eventid = (int)($review->eventid ?? 0);
    if ($eventid > 0 || (string)($review->reviewtype ?? '') === 'event') {
        $event = $DB->get_record(
            'quizaccess_proctoring_events',
            ['id' => $eventid],
            'id, eventtype, eventdetail, pagevisibility, currenturl, timemodified'
        );
        $eventtype = $event ? quizaccess_proctoring_get_readable_ai_event_type((string)$event->eventtype) : 'Desktop event';
        $eventdetail = $event ? quizaccess_proctoring_format_ai_event_detail((string)$event->eventdetail) : '';

        return "You are reviewing one desktop screenshot captured during an online quiz proctoring event. "
            . "Use only visible evidence in the screenshot and the event metadata below. "
            . "Inspect the entire screenshot, including browser tabs, browser side panels, right-side assistant panels, "
            . "floating browser AI surfaces, and any text outside the Moodle quiz area. "
            . "Do not identify the student or infer intent from protected traits. "
            . "Mark cheating likely when a visible AI/chat/search assistant panel or tab, including Gemini, Ask Gemini, "
            . "Copilot, ChatGPT, Claude, Perplexity, or similar tools, is open during the quiz and appears to show answers, "
            . "explanations, or question-related help. Also mark cheating likely for another person helping; unauthorized notes; "
            . "a phone used for answers; or the quiz being outside the shared screen controls. "
            . "If a browser AI panel is visibly open but its text is unreadable, choose suspicious or inconclusive based on visible context. "
            . "If the screenshot only shows a focus-change event without visible unauthorized content, choose inconclusive. "
            . "Return a cautious review score from 0 to 100 where "
            . (int)$settings['decisionthreshold'] . "+ means strong visual evidence that needs escalation. "
            . "This is advisory for a human reviewer, not an automatic misconduct finding.\n\n"
            . "Event type: " . $eventtype . "\n"
            . "Event details: " . $eventdetail . "\n"
            . "Page visibility: " . ($event ? (string)$event->pagevisibility : '') . "\n"
            . "Images provided: " . $imagecount;
    }

    return "You are reviewing proctoring evidence for an online quiz attempt. "
        . "Use only visible evidence in the provided images and the metadata below. "
        . "Do not identify the student or infer intent from protected traits. "
        . "Do not mark cheating likely unless there is clear visual evidence such as another person helping, "
        . "an AI/chat/search answer panel, unauthorized notes, a phone used for answers, or the quiz being outside "
        . "the shared screen controls. If evidence is incomplete, choose inconclusive. "
        . "Return a cautious review score from 0 to 100 where "
        . (int)$settings['decisionthreshold'] . "+ means strong visual evidence that needs escalation. "
        . "This is advisory for a human reviewer, not an automatic misconduct finding.\n\n"
        . "Risk score: " . (int)$review->riskscore . "/100\n"
        . "AI trigger threshold: " . (int)$review->triggerthreshold . "/100\n"
        . "AI decision threshold: " . (int)$settings['decisionthreshold'] . "/100\n"
        . "Images provided: " . $imagecount;
}

/**
 * Extract output text from a Responses API payload.
 *
 * @param array $response Decoded Responses API payload.
 * @return string Output text.
 */
function quizaccess_proctoring_extract_openai_output_text(array $response): string {
    if (!empty($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }
    if (empty($response['output']) || !is_array($response['output'])) {
        return '';
    }

    foreach ($response['output'] as $item) {
        if (empty($item['content']) || !is_array($item['content'])) {
            continue;
        }
        foreach ($item['content'] as $content) {
            if (isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }
        }
    }

    return '';
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

    try {
        $endpoint = quizaccess_proctoring_validate_outbound_endpoint($endpoint);
    } catch (moodle_exception $e) {
        mtrace('Error: Configured Saylor AI endpoint URL is not allowed.');
        quizaccess_proctoring_update_match_result((int)$reportid, 0, 101);
        return false;
    }

    $referenceimage = quizaccess_proctoring_pluginfile_url_to_bytes($referenceimageurl);
    $targetimage = quizaccess_proctoring_pluginfile_url_to_bytes($targetimageurl);

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
        'CURLOPT_FOLLOWLOCATION' => false,
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
 * Decodes and validates a browser-submitted base64 image payload.
 *
 * @param string $data Data URL or base64 image.
 * @param int $maxbytes Maximum decoded byte size.
 * @return string Decoded image bytes.
 * @throws invalid_parameter_exception If the payload is not a valid image.
 */
function quizaccess_proctoring_decode_base64_image_data(string $data, int $maxbytes = 2097152): string {
    $data = trim($data);
    if ($data === '') {
        throw new invalid_parameter_exception('Image payload is empty.');
    }

    if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $data)) {
        $data = preg_replace('#^data:image/[^;]+;base64,#i', '', $data);
    } else if (strpos($data, ',') !== false) {
        [, $data] = explode(',', $data, 2);
    }

    $data = preg_replace('/\s+/', '', $data);
    if ($data === '' || ((int)(strlen($data) * 3 / 4) > $maxbytes)) {
        throw new invalid_parameter_exception('Image payload is too large.');
    }

    $decoded = base64_decode($data, true);
    if ($decoded === false || strlen($decoded) > $maxbytes) {
        throw new invalid_parameter_exception('Image payload is invalid.');
    }

    $info = @getimagesizefromstring($decoded);
    if ($info === false || empty($info['mime']) || strpos($info['mime'], 'image/') !== 0) {
        throw new invalid_parameter_exception('Image payload is not an image.');
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width <= 0 || $height <= 0 || ($width * $height) > 12000000) {
        throw new invalid_parameter_exception('Image dimensions are invalid.');
    }

    return $decoded;
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
    $data = quizaccess_proctoring_decode_base64_image_data($data, 2097152);

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
