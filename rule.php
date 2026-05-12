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
 * Implementaton for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2020 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This file must be included within the Moodle framework.
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/classes/link_generator.php');
require_once(__DIR__ . '/lib.php');

// Check if the Moodle version is 4.2 or higher, which introduced updates to the access rule base class.
if (class_exists('\mod_quiz\local\access_rule_base')) {
    // Use class aliases for compatibility with Moodle 4.2 or higher.
    class_alias('\mod_quiz\local\access_rule_base', '\quizaccess_proctoring_parent_class_alias');
    class_alias('\mod_quiz\form\preflight_check_form', '\quizaccess_proctoring_preflight_form_alias');
} else {
    // Include the legacy access rule base class for older Moodle versions.
    require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');
    class_alias('\quiz_access_rule_base', '\quizaccess_proctoring_parent_class_alias');
    class_alias('\mod_quiz_preflight_check_form', '\quizaccess_proctoring_preflight_form_alias');
}

/**
 * Quiz access proctoring class.
 *
 * Extends the parent class to implement custom proctoring behavior.
 */
class quizaccess_proctoring extends quizaccess_proctoring_parent_class_alias {
    /** @var string Cloudflare Turnstile token verification endpoint. */
    private const TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    /** @var string Skip desktop screen-share requirements on mobile/tablet browsers. */
    private const MOBILE_SCREEN_SHARE_BYPASS = 'bypass';
    /** @var string Try to enforce desktop screen-share requirements on mobile/tablet browsers. */
    private const MOBILE_SCREEN_SHARE_REQUIRE = 'require';
    /** @var string Block mobile/tablet browsers when desktop screen sharing is required. */
    private const MOBILE_SCREEN_SHARE_BLOCK = 'block';

    /**
     * Determines whether a preflight check is required for the given attempt.
     *
     * @param int $attemptid The ID of the attempt being checked.
     * @return bool True if a preflight check is required, false otherwise.
     */
    public function is_preflight_check_required($attemptid) {
        $script = $this->get_topmost_script();
        $base = basename($script);

        return ($base === 'view.php');
    }

    /**
     * Get the file path of the topmost script in the call stack.
     *
     * @return string The file path of the topmost script.
     * @throws coding_exception If an error occurs while retrieving the script.
     */
    public function get_topmost_script() {
        $backtrace = debug_backtrace(
            defined('DEBUG_BACKTRACE_IGNORE_ARGS') ? DEBUG_BACKTRACE_IGNORE_ARGS : false
        );
        $topframe = array_pop($backtrace);

        return $topframe['file'];
    }

    /**
     * Retrieve course ID, quiz ID, and course module ID from the preflight form.
     *
     * @param quizaccess_proctoring_preflight_form_alias $quizform The preflight form instance.
     * @return array An associative array containing 'courseid', 'quizid', and 'cmid'.
     * @throws coding_exception If an error occurs during processing.
     */
    public function get_courseid_cmid_from_preflight_form(quizaccess_proctoring_preflight_form_alias $quizform) {
        return [
            'courseid' => $this->quiz->course,
            'quizid' => $this->quiz->id,
            'cmid' => $this->quiz->cmid,
        ];
    }

    /**
     * Determine whether the site default requires an entire screen share before quiz start.
     *
     * @return bool True if the site default requires an entire screen share.
     */
    private static function site_requires_entire_screen() {
        $setting = get_config('quizaccess_proctoring', 'requireentirescreen');

        if ($setting === false || $setting === null || $setting === '') {
            return true;
        }

        return (int)$setting === 1;
    }

    /**
     * Determine whether this quiz is configured to require an entire screen share before quiz start.
     *
     * @return bool True if this quiz/site configuration requires an entire screen share.
     */
    private function configured_requires_entire_screen() {
        $quizsetting = $this->quiz->requireentirescreen ?? -1;

        if ($quizsetting === null || (int)$quizsetting === -1) {
            return self::site_requires_entire_screen();
        }

        return (int)$quizsetting === 1;
    }

    /**
     * Determine whether the site captures desktop screenshots on violations.
     *
     * @return bool True if desktop capture is enabled.
     */
    private static function site_captures_violation_desktop() {
        $setting = get_config('quizaccess_proctoring', 'captureviolationdesktop');

        if ($setting === false || $setting === null || $setting === '') {
            return true;
        }

        return (int)$setting === 1;
    }

