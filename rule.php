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
    /** @var string Disable browser multi-monitor detection. */
    private const MULTI_MONITOR_OFF = 'off';
    /** @var string Log multi-monitor detection events without interrupting the student. */
    private const MULTI_MONITOR_LOG = 'log';
    /** @var string Warn students when multiple monitors are detected. */
    private const MULTI_MONITOR_WARN = 'warn';
    /** @var string Block quiz start when multiple monitors are detected. */
    private const MULTI_MONITOR_BLOCK = 'block';
    /** @var string Choose the least intrusive screen-share persistence mode for the active policy. */
    private const SCREEN_SHARE_PERSISTENCE_AUTO = 'auto';
    /** @var string Keep screen sharing on the main Moodle page. */
    private const SCREEN_SHARE_PERSISTENCE_MAIN = 'main';
    /** @var string Use the persistent helper window to keep screen sharing across page loads. */
    private const SCREEN_SHARE_PERSISTENCE_HELPER = 'helper';

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
     * Determine whether webcam phone detection is enabled site-wide.
     *
     * @return bool True when phone detection is enabled.
     */
    private static function site_detects_phone(): bool {
        return (int)get_config('quizaccess_proctoring', 'detectphone') === 1;
    }

    /**
     * Get the mobile/tablet desktop screen-share policy.
     *
     * @return string One of the MOBILE_SCREEN_SHARE_* constants.
     */
    private static function mobile_screen_share_mode() {
        $mode = get_config('quizaccess_proctoring', 'mobilescreensharemode');

        if (
            in_array($mode, [
            self::MOBILE_SCREEN_SHARE_BYPASS,
            self::MOBILE_SCREEN_SHARE_REQUIRE,
            self::MOBILE_SCREEN_SHARE_BLOCK,
            ], true)
        ) {
            return $mode;
        }

        return self::MOBILE_SCREEN_SHARE_BYPASS;
    }

    /**
     * Get the effective browser multi-monitor detection mode.
     *
     * @return string One of the MULTI_MONITOR_* constants.
     */
    private static function multi_monitor_mode() {
        if (self::is_mobile_or_tablet()) {
            return self::MULTI_MONITOR_OFF;
        }

        $mode = get_config('quizaccess_proctoring', 'multimonitormode');

        if (
            in_array($mode, [
            self::MULTI_MONITOR_OFF,
            self::MULTI_MONITOR_LOG,
            self::MULTI_MONITOR_WARN,
            self::MULTI_MONITOR_BLOCK,
            ], true)
        ) {
            return $mode;
        }

        return self::MULTI_MONITOR_WARN;
    }

    /**
     * Determine whether the quiz should blur when browser monitor detection finds multiple displays.
     *
     * @return bool True when multiple-monitor blur should run on the attempt page.
     */
    private static function blur_quiz_with_multiple_monitors(): bool {
        if (self::is_mobile_or_tablet()) {
            return false;
        }

        return (int)get_config('quizaccess_proctoring', 'blurquizwithmultiplemonitors') === 1;
    }

    /**
     * Determine whether desktop mouse/pointer activity should be logged.
     *
     * @return bool True when mouse activity monitoring should run on desktop attempts.
     */
    private static function monitors_desktop_mouse_activity(): bool {
        if (self::is_mobile_or_tablet()) {
            return false;
        }

        return (int)get_config('quizaccess_proctoring', 'monitormouseactivity') === 1;
    }

    /**
     * Get the configured screen-share persistence mode.
     *
     * @return string One of the SCREEN_SHARE_PERSISTENCE_* constants.
     */
    private static function screen_share_persistence_mode(): string {
        $mode = get_config('quizaccess_proctoring', 'screensharepersistencemode');

        if (
            in_array($mode, [
            self::SCREEN_SHARE_PERSISTENCE_AUTO,
            self::SCREEN_SHARE_PERSISTENCE_MAIN,
            self::SCREEN_SHARE_PERSISTENCE_HELPER,
            ], true)
        ) {
            return $mode;
        }

        return self::SCREEN_SHARE_PERSISTENCE_AUTO;
    }

    /**
     * Determine whether the persistent helper window should be used.
     *
     * @return bool True when the helper window should be used.
     */
    private static function should_use_persistent_screen_monitor(): bool {
        if (self::is_mobile_or_tablet()) {
            return false;
        }

        $mode = self::screen_share_persistence_mode();
        if ($mode === self::SCREEN_SHARE_PERSISTENCE_HELPER) {
            return true;
        }
        if ($mode === self::SCREEN_SHARE_PERSISTENCE_MAIN) {
            return false;
        }

        // Auto mode: always prefer the helper window on desktop. A getDisplayMedia stream is bound to
        // the page that requested it, so main-page sharing is torn down on every quiz navigation and the
        // student is re-prompted to share their screen. The helper window keeps the stream alive across
        // page loads regardless of the multi-monitor policy, so desktop capture stays available for
        // violation screenshots without re-prompting.
        return true;
    }

    /**
     * Determine whether the visible screen check marker is needed.
     *
     * @param string $multimonitormode Effective multi-monitor mode.
     * @return bool True when the marker should be shown and checked.
     */
    /**
     * Whether the visible screen check marker should be shown and looked for.
     *
     * The marker is what tells us *which* screen a student shared; without it we still
     * require an entire-screen share and still capture desktop evidence, we just cannot
     * say the shared screen is the one displaying the quiz. It is off by default because
     * the marker has to be visible in the captured frames: anything in front of the quiz
     * window hides it and the share is reported as the wrong screen.
     *
     * The admin setting is the master switch. Only when it is on do the persistent
     * monitor and multi-monitor policy have a say -- the helper window samples whichever
     * screen it was granted, so it needs the marker to identify it whatever the
     * multi-monitor policy, whereas blocking multiple monitors outright already
     * guarantees there is only one screen to share.
     *
     * @param string $multimonitormode One of the MULTI_MONITOR_* modes.
     * @param bool $usepersistentmonitor Whether the persistent helper window holds the share.
     * @return bool
     */
    private static function should_require_screen_marker(
        string $multimonitormode,
        bool $usepersistentmonitor = false
    ): bool {
        if (!self::screen_marker_setting_enabled()) {
            return false;
        }

        return $usepersistentmonitor || $multimonitormode !== self::MULTI_MONITOR_BLOCK;
    }

    /**
     * Whether the admin has switched the screen check marker on.
     *
     * Treats an unset value as off, matching the setting's default, so a site that has
     * never saved the setting behaves the same as one that saved it unticked. The helper
     * window in screenmonitor.php reads the same config key directly, as this class is
     * not autoloadable from a standalone page.
     *
     * @return bool
     */
    private static function screen_marker_setting_enabled(): bool {
        return (int)get_config('quizaccess_proctoring', 'requirescreenmarker') === 1;
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
            'data-size' => 'normal',
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
     * Determine whether the site requires ID verification before starting new proctored quiz attempts.
     *
     * @return bool True if ID verification is required.
     */
    private static function site_requires_id_verification(): bool {
        $setting = get_config('quizaccess_proctoring', 'idverificationenabled');

        return $setting !== false && (int)$setting === 1;
    }

    /**
     * Determine whether ID verification requires front and back ID images.
     *
     * @return bool True if the back ID image is required.
     */
    private static function id_verification_requires_back_image(): bool {
        $setting = get_config('quizaccess_proctoring', 'idverificationrequireback');

        return $setting !== false && (int)$setting === 1;
    }

    /**
     * Builds the "I can't provide a photo ID" self-service block for the precheck.
     *
     * Clicking the link asks what is actually wrong instead of sending anything: students
     * who cannot get a usable picture get capture tips, students who left their ID
     * elsewhere are told to fetch it and come back, and students with no ID at all get the
     * list of details the contact address needs to review an exception. The plugin never
     * emails that address itself — students send it themselves — so a stray click costs
     * nobody an inbox item. The two escalation paths do record a declaration, which is what
     * puts the student in the Manage overrides page's pending list for a human decision.
     *
     * @param bool $idverificationpassed Whether the student already passed.
     * @return string HTML block, or an empty string when unavailable.
     */
    private function get_id_exemption_request_html(bool $idverificationpassed): string {
        global $USER;

        if ($idverificationpassed) {
            return '';
        }
        $contact = trim((string)get_config('quizaccess_proctoring', 'idexemptioncontactemail'));
        if ($contact === '' || !validate_email($contact)) {
            return '';
        }

        $component = 'quizaccess_proctoring';
        $quizname = format_string($this->quiz->name);
        $coursename = format_string(get_course((int)$this->quiz->course)->fullname);

        // Opening lines of both draft emails: what staff need to find this student and exam.
        $mailheader = [
            get_string('idexemption:mail_fullname', $component, fullname($USER)),
            get_string('idexemption:mail_email', $component, $USER->email),
            get_string('idexemption:mail_course', $component, $coursename),
            get_string('idexemption:mail_exam', $component, $quizname),
        ];

        // The address doubles as a mailto: link that opens a draft with those details already
        // filled in. Nothing is sent by clicking it, and the visible text stays copyable for
        // students whose browser has no mail client wired up.
        $maillink = function (string $subject, array $bodylines) use ($contact) {
            $href = 'mailto:' . $contact .
                '?subject=' . rawurlencode($subject) .
                '&body=' . rawurlencode(implode("\r\n", $bodylines));

            return html_writer::link($href, $contact, ['class' => 'proctoring-idv-exempt-mail']);
        };
        $answer = function (string $reason, string $body) {
            return html_writer::div($body, 'proctoring-idv-exempt-answer mt-2', [
                'data-exempt-answer' => $reason,
                'style' => 'display:none;',
            ]);
        };

        // 1. Cannot capture a usable picture: tips first, escalation only if they persist.
        $tips = '';
        foreach (range(1, 6) as $tip) {
            $tips .= html_writer::tag('li', get_string('idexemption:capture_tip' . $tip, $component));
        }
        $capturemail = $maillink(
            get_string('idexemption:capture_mailsubject', $component, $quizname),
            array_merge($mailheader, [
                '',
                get_string('idexemption:capture_maildevice', $component),
                '',
                get_string('idexemption:capture_mailwhat', $component),
                '',
            ])
        );
        $captureanswer = $answer(
            'capture',
            html_writer::tag('p', get_string('idexemption:capture_heading', $component), [
                'class' => 'font-weight-bold fw-bold mb-1',
            ]) .
            html_writer::tag('ul', $tips, ['class' => 'mb-2']) .
            html_writer::tag('button', get_string('idexemption:capture_stuck', $component), [
                'type' => 'button',
                'id' => 'proctoring-idv-exempt-stuck',
                'class' => 'btn btn-outline-secondary btn-sm',
            ]) .
            html_writer::div(
                html_writer::tag(
                    'p',
                    get_string('idexemption:capture_stuckintro', $component, $capturemail),
                    ['class' => 'mb-1']
                ) .
                html_writer::tag('p', get_string('idexemption:turnaround', $component), ['class' => 'mb-1']) .
                html_writer::tag('p', get_string('idexemption:mailhint', $component), ['class' => 'text-muted mb-0']),
                'proctoring-idv-exempt-escalation mt-2',
                ['id' => 'proctoring-idv-exempt-capture-escalation', 'style' => 'display:none;']
            )
        );

        // 2. Has an ID, just not to hand: nothing to send, and nothing is lost by coming back.
        $notnowanswer = $answer(
            'notnow',
            html_writer::tag('p', get_string('idexemption:notnow_body', $component), ['class' => 'mb-0'])
        );

        // 3. No photo ID at all. A bare "I have no ID" request only earns the student a
        // "so why not?" reply, so the request is a short form: a category and, in their own
        // words, why. Both are required here and re-checked server-side. The same answers are
        // folded into the email draft afterwards, so the support ticket and the Moodle
        // request say the same thing.
        $categoryoptions = html_writer::tag(
            'option',
            get_string('idexemption:categoryprompt', $component),
            ['value' => '']
        );
        foreach (\quizaccess_proctoring\local\id_exception::CATEGORIES as $category) {
            $categoryoptions .= html_writer::tag(
                'option',
                get_string('idexemption:category_' . $category, $component),
                ['value' => $category]
            );
        }
        $noidform =
            html_writer::label(
                get_string('idexemption:categorylabel', $component),
                'proctoring-idv-exempt-category',
                true,
                ['class' => 'font-weight-bold fw-bold d-block mb-1']
            ) .
            html_writer::tag('select', $categoryoptions, [
                'id' => 'proctoring-idv-exempt-category',
                'class' => 'custom-select form-select form-control mb-2',
            ]) .
            html_writer::label(
                get_string('idexemption:detaillabel', $component),
                'proctoring-idv-exempt-detail',
                true,
                ['class' => 'font-weight-bold fw-bold d-block mb-1']
            ) .
            html_writer::tag('textarea', '', [
                'id' => 'proctoring-idv-exempt-detail',
                'class' => 'form-control mb-2',
                'rows' => 3,
                'maxlength' => \quizaccess_proctoring\local\id_exception::DETAIL_MAX,
                'placeholder' => get_string('idexemption:detailplaceholder', $component),
            ]) .
            html_writer::label(
                get_string('idexemption:altlabel', $component),
                'proctoring-idv-exempt-alt',
                true,
                ['class' => 'd-block mb-1']
            ) .
            html_writer::tag('textarea', '', [
                'id' => 'proctoring-idv-exempt-alt',
                'class' => 'form-control mb-2',
                'rows' => 2,
                'maxlength' => \quizaccess_proctoring\local\id_exception::ALTERNATIVES_MAX,
                'placeholder' => get_string('idexemption:altplaceholder', $component),
            ]) .
            html_writer::tag('button', get_string('idexemption:submitrequest', $component), [
                'type' => 'button',
                'id' => 'proctoring-idv-exempt-submit',
                'class' => 'btn btn-primary btn-sm',
                'disabled' => 'disabled',
            ]) .
            html_writer::div(
                get_string('idexemption:requiredhint', $component),
                'text-muted small mt-1',
                ['id' => 'proctoring-idv-exempt-required']
            );

        // The follow-up email is built in the browser once the answers exist, so the draft
        // carries them. These carry the pieces JS needs to assemble that mailto: URL.
        $noidsent = html_writer::div(
            html_writer::tag('p', get_string('idexemption:noid_emailintro', $component), ['class' => 'mb-1']) .
            html_writer::tag('p', '', ['class' => 'mb-1', 'id' => 'proctoring-idv-exempt-maillink']) .
            html_writer::tag('p', get_string('idexemption:turnaround', $component), ['class' => 'mb-1']) .
            html_writer::tag('p', get_string('idexemption:noid_wait', $component), ['class' => 'mb-1']) .
            html_writer::tag('p', get_string('idexemption:mailhint', $component), ['class' => 'text-muted mb-0']),
            'proctoring-idv-exempt-escalation mt-2',
            [
                'id' => 'proctoring-idv-exempt-noid-sent',
                'style' => 'display:none;',
                'data-contact' => $contact,
                'data-subject' => get_string('idexemption:noid_mailsubject', $component, $quizname),
                'data-header' => implode("\n", $mailheader),
                'data-reasonlabel' => get_string('idexemption:noid_mailreason', $component),
                'data-altlabel' => get_string('idexemption:noid_mailalt', $component),
            ]
        );
        $noidanswer = $answer(
            'noid',
            html_writer::tag('p', get_string('idexemption:noid_intro', $component), ['class' => 'mb-2']) .
            html_writer::div($noidform, 'proctoring-idv-exempt-form') .
            $noidsent
        );

        $choices = '';
        foreach (['capture', 'notnow', 'noid'] as $reason) {
            $choices .= html_writer::tag('button', get_string('idexemption:reason_' . $reason, $component), [
                'type' => 'button',
                'class' => 'btn btn-outline-secondary btn-sm d-block mb-1 proctoring-idv-exempt-choice',
                'data-exempt-reason' => $reason,
            ]);
        }

        return html_writer::div(
            html_writer::tag('button', get_string('modal:idexemptionbutton', $component), [
                'type' => 'button',
                'id' => 'idverificationexempt',
                'class' => 'btn btn-link p-0 proctoring-idv-exempt-link',
                'aria-expanded' => 'false',
                'aria-controls' => 'proctoring-idv-exempt-triage',
            ]) .
            html_writer::div(
                html_writer::tag('p', get_string('idexemption:triageheading', $component), [
                    'class' => 'font-weight-bold fw-bold mb-2',
                ]) .
                html_writer::div($choices, 'proctoring-idv-exempt-choices') .
                $captureanswer . $notnowanswer . $noidanswer,
                'proctoring-idv-exempt-triage mt-2',
                ['id' => 'proctoring-idv-exempt-triage', 'style' => 'display:none;']
            ) .
            html_writer::div('', 'proctoring-idv-exempt-note', [
                'id' => 'id_exemption_result',
                'style' => 'display:none;',
            ]),
            'proctoring-idv-exempt mt-2'
        );
    }

    /**
     * Determine whether this preflight submission should require ID verification.
     *
     * @param int|null $attemptid Current attempt id, if one already exists.
     * @return bool True when starting a new attempt requires ID verification.
     */
    private function should_require_id_verification($attemptid): bool {
        return empty($attemptid) && self::site_requires_id_verification();
    }

    /**
     * Check whether the current user has passed ID verification for this quiz module.
     *
     * @return bool True when a passing server-side ID verification row exists.
     */
    private function current_user_has_passed_id_verification(): bool {
        global $USER;

        return quizaccess_proctoring_user_has_passed_id_verification(
            (int)$this->quiz->course,
            (int)$this->quiz->cmid,
            (int)$USER->id
        );
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
     * Get the student handbook URL linked below the integrity statement.
     *
     * The built-in default lives in a language string so each locale can point at its own
     * handbook. An administrator who clears the setting gets no link at all.
     *
     * @return string The handbook URL, or an empty string when no link should be shown.
     */
    private static function get_honor_handbook_url() {
        $url = get_config('quizaccess_proctoring', 'honorstatementhandbookurl');

        if ($url === false) {
            $url = get_string('honorstatement:handbookurldefault', 'quizaccess_proctoring');
        }

        $url = trim((string)$url);

        return $url === '' ? '' : clean_param($url, PARAM_URL);
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
     * Check whether the pre-quiz privacy notice agreement is required.
     *
     * @return bool True when the notice is required.
     */
    private static function privacy_notice_required() {
        $setting = get_config('quizaccess_proctoring', 'privacynoticerequired');
        return $setting === false ? true : (int)$setting === 1;
    }

    /**
     * Get the configured pre-quiz privacy notice.
     *
     * @return string Notice text.
     */
    private static function get_privacy_notice() {
        $notice = get_config('quizaccess_proctoring', 'privacynotice');

        if ($notice === false || trim((string)$notice) === '') {
            return get_string('privacynotice:default', 'quizaccess_proctoring');
        }

        return (string)$notice;
    }

    /**
     * Get the configured privacy agreement checkbox label.
     *
     * @return string Checkbox label.
     */
    private static function get_privacy_agreement_label() {
        $label = get_config('quizaccess_proctoring', 'privacyagreementlabel');

        if ($label === false || trim((string)$label) === '') {
            return get_string('privacynotice:agreementdefault', 'quizaccess_proctoring');
        }

        return (string)$label;
    }

    /**
     * Build a concise data collection summary based on enabled proctoring settings.
     *
     * @param bool $captcharequired Whether CAPTCHA is required.
     * @param string|int $faceidcheck Whether face recognition is required.
     * @param int $requireentirescreen Whether screen sharing is required.
     * @param string $multimonitormode Multi-monitor mode.
     * @return string Rendered details HTML.
     */
    private static function make_privacy_notice_details(
        $captcharequired,
        $idverificationrequired,
        $faceidcheck,
        $requireentirescreen,
        $multimonitormode
    ): string {
        $items = [
            get_string('privacynotice:item_eventlogs', 'quizaccess_proctoring'),
        ];

        if ($idverificationrequired) {
            $items[] = get_string('privacynotice:item_idverification', 'quizaccess_proctoring');
        }
        if ((string)$faceidcheck === '1') {
            $items[] = get_string('privacynotice:item_webcam', 'quizaccess_proctoring');
        }
        if (self::site_detects_phone()) {
            $items[] = get_string('privacynotice:item_phonedetection', 'quizaccess_proctoring');
        }
        if ((int)$requireentirescreen === 1 || (int)get_config('quizaccess_proctoring', 'captureviolationdesktop') === 1) {
            $items[] = get_string('privacynotice:item_desktop', 'quizaccess_proctoring');
        }
        if ((int)get_config('quizaccess_proctoring', 'monitorbrowseractivity') === 1) {
            $items[] = get_string('privacynotice:item_browseractivity', 'quizaccess_proctoring');
        }
        if (self::monitors_desktop_mouse_activity()) {
            $items[] = get_string('privacynotice:item_mouse', 'quizaccess_proctoring');
        }
        if ((int)get_config('quizaccess_proctoring', 'blockclipboard') === 1) {
            $items[] = get_string('privacynotice:item_clipboard', 'quizaccess_proctoring');
        }
        if ($captcharequired) {
            $items[] = get_string('privacynotice:item_captcha', 'quizaccess_proctoring');
        }
        if ($multimonitormode !== self::MULTI_MONITOR_OFF || self::blur_quiz_with_multiple_monitors()) {
            $items[] = get_string('privacynotice:item_monitors', 'quizaccess_proctoring');
        }
        if ((int)get_config('quizaccess_proctoring', 'aireviewenabled') === 1) {
            $items[] = get_string('privacynotice:item_aireview', 'quizaccess_proctoring');
        }
        if (
            (int)get_config('quizaccess_proctoring', 'riskreviewenabled') > 0 ||
                (int)get_config('quizaccess_proctoring', 'cheatinglockoutenabled') === 1
        ) {
            $items[] = get_string('privacynotice:item_riskreview', 'quizaccess_proctoring');
        }

        $list = '';
        foreach ($items as $item) {
            $list .= html_writer::tag('li', s($item));
        }

        $retentiondays = (int)get_config('quizaccess_proctoring', 'imageretentiondays');
        if ($retentiondays === 1) {
            $retention = get_string('privacynotice:retentiononeday', 'quizaccess_proctoring');
        } else if ($retentiondays > 0) {
            $retention = get_string('privacynotice:retentiondays', 'quizaccess_proctoring', $retentiondays);
        } else {
            $retention = get_string('privacynotice:retentionmanual', 'quizaccess_proctoring');
        }

        return html_writer::tag(
            'details',
            html_writer::tag('summary', s(get_string('privacynotice:detailsummary', 'quizaccess_proctoring'))) .
            html_writer::tag('ul', $list, ['class' => 'mb-2']) .
            html_writer::div(s($retention), 'small text-muted'),
            ['class' => 'proctoring-privacy-details mt-2']
        );
    }

    /**
     * Check whether the current user can bypass student-only high-risk retake lockouts.
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
     * Get the currently active high-risk lockout for the current user.
     *
     * @return array|false Lockout details or false.
     */
    private function get_current_user_cheating_lockout() {
        global $USER;

        if (
            empty($USER->id) || empty($this->quiz->course) || empty($this->quiz->cmid) ||
                $this->can_bypass_cheating_lockout()
        ) {
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
     * Build the visible checklist shown in the quiz start preflight popup.
     *
     * @param bool $privacyrequired Whether the privacy notice agreement is required.
     * @param bool $honorrequired Whether the integrity statement is required.
     * @param bool $captcharequired Whether a CAPTCHA/security check is required.
     * @param bool $idverificationrequired Whether ID verification is required.
     * @param string|int $faceidcheck Whether face recognition is required.
     * @param bool $registerface Whether the first face capture will register the user.
     * @param int $requireentirescreen Whether entire screen sharing is required.
     * @param string $multimonitormode Browser multi-monitor detection mode.
     * @return string Checklist HTML.
     * @throws coding_exception
     */
    private static function make_preflight_requirements_panel(
        $privacyrequired,
        $honorrequired,
        $captcharequired,
        $idverificationrequired,
        $faceidcheck,
        $registerface,
        $requireentirescreen,
        $multimonitormode
    ) {
        $requirements = [];

        if ($privacyrequired) {
            $requirements['privacy'] = get_string('preflight:privacy', 'quizaccess_proctoring');
        }
        if ($honorrequired) {
            $requirements['honor'] = get_string('preflight:honesty', 'quizaccess_proctoring');
        }
        if ($captcharequired) {
            $requirements['captcha'] = get_string('preflight:securitycheck', 'quizaccess_proctoring');
        }
        if ($idverificationrequired) {
            $requirements['identity'] = get_string('preflight:idverification', 'quizaccess_proctoring');
        }
        if ((string)$faceidcheck === '1') {
            $requirements['face'] = $registerface
                ? get_string('preflight:registerface', 'quizaccess_proctoring')
                : get_string('preflight:facerecognition', 'quizaccess_proctoring');
        }
        if ((int)$requireentirescreen === 1) {
            $requirements['screen'] = get_string('preflight:screenshare', 'quizaccess_proctoring');
        }
        if ($multimonitormode === self::MULTI_MONITOR_BLOCK) {
            $requirements['multimonitor'] = get_string('preflight:singlemonitor', 'quizaccess_proctoring');
        }

        if (empty($requirements)) {
            return '';
        }

        $items = '';
        foreach ($requirements as $key => $label) {
            $status = html_writer::span(
                get_string('modal:pending', 'quizaccess_proctoring'),
                'proctoring-preflight-status is-pending',
                ['id' => 'proctoring-check-' . $key . '-status']
            );
            $items .= html_writer::div(
                html_writer::span($label, 'proctoring-preflight-label') . $status,
                'proctoring-preflight-item is-pending',
                ['id' => 'proctoring-check-' . $key]
            );
        }

        return html_writer::div(
            html_writer::div(
                get_string('preflight:requirementsheading', 'quizaccess_proctoring'),
                'proctoring-preflight-heading'
            ) .
            html_writer::div(
                get_string('preflight:requirementsintro', 'quizaccess_proctoring'),
                'proctoring-preflight-intro'
            ) .
            html_writer::div($items, 'proctoring-preflight-items'),
            'proctoring-preflight-panel alert alert-light border mb-3',
            ['role' => 'status']
        );
    }

    /**
     * Build a heading for one guided preflight step.
     *
     * @param string $title The step title.
     * @param string $description The short step description.
     * @return string Step heading HTML.
     */
    private static function make_preflight_step_heading($title, $description) {
        return html_writer::div(
            html_writer::div($title, 'proctoring-preflight-step-title') .
            html_writer::div($description, 'proctoring-preflight-step-description'),
            'proctoring-preflight-step-heading'
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
        $privacyrequired = self::privacy_notice_required();
        $honorrequired = self::honor_statement_required();

        // Compute the five base (site-default -> per-quiz) requirement states. Per-student overrides
        // are layered on top of these below, at the new-attempt gate only.
        $requireentirescreen = $this->requires_entire_screen() ? 1 : 0;
        $captcharequired = $this->should_require_captcha($attemptid);
        $idverificationrequired = $this->should_require_id_verification($attemptid);
        $multimonitormode = self::multi_monitor_mode();

        // Per-student override resolution runs only at the new-attempt gate ($attemptid empty), so an
        // in-progress attempt keeps the requirement state snapshotted when its attempt started. When no
        // applicable override exists, resolve_all() returns the base states unchanged.
        if (empty($attemptid)) {
            $resolver = '\quizaccess_proctoring\local\override_resolver';
            $basestates = [
                $resolver::REQ_CAPTCHA => (bool)$captcharequired,
                $resolver::REQ_WEBCAM => ((string)$faceidcheck === '1'),
                $resolver::REQ_IDVERIFICATION => (bool)$idverificationrequired,
                $resolver::REQ_SCREENSHARE => ((int)$requireentirescreen === 1),
                $resolver::REQ_MULTIMONITOR => ($multimonitormode !== self::MULTI_MONITOR_OFF),
            ];

            $resolved = $resolver::resolve_all(
                (int)$coursedata['courseid'],
                (int)$coursedata['quizid'],
                (int)$USER->id,
                time(),
                $basestates
            );

            // Write the resolved booleans back into the config flags consumed by startAttempt.js.
            $captcharequired = $resolved[$resolver::REQ_CAPTCHA];
            $idverificationrequired = $resolved[$resolver::REQ_IDVERIFICATION];
            $requireentirescreen = $resolved[$resolver::REQ_SCREENSHARE] ? 1 : 0;
            $faceidcheck = $resolved[$resolver::REQ_WEBCAM] ? '1' : '0';

            // A disabled effective multi-monitor state forces the mode OFF.
            if (!$resolved[$resolver::REQ_MULTIMONITOR]) {
                $multimonitormode = self::MULTI_MONITOR_OFF;
            }
        }

        // Derive the values that depend on the (possibly overridden) requirement states.
        $registerface = ((string)$faceidcheck === '1' && !$hasreferenceimage);
        $idverificationpassed = $idverificationrequired ? $this->current_user_has_passed_id_verification() : true;
        $idverificationrequireback = $idverificationrequired && self::id_verification_requires_back_image();
        $usepersistentmonitor = self::should_use_persistent_screen_monitor();
        $screenmarkerrequired = self::should_require_screen_marker($multimonitormode, $usepersistentmonitor);

        // Prepare data for the JavaScript module.
        $examurl = new moodle_url('/mod/quiz/startattempt.php');
        $screenmonitorkey = 'cm' . (int)$coursedata['cmid'] . 'user' . (int)$USER->id;
        $screenmonitorurl = new moodle_url('/mod/quiz/accessrule/proctoring/screenmonitor.php', [
            'cmid' => (int)$coursedata['cmid'],
            'key' => $screenmonitorkey,
        ]);
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
            'privacyrequired' => $privacyrequired ? 1 : 0,
            'honorrequired' => $honorrequired ? 1 : 0,
            'captcharequired' => $captcharequired ? 1 : 0,
            'idverificationrequired' => $idverificationrequired ? 1 : 0,
            'idverificationpassed' => $idverificationpassed ? 1 : 0,
            'idverificationrequireback' => $idverificationrequireback ? 1 : 0,
            'multimonitormode' => $multimonitormode,
            'screenmarkerrequired' => $screenmarkerrequired ? 1 : 0,
            'screenmonitorurl' => $usepersistentmonitor ? $screenmonitorurl->out(false) : '',
            'screenmonitorchannel' => $usepersistentmonitor ? 'quizaccess_proctoring_screen_' . $screenmonitorkey : '',
            'screenmonitorstatuskey' => $usepersistentmonitor ? 'quizaccess_proctoring_screen_status_' . $screenmonitorkey : '',
            'screenmonitorwindowname' => $usepersistentmonitor ? 'quizaccess_proctoring_screen_' . $screenmonitorkey : '',
        ];

        // Include Face API JS library if required.
        $fcmethod = get_config('quizaccess_proctoring', 'fcmethod');
        $modelurl = null;
        if ($fcmethod === 'customapi' || $idverificationrequired) {
            $modelurl = $CFG->wwwroot . '/mod/quiz/accessrule/proctoring/thirdpartylibs/models';
            $PAGE->requires->js('/mod/quiz/accessrule/proctoring/amd/build/face-api.min.js', true);
        }
        $PAGE->requires->js_call_amd('quizaccess_proctoring/startAttempt', 'setup', [$record, $modelurl]);

        // Add HTML wrapper for the form.
        $mform->addElement('html', "<div class='quiz-check-form'>");
        $mform->addElement(
            'html',
            self::make_preflight_requirements_panel(
                $privacyrequired,
                $honorrequired,
                $captcharequired,
                $idverificationrequired,
                $faceidcheck,
                $registerface,
                $requireentirescreen,
                $multimonitormode
            )
        );

        // Compact time-limit line for the stepper footer (JS relocates it there).
        if (!empty($this->quiz->timelimit) && (int)$this->quiz->timelimit > 0) {
            $mform->addElement('html', html_writer::div(
                get_string(
                    'preflight:timelimitfooter',
                    'quizaccess_proctoring',
                    format_time((int)$this->quiz->timelimit)
                ),
                'proctoring-stepper-timelimit',
                ['id' => 'proctoring-stepper-timelimit', 'style' => 'display:none;']
            ));
        }

        // Prepare user profile image URL.
        $profileimageurl = $USER->picture
            ? (new moodle_url("/user/pix.php/{$USER->id}/f1.jpg"))->out(false)
            : '';

        $mform->addElement('html', "<div class='proctoring-preflight-steps'>");

        if ($privacyrequired) {
            $notice = html_writer::div(
                format_text(self::get_privacy_notice(), FORMAT_PLAIN, ['para' => true]),
                'proctoring-privacy-notice-text'
            );
            $mform->addElement(
                'html',
                "<section id='proctoring-step-privacy' class='proctoring-preflight-step' data-preflight-step='privacy'>" .
                self::make_preflight_step_heading(
                    get_string('preflightstep:privacy:title', 'quizaccess_proctoring'),
                    get_string('preflightstep:privacy:desc', 'quizaccess_proctoring')
                )
            );
            $mform->addElement(
                'html',
                html_writer::div(
                    $notice . self::make_privacy_notice_details(
                        $captcharequired,
                        $idverificationrequired,
                        $faceidcheck,
                        $requireentirescreen,
                        $multimonitormode
                    ),
                    'proctoring-privacy-notice alert alert-info mb-3'
                )
            );
            $mform->addElement('checkbox', 'proctoringprivacy', '', self::get_privacy_agreement_label());
            $mform->addRule(
                'proctoringprivacy',
                get_string('privacynotice:required', 'quizaccess_proctoring'),
                'required',
                null,
                'client'
            );
            $mform->addElement('html', '</section>');
        }

        if ($honorrequired) {
            $statement = html_writer::div(
                format_text(self::get_honor_statement(), FORMAT_PLAIN, ['para' => true]),
                'proctoring-honor-statement-text'
            );
            $handbookurl = self::get_honor_handbook_url();
            if ($handbookurl !== '') {
                $handbooklink = html_writer::link(
                    $handbookurl,
                    get_string('honorstatement:handbooklinktext', 'quizaccess_proctoring'),
                    ['target' => '_blank', 'rel' => 'noopener noreferrer']
                );
                $statement .= html_writer::div(
                    get_string('honorstatement:handbooklink', 'quizaccess_proctoring', $handbooklink),
                    'proctoring-honor-statement-handbook small'
                );
            }
            $mform->addElement(
                'html',
                "<section id='proctoring-step-honor' class='proctoring-preflight-step' data-preflight-step='honor'>" .
                self::make_preflight_step_heading(
                    get_string('preflightstep:honor:title', 'quizaccess_proctoring'),
                    get_string('preflightstep:honor:desc', 'quizaccess_proctoring')
                )
            );
            $mform->addElement('html', html_writer::div($statement, 'proctoring-honor-statement mb-3'));
            $mform->addElement('checkbox', 'proctoring', '', self::get_honor_agreement_label());
            $mform->addRule('proctoring', get_string('youmustagree', 'quizaccess_proctoring'), 'required', null, 'client');
            $mform->addElement('html', '</section>');
        }

        if ($captcharequired) {
            $mform->addElement(
                'html',
                "<section id='proctoring-step-captcha' class='proctoring-preflight-step' data-preflight-step='captcha'>" .
                self::make_preflight_step_heading(
                    get_string('preflightstep:captcha:title', 'quizaccess_proctoring'),
                    get_string('preflightstep:captcha:desc', 'quizaccess_proctoring')
                ) .
                "<div class='proctoring-security-check alert alert-info'>"
            );
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
            $mform->addElement('html', '</div></section>');
        }

        if ($idverificationrequired) {
            $buildidcameracapture = static function (string $side): string {
                $suffix = $side === 'back' ? '-back' : '';
                $buttonsuffix = $side === 'back' ? 'back' : '';

                return html_writer::div(
                    html_writer::div(
                        html_writer::tag('video', '', [
                            'id' => 'proctoring-id-document' . $suffix . '-video',
                            'class' => 'proctoring-id-document-video',
                            'autoplay' => 'autoplay',
                            'muted' => 'muted',
                            'playsinline' => 'playsinline',
                        ]) .
                        html_writer::div(
                            html_writer::span(
                                get_string('modal:idverificationdocumentnotinwindow', 'quizaccess_proctoring'),
                                'proctoring-id-document-status'
                            ),
                            'proctoring-id-document-guide',
                            ['aria-live' => 'polite']
                        ) .
                        html_writer::empty_tag('img', [
                            'id' => 'proctoring-id-document' . $suffix . '-preview-image',
                            'class' => 'proctoring-id-document-preview-image',
                            'alt' => '',
                        ]),
                        'proctoring-id-document-preview',
                        ['id' => 'proctoring-id-document' . $suffix . '-preview', 'style' => 'display:none;']
                    ) .
                    html_writer::tag('canvas', '', [
                        'id' => 'proctoring-id-document' . $suffix . '-canvas',
                        'style' => 'display:none;',
                    ]) .
                    html_writer::div(
                        html_writer::tag(
                            'button',
                            get_string('modal:idverificationdocumentcamera', 'quizaccess_proctoring'),
                            [
                                'type' => 'button',
                                'id' => 'idverificationdocument' . $buttonsuffix . 'camera',
                                'class' => 'btn btn-secondary mr-2 mb-2',
                            ]
                        ) .
                        html_writer::tag(
                            'button',
                            get_string('modal:idverificationdocumentcapture', 'quizaccess_proctoring'),
                            [
                                'type' => 'button',
                                'id' => 'idverificationdocument' . $buttonsuffix . 'capture',
                                'class' => 'btn btn-secondary mr-2 mb-2',
                                'style' => 'display:none;',
                            ]
                        ) .
                        html_writer::tag(
                            'button',
                            get_string('modal:idverificationdocumentretake', 'quizaccess_proctoring'),
                            [
                                'type' => 'button',
                                'id' => 'idverificationdocument' . $buttonsuffix . 'retake',
                                'class' => 'btn btn-link mb-2',
                                'style' => 'display:none;',
                            ]
                        ),
                        'proctoring-id-document-actions'
                    ),
                    'proctoring-id-document-camera mb-3'
                );
            };
            $alreadyverified = $idverificationpassed
                ? html_writer::div(
                    get_string('modal:idverificationpassed', 'quizaccess_proctoring'),
                    'alert alert-success proctoring-idv-existing'
                )
                : '';
            $frontlabel = $idverificationrequireback
                ? get_string('modal:idverificationdocumentfront', 'quizaccess_proctoring')
                : get_string('modal:idverificationdocument', 'quizaccess_proctoring');
            $frontidhtml = html_writer::div(
                html_writer::label(
                    $frontlabel,
                    'proctoring-id-document',
                    false,
                    ['class' => 'font-weight-bold']
                ) .
                html_writer::empty_tag('input', [
                    'type' => 'file',
                    'id' => 'proctoring-id-document',
                    'class' => 'form-control mb-3',
                    'accept' => 'image/*',
                    'capture' => 'environment',
                ]) .
                $buildidcameracapture('front'),
                'proctoring-id-document-side proctoring-id-document-side-front'
            );
            $backidhtml = $idverificationrequireback ? html_writer::div(
                html_writer::label(
                    get_string('modal:idverificationdocumentback', 'quizaccess_proctoring'),
                    'proctoring-id-document-back',
                    false,
                    ['class' => 'font-weight-bold']
                ) .
                html_writer::empty_tag('input', [
                    'type' => 'file',
                    'id' => 'proctoring-id-document-back',
                    'class' => 'form-control mb-3',
                    'accept' => 'image/*',
                    'capture' => 'environment',
                ]) .
                $buildidcameracapture('back'),
                'proctoring-id-document-side proctoring-id-document-side-back'
            ) : '';
            $idverificationhtml = html_writer::div(
                $frontidhtml .
                $backidhtml .
                html_writer::tag('video', '', [
                    'id' => 'proctoring-id-live-video',
                    'class' => 'proctoring-id-live-video mb-2',
                    'autoplay' => 'autoplay',
                    'muted' => 'muted',
                    'playsinline' => 'playsinline',
                ]) .
                html_writer::tag('canvas', '', [
                    'id' => 'proctoring-id-live-canvas',
                    'style' => 'display:none;',
                ]) .
                html_writer::empty_tag('img', [
                    'id' => 'proctoring-id-live-crop',
                    'alt' => '',
                    'style' => 'display:none;',
                ]) .
                html_writer::div(
                    html_writer::tag(
                        'button',
                        html_writer::div('', 'proctoring-loadingspinner', ['id' => 'idverification_spinner']) .
                            get_string('modal:idverificationverify', 'quizaccess_proctoring'),
                        [
                            'type' => 'button',
                            'id' => 'idverificationvalidate',
                            'class' => 'btn btn-primary mb-2',
                        ]
                    ),
                    'proctoring-idv-actions'
                ) .
                html_writer::div(
                    get_string('modal:idverification', 'quizaccess_proctoring') . ' ' .
                    html_writer::span(
                        $idverificationpassed
                            ? get_string('preflight:complete', 'quizaccess_proctoring')
                            : get_string('modal:pending', 'quizaccess_proctoring'),
                        '',
                        ['id' => 'id_verification_result']
                    ),
                    'proctoring-idv-result mt-2'
                ) .
                $this->get_id_exemption_request_html($idverificationpassed),
                'proctoring-idv-panel alert alert-info'
            );

            $mform->addElement(
                'html',
                "<section id='proctoring-step-identity' class='proctoring-preflight-step' data-preflight-step='identity'>" .
                self::make_preflight_step_heading(
                    get_string('preflightstep:idverification:title', 'quizaccess_proctoring'),
                    get_string('preflightstep:idverification:desc', 'quizaccess_proctoring')
                ) .
                $alreadyverified .
                $idverificationhtml .
                '</section>'
            );
        }

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
            $mform->addElement(
                'html',
                "<section id='proctoring-step-face' class='proctoring-preflight-step' data-preflight-step='face'>" .
                self::make_preflight_step_heading(
                    $registerface
                        ? get_string('preflightstep:registerface:title', 'quizaccess_proctoring')
                        : get_string('preflightstep:face:title', 'quizaccess_proctoring'),
                    $registerface
                        ? get_string('preflightstep:registerface:desc', 'quizaccess_proctoring')
                        : get_string('preflightstep:face:desc', 'quizaccess_proctoring')
                )
            );
            // Render modal content.
            $modalcontent = $this->make_modal_content($quizform, $faceidcheck);
            // Add modal content and action buttons to the form.
            $mform->addElement('html', $modalcontent);
            $mform->addElement('html', $hiddenvalue);

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
            $mform->addElement('html', '</section>');
        } else {
            $mform->addElement('html', $hiddenvalue);
        }

        if ($requireentirescreen === 1) {
            $screensharebtn = sprintf(
                "<section id='proctoring-step-screen' class='proctoring-preflight-step' data-preflight-step='screen'>
                %s
                <div class='container'><div class='row'><div class='col'>
                    %s&nbsp;<span id='screen_share_result'>%s</span>
                    <button id='screensharevalidate' class='btn btn-secondary mt-3' style='display: flex;
                                                justify-content: center; align-items: center;'>
                        %s
                    </button>
                </div></div></div></section>",
                self::make_preflight_step_heading(
                    get_string('preflightstep:screen:title', 'quizaccess_proctoring'),
                    get_string('preflightstep:screen:desc', 'quizaccess_proctoring')
                ),
                get_string('modal:screenshare', 'quizaccess_proctoring'),
                get_string('modal:pending', 'quizaccess_proctoring'),
                get_string('modal:shareentirescreen', 'quizaccess_proctoring')
            );
            $mform->addElement('html', $screensharebtn);
        }

        if ($multimonitormode === self::MULTI_MONITOR_BLOCK) {
            $monitorcheck = sprintf(
                "<section id='proctoring-step-multimonitor' class='proctoring-preflight-step' data-preflight-step='multimonitor'>
                %s
                <div class='container'><div class='row'><div class='col'>
                    <div id='multi_monitor_result' class='mb-2'>%s</div>
                    <button id='multimonitorvalidate' class='btn btn-secondary mt-2' style='display: flex;
                                                justify-content: center; align-items: center;'>
                        %s
                    </button>
                </div></div></div></section>",
                self::make_preflight_step_heading(
                    get_string('preflightstep:multimonitor:title', 'quizaccess_proctoring'),
                    get_string('preflightstep:multimonitor:desc', 'quizaccess_proctoring')
                ),
                get_string('modal:pending', 'quizaccess_proctoring'),
                get_string('modal:checkmonitors', 'quizaccess_proctoring')
            );
            $mform->addElement('html', $monitorcheck);
        }

        $mform->addElement(
            'html',
            html_writer::div(
                get_string('preflightstep:ready', 'quizaccess_proctoring'),
                'proctoring-preflight-ready alert alert-success',
                ['id' => 'proctoring-preflight-ready']
            )
        );
        $mform->addElement('html', '</div>');

        // Add hidden inputs.
        $mform->addElement('hidden', 'entirescreenconfirmed', 0);
        $mform->setType('entirescreenconfirmed', PARAM_INT);
        $mform->addElement('hidden', 'multimonitorconfirmed', $multimonitormode === self::MULTI_MONITOR_BLOCK ? 0 : 1);
        $mform->setType('multimonitorconfirmed', PARAM_INT);
        $mform->addElement('hidden', 'idverificationconfirmed', $idverificationpassed ? 1 : 0);
        $mform->setType('idverificationconfirmed', PARAM_INT);

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
        if (self::privacy_notice_required() && empty($data['proctoringprivacy'])) {
            $errors['proctoringprivacy'] = get_string('privacynotice:required', 'quizaccess_proctoring');
        }

        if (self::honor_statement_required() && empty($data['proctoring'])) {
            $errors['proctoring'] = get_string('youmustagree', 'quizaccess_proctoring');
        }

        if ($this->requires_entire_screen() && empty($data['entirescreenconfirmed'])) {
            $errorkey = self::honor_statement_required() ? 'proctoring' : 'entirescreenconfirmed';
            if (empty($errors[$errorkey])) {
                $errors[$errorkey] = get_string('entirescreenrequired', 'quizaccess_proctoring');
            }
        }

        if (self::multi_monitor_mode() === self::MULTI_MONITOR_BLOCK && empty($data['multimonitorconfirmed'])) {
            $errorkey = self::honor_statement_required() ? 'proctoring' : 'multimonitorconfirmed';
            if (empty($errors[$errorkey])) {
                $errors[$errorkey] = get_string('multimonitor:blockmessage', 'quizaccess_proctoring');
            }
        }

        if ($this->should_require_id_verification($attemptid) && !$this->current_user_has_passed_id_verification()) {
            $errorkey = self::honor_statement_required() ? 'proctoring' : 'idverificationconfirmed';
            if (empty($errors[$errorkey])) {
                $errors[$errorkey] = get_string('modal:idverificationfailed', 'quizaccess_proctoring');
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
     * Prevent new attempts while a high-risk proctoring lockout is active.
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
        $mform->addElement('header', 'quizaccess_proctoring_section', get_string('proctoringsection', 'quizaccess_proctoring'));

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
                2 => get_string('riskreviewmode_autofail', 'quizaccess_proctoring'),
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
            $riskreviewmode = isset($quiz->riskreviewmode) ? (int)$quiz->riskreviewmode : -1;
            if (!in_array($riskreviewmode, [-1, 0, 1, 2], true)) {
                $riskreviewmode = -1;
            }
            $riskreviewthreshold = isset($quiz->riskreviewthreshold) ? (int)$quiz->riskreviewthreshold : -1;
            if ($riskreviewthreshold !== -1) {
                $riskreviewthreshold = max(1, min(100, $riskreviewthreshold));
            }

            $record = (object)[
                'quizid' => $quiz->id,
                'proctoringrequired' => 1,
                'requireentirescreen' => isset($quiz->requireentirescreen) ? (int)$quiz->requireentirescreen : -1,
                'captchamode' => isset($quiz->captchamode) ? (int)$quiz->captchamode : -1,
                'riskreviewmode' => $riskreviewmode,
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
            'image_width' => (int)get_config('quizaccess_proctoring', 'autoreconfigureimagewidth') ?: 480,
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

        if ($cmid && $attempt && $this->maybe_show_finished_attempt_risk_hold_notice((int)$cmid, (int)$attempt)) {
            return;
        }

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
            $record->monitormouseactivity = self::monitors_desktop_mouse_activity() ? 1 : 0;
            $record->blockclipboard = (int)(get_config('quizaccess_proctoring', 'blockclipboard') ?? 1);
            $record->captureviolationdesktop = $this->should_capture_violation_desktop() ? 1 : 0;
            $record->multimonitormode = self::multi_monitor_mode();
            // Log and Warn are explicit "allow extra monitors" policies, so they win
            // over the blur checkbox: the blur enforcement only runs when the mode is
            // Block (belt and braces) or Off (blur as the sole enforcement).
            $record->blurquizwithmultiplemonitors = (self::blur_quiz_with_multiple_monitors() &&
                !in_array($record->multimonitormode, [self::MULTI_MONITOR_LOG, self::MULTI_MONITOR_WARN], true)) ? 1 : 0;
            $record->blurquizwithoutface = (int)(get_config('quizaccess_proctoring', 'blurquizwithoutface') ?? 0);
            $faceblurminscore = (float)(get_config('quizaccess_proctoring', 'faceblurminscore') ?: 0.30);
            $record->faceblurminscore = max(0.10, min(0.95, $faceblurminscore));
            $faceblurmisses = (int)(get_config('quizaccess_proctoring', 'faceblurmisses') ?: 4);
            $record->faceblurmisses = max(1, min(20, $faceblurmisses));
            $faceblurhits = (int)(get_config('quizaccess_proctoring', 'faceblurhits') ?: 1);
            $record->faceblurhits = max(1, min(10, $faceblurhits));
            $faceblurinitialgrace = (int)(get_config('quizaccess_proctoring', 'faceblurinitialgrace') ?: 10);
            $record->faceblurinitialgrace = max(0, min(60, $faceblurinitialgrace));

            // Webcam phone detection: only requested when the site monitor is on, the student has
            // no waiving per-student override, and the object-detection libraries are vendored.
            // Monitoring is resolved live (not gate-snapshotted), so an override granted mid-course
            // takes effect on the student's next attempt page load.
            $record->detectphone = 0;
            $record->detectphoneminscore = 0.6;
            $record->phonedetectliburl = '';
            if (self::site_detects_phone()) {
                $resolver = '\quizaccess_proctoring\local\override_resolver';
                $resolved = $resolver::resolve_all(
                    (int)$COURSE->id,
                    (int)$contextquiz->instance,
                    (int)$USER->id,
                    time(),
                    [$resolver::REQ_PHONEDETECTION => true]
                );
                $phonelibdir = $CFG->dirroot . '/mod/quiz/accessrule/proctoring/thirdpartylibs/objectdetect';
                $phonelibsready = file_exists($phonelibdir . '/tf.min.js')
                    && file_exists($phonelibdir . '/coco-ssd.min.js')
                    && file_exists($phonelibdir . '/model/model.json');
                if (!$phonelibsready) {
                    debugging(
                        'quizaccess_proctoring: phone detection is enabled but the object-detection'
                            . ' libraries are missing from thirdpartylibs/objectdetect; see the README'
                            . ' in that directory.',
                        DEBUG_DEVELOPER
                    );
                }
                if ($resolved[$resolver::REQ_PHONEDETECTION] && $phonelibsready) {
                    $minscore = (int)(get_config('quizaccess_proctoring', 'detectphoneminscore') ?: 60);
                    $record->detectphone = 1;
                    $record->detectphoneminscore = max(20, min(95, $minscore)) / 100;
                    $record->phonedetectliburl = $CFG->wwwroot
                        . '/mod/quiz/accessrule/proctoring/thirdpartylibs/objectdetect';
                }
            }

            $usepersistentmonitor = self::should_use_persistent_screen_monitor();
            $record->screenmarkerrequired = self::should_require_screen_marker(
                $record->multimonitormode,
                $usepersistentmonitor
            ) ? 1 : 0;
            $screenmonitorkey = 'cm' . (int)$cmid . 'user' . (int)$USER->id;
            $screenmonitorurl = new moodle_url('/mod/quiz/accessrule/proctoring/screenmonitor.php', [
                'cmid' => (int)$cmid,
                'key' => $screenmonitorkey,
            ]);
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
     * Show the post-submit risk hold notice and avoid starting live proctoring on finished attempts.
     *
     * @param int $cmid Quiz course module id.
     * @param int $attemptid Quiz attempt id.
     * @return bool True when the attempt is already finished.
     */
    private function maybe_show_finished_attempt_risk_hold_notice(int $cmid, int $attemptid): bool {
        global $COURSE, $DB, $USER;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], 'id, userid, state');
        if (!$attempt || (int)$attempt->userid !== (int)$USER->id || (string)$attempt->state !== 'finished') {
            return false;
        }

        if (!quizaccess_proctoring_student_hold_notice_enabled()) {
            return true;
        }

        $reports = $DB->get_records(
            'quizaccess_proctoring_logs',
            [
                'courseid' => (int)$COURSE->id,
                'quizid' => $cmid,
                'userid' => (int)$USER->id,
                'status' => $attemptid,
                'deletionprogress' => 0,
            ],
            'id ASC',
            'id',
            0,
            1
        );
        $report = $reports ? reset($reports) : false;
        $hold = quizaccess_proctoring_get_risk_hold(
            (int)$COURSE->id,
            $cmid,
            (int)$USER->id,
            $attemptid,
            $report ? (int)$report->id : 0
        );
        if ($hold && (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE) {
            \core\notification::add(
                quizaccess_proctoring_get_student_risk_hold_notice_html($hold),
                \core\output\notification::NOTIFY_WARNING
            );
        } else if ($hold && (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_AUTO_FAILED) {
            \core\notification::add(
                quizaccess_proctoring_get_student_risk_failure_notice_html($hold),
                \core\output\notification::NOTIFY_ERROR
            );
        } else if ($hold && (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_CONFIRMED) {
            \core\notification::add(
                quizaccess_proctoring_get_student_risk_confirmed_notice_html($hold),
                \core\output\notification::NOTIFY_ERROR
            );
        }

        return true;
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
