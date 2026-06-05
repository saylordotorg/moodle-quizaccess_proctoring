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

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir.'/externallib.php');
require_once($CFG->dirroot.'/mod/quiz/accessrule/proctoring/lib.php');

/**
 * External API class for the Quiz Proctoring plugin.
 *
 * This class provides external functions for the `quizaccess_proctoring` plugin,
 * allowing integration with Moodle’s web services.
 *
 * @package   quizaccess_proctoring
 * @category  external
 * @copyright 2024 Saylor Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizaccess_proctoring_external extends external_api {

    /** Maximum decoded webcam image payload size. */
    private const MAX_WEBCAM_IMAGE_BYTES = 3145728;

    /** Maximum decoded cropped face image payload size. */
    private const MAX_FACE_IMAGE_BYTES = 2097152;

    /** Maximum decoded desktop violation screenshot payload size. */
    private const MAX_DESKTOP_IMAGE_BYTES = 12582912;

    /** Maximum decoded ID document image payload size. */
    private const MAX_ID_DOCUMENT_IMAGE_BYTES = 8388608;

    /** Maximum image pixels accepted from browser-submitted images. */
    private const MAX_IMAGE_PIXELS = 12000000;

    /** Upload/event rate limit window in seconds. */
    private const RATE_LIMIT_WINDOW = 60;

    /** Maximum camshot uploads per user/module/window. */
    private const MAX_CAMSHOTS_PER_WINDOW = 30;

    /** Maximum suspicious events per user/module/window. */
    private const MAX_EVENTS_PER_WINDOW = 120;

    /** Maximum ID verification requests per user/module/window. */
    private const MAX_ID_VERIFICATIONS_PER_WINDOW = 12;

    /**
     * Defines the parameters required for sending a camshot.
     *
     * This function specifies the parameters that must be provided when calling
     * the send_camshot web service.
     *
     * @return external_function_parameters The required parameters:
     *      - 'courseid' (int): The ID of the course where the proctoring took place.
     *      - 'screenshotid' (int): The unique ID of the captured screenshot.
     *      - 'quizid' (int): The ID of the quiz associated with the screenshot.
     *      - 'webcampicture' (string): The base64-encoded webcam image or file path.
     *      - 'imagetype' (int): The type of image being stored.
     *      - 'parenttype' (string): The parent type associated with the face image.
     *      - 'faceimage' (string): The base64-encoded cropped face image or file path.
     *      - 'facefound' (int): A flag indicating whether a face was detected (1 = Yes, 0 = No).
     */
    public static function send_camshot_parameters() {
        return new external_function_parameters(
            [
                'courseid' => new external_value(PARAM_INT, 'course id'),
                'screenshotid' => new external_value(PARAM_INT, 'screenshot id'),
                'quizid' => new external_value(PARAM_INT, 'screenshot quiz id'),
                'webcampicture' => new external_value(PARAM_RAW, 'webcam photo'),
                'imagetype' => new external_value(PARAM_INT, 'image type'),
                'parenttype' => new external_value(PARAM_RAW, 'Face image parent type'),
                'faceimage' => new external_value(PARAM_RAW, 'Face Image'),
                'facefound' => new external_value(PARAM_INT, 'Face found flag'),
            ]
        );
    }

    /**
     * Store the cam shots in Moodle subsystems and insert into the log table.
     *
     * This function processes webcam images and face images, storing them in Moodle's file storage system
     * and inserting records into the `quizaccess_proctoring_logs` and `quizaccess_proctoring_face_images` tables.
     * The images are saved and linked to the appropriate quiz and user. Additionally, metadata like face found
     * flag and parent type are saved.
     *
     * @param int $courseid The course ID where the proctoring took place.
     * @param int $screenshotid The ID of the screenshot being uploaded.
     * @param int $quizid The ID of the quiz associated with the screenshot (or CMID).
     * @param string $webcampicture The base64-encoded webcam image.
     * @param int $imagetype The type of image being uploaded (e.g., webcam photo or other).
     * @param string $parenttype The parent type, indicating whether the image is an Admin Image or Webcam Image.
     * @param string $faceimage The base64-encoded face image extracted from the webcam photo.
     * @param int $facefound A flag indicating whether a face was detected (1 = face found, 0 = face not found).
     *
     * @return array Returns an array with the following:
     *      - 'screenshotid' (int): The ID of the stored screenshot.
     *      - 'warnings' (array): A list of warnings generated during the process (if any).
     *
     * @throws dml_exception If there is a problem with database interaction.
     * @throws file_exception If there is an issue storing or retrieving files.
     * @throws invalid_parameter_exception If one or more parameters are invalid.
     * @throws stored_file_creation_exception If there is a problem creating or storing files.
     */
    public static function send_camshot
        ($courseid, $screenshotid, $quizid, $webcampicture, $imagetype, $parenttype, $faceimage, $facefound) {
        global $DB, $USER;

        // Validate the params.
        self::validate_parameters(
            self::send_camshot_parameters(),
            [
                'courseid' => $courseid,
                'screenshotid' => $screenshotid,
                'quizid' => $quizid,
                'webcampicture' => $webcampicture,
                'imagetype' => $imagetype,
                'parenttype' => $parenttype,
                'faceimage' => $faceimage,
                'facefound' => $facefound,
            ]
        );

        [$cm, $context] = self::get_authorized_quiz_context((int)$courseid, (int)$quizid);

        $warnings = [];

        if ($imagetype == 1) {
            $parenttype = self::clean_parent_type((string)$parenttype);
            self::validate_image_payload($webcampicture, self::MAX_WEBCAM_IMAGE_BYTES);
            if (!empty($faceimage)) {
                self::validate_image_payload($faceimage, self::MAX_FACE_IMAGE_BYTES);
            }
            self::enforce_recent_record_limit('quizaccess_proctoring_logs', [
                'courseid' => (int)$courseid,
                'quizid' => (int)$cm->id,
                'userid' => (int)$USER->id,
            ], self::MAX_CAMSHOTS_PER_WINDOW);

            $camshot = self::get_owned_report((int)$screenshotid, (int)$courseid, (int)$cm->id);
            if ((int)$camshot->status > 0) {
                self::get_owned_quiz_attempt((int)$camshot->status, $cm);
            }

            $record = new stdClass();
            $record->filearea = 'picture';
            $record->component = 'quizaccess_proctoring';
            $record->filepath = '';
            $record->itemid = (int)$screenshotid;
            $record->license = '';
            $record->author = '';

            $fs = get_file_storage();
            $record->filepath = file_correct_filepath($record->filepath);

            $url = self::geturl($webcampicture, (int)$screenshotid, $USER, (int)$courseid, $record, $context, $fs);

            $record = new stdClass();
            $record->courseid = (int)$courseid;
            $record->quizid = (int)$cm->id;
            $record->userid = $USER->id;
            $record->webcampicture = "{$url}";
            $record->status = $camshot->status;
            $record->timemodified = time();
            $screenshotid = $DB->insert_record('quizaccess_proctoring_logs', $record, true);
            $logid = $screenshotid;

            // Save the face image.
            $record = new stdClass();
            $record->filearea = 'face_image';
            $record->component = 'quizaccess_proctoring';
            $record->filepath = '';
            $record->itemid = $screenshotid;
            $record->license = '';
            $record->author = '';

            $fs = get_file_storage();
            $record->filepath = file_correct_filepath($record->filepath);

            $url = "";
            if ($faceimage) {
                $url = self::quizaccess_proctoring_geturl_without_timecode(
                    $faceimage, $screenshotid, $USER, (int)$courseid, $record, $context, $fs);
            }
            $record = new stdClass();
            $record->parent_type = $parenttype;
            $record->parentid = $screenshotid;
            $record->faceimage = "{$url}";
            $record->facefound = $facefound;
            $record->timemodified = time();
            $screenshotid = $DB->insert_record('quizaccess_proctoring_face_images', $record, true);
            self::run_continuous_face_check($logid, (int)$facefound);

            $result = [];
            $result['screenshotid'] = $screenshotid;
            $result['warnings'] = $warnings;
        } else {
            $result = [];
            $result['screenshotid'] = 100;
            $result['warnings'] = [];
        }

        return $result;
    }

    /**
     * Return structure for sending cam shots.
     *
     * This function defines the structure of the response that is returned after
     * sending a cam shot. It includes the `screenshotid` which is the identifier
     * for the stored screenshot and any warnings that might have occurred during
     * the operation.
     *
     * @return external_single_structure The structure of the return value, which contains:
     *      - 'screenshotid' (int): The ID of the screenshot that was sent.
     *      - 'warnings' (array): An array containing any warnings encountered during the process.
     */
    public static function send_camshot_returns() {
        return new external_single_structure(
            [
                'screenshotid' => new external_value(PARAM_INT, 'screenshot sent id'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Defines parameters for suspicious browser activity logging.
     *
     * @return external_function_parameters
     */
    public static function log_event_parameters() {
        return new external_function_parameters(
            [
                'courseid' => new external_value(PARAM_INT, 'course id'),
                'quizid' => new external_value(PARAM_INT, 'course module id'),
                'attemptid' => new external_value(PARAM_INT, 'quiz attempt id', VALUE_DEFAULT, 0),
                'reportid' => new external_value(PARAM_INT, 'initial proctoring log id', VALUE_DEFAULT, 0),
                'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'event type'),
                'eventdetail' => new external_value(PARAM_RAW, 'JSON event details', VALUE_DEFAULT, ''),
                'pagevisibility' => new external_value(PARAM_ALPHANUMEXT, 'document visibility state', VALUE_DEFAULT, ''),
                'currenturl' => new external_value(PARAM_RAW, 'page URL', VALUE_DEFAULT, ''),
                'screenshot' => new external_value(PARAM_RAW, 'desktop screenshot data URI', VALUE_DEFAULT, ''),
            ]
        );
    }

    /**
     * Logs browser activity that may indicate quiz proctoring risk.
     *
     * @param int $courseid Course ID.
     * @param int $quizid Course module ID.
     * @param int $attemptid Quiz attempt ID.
     * @param int $reportid Initial proctoring log ID.
     * @param string $eventtype Event type.
     * @param string $eventdetail Event detail JSON.
     * @param string $pagevisibility Document visibility state.
     * @param string $currenturl Page URL when the event was observed.
     * @param string $screenshot Desktop screenshot data URI captured when the event was observed.
     * @return array Event result.
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    public static function log_event(
        $courseid,
        $quizid,
        $attemptid = 0,
        $reportid = 0,
        $eventtype = '',
        $eventdetail = '',
        $pagevisibility = '',
        $currenturl = '',
        $screenshot = ''
    ) {
        global $DB, $USER;

        self::validate_parameters(
            self::log_event_parameters(),
            [
                'courseid' => $courseid,
                'quizid' => $quizid,
                'attemptid' => $attemptid,
                'reportid' => $reportid,
                'eventtype' => $eventtype,
                'eventdetail' => $eventdetail,
                'pagevisibility' => $pagevisibility,
                'currenturl' => $currenturl,
                'screenshot' => $screenshot,
            ]
        );

        [$cm, $context] = self::get_authorized_quiz_context((int)$courseid, (int)$quizid);

        $allowedevents = [
            'tab_hidden',
            'tab_visible',
            'focus_lost',
            'focus_returned',
            'clipboard_copy',
            'clipboard_cut',
            'clipboard_paste',
            'contextmenu',
            'shortcut',
            'possible_ai_tool',
            'page_exit',
            'screen_marker_missing',
            'screen_share_stopped',
            'multiple_faces_detected',
            'face_missing',
            'no_face_detected',
            'audio_detected',
            'multiple_monitors_detected',
            'monitor_detection_unavailable',
        ];

        if (!in_array($eventtype, $allowedevents, true)) {
            $eventtype = 'shortcut';
        }

        $attemptid = max(0, (int)$attemptid);
        $reportid = max(0, (int)$reportid);
        if ($attemptid > 0) {
            self::get_owned_quiz_attempt($attemptid, $cm);
        }
        if ($reportid > 0) {
            $report = self::get_owned_report($reportid, (int)$courseid, (int)$cm->id, $attemptid);
            if ($attemptid === 0 && (int)$report->status > 0) {
                $attemptid = (int)$report->status;
                self::get_owned_quiz_attempt($attemptid, $cm);
            }
        } else if (!empty($screenshot)) {
            throw new invalid_parameter_exception('A report id is required when saving a desktop screenshot.');
        }

        if (!empty($screenshot)) {
            self::validate_image_payload($screenshot, self::MAX_DESKTOP_IMAGE_BYTES);
        }
        self::enforce_recent_record_limit('quizaccess_proctoring_events', [
            'courseid' => (int)$courseid,
            'quizid' => (int)$cm->id,
            'userid' => (int)$USER->id,
        ], self::MAX_EVENTS_PER_WINDOW);

        $record = new stdClass();
        $record->courseid = (int)$courseid;
        $record->quizid = $cm->id;
        $record->userid = $USER->id;
        $record->attemptid = $attemptid;
        $record->reportid = $reportid;
        $record->eventtype = substr($eventtype, 0, 40);
        $record->eventdetail = substr($eventdetail, 0, 2000);
        $record->pagevisibility = substr($pagevisibility, 0, 20);
        $record->currenturl = substr($currenturl, 0, 1000);
        $record->screenshoturl = '';
        $record->timemodified = time();

        $eventid = $DB->insert_record('quizaccess_proctoring_events', $record, true);

        if (!empty($screenshot)) {
            try {
                $record->id = $eventid;
                $record->screenshoturl = self::save_event_screenshot($courseid, $cm->id, $eventid, $screenshot);
                $DB->update_record('quizaccess_proctoring_events', $record);
                quizaccess_proctoring_queue_event_ai_review($eventid);
            } catch (Throwable $e) {
                // Keep the event log even if the optional desktop capture cannot be stored.
            }
        }

        return [
            'eventid' => $eventid,
            'warnings' => [],
        ];
    }

    /**
     * Return structure for suspicious browser activity logging.
     *
     * @return external_single_structure
     */
    public static function log_event_returns() {
        return new external_single_structure(
            [
                'eventid' => new external_value(PARAM_INT, 'event id'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Resolves and authorizes a quiz course module for browser-submitted proctoring data.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course module ID.
     * @return array [cm_info|stdClass, context_module]
     */
    private static function get_authorized_quiz_context(int $courseid, int $cmid): array {
        $cm = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
        $context = context_module::instance($cm->id, MUST_EXIST);
        self::validate_context($context);
        require_capability('quizaccess/proctoring:sendcamshot', $context);

        return [$cm, $context];
    }

    /**
     * Verifies that a quiz attempt belongs to the current user and quiz module.
     *
     * @param int $attemptid Quiz attempt ID.
     * @param stdClass|cm_info $cm Quiz course module.
     * @return stdClass Attempt record.
     */
    private static function get_owned_quiz_attempt(int $attemptid, $cm): stdClass {
        global $DB, $USER;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, quiz, userid, state', MUST_EXIST);
        if ((int)$attempt->userid !== (int)$USER->id || (int)$attempt->quiz !== (int)$cm->instance) {
            throw new invalid_parameter_exception('Invalid quiz attempt.');
        }

        if (!in_array((string)$attempt->state, ['inprogress', 'overdue'], true)) {
            throw new invalid_parameter_exception('Quiz attempt is not active.');
        }

        return $attempt;
    }

    /**
     * Verifies that a proctoring report row belongs to the current user, module, and attempt.
     *
     * @param int $reportid Proctoring log ID.
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course module ID.
     * @param int $attemptid Optional quiz attempt ID.
     * @return stdClass Proctoring log row.
     */
    private static function get_owned_report(int $reportid, int $courseid, int $cmid, int $attemptid = 0): stdClass {
        global $DB, $USER;

        $report = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid], '*', MUST_EXIST);
        if ((int)$report->courseid !== $courseid || (int)$report->quizid !== $cmid ||
                (int)$report->userid !== (int)$USER->id) {
            throw new invalid_parameter_exception('Invalid proctoring report.');
        }

        if ($attemptid > 0 && (int)$report->status > 0 && (int)$report->status !== $attemptid) {
            throw new invalid_parameter_exception('Proctoring report does not belong to this attempt.');
        }

        return $report;
    }

    /**
     * Restricts submitted face parent types to values the plugin creates.
     *
     * @param string $parenttype Submitted parent type.
     * @return string Clean parent type.
     */
    private static function clean_parent_type(string $parenttype): string {
        return in_array($parenttype, ['camshot_image', 'admin_image'], true) ? $parenttype : 'camshot_image';
    }

    /**
     * Validates browser-submitted image data without storing it.
     *
     * @param string $data Data URL or base64 image.
     * @param int $maxbytes Maximum decoded bytes.
     */
    private static function validate_image_payload(string $data, int $maxbytes): void {
        self::decode_base64_image_data($data, $maxbytes);
    }

    /**
     * Applies a practical per-user rate limit for proctoring writes.
     *
     * @param string $table Database table.
     * @param array $conditions Equality conditions.
     * @param int $limit Maximum records in the window.
     */
    private static function enforce_recent_record_limit(string $table, array $conditions, int $limit): void {
        global $DB;

        $where = [];
        $params = ['since' => time() - self::RATE_LIMIT_WINDOW];
        foreach ($conditions as $field => $value) {
            $param = 'p_' . $field;
            $where[] = $field . ' = :' . $param;
            $params[$param] = $value;
        }
        $where[] = 'timemodified >= :since';

        if ($DB->count_records_select($table, implode(' AND ', $where), $params) >= $limit) {
            throw new moodle_exception('error', 'error', '', null, 'Too many proctoring requests. Please wait and try again.');
        }
    }

    /**
     * Stores a desktop screenshot captured for a suspicious activity event.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @param int $eventid Event record ID.
     * @param string $screenshot Desktop screenshot data URI.
     * @return string Stored screenshot URL.
     */
    private static function save_event_screenshot(int $courseid, int $cmid, int $eventid, string $screenshot): string {
        global $USER;

        $context = context_module::instance($cmid, MUST_EXIST);
        $fs = get_file_storage();

        $record = new stdClass();
        $record->filearea = 'violation_screenshot';
        $record->component = 'quizaccess_proctoring';
        $record->filepath = file_correct_filepath('');
        $record->itemid = $eventid;
        $record->license = '';
        $record->author = '';
        $record->courseid = $courseid;
        $record->filename = 'desktop-event-' . $eventid . '-' . $USER->id . '-' . $courseid . '-' . time() .
            random_int(1, 1000) . '.png';
        $record->contextid = $context->id;
        $record->userid = $USER->id;

        $data = self::add_timecode_to_image(self::decode_base64_image_data($screenshot, self::MAX_DESKTOP_IMAGE_BYTES));
        $fs->create_file_from_string($record, $data);

        return moodle_url::make_pluginfile_url(
            $context->id,
            $record->component,
            $record->filearea,
            $record->itemid,
            $record->filepath,
            $record->filename,
            false
        )->out(false);
    }

    /**
     * Runs an immediate face match for a stored webcam capture when continuous checks are enabled.
     *
     * @param int $reportid The quizaccess_proctoring_logs record ID.
     * @param int $facefound Whether the browser-side detector found a face.
     */
    private static function run_continuous_face_check(int $reportid, int $facefound): void {
        global $DB;

        if ((int)quizaccess_proctoring_get_proctoring_settings('continuousfacecheck') !== 1) {
            return;
        }

        $method = quizaccess_proctoring_get_proctoring_settings('fcmethod');
        if (!quizaccess_proctoring_is_facematch_method_enabled($method) ||
                !quizaccess_proctoring_facematch_credentials_available($method)) {
            return;
        }

        $report = $DB->get_record('quizaccess_proctoring_logs', ['id' => $reportid]);
        if (!$report || empty($report->webcampicture)) {
            return;
        }

        if ($facefound !== 1) {
            quizaccess_proctoring_log_fm_warning($reportid);
            quizaccess_proctoring_update_match_result($reportid, 0, 3);
            return;
        }

        $checkevery = max(1, (int)quizaccess_proctoring_get_proctoring_settings('continuousfacecheckevery'));
        if ($checkevery > 1) {
            $capturecount = $DB->count_records_select(
                'quizaccess_proctoring_logs',
                "courseid = :courseid AND quizid = :quizid AND userid = :userid AND webcampicture <> ''",
                [
                    'courseid' => $report->courseid,
                    'quizid' => $report->quizid,
                    'userid' => $report->userid,
                ]
            );

            if ($capturecount % $checkevery !== 0) {
                return;
            }
        }

        if (quizaccess_proctoring_is_custom_ai_method($method)) {
            $referenceimageurl = quizaccess_proctoring_get_image_url($report->userid);
            $targetimageurl = $report->webcampicture;
        } else {
            [$referenceimageurl, $targetimageurl] = quizaccess_proctoring_get_face_images($reportid, false);
        }

        if (empty($referenceimageurl) || empty($targetimageurl)) {
            quizaccess_proctoring_log_fm_warning($reportid);
            quizaccess_proctoring_update_match_result($reportid, 0, 3);
            return;
        }

        quizaccess_proctoring_extracted($referenceimageurl, $targetimageurl, $reportid);
    }

    /**
     * Adds a timestamp to the captured image.
     *
     * This function takes an image in raw data format, adds a timestamp in the
     * specified format to the image, and returns the updated image data.
     *
     * @param string $data The raw image data (in PNG or JPEG format) to which the timestamp will be added.
     * @return string The updated image data with the added timestamp.
     * @throws Exception If there is an issue with image creation or manipulation.
     */
    private static function add_timecode_to_image($data) {
        global $CFG;

        $image = imagecreatefromstring($data);
        imagefilledrectangle($image, 0, 0, 120, 22, imagecolorallocatealpha($image, 255, 255, 255, 60));
        imagefttext($image, 9, 0, 4, 16, imagecolorallocate($image, 0, 0, 0),
            $CFG->dirroot . '/mod/quiz/accessrule/proctoring/assets/Roboto-Light.ttf', date('d-m-Y H:i:s') );
        ob_start();
        imagepng($image);
        $data = ob_get_clean();
        ob_end_clean();
        imagedestroy($image);
        return $data;
    }

    /**
     * Defines the parameters for the validate_face function.
     *
     * This function defines and returns the expected parameters for the
     * validate_face function, which includes information about the course,
     * the activity, the profile photo, webcam photo, face image, and face found flag.
     *
     * @return external_function_parameters The parameters required for the validate_face function.
     */
    public static function validate_face_parameters() {
        return new external_function_parameters(
            [
                'courseid' => new external_value(PARAM_INT, 'course id'),
                'cmid' => new external_value(PARAM_INT, 'cm id'),
                'profileimage' => new external_value(PARAM_RAW, 'profile photo'),
                'webcampicture' => new external_value(PARAM_RAW, 'webcam photo'),
                'parenttype' => new external_value(PARAM_RAW, 'Face image parent type'),
                'faceimage' => new external_value(PARAM_RAW, 'Face Image'),
                'facefound' => new external_value(PARAM_INT, 'Face found flag'),
            ]
        );
    }

    /**
     * Stores the captured Cam shots in Moodle subsystems and logs them in the database.
     *
     * This function validates the parameters, processes the webcam and face images, stores them in Moodle's
     * file storage, inserts a record in the `quizaccess_proctoring_logs` and `quizaccess_proctoring_face_images` tables,
     * performs face checking, and returns the result along with warnings if applicable.
     *
     * @param mixed $courseid The course ID.
     * @param mixed $cmid The course module ID.
     * @param mixed $profileimage The profile image of the user.
     * @param mixed $webcampicture The webcam image captured.
     * @param mixed $parenttype The type of parent image (e.g., Admin or Webcam).
     * @param mixed $faceimage The face image captured.
     * @param bool $facefound Flag indicating whether a face was detected (0 or 1).
     *
     * @return array An array containing the `screenshotid`, `status`, and `warnings`.
     *
     * @throws dml_exception If there is a database issue.
     * @throws file_exception If there is an issue with file handling.
     * @throws invalid_parameter_exception If any of the parameters are invalid.
     * @throws stored_file_creation_exception If there is an error creating the stored file.
     */
    public static function validate_face($courseid, $cmid, $profileimage, $webcampicture, $parenttype, $faceimage, $facefound) {
        global $DB, $USER, $CFG;

        // Validate the params.
        self::validate_parameters(
            self::validate_face_parameters(),
            [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'profileimage' => $profileimage,
                'webcampicture' => $webcampicture,
                'parenttype' => $parenttype,
                'faceimage' => $faceimage,
                'facefound' => $facefound,
            ]
        );

        [$cm, $context] = self::get_authorized_quiz_context((int)$courseid, (int)$cmid);
        $parenttype = self::clean_parent_type((string)$parenttype);
        if (!empty($webcampicture)) {
            self::validate_image_payload($webcampicture, self::MAX_WEBCAM_IMAGE_BYTES);
        }
        if (!empty($faceimage)) {
            self::validate_image_payload($faceimage, self::MAX_FACE_IMAGE_BYTES);
        }
        self::enforce_recent_record_limit('quizaccess_proctoring_logs', [
            'courseid' => (int)$courseid,
            'quizid' => (int)$cm->id,
            'userid' => (int)$USER->id,
        ], self::MAX_CAMSHOTS_PER_WINDOW);

        $warnings = [];

        if (!$DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $USER->id])) {
            if (empty($webcampicture) || (int)$facefound !== 1 || empty($faceimage)) {
                $result = [];
                $result['screenshotid'] = 0;
                $result['status'] = 'facenotfound';
                $result['warnings'] = $warnings;
                return $result;
            }

            if (!self::reference_image_is_clear($webcampicture, $faceimage)) {
                $result = [];
                $result['screenshotid'] = 0;
                $result['status'] = 'faceunclear';
                $result['warnings'] = $warnings;
                return $result;
            }

            $referenceid = self::save_reference_image($USER->id, $webcampicture, $faceimage, (int)$facefound);
            $result = [];
            $result['screenshotid'] = $referenceid;
            $result['status'] = 'registered';
            $result['warnings'] = $warnings;
            return $result;
        }

        $screenshotid = time();
        $record = new stdClass();
        $record->filearea = 'picture';
        $record->component = 'quizaccess_proctoring';
        $record->filepath = '';
        $record->itemid = $screenshotid;
        $record->license = '';
        $record->author = '';

        $fs = get_file_storage();
        $record->filepath = file_correct_filepath($record->filepath);

        // For base64 to file.
        $data = $webcampicture;
        $url = self::geturl($data, $screenshotid, $USER, $courseid, $record, $context, $fs);

        $record = new stdClass();
        $record->courseid = (int)$courseid;
        $record->quizid = (int)$cm->id;
        $record->userid = $USER->id;
        $record->webcampicture = "{$url}";
        $record->status = $screenshotid;
        $record->timemodified = time();
        $screenshotid = $DB->insert_record('quizaccess_proctoring_logs', $record, true);

        // Save the face image.
        $record = new stdClass();
        $record->filearea = 'face_image';
        $record->component = 'quizaccess_proctoring';
        $record->filepath = '';
        $record->itemid = $screenshotid;
        $record->license = '';
        $record->author = '';

        $fs = get_file_storage();
        $record->filepath = file_correct_filepath($record->filepath);

        $url = "";
        if ($faceimage) {
            $url = self::quizaccess_proctoring_geturl_without_timecode(
                $faceimage, $screenshotid, $USER, $courseid, $record, $context, $fs);
        }
        $record = new stdClass();
        $record->parent_type = $parenttype;
        $record->parentid = $screenshotid;
        $record->faceimage = "{$url}";
        $record->facefound = $facefound;
        $record->timemodified = time();
        $faceimageid = $DB->insert_record('quizaccess_proctoring_face_images', $record, true);
        $profileimageurl = quizaccess_proctoring_get_image_url( $USER->id);
        if ($profileimageurl == false) {
            $result = [];
            $result['screenshotid'] = $screenshotid;
            $result['status'] = 'photonotuploaded';
            $result['warnings'] = $warnings;
            return $result;
        }

        // Face check.
        require_once($CFG->dirroot.'/mod/quiz/accessrule/proctoring/lib.php');
        $method = quizaccess_proctoring_get_proctoring_settings("fcmethod");
        if ($method == "customapi") {
            $referenceimageurl = quizaccess_proctoring_get_image_url($USER->id);
            if (!$referenceimageurl) {
                $result = [];
                $result['screenshotid'] = $screenshotid;
                $result['status'] = 'photonotuploaded';
                $result['warnings'] = $warnings;
                return $result;
            }
            quizaccess_proctoring_extracted($referenceimageurl, $url, $screenshotid);
        }

        $currentdata = $DB->get_record('quizaccess_proctoring_logs', ['id' => $screenshotid]);
        $awsscore = $currentdata->awsscore;
        $threshhold = (int)quizaccess_proctoring_get_proctoring_settings('threshold');

        if ($awsscore > $threshhold) {
            $status = "success";
        } else {
            $status = "failed";
        }

        $result = [];
        $result['screenshotid'] = $screenshotid;
        $result['status'] = $status;
        $result['warnings'] = $warnings;
        // API is invalid or not set.
        if ($currentdata->awsflag == 101) {
            $result['status'] = 'invalidApi';
        }
        return $result;
    }


    /**
     * Returns the structure for the cam shots validation response.
     *
     * This function defines the structure of the returned data when a cam shot validation is performed.
     * It returns the screenshot ID, validation status, and any warnings encountered during the process.
     *
     * @return external_single_structure A single structure containing:
     *  - 'screenshotid' => The ID of the screenshot sent for validation (integer).
     *  - 'status' => The response status of the validation (string).
     *  - 'warnings' => Any warnings encountered during validation (external_warnings).
     */
    public static function validate_face_returns() {
        return new external_single_structure(
            [
                'screenshotid' => new external_value(PARAM_INT, 'screenshot sent id'),
                'status' => new external_value(PARAM_TEXT, 'validation response'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Defines parameters for pre-attempt ID verification.
     *
     * @return external_function_parameters
     */
    public static function verify_id_parameters() {
        return new external_function_parameters(
            [
                'courseid' => new external_value(PARAM_INT, 'course id'),
                'cmid' => new external_value(PARAM_INT, 'cm id'),
                'attemptid' => new external_value(PARAM_INT, 'attempt id', VALUE_DEFAULT, 0),
                'idimage' => new external_value(PARAM_RAW, 'ID document image'),
                'liveimage' => new external_value(PARAM_RAW, 'Live webcam image'),
                'livefacefound' => new external_value(PARAM_INT, 'Browser face detection flag', VALUE_DEFAULT, 0),
            ]
        );
    }

    /**
     * Stores ID verification evidence and sends it to the configured Saylor/custom AI endpoint.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course module ID.
     * @param int $attemptid Attempt ID when known.
     * @param string $idimage ID document image data URI.
     * @param string $liveimage Live webcam image data URI.
     * @param int $livefacefound Whether browser-side detection found a live face.
     * @return array Verification result.
     */
    public static function verify_id($courseid, $cmid, $attemptid, $idimage, $liveimage, $livefacefound = 0) {
        global $DB, $USER;

        self::validate_parameters(
            self::verify_id_parameters(),
            [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'attemptid' => $attemptid,
                'idimage' => $idimage,
                'liveimage' => $liveimage,
                'livefacefound' => $livefacefound,
            ]
        );

        [$cm, $context] = self::get_authorized_quiz_context((int)$courseid, (int)$cmid);
        if ((int)get_config('quizaccess_proctoring', 'idverificationenabled') !== 1) {
            return [
                'verificationid' => 0,
                'status' => 'disabled',
                'facescore' => 0,
                'namescore' => 0,
                'extractedname' => '',
                'message' => '',
                'warnings' => [],
            ];
        }

        self::validate_image_payload((string)$idimage, self::MAX_ID_DOCUMENT_IMAGE_BYTES);
        self::validate_image_payload((string)$liveimage, self::MAX_WEBCAM_IMAGE_BYTES);
        self::enforce_recent_record_limit('quizaccess_proctoring_idv', [
            'courseid' => (int)$courseid,
            'quizid' => (int)$cm->id,
            'userid' => (int)$USER->id,
        ], self::MAX_ID_VERIFICATIONS_PER_WINDOW);

        $now = time();
        $profilename = fullname($USER);
        $record = (object)[
            'courseid' => (int)$courseid,
            'quizid' => (int)$cm->id,
            'userid' => (int)$USER->id,
            'attemptid' => (int)$attemptid,
            'status' => 'pending',
            'facescore' => 0,
            'namescore' => 0,
            'extractedname' => '',
            'profilename' => $profilename,
            'idimageurl' => '',
            'liveimageurl' => '',
            'errormessage' => '',
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $verificationid = $DB->insert_record('quizaccess_proctoring_idv', $record);
        $idbytes = self::decode_base64_image_data((string)$idimage, self::MAX_ID_DOCUMENT_IMAGE_BYTES);
        $livebytes = self::decode_base64_image_data((string)$liveimage, self::MAX_WEBCAM_IMAGE_BYTES);
        $record->id = $verificationid;
        $record->idimageurl = self::save_id_verification_image(
            (int)$courseid,
            (int)$cm->id,
            (int)$verificationid,
            'id_document',
            $idbytes,
            $context
        );
        $record->liveimageurl = self::save_id_verification_image(
            (int)$courseid,
            (int)$cm->id,
            (int)$verificationid,
            'id_live_image',
            $livebytes,
            $context
        );

        if ((int)$livefacefound !== 1) {
            $providerresult = [
                'status' => 'retry',
                'facescore' => 0,
                'namescore' => 0,
                'extractedname' => '',
                'message' => get_string('facenotfoundoncam', 'quizaccess_proctoring'),
            ];
        } else {
            $providerresult = self::call_id_verification_endpoint($idbytes, $livebytes, $USER);
        }
        $providerresult['message'] = self::get_id_verification_student_message($providerresult, $profilename);

        $record->status = $providerresult['status'];
        $record->facescore = $providerresult['facescore'];
        $record->namescore = $providerresult['namescore'];
        $record->extractedname = $providerresult['extractedname'];
        $record->errormessage = $providerresult['message'];
        $record->timemodified = time();
        $DB->update_record('quizaccess_proctoring_idv', $record);

        return [
            'verificationid' => (int)$verificationid,
            'status' => $record->status,
            'facescore' => (int)$record->facescore,
            'namescore' => (int)$record->namescore,
            'extractedname' => (string)$record->extractedname,
            'message' => (string)$record->errormessage,
            'warnings' => [],
        ];
    }

    /**
     * Returns the ID verification response structure.
     *
     * @return external_single_structure
     */
    public static function verify_id_returns() {
        return new external_single_structure(
            [
                'verificationid' => new external_value(PARAM_INT, 'ID verification record id'),
                'status' => new external_value(PARAM_TEXT, 'pass, failed, retry, error, or disabled'),
                'facescore' => new external_value(PARAM_INT, 'Face match score'),
                'namescore' => new external_value(PARAM_INT, 'Name match score'),
                'extractedname' => new external_value(PARAM_TEXT, 'Name extracted from the ID document'),
                'message' => new external_value(PARAM_TEXT, 'Provider or validation message'),
                'warnings' => new external_warnings(),
            ]
        );
    }

    /**
     * Saves a pre-attempt ID verification image to Moodle file storage.
     *
     * @param int $courseid Course ID.
     * @param int $cmid Quiz course module ID.
     * @param int $verificationid ID verification record ID.
     * @param string $filearea File area.
     * @param string $bytes Validated image bytes.
     * @param context $context Module context.
     * @return string Stored pluginfile URL.
     */
    private static function save_id_verification_image(
        int $courseid,
        int $cmid,
        int $verificationid,
        string $filearea,
        string $bytes,
        context $context
    ): string {
        global $USER;

        $prefix = $filearea === 'id_document' ? 'id-document' : 'id-live';
        $record = new stdClass();
        $record->contextid = $context->id;
        $record->component = 'quizaccess_proctoring';
        $record->filearea = $filearea;
        $record->itemid = $verificationid;
        $record->filepath = file_correct_filepath('');
        $record->filename = $prefix . '-' . $verificationid . '-' . $USER->id . '-' . $courseid . '-' . time() .
            '-' . random_int(1, 1000) . '.png';
        $record->userid = $USER->id;
        $record->author = fullname($USER);
        $record->license = '';
        $record->courseid = $courseid;

        $fs = get_file_storage();
        $fs->create_file_from_string($record, $bytes);

        return moodle_url::make_pluginfile_url(
            $context->id,
            $record->component,
            $record->filearea,
            $record->itemid,
            $record->filepath,
            $record->filename,
            false
        )->out(false);
    }

    /**
     * Calls the configured Saylor/custom ID verification endpoint.
     *
     * @param string $idbytes ID document image bytes.
     * @param string $livebytes Live webcam image bytes.
     * @param stdClass $user Moodle user record.
     * @return array Normalized result.
     */
    private static function call_id_verification_endpoint(string $idbytes, string $livebytes, stdClass $user): array {
        $endpoint = trim((string)get_config('quizaccess_proctoring', 'idverificationendpoint'));
        $apikey = trim((string)get_config('quizaccess_proctoring', 'idverificationapikey'));
        $faceconfig = get_config('quizaccess_proctoring', 'idverificationfacethreshold');
        $nameconfig = get_config('quizaccess_proctoring', 'idverificationnamethreshold');
        $facethreshold = max(1, min(100, $faceconfig === false ? 80 : (int)$faceconfig));
        $namethreshold = max(1, min(100, $nameconfig === false ? 80 : (int)$nameconfig));

        if ($endpoint === '' || $apikey === '') {
            return self::make_id_verification_result(
                'error',
                0,
                0,
                '',
                get_string('modal:idverificationprovidererror', 'quizaccess_proctoring')
            );
        }

        try {
            $endpoint = quizaccess_proctoring_validate_outbound_endpoint($endpoint);
        } catch (moodle_exception $e) {
            return self::make_id_verification_result(
                'error',
                0,
                0,
                '',
                get_string('outboundendpointinvalid', 'quizaccess_proctoring')
            );
        }

        $payload = json_encode([
            'id_image' => base64_encode($idbytes),
            'live_image' => base64_encode($livebytes),
            'profile_firstname' => (string)($user->firstname ?? ''),
            'profile_lastname' => (string)($user->lastname ?? ''),
            'profile_fullname' => fullname($user),
            'face_threshold' => $facethreshold,
            'name_threshold' => $namethreshold,
        ]);

        if ($payload === false) {
            return self::make_id_verification_result(
                'error',
                0,
                0,
                '',
                get_string('modal:idverificationprovidererror', 'quizaccess_proctoring')
            );
        }

        $curl = new curl();
        $response = $curl->post($endpoint, $payload, [
            'CURLOPT_TIMEOUT' => 45,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_HTTPHEADER' => [
                'X-API-Key: ' . $apikey,
                'Content-Type: application/json',
            ],
        ]);

        if ($curl->get_errno()) {
            return self::make_id_verification_result(
                'error',
                0,
                0,
                '',
                get_string('modal:idverificationprovidererror', 'quizaccess_proctoring')
            );
        }

        $httpcode = (int)($curl->get_info()['http_code'] ?? 0);
        if ($httpcode < 200 || $httpcode >= 300) {
            return self::make_id_verification_result(
                'error',
                0,
                0,
                '',
                get_string('modal:idverificationprovidererror', 'quizaccess_proctoring')
            );
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            return self::make_id_verification_result(
                'error',
                0,
                0,
                '',
                get_string('modal:idverificationprovidererror', 'quizaccess_proctoring')
            );
        }

        $verified = !empty($decoded['verified']) || !empty($decoded['match']);
        $facescore = self::get_first_numeric_response_value($decoded, [
            'face_score',
            'faceScore',
            'similarity',
            'similarity_score',
        ]);
        $namescore = self::get_first_numeric_response_value($decoded, [
            'name_score',
            'nameScore',
            'profile_name_score',
            'name_similarity',
        ]);

        if ($verified) {
            $facescore = $facescore > 0 ? $facescore : 100;
            $namescore = $namescore > 0 ? $namescore : 100;
        }

        $rawstatus = strtolower((string)($decoded['status'] ?? $decoded['decision'] ?? ''));
        $status = ($facescore >= $facethreshold && $namescore >= $namethreshold) ? 'pass' : 'failed';
        if ($status !== 'pass' && in_array($rawstatus, ['retry', 'manual', 'error'], true)) {
            $status = $rawstatus === 'manual' ? 'failed' : $rawstatus;
        }

        $extractedname = (string)($decoded['extracted_name'] ?? $decoded['id_name'] ?? $decoded['name'] ?? '');
        $message = (string)($decoded['message'] ?? $decoded['summary'] ?? '');
        if ($message === '') {
            $message = $status === 'pass'
                ? get_string('modal:idverificationpassed', 'quizaccess_proctoring')
                : get_string('modal:idverificationfailed', 'quizaccess_proctoring');
        }

        return self::make_id_verification_result($status, $facescore, $namescore, $extractedname, $message);
    }

    /**
     * Gets the student-facing ID verification message for a normalized provider result.
     *
     * @param array $result Normalized ID verification result.
     * @param string $profilename Moodle profile name used for comparison.
     * @return string Message shown to the student and stored with the verification row.
     */
    private static function get_id_verification_student_message(array $result, string $profilename): string {
        $status = (string)($result['status'] ?? '');
        $message = (string)($result['message'] ?? '');
        if ($status === 'pass' || $status === 'retry' || $status === 'error') {
            return $message;
        }

        if ((int)get_config('quizaccess_proctoring', 'idverificationfailuredetails') !== 1) {
            return get_string('modal:idverificationfailed', 'quizaccess_proctoring');
        }

        $faceconfig = get_config('quizaccess_proctoring', 'idverificationfacethreshold');
        $nameconfig = get_config('quizaccess_proctoring', 'idverificationnamethreshold');
        $facethreshold = max(1, min(100, $faceconfig === false ? 80 : (int)$faceconfig));
        $namethreshold = max(1, min(100, $nameconfig === false ? 80 : (int)$nameconfig));
        $facefailed = (int)($result['facescore'] ?? 0) < $facethreshold;
        $namefailed = (int)($result['namescore'] ?? 0) < $namethreshold;

        if ($facefailed && $namefailed) {
            return get_string('modal:idverificationfailed_both', 'quizaccess_proctoring');
        }

        if ($facefailed) {
            return get_string('modal:idverificationfailed_face', 'quizaccess_proctoring');
        }

        if ($namefailed) {
            $idname = trim((string)($result['extractedname'] ?? ''));
            return get_string('modal:idverificationfailed_name', 'quizaccess_proctoring', (object)[
                'idname' => $idname !== '' ? $idname : get_string('modal:idverificationnameunknown', 'quizaccess_proctoring'),
                'profilename' => $profilename,
            ]);
        }

        return $message !== '' ? $message : get_string('modal:idverificationfailed', 'quizaccess_proctoring');
    }

    /**
     * Gets the first numeric response value matching a set of candidate keys.
     *
     * @param array $response Decoded API response.
     * @param array $keys Candidate keys.
     * @return int Rounded score.
     */
    private static function get_first_numeric_response_value(array $response, array $keys): int {
        foreach ($keys as $key) {
            if (isset($response[$key]) && is_numeric($response[$key])) {
                return max(0, min(100, (int)round((float)$response[$key])));
            }
        }

        if (isset($response['scores']) && is_array($response['scores'])) {
            return self::get_first_numeric_response_value($response['scores'], $keys);
        }

        return 0;
    }

    /**
     * Creates a normalized ID verification result array.
     *
     * @param string $status Result status.
     * @param int $facescore Face score.
     * @param int $namescore Name score.
     * @param string $extractedname Extracted ID name.
     * @param string $message Provider or validation message.
     * @return array
     */
    private static function make_id_verification_result(
        string $status,
        int $facescore,
        int $namescore,
        string $extractedname,
        string $message
    ): array {
        $status = in_array($status, ['pass', 'failed', 'retry', 'error'], true) ? $status : 'failed';

        return [
            'status' => $status,
            'facescore' => max(0, min(100, $facescore)),
            'namescore' => max(0, min(100, $namescore)),
            'extractedname' => core_text::substr($extractedname, 0, 255),
            'message' => core_text::substr($message, 0, 1000),
        ];
    }

    /**
     * Saves a student's first webcam capture as their reference image.
     *
     * @param int $userid The user ID.
     * @param string $webcampicture Full webcam image as a base64 data URI.
     * @param string $faceimage Cropped face image as a base64 data URI when available.
     * @param int $facefound Whether the browser-side detector found a face.
     * @return int The quizaccess_proctoring_user_images record ID.
     */
    private static function save_reference_image(int $userid, string $webcampicture, string $faceimage, int $facefound): int {
        global $DB;

        $context = context_system::instance();
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'quizaccess_proctoring', 'user_photo', $userid);

        $record = new stdClass();
        $record->filearea = 'user_photo';
        $record->component = 'quizaccess_proctoring';
        $record->filepath = file_correct_filepath('');
        $record->itemid = $userid;
        $record->license = '';
        $record->author = '';
        $record->courseid = 0;
        $record->filename = 'user-' . $userid . '-' . time() . '-' . random_int(1, 1000) . '.png';
        $record->contextid = $context->id;
        $record->userid = $userid;

        $fs->create_file_from_string($record, self::decode_base64_image_data($webcampicture));

        $userimagerecord = $DB->get_record('quizaccess_proctoring_user_images', ['user_id' => $userid]);
        if ($userimagerecord) {
            $userimagerecord->photo_draft_id = 0;
            $DB->update_record('quizaccess_proctoring_user_images', $userimagerecord);
            $parentid = $userimagerecord->id;
        } else {
            $userimagerecord = new stdClass();
            $userimagerecord->user_id = $userid;
            $userimagerecord->photo_draft_id = 0;
            $parentid = $DB->insert_record('quizaccess_proctoring_user_images', $userimagerecord);
        }

        $faceimagefile = new stdClass();
        $faceimagefile->filearea = 'face_image';
        $faceimagefile->component = 'quizaccess_proctoring';
        $faceimagefile->filepath = file_correct_filepath('');
        $faceimagefile->itemid = $userid;
        $faceimagefile->license = '';
        $faceimagefile->author = '';

        $faceimagedata = !empty($faceimage) ? $faceimage : $webcampicture;
        $faceurl = quizaccess_proctoring_geturl_of_faceimage($faceimagedata, $userid, $faceimagefile, $context, $fs);

        $facetablerecord = new stdClass();
        $facetablerecord->parent_type = 'admin_image';
        $facetablerecord->parentid = $parentid;
        $facetablerecord->faceimage = "{$faceurl}";
        $facetablerecord->facefound = $facefound ? 1 : 0;
        $facetablerecord->timemodified = time();

        $existingface = $DB->get_record('quizaccess_proctoring_face_images', [
            'parentid' => $parentid,
            'parent_type' => 'admin_image',
        ]);

        if ($existingface) {
            $facetablerecord->id = $existingface->id;
            $DB->update_record('quizaccess_proctoring_face_images', $facetablerecord);
        } else {
            $DB->insert_record('quizaccess_proctoring_face_images', $facetablerecord);
        }

        return $parentid;
    }

    /**
     * Applies a server-side quality floor before saving a self-registered reference image.
     *
     * @param string $webcampicture Full webcam image as a base64 data URI.
     * @param string $faceimage Cropped face image as a base64 data URI.
     * @return bool True when the image is good enough to use as a future reference.
     */
    private static function reference_image_is_clear(string $webcampicture, string $faceimage): bool {
        if (!function_exists('imagecreatefromstring')) {
            return true;
        }

        $imagedata = !empty($faceimage) ? $faceimage : $webcampicture;
        $image = @imagecreatefromstring(self::decode_base64_image_data($imagedata, self::MAX_FACE_IMAGE_BYTES));
        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 50 || $height < 50) {
            imagedestroy($image);
            return false;
        }

        $quality = self::get_image_quality($image, $width, $height);
        imagedestroy($image);

        return $quality['brightness'] >= 25 &&
            $quality['brightness'] <= 235 &&
            $quality['contrast'] >= 5 &&
            $quality['sharpness'] >= 1;
    }

    /**
     * Estimates brightness, contrast, and edge sharpness for an image resource.
     *
     * @param resource|\GdImage $image Image resource.
     * @param int $width Image width.
     * @param int $height Image height.
     * @return array Image quality measurements.
     */
    private static function get_image_quality($image, int $width, int $height): array {
        $step = max(1, (int)floor(sqrt(($width * $height) / 2500)));
        $count = 0;
        $sum = 0;
        $sumsq = 0;
        $edgedelta = 0;
        $edgecount = 0;
        $previousrow = [];

        for ($y = 0; $y < $height; $y += $step) {
            $row = [];
            $column = 0;
            for ($x = 0; $x < $width; $x += $step) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $luminance = (0.2126 * $rgb['red']) + (0.7152 * $rgb['green']) + (0.0722 * $rgb['blue']);
                $row[$column] = $luminance;
                $sum += $luminance;
                $sumsq += $luminance * $luminance;
                $count++;

                if ($column > 0) {
                    $edgedelta += abs($luminance - $row[$column - 1]);
                    $edgecount++;
                }
                if (array_key_exists($column, $previousrow)) {
                    $edgedelta += abs($luminance - $previousrow[$column]);
                    $edgecount++;
                }
                $column++;
            }
            $previousrow = $row;
        }

        $brightness = $count > 0 ? $sum / $count : 0;
        $variance = $count > 0 ? max(0, ($sumsq / $count) - ($brightness * $brightness)) : 0;

        return [
            'brightness' => $brightness,
            'contrast' => sqrt($variance),
            'sharpness' => $edgecount > 0 ? $edgedelta / $edgecount : 0,
        ];
    }

    /**
     * Decodes a base64 image or base64 data URI.
     *
     * @param string $data Image data.
     * @return string Decoded binary image data.
     * @throws invalid_parameter_exception If the payload cannot be decoded.
     */
    private static function decode_base64_image_data(string $data, int $maxbytes = self::MAX_WEBCAM_IMAGE_BYTES): string {
        $data = trim($data);
        if ($data === '') {
            throw new invalid_parameter_exception('Image data is required.');
        }

        if (preg_match('/^data:([^;]+);base64,(.*)$/is', $data, $matches)) {
            $mime = strtolower($matches[1]);
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'], true)) {
                throw new invalid_parameter_exception('Unsupported image type.');
            }
            $data = $matches[2];
        } else if (strpos($data, ',') !== false) {
            [, $data] = explode(',', $data, 2);
        }

        $data = preg_replace('/\s+/', '', $data);
        $maxencoded = (int)ceil(($maxbytes * 4) / 3) + 1024;
        if (strlen($data) > $maxencoded) {
            throw new invalid_parameter_exception('Image data is too large.');
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new invalid_parameter_exception('Invalid reference image data.');
        }
        if (strlen($decoded) > $maxbytes) {
            throw new invalid_parameter_exception('Image data is too large.');
        }

        $info = @getimagesizefromstring($decoded);
        if (empty($info[0]) || empty($info[1]) || empty($info['mime']) ||
                strpos((string)$info['mime'], 'image/') !== 0) {
            throw new invalid_parameter_exception('Invalid image data.');
        }
        if (((int)$info[0] * (int)$info[1]) > self::MAX_IMAGE_PIXELS) {
            throw new invalid_parameter_exception('Image dimensions are too large.');
        }

        return $decoded;
    }

    /**
     * Returns the image URL from image data after adding a timecode at the top of the image.
     *
     * This function processes the base64 encoded image data, adds a timecode, and stores the image in Moodle's file system.
     * It then returns the URL of the stored image file.
     *
     * @param string $data The base64 encoded image data.
     * @param int $screenshotid The unique ID of the screenshot.
     * @param object $USER The current user object.
     * @param int $courseid The ID of the course.
     * @param stdClass $record The record object used to store file metadata.
     * @param context $context The context in which the file will be stored.
     * @param mixed $fs The file storage instance to handle file operations.
     * @return mixed The URL of the stored image file with the timecode added.
     */
    private static function geturl(string $data, int $screenshotid, $USER, int $courseid, stdClass $record, $context, $fs) {
        $data = self::decode_base64_image_data($data, self::MAX_WEBCAM_IMAGE_BYTES);
        $filename = 'webcam-' . $screenshotid . '-' . $USER->id . '-' . $courseid . '-' . time() . random_int(1, 1000) . '.png';

        $data = self::add_timecode_to_image($data);

        $record->courseid = $courseid;
        $record->filename = $filename;
        $record->contextid = $context->id;
        $record->userid = $USER->id;

        $fs->create_file_from_string($record, $data);

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

    /**
     * Returns the image URL without adding a timecode at the top of the image.
     *
     * This function processes the base64 encoded image data, stores the image in Moodle's file system without adding a timecode,
     * and then returns the URL of the stored image file.
     *
     * @param string $data The base64 encoded image data.
     * @param int $screenshotid The unique ID of the screenshot.
     * @param object $USER The current user object.
     * @param int $courseid The ID of the course.
     * @param stdClass $record The record object used to store file metadata.
     * @param mixed $context The context in which the file will be stored.
     * @param mixed $fs The file storage instance to handle file operations.
     * @return mixed The URL of the stored image file without the timecode added.
     */
    private static function quizaccess_proctoring_geturl_without_timecode(
        string $data, int $screenshotid, $USER, int $courseid, stdClass $record, $context, $fs) {
        $data = self::decode_base64_image_data($data, self::MAX_FACE_IMAGE_BYTES);
        $filename = 'webcam-' . $screenshotid . '-' . $USER->id . '-' . $courseid . '-' . time() . random_int(1, 1000) . '.png';

        $record->courseid = $courseid;
        $record->filename = $filename;
        $record->contextid = $context->id;
        $record->userid = $USER->id;

        $fs->create_file_from_string($record, $data);

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
}