    /**
     * Get the mobile/tablet desktop screen-share policy.
     *
     * @return string One of the MOBILE_SCREEN_SHARE_* constants.
     */
    private static function mobile_screen_share_mode() {
        $mode = get_config('quizaccess_proctoring', 'mobilescreensharemode');

        if (in_array($mode, [
            self::MOBILE_SCREEN_SHARE_BYPASS,
            self::MOBILE_SCREEN_SHARE_REQUIRE,
            self::MOBILE_SCREEN_SHARE_BLOCK,
        ], true)) {
            return $mode;
        }

        return self::MOBILE_SCREEN_SHARE_BYPASS;
    }

    /**
     * Determine whether the current request is from a mobile or tablet device.
     *
     * @return bool True for Moodle-detected mobile/tablet browsers.
     */
    private static function is_mobile_or_tablet() {
        $devicetype = \core_useragent::get_device_type();

        return in_array($devicetype, [
            \core_useragent::DEVICETYPE_MOBILE,
            \core_useragent::DEVICETYPE_TABLET,
        ], true);
    }

    /**
     * Determine whether mobile/tablet users should be blocked because screen sharing is required.
     *
     * @return bool True when the student should use a desktop/laptop browser.
     */
    private function mobile_screen_share_blocks_attempt() {
        if (!self::is_mobile_or_tablet() || self::mobile_screen_share_mode() !== self::MOBILE_SCREEN_SHARE_BLOCK) {
            return false;
        }

        return $this->configured_requires_entire_screen() || self::site_captures_violation_desktop();
    }

    /**
     * Determine whether this request should require an entire screen share before quiz start.
     *
     * @return bool True if the preflight form should require an entire screen share.
     */
    private function requires_entire_screen() {
        if (!$this->configured_requires_entire_screen()) {
            return false;
        }

        if (self::is_mobile_or_tablet() && self::mobile_screen_share_mode() === self::MOBILE_SCREEN_SHARE_BYPASS) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the attempt page should request desktop capture for browser violations.
     *
     * @return bool True when desktop capture should be requested during the attempt.
     */
    private function should_capture_violation_desktop() {
        if (!self::site_captures_violation_desktop()) {
            return false;
        }

        if (self::is_mobile_or_tablet() && self::mobile_screen_share_mode() === self::MOBILE_SCREEN_SHARE_BYPASS) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the site default requires CAPTCHA before starting a quiz attempt.
     *
     * @return bool True if the site default requires CAPTCHA.
     */
    private static function site_requires_captcha() {
        $setting = get_config('quizaccess_proctoring', 'captchabeforeattemptenabled');

        if ($setting === false || $setting === null || $setting === '') {
            return false;
        }

        return (int)$setting === 1;
    }

    /**
     * Determine whether this quiz requires CAPTCHA before starting a new attempt.
     *
     * @return bool True if CAPTCHA is required.
     */
    private function requires_captcha() {
        $quizsetting = $this->quiz->captchamode ?? -1;

        if ($quizsetting === null || (int)$quizsetting === -1) {
            return self::site_requires_captcha();
        }

        return (int)$quizsetting === 1;
    }

    /**
     * Get the configured CAPTCHA provider.
     *
     * @return string CAPTCHA provider key.
     */
    private static function captcha_provider() {
        $provider = get_config('quizaccess_proctoring', 'captchaprovider');

        if (in_array($provider, ['turnstile', 'recaptcha'], true)) {
            return $provider;
        }

        return 'turnstile';
    }

    /**
     * Determine whether the selected CAPTCHA provider keys are configured.
     *
     * @return bool True when the selected provider has required keys.
     */
    private static function captcha_configured() {
        global $CFG;

        if (self::captcha_provider() === 'turnstile') {
            return trim((string)get_config('quizaccess_proctoring', 'turnstilesitekey')) !== '' &&
                trim((string)get_config('quizaccess_proctoring', 'turnstilesecretkey')) !== '';
        }

        return !empty($CFG->recaptchapublickey) && !empty($CFG->recaptchaprivatekey);
    }

    /**
     * Get the selected provider's missing-configuration message.
     *
     * @return string Localized message.
     */
    private static function captcha_not_configured_message() {
        if (self::captcha_provider() === 'turnstile') {
            return get_string('captcha:turnstilenotconfigured', 'quizaccess_proctoring');
        }

        return get_string('captcha:recaptchanotconfigured', 'quizaccess_proctoring');
    }

    /**
     * Render the Cloudflare Turnstile widget.
     *
     * @return string Widget HTML.
     */
    private static function turnstile_widget_html() {
        $sitekey = trim((string)get_config('quizaccess_proctoring', 'turnstilesitekey'));

        return html_writer::div('', 'cf-turnstile', [
                'data-sitekey' => $sitekey,
                'data-theme' => 'auto',
            ]) .
            html_writer::tag('script', '', [
                'src' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
                'async' => 'async',
                'defer' => 'defer',
            ]);
    }

    /**
     * Determine whether CAPTCHA should be required for this preflight submission.
     *
     * @param int|null $attemptid Current attempt id, if one already exists.
     * @return bool True when starting a new attempt requires CAPTCHA.
     */
    private function should_require_captcha($attemptid) {
        return empty($attemptid) && $this->requires_captcha();
    }

    /**
     * Determine whether students must accept the pre-quiz integrity statement.
     *
     * @return bool True if the integrity statement checkbox is required.
     */
    private static function honor_statement_required() {
        $setting = get_config('quizaccess_proctoring', 'honorstatementrequired');

        if ($setting === false || $setting === null || $setting === '') {
            return true;
        }

        return (int)$setting === 1;
    }

    /**
     * Get the configured pre-quiz integrity statement.
     *
     * @return string The statement text shown before the quiz starts.
     */
    private static function get_honor_statement() {
        $statement = get_config('quizaccess_proctoring', 'honorstatement');

        if ($statement === false || trim((string)$statement) === '') {
            return get_string('honorstatement:default', 'quizaccess_proctoring');
        }

        return (string)$statement;
    }

    /**
     * Get the configured agreement checkbox label for the integrity statement.
     *
     * @return string The checkbox label.
     */
    private static function get_honor_agreement_label() {
        $label = get_config('quizaccess_proctoring', 'honoragreementlabel');

        if ($label === false || trim((string)$label) === '') {
            return get_string('honorstatement:agreementdefault', 'quizaccess_proctoring');
        }

        return (string)$label;
    }

    /**
     * Check whether the current user can bypass student-only retake lockouts.
     *
     * @return bool True when the current user can review risk holds for this quiz.
     */
    private function can_bypass_cheating_lockout(): bool {
        global $USER;

        if (empty($USER->id) || empty($this->quiz->cmid)) {
            return false;
        }

        $context = context_module::instance((int)$this->quiz->cmid);
        return has_capability('quizaccess/proctoring:reviewriskholds', $context, $USER->id);
    }

    /**
     * Get the currently active confirmed-violation lockout for the current user.
     *
     * @return array|false Lockout details or false.
     */
    private function get_current_user_cheating_lockout() {
        global $USER;

        if (empty($USER->id) || empty($this->quiz->course) || empty($this->quiz->cmid) ||
                $this->can_bypass_cheating_lockout()) {
            return false;
        }

        return quizaccess_proctoring_get_active_cheating_lockout(
            (int)$this->quiz->course,
            (int)$this->quiz->cmid,
            (int)$USER->id,
            (int)$this->timenow
        );
    }


    /**
     * Generate the modal content for the webcam proctoring interface.
     *
     * @param mixed $quizform The quiz form instance.
     * @param mixed $faceidcheck A flag indicating whether face ID check is required.
     * @return string The rendered HTML content for the modal.
     * @throws coding_exception If an error occurs during rendering.
     */
    public function make_modal_content($quizform, $faceidcheck) {
        global $OUTPUT;

        // Prepare data for Mustache template rendering.
        $data = [
            'header' => get_string('openwebcam', 'quizaccess_proctoring'),
            'proctoringstatement' => get_string(
                'proctoringstatement',
                'quizaccess_proctoring'
            ),
            'videonotavailable' => get_string('videonotavailable', 'quizaccess_proctoring'),
            'photoalt' => get_string('photoalttext', 'quizaccess_proctoring'),
        ];

        // Render the content using Mustache template.
        return $OUTPUT->render_from_template('quizaccess_proctoring/cam_modal_content', $data);
    }

    /**
     * Adds preflight check form fields.
     *
     * @param quizaccess_proctoring_preflight_form_alias $quizform The preflight form instance.
     * @param MoodleQuickForm $mform The Moodle form object.
     * @param int $attemptid The quiz attempt ID.
     * @throws coding_exception If an error occurs during processing.
     */
    public function add_preflight_check_form_fields(
        quizaccess_proctoring_preflight_form_alias $quizform,
        MoodleQuickForm $mform,
        $attemptid
    ) {
        global $PAGE, $DB, $USER, $CFG;

        // Retrieve course and module data.
        $coursedata = $this->get_courseid_cmid_from_preflight_form($quizform);

        // Fetch camera shot delay configuration.
        $delaydata = $DB->get_record('config_plugins', [
            'plugin' => 'quizaccess_proctoring',
            'name' => 'autoreconfigurecamshotdelay',
        ]);
        $camshotdelay = !empty($delaydata) ? ((int)$delaydata->value * 1000) : 30000; // Default to 30 seconds if not configured.

        // Fetch face ID check setting.
        $faceidrow = $DB->get_record('config_plugins', [
            'plugin' => 'quizaccess_proctoring',
            'name' => 'fcheckstartchk',
        ]);
        $faceidcheck = $faceidrow->value ?? 0;

        // Fetch image width configuration.
        $imagewidth = get_config('quizaccess_proctoring', 'autoreconfigureimagewidth') ?? '';
        $hasreferenceimage = $DB->record_exists('quizaccess_proctoring_user_images', ['user_id' => $USER->id]);
        $registerface = ($faceidcheck === '1' && !$hasreferenceimage);
        $requireentirescreen = $this->requires_entire_screen() ? 1 : 0;

        // Prepare data for the JavaScript module.
        $examurl = new moodle_url('/mod/quiz/startattempt.php');
        $screenmonitorkey = 'cm' . (int)$coursedata['cmid'] . 'user' . (int)$USER->id;
        $screenmonitorurl = new moodle_url('/mod/quiz/accessrule/proctoring/screenmonitor.php', [
            'cmid' => (int)$coursedata['cmid'],
            'key' => $screenmonitorkey,
        ]);
        $usepersistentmonitor = !self::is_mobile_or_tablet();
        $record = [
            'id' => 0,
            'courseid' => (int)$coursedata['courseid'],
            'cmid' => (int)$coursedata['cmid'],
            'attemptid' => $attemptid,
            'imagewidth' => $imagewidth,
            'screenshotinterval' => $camshotdelay,
            'examurl' => $examurl->out(false),
            'registerface' => $registerface ? 1 : 0,
            'faceidcheck' => $faceidcheck === '1' ? 1 : 0,
            'requireentirescreen' => $requireentirescreen,
            'screenmonitorurl' => $usepersistentmonitor ? $screenmonitorurl->out(false) : '',
            'screenmonitorchannel' => $usepersistentmonitor ? 'quizaccess_proctoring_screen_' . $screenmonitorkey : '',
            'screenmonitorstatuskey' => $usepersistentmonitor ? 'quizaccess_proctoring_screen_status_' . $screenmonitorkey : '',
            'screenmonitorwindowname' => $usepersistentmonitor ? 'quizaccess_proctoring_screen_' . $screenmonitorkey : '',
        ];

        // Include Face API JS library if required.
        $fcmethod = get_config('quizaccess_proctoring', 'fcmethod');
        $modelurl = null;
        if ($fcmethod === 'customapi') {
            $modelurl = $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/thirdpartylibs/models';
            $PAGE->requires->js('/mod/quiz/accessrule/proctoring/amd/build/face-api.min.js', true);
        }
        $PAGE->requires->js_call_amd('quizaccess_proctoring/startAttempt', 'setup', [$record, $modelurl]);

        // Add HTML wrapper for the form.
        $mform->addElement('html', "<div class='quiz-check-form'>");

        // Prepare user profile image URL.
        $profileimageurl = $USER->picture
            ? (new moodle_url("/user/pix.php/{$USER->id}/f1.jpg"))->out(false)
            : '';

        if (self::honor_statement_required()) {
            $statement = html_writer::tag('h3',
                    get_string('honorstatement:heading', 'quizaccess_proctoring')) .
                html_writer::div(
                    format_text(self::get_honor_statement(), FORMAT_PLAIN, ['para' => true]),
                    'proctoring-honor-statement-text'
                );
            $mform->addElement('html', html_writer::div($statement, 'proctoring-honor-statement mb-3'));
            $mform->addElement('checkbox', 'proctoring', '', self::get_honor_agreement_label());
            $mform->addRule('proctoring', get_string('youmustagree', 'quizaccess_proctoring'), 'required', null, 'client');
        }

        if ($this->should_require_captcha($attemptid)) {
            if (self::captcha_configured()) {
                if (self::captcha_provider() === 'turnstile') {
                    $mform->addElement(
                        'static',
                        'proctoringcaptcha',
                        get_string('captcha:heading', 'quizaccess_proctoring'),
                        self::turnstile_widget_html()
                    );
                } else {
                    $mform->addElement('recaptcha', 'proctoringcaptcha', get_string('captcha:heading', 'quizaccess_proctoring'));
                    $mform->addHelpButton('proctoringcaptcha', 'recaptcha', 'auth');
                }
            } else {
                $mform->addElement(
                    'static',
                    'proctoringcaptchaunavailable',
                    get_string('captcha:heading', 'quizaccess_proctoring'),
                    self::captcha_not_configured_message()
                );
            }
        }

        // Render modal content.
        $modalcontent = $this->make_modal_content($quizform, $faceidcheck);
        // Add modal content and action buttons to the form.
        $mform->addElement('html', $modalcontent);

        // Hidden form inputs.
        $hiddenvalue = sprintf(
            '<input type="hidden" id="courseidval" value="%d"/>
            <input type="hidden" id="cmidval" value="%d"/>
            <input type="hidden" id="profileimage" value="%s"/>
            <input type="hidden" id="faceregistrationneeded" value="%d"/>',
            $coursedata['courseid'],
            $coursedata['cmid'],
            $profileimageurl,
            $registerface ? 1 : 0
        );

        // Prepare action buttons if face validation is enabled.
        $actionbtns = '';
        if ($faceidcheck === '1') {
            $facevalidationlabel = $registerface
                ? get_string('modal:faceregistration', 'quizaccess_proctoring')
                : get_string('modal:facevalidation', 'quizaccess_proctoring');
            $pending = get_string('modal:pending', 'quizaccess_proctoring');
            $validateface = $registerface
                ? get_string('modal:registerface', 'quizaccess_proctoring')
                : get_string('modal:validateface', 'quizaccess_proctoring');
            $actionbtns = sprintf(
                "%s&nbsp;<span id='face_validation_result'>%s</span>
                <button id='fcvalidate' class='btn btn-primary mt-3' style='display: flex;
                                            justify-content: center; align-items: center;'>
                    <div class='proctoring-loadingspinner' id='loading_spinner'></div>%s
                </button>",
                $facevalidationlabel,
                $pending,
                $validateface
            );
        }

        if (!empty($actionbtns)) {
            $mform->addElement('html', "<div class='container'><div class='row'><div class='col'>{$actionbtns}</div></div></div>");
        }

        if ($requireentirescreen === 1) {
            $screensharebtn = sprintf(
                "<div class='container'><div class='row'><div class='col'>
                    %s&nbsp;<span id='screen_share_result'>%s</span>
                    <button id='screensharevalidate' class='btn btn-secondary mt-3' style='display: flex;
                                                justify-content: center; align-items: center;'>
                        %s
                    </button>
                </div></div></div>",
                get_string('modal:screenshare', 'quizaccess_proctoring'),
                get_string('modal:pending', 'quizaccess_proctoring'),
                get_string('modal:shareentirescreen', 'quizaccess_proctoring')
            );
            $mform->addElement('html', $screensharebtn);
        }

        // Add hidden inputs and proctoring checkbox.
        $mform->addElement('html', $hiddenvalue);
        $mform->addElement('hidden', 'entirescreenconfirmed', 0);
        $mform->setType('entirescreenconfirmed', PARAM_INT);

        // Close the form wrapper.
        $mform->addElement('html', '</div>');
    }

    /**
     * Validate the preflight check.
     *
     * @param array $data Form data submitted by the user.
     * @param array $files Files uploaded during the form submission.
     * @param array $errors Array to hold validation errors.
     * @param int $attemptid The quiz attempt ID.
     * @return array Updated errors array.
     */
    public function validate_preflight_check($data, $files, $errors, $attemptid) {
        global $CFG;

        // Extend validation from the parent class.
        if (method_exists(get_parent_class($this), 'validate_preflight_check')) {
            $errors = parent::validate_preflight_check($data, $files, $errors, $attemptid);
        }

        // Ensure the integrity statement checkbox is checked when the setting is enabled.
        if (self::honor_statement_required() && empty($data['proctoring'])) {
            $errors['proctoring'] = get_string('youmustagree', 'quizaccess_proctoring');
        }

        if ($this->requires_entire_screen() && empty($data['entirescreenconfirmed'])) {
            $errorkey = self::honor_statement_required() ? 'proctoring' : 'entirescreenconfirmed';
            if (empty($errors[$errorkey])) {
                $errors[$errorkey] = get_string('entirescreenrequired', 'quizaccess_proctoring');
            }
        }

        if ($this->should_require_captcha($attemptid)) {
            if (!self::captcha_configured()) {
                $errors['proctoringcaptchaunavailable'] = self::captcha_not_configured_message();
            } else if (self::captcha_provider() === 'turnstile') {
                $response = isset($data['cf-turnstile-response'])
                    ? (string)$data['cf-turnstile-response']
                    : optional_param('cf-turnstile-response', '', PARAM_RAW);
                if (trim($response) === '') {
                    $errors['proctoringcaptcha'] = get_string('captcha:missingchallenge', 'quizaccess_proctoring');
                } else {
                    require_once($CFG->libdir . '/filelib.php');
                    $curl = new curl();
                    $rawresponse = $curl->post(self::TURNSTILE_VERIFY_URL, [
                        'secret' => trim((string)get_config('quizaccess_proctoring', 'turnstilesecretkey')),
                        'response' => $response,
                        'remoteip' => getremoteaddr(),
                    ]);
                    $result = json_decode($rawresponse);
                    if (empty($result->success)) {
                        $errors['proctoringcaptcha'] = get_string('captcha:verificationfailed', 'quizaccess_proctoring');
                    }
                }
            } else {
                $response = isset($data['g-recaptcha-response'])
                    ? (string)$data['g-recaptcha-response']
                    : optional_param('g-recaptcha-response', '', PARAM_RAW);
                if (trim($response) === '') {
                    $errors['proctoringcaptcha'] = get_string('missingrecaptchachallengefield');
                } else {
                    require_once($CFG->libdir . '/recaptchalib_v2.php');
                    $result = recaptcha_check_response(
                        RECAPTCHA_VERIFY_URL,
                        $CFG->recaptchaprivatekey,
                        getremoteaddr(),
                        $response
                    );
                    if (empty($result['isvalid'])) {
                        $errors['proctoringcaptcha'] = !empty($result['error'])
                            ? $result['error']
                            : get_string('incorrectpleasetryagain', 'auth');
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Determine if the access rule should be applied to the quiz.
     *
     * @param quiz $quizobj Quiz object.
     * @param int $timenow Current timestamp.
     * @param bool $canignoretimelimits Flag to check if time limits can be ignored.
     * @return quiz_access_rule_base|null Returns an instance of the rule or null.
     */
    public static function make($quizobj, $timenow, $canignoretimelimits) {
        // Check if proctoring is required for the quiz.
        if (empty($quizobj->get_quiz()->proctoringrequired)) {
            return null;
        }

        return new self($quizobj, $timenow);
    }

    /**
     * Prevent new attempts while a confirmed proctoring violation lockout is active.
     *
     * @param int $numprevattempts Number of previous attempts.
     * @param stdClass|null $lastattempt The last attempt.
     * @return string|false Message when blocked, otherwise false.
     */
    public function prevent_new_attempt($numprevattempts, $lastattempt) {
        $lockout = $this->get_current_user_cheating_lockout();
        if ($lockout) {
            return get_string(
                'riskreview:lockoutmessage',
                'quizaccess_proctoring',
                userdate((int)$lockout['until'])
            );
        }

        if ($this->mobile_screen_share_blocks_attempt()) {
            return get_string('mobilescreenshare:blocked', 'quizaccess_proctoring');
        }

        return false;
    }

    /**
     * Add the proctoring required setting to the quiz settings form.
     *
     * @param mod_quiz_mod_form $quizform The quiz settings form object.
     * @param MoodleQuickForm $mform The Moodle form wrapper.
     */
    public static function add_settings_form_fields($quizform, MoodleQuickForm $mform) {
        // Add the "Proctoring Required" dropdown.
        $mform->addElement(
            'select',
            'proctoringrequired',
            get_string('proctoringrequired', 'quizaccess_proctoring'),
            [
                0 => get_string('notrequired', 'quizaccess_proctoring'),
                1 => get_string('proctoringrequiredoption', 'quizaccess_proctoring'),
            ]
        );

        // Add a help button for the proctoring setting.
        $mform->addHelpButton('proctoringrequired', 'proctoringrequired', 'quizaccess_proctoring');

        $mform->addElement(
            'select',
            'requireentirescreen',
            get_string('requireentirescreen', 'quizaccess_proctoring'),
            [
                -1 => get_string('requireentirescreen_inherit', 'quizaccess_proctoring'),
                1 => get_string('requireentirescreen_enabled', 'quizaccess_proctoring'),
                0 => get_string('requireentirescreen_disabled', 'quizaccess_proctoring'),
            ]
        );
        $mform->setDefault('requireentirescreen', -1);
        $mform->addHelpButton('requireentirescreen', 'requireentirescreen', 'quizaccess_proctoring');
        $mform->hideIf('requireentirescreen', 'proctoringrequired', 'eq', 0);

        $mform->addElement(
            'select',
            'captchamode',
            get_string('captchamode', 'quizaccess_proctoring'),
            [
                -1 => get_string('captchamode_inherit', 'quizaccess_proctoring'),
                1 => get_string('captchamode_enabled', 'quizaccess_proctoring'),
                0 => get_string('captchamode_disabled', 'quizaccess_proctoring'),
            ]
        );
        $mform->setDefault('captchamode', -1);
        $mform->addHelpButton('captchamode', 'captchamode', 'quizaccess_proctoring');
        $mform->hideIf('captchamode', 'proctoringrequired', 'eq', 0);

        $mform->addElement(
            'select',
            'riskreviewmode',
            get_string('riskreviewmode', 'quizaccess_proctoring'),
            [
                -1 => get_string('riskreviewmode_inherit', 'quizaccess_proctoring'),
                1 => get_string('riskreviewmode_enabled', 'quizaccess_proctoring'),
                0 => get_string('riskreviewmode_disabled', 'quizaccess_proctoring'),
            ]
        );
        $mform->setDefault('riskreviewmode', -1);
        $mform->addHelpButton('riskreviewmode', 'riskreviewmode', 'quizaccess_proctoring');
        $mform->hideIf('riskreviewmode', 'proctoringrequired', 'eq', 0);

        $mform->addElement(
            'text',
            'riskreviewthreshold',
            get_string('riskreviewthreshold', 'quizaccess_proctoring'),
            ['size' => 4]
        );
        $mform->setType('riskreviewthreshold', PARAM_INT);
        $mform->setDefault('riskreviewthreshold', -1);
        $mform->addRule('riskreviewthreshold', null, 'numeric', null, 'client');
        $mform->addHelpButton('riskreviewthreshold', 'riskreviewthreshold', 'quizaccess_proctoring');
        $mform->hideIf('riskreviewthreshold', 'proctoringrequired', 'eq', 0);
    }

    /**
     * Save any submitted settings when the quiz settings form is submitted.
     * Called from quiz_after_add_or_update() in lib.php.
     *
     * @param object $quiz Data from the quiz form, including $quiz->id for the quiz being saved.
     * @throws dml_exception
     */
    public static function save_settings($quiz) {
        global $DB;

        // Check if proctoring is required for the quiz.
        if (empty($quiz->proctoringrequired)) {
            // Remove any existing proctoring settings if not required.
            $DB->delete_records('quizaccess_proctoring', ['quizid' => $quiz->id]);
        } else {
            $riskreviewthreshold = isset($quiz->riskreviewthreshold) ? (int)$quiz->riskreviewthreshold : -1;
            if ($riskreviewthreshold !== -1) {
                $riskreviewthreshold = max(1, min(100, $riskreviewthreshold));
            }

            $record = (object)[
                'quizid' => $quiz->id,
                'proctoringrequired' => 1,
                'requireentirescreen' => isset($quiz->requireentirescreen) ? (int)$quiz->requireentirescreen : -1,
                'captchamode' => isset($quiz->captchamode) ? (int)$quiz->captchamode : -1,
                'riskreviewmode' => isset($quiz->riskreviewmode) ? (int)$quiz->riskreviewmode : -1,
                'riskreviewthreshold' => $riskreviewthreshold,
            ];

            // Add or update the proctoring settings for this quiz.
            if ($existing = $DB->get_record('quizaccess_proctoring', ['quizid' => $quiz->id])) {
                $record->id = $existing->id;
                $DB->update_record('quizaccess_proctoring', $record);
            } else {
                $DB->insert_record('quizaccess_proctoring', $record);
            }
        }
    }

    /**
     * Delete any rule-specific settings when the quiz is deleted.
     * Called from quiz_delete_instance() in lib.php.
     *
     * @param object $quiz Data from the database, including $quiz->id for the quiz being deleted.
     * @throws dml_exception
     */
    public static function delete_settings($quiz) {
        global $DB;

        // Remove all proctoring settings related to the quiz.
        $DB->delete_records('quizaccess_proctoring', ['quizid' => $quiz->id]);
    }

    /**
     * Return SQL needed to load settings from all access plugins in one query.
     * This optimizes performance for loading quiz settings.
     *
     * @param int $quizid The ID of the quiz for which settings are being loaded.
     * @return array Contains fields, joins, and params for the SQL query.
     */
    public static function get_settings_sql($quizid) {
        return [
            'proctoring.proctoringrequired, proctoring.requireentirescreen, ' .
                'proctoring.captchamode, proctoring.riskreviewmode, ' .
                'proctoring.riskreviewthreshold', // Fields to select.
            'LEFT JOIN {quizaccess_proctoring} proctoring ON proctoring.quizid = quiz.id', // Join clause.
            [], // No additional parameters.
        ];
    }

    /**
     * Provide information about the restriction to display on the quiz view page.
     *
     * @return array Messages explaining the restriction.
     * @throws coding_exception
     */
    public function description() {
        global $PAGE;

        // Localized strings for user messages.
        $record = (object)[
            'allowcamerawarning' => get_string('warning:cameraallowwarning', 'quizaccess_proctoring'),
            'cameraallow' => get_string('info:cameraallow', 'quizaccess_proctoring'),
        ];

        // Initialize JS for proctoring with the required data.
        $PAGE->requires->js_call_amd('quizaccess_proctoring/proctoring', 'init', [$record]);

        // Messages for the quiz view page.
        $messages = [
            get_string('proctoringheader', 'quizaccess_proctoring'),
            $this->get_download_config_button(),
        ];

        return $messages;
    }

    /**
     * Sets up the attempt (review or summary) page with any special extra
     * properties required by this rule.
     *
     * @param moodle_page $page The page object to initialise.
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function setup_attempt_page($page) {
        global $CFG, $DB, $COURSE, $USER;

        // Fetch parameters.
        $cmid = optional_param('cmid', 0, PARAM_INT);
        $attempt = optional_param('attempt', 0, PARAM_INT);
        // Set page properties.
        $page->set_title($this->quizobj->get_course()->shortname . ': ' . $page->title);
        $page->set_popup_notification_allowed(false);
        $page->set_heading($page->title);

        if ($cmid) {
            // Fetch the course module record for the quiz.
            $contextquiz = $DB->get_record('course_modules', ['id' => $cmid]);

            if (!$contextquiz) {
                throw new coding_exception('Invalid course module ID.');
            }

            // Insert a new log entry for the attempt.
            $record = (object)[
                'courseid' => $COURSE->id,
                'quizid' => $contextquiz->id,
                'userid' => $USER->id,
                'webcampicture' => '',
                'status' => $attempt,
                'timemodified' => time(),
            ];
            $record->id = $DB->insert_record('quizaccess_proctoring_logs', $record);

            // Retrieve screenshot delay and image width settings.
            $camshotdelay = (int)get_config('quizaccess_proctoring', 'autoreconfigurecamshotdelay') * 1000 ?: 30000;
            $imagewidth = (int)get_config('quizaccess_proctoring', 'autoreconfigureimagewidth') ?: 230;

            // Add additional data to the record.
            $quizurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
            $record->camshotdelay = $camshotdelay;
            $record->image_width = $imagewidth;
            $record->quizurl = $quizurl->out();
            $record->monitorbrowseractivity = (int)(get_config('quizaccess_proctoring', 'monitorbrowseractivity') ?? 1);
            $record->blockclipboard = (int)(get_config('quizaccess_proctoring', 'blockclipboard') ?? 1);
            $record->captureviolationdesktop = $this->should_capture_violation_desktop() ? 1 : 0;
            $record->blurquizwithoutface = (int)(get_config('quizaccess_proctoring', 'blurquizwithoutface') ?? 0);
            $screenmonitorkey = 'cm' . (int)$cmid . 'user' . (int)$USER->id;
            $screenmonitorurl = new moodle_url('/mod/quiz/accessrule/proctoring/screenmonitor.php', [
                'cmid' => (int)$cmid,
                'key' => $screenmonitorkey,
            ]);
            $usepersistentmonitor = !self::is_mobile_or_tablet();
            $record->screenmonitorurl = $usepersistentmonitor ? $screenmonitorurl->out(false) : '';
            $record->screenmonitorchannel = $usepersistentmonitor ? 'quizaccess_proctoring_screen_' . $screenmonitorkey : '';
            $record->screenmonitorstatuskey = $usepersistentmonitor ?
                'quizaccess_proctoring_screen_status_' . $screenmonitorkey : '';
            $record->screenmonitorwindowname = $usepersistentmonitor ? 'quizaccess_proctoring_screen_' . $screenmonitorkey : '';

            // Configure face model URL and include JS.
            $fcmethod = get_config('quizaccess_proctoring', 'fcmethod');
            $modelurl = ($fcmethod === 'customapi' || !empty($record->blurquizwithoutface))
                ? $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/thirdpartylibs/models'
                : null;

            if ($modelurl) {
                $page->requires->js('/mod/quiz/accessrule/proctoring/amd/build/face-api.min.js', true);
            }

            // Initialise the proctoring setup with JavaScript.
            $page->requires->js_call_amd('quizaccess_proctoring/proctoring', 'setup', [$record, $modelurl]);
        }
    }

    /**
     * Get a button to view the Proctoring report.
     *
     * @return string A link to view the report, or an empty string if the user lacks capability.
     *
     * @throws coding_exception
     */
    private function get_download_config_button(): string {
        global $OUTPUT, $USER;

        // Get the context for the module.
        $context = context_module::instance($this->quiz->cmid, MUST_EXIST);

        // Check if the user has the required capability to view the report.
        if (has_capability('quizaccess/proctoring:viewreport', $context, $USER->id)) {
            // Generate the link for the proctoring report.
            $httplink = \quizaccess_proctoring\link_generator::get_link(
                $this->quiz->course,
                $this->quiz->cmid,
                false,
                is_https()
            );

            // Return a single button linking to the report.
            return $OUTPUT->single_button($httplink, get_string('picturesreport', 'quizaccess_proctoring'), 'get');
        }

        // Return an empty string if the user lacks the required capability.
        return '';
    }
}
