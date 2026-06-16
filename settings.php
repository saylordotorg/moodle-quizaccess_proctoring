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
 * Settings for the quizaccess_proctoring plugin.
 *
 * @package    quizaccess_proctoring
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (!class_exists('quizaccess_proctoring_admin_category', false)) {
    /**
     * Admin category that opens the primary proctoring settings page when selected.
     *
     * @package quizaccess_proctoring
     */
    class quizaccess_proctoring_admin_category extends admin_category {
        /**
         * Get the URL for the primary plugin settings page.
         *
         * @return moodle_url Settings page URL.
         */
        public function get_settings_page_url(): moodle_url {
            return new moodle_url('/admin/settings.php', ['section' => 'modsettingsquizcatproctoring']);
        }
    }
}

if ($hassiteconfig) {
    global $PAGE, $DB;

    $adminsections = [
        [
            'key' => 'precheck',
            'label' => get_string('setting:admincontrols_tab_precheck', 'quizaccess_proctoring'),
            'heading' => get_string('setting:precheckheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'face',
            'label' => get_string('setting:admincontrols_tab_face', 'quizaccess_proctoring'),
            'heading' => get_string('setting:faceheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'identity',
            'label' => get_string('setting:admincontrols_tab_identity', 'quizaccess_proctoring'),
            'heading' => get_string('setting:idverificationheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'monitoring',
            'label' => get_string('setting:admincontrols_tab_monitoring', 'quizaccess_proctoring'),
            'heading' => get_string('setting:monitoringheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'review',
            'label' => get_string('setting:admincontrols_tab_review', 'quizaccess_proctoring'),
            'heading' => get_string('setting:riskheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'ai',
            'label' => get_string('setting:admincontrols_tab_ai', 'quizaccess_proctoring'),
            'heading' => get_string('setting:aireviewheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'reporting',
            'label' => get_string('setting:admincontrols_tab_reporting', 'quizaccess_proctoring'),
            'heading' => get_string('setting:reportingheading', 'quizaccess_proctoring'),
        ],
        [
            'key' => 'retention',
            'label' => get_string('setting:admincontrols_tab_retention', 'quizaccess_proctoring'),
            'heading' => get_string('setting:retentionheading', 'quizaccess_proctoring'),
        ],
    ];

    $admintabs = array_merge([[
        'key' => 'all',
        'label' => get_string('setting:admincontrols_tab_all', 'quizaccess_proctoring'),
        'heading' => '',
    ]], $adminsections);

    $quicktoggles = [
        [
            'setting' => 'privacynoticerequired',
            'label' => get_string('setting:quicktoggle_privacy', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'honorstatementrequired',
            'label' => get_string('setting:quicktoggle_honesty', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'captchabeforeattemptenabled',
            'label' => get_string('setting:quicktoggle_captcha', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'idverificationenabled',
            'label' => get_string('setting:quicktoggle_idverification', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'fcheckstartchk',
            'label' => get_string('setting:quicktoggle_facebeforestart', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'continuousfacecheck',
            'label' => get_string('setting:quicktoggle_continuousface', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'requireentirescreen',
            'label' => get_string('setting:quicktoggle_screenshare', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'monitorbrowseractivity',
            'label' => get_string('setting:quicktoggle_browseractivity', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'monitormouseactivity',
            'label' => get_string('setting:quicktoggle_mouseactivity', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'blockclipboard',
            'label' => get_string('setting:quicktoggle_clipboard', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'captureviolationdesktop',
            'label' => get_string('setting:quicktoggle_desktopcapture', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'blurquizwithoutface',
            'label' => get_string('setting:quicktoggle_faceblur', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'blurquizwithmultiplemonitors',
            'label' => get_string('setting:quicktoggle_monitorblur', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'aireviewenabled',
            'label' => get_string('setting:quicktoggle_aireview', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'riskreviewenabled',
            'label' => get_string('setting:quicktoggle_riskreview', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'studentholdnoticeenabled',
            'label' => get_string('setting:quicktoggle_studentnotice', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'cheatinglockoutenabled',
            'label' => get_string('setting:quicktoggle_retakelockout', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'dailyreportenabled',
            'label' => get_string('setting:quicktoggle_dailyreport', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'dailyreportincludeall',
            'label' => get_string('setting:quicktoggle_reportall', 'quizaccess_proctoring'),
        ],
        [
            'setting' => 'dailyreportsendempty',
            'label' => get_string('setting:quicktoggle_reportempty', 'quizaccess_proctoring'),
        ],
    ];

    $PAGE->requires->js_call_amd('quizaccess_proctoring/adminSettings', 'init');

    // Plugin description and name.
    $plugindescription = get_string('plugin_description', 'quizaccess_proctoring');

    // Add the plugin name and description.
    $settings->add(new admin_setting_heading(
        'pluginnameheading',
        '',
        $plugindescription
    ));

    $tabbuttons = '';
    foreach ($admintabs as $tab) {
        $tabattributes = [
            'type' => 'button',
            'class' => 'btn btn-outline-secondary btn-sm quizaccess-proctoring-admin-tab',
            'data-proctoring-admin-tab' => $tab['key'],
            'aria-pressed' => $tab['key'] === 'all' ? 'true' : 'false',
        ];
        if (!empty($tab['heading'])) {
            $tabattributes['data-proctoring-admin-heading'] = $tab['heading'];
        }
        $tabbuttons .= html_writer::tag('button', $tab['label'], $tabattributes);
    }

    $bulkbuttons = html_writer::tag(
        'button',
        get_string('setting:admincontrols_enableall', 'quizaccess_proctoring'),
        [
            'type' => 'button',
            'class' => 'btn btn-secondary btn-sm',
            'data-proctoring-admin-bulk' => 'enable',
        ]
    ) . html_writer::tag(
        'button',
        get_string('setting:admincontrols_disableall', 'quizaccess_proctoring'),
        [
            'type' => 'button',
            'class' => 'btn btn-outline-secondary btn-sm',
            'data-proctoring-admin-bulk' => 'disable',
        ]
    );

    $togglehtml = '';
    foreach ($quicktoggles as $toggle) {
        $togglehtml .= html_writer::tag(
            'label',
            html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'class' => 'quizaccess-proctoring-admin-toggle-input',
                'data-proctoring-admin-setting' => $toggle['setting'],
                'aria-label' => $toggle['label'],
            ]) .
            html_writer::span($toggle['label'], 'quizaccess-proctoring-admin-toggle-label') .
            html_writer::span(
                get_string('setting:admincontrols_badge_off', 'quizaccess_proctoring'),
                'badge bg-secondary quizaccess-proctoring-admin-toggle-state',
                [
                    'data-proctoring-admin-state' => '',
                    'data-on-label' => get_string('setting:admincontrols_badge_on', 'quizaccess_proctoring'),
                    'data-off-label' => get_string('setting:admincontrols_badge_off', 'quizaccess_proctoring'),
                ]
            ),
            ['class' => 'quizaccess-proctoring-admin-toggle']
        );
    }

    $admincontrolhtml = html_writer::div(
        html_writer::div(
            get_string('setting:admincontrols_desc', 'quizaccess_proctoring'),
            'quizaccess-proctoring-admin-intro'
        ) .
        html_writer::div($tabbuttons, 'quizaccess-proctoring-admin-tabs', ['role' => 'tablist']) .
        html_writer::div($bulkbuttons, 'quizaccess-proctoring-admin-bulk-actions') .
        html_writer::div($togglehtml, 'quizaccess-proctoring-admin-quickgrid'),
        'quizaccess-proctoring-admin-controls',
        [
            'id' => 'quizaccess-proctoring-admin-controls',
            'data-proctoring-admin-storagekey' => 'quizaccess_proctoring_admin_tab',
        ]
    );

    $settings->add(new admin_setting_description(
        'quizaccess_proctoring/admincontrols',
        get_string('setting:admincontrolsheading', 'quizaccess_proctoring'),
        $admincontrolhtml
    ));


    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_precheckheading',
        get_string('setting:precheckheading', 'quizaccess_proctoring'),
        get_string('setting:precheckheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/honorstatementrequired',
        get_string('setting:honorstatementrequired', 'quizaccess_proctoring'),
        get_string('setting:honorstatementrequired_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configtextarea(
        'quizaccess_proctoring/honorstatement',
        get_string('setting:honorstatement', 'quizaccess_proctoring'),
        get_string('setting:honorstatement_desc', 'quizaccess_proctoring'),
        get_string('honorstatement:default', 'quizaccess_proctoring'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/honoragreementlabel',
        get_string('setting:honoragreementlabel', 'quizaccess_proctoring'),
        get_string('setting:honoragreementlabel_desc', 'quizaccess_proctoring'),
        get_string('honorstatement:agreementdefault', 'quizaccess_proctoring'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/privacynoticerequired',
        get_string('setting:privacynoticerequired', 'quizaccess_proctoring'),
        get_string('setting:privacynoticerequired_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configtextarea(
        'quizaccess_proctoring/privacynotice',
        get_string('setting:privacynotice', 'quizaccess_proctoring'),
        get_string('setting:privacynotice_desc', 'quizaccess_proctoring'),
        get_string('privacynotice:default', 'quizaccess_proctoring'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/privacyagreementlabel',
        get_string('setting:privacyagreementlabel', 'quizaccess_proctoring'),
        get_string('setting:privacyagreementlabel_desc', 'quizaccess_proctoring'),
        get_string('privacynotice:agreementdefault', 'quizaccess_proctoring'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/captchabeforeattemptenabled',
        get_string('setting:captchabeforeattemptenabled', 'quizaccess_proctoring'),
        get_string('setting:captchabeforeattemptenabled_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/captchaprovider',
        get_string('setting:captchaprovider', 'quizaccess_proctoring'),
        get_string('setting:captchaprovider_desc', 'quizaccess_proctoring'),
        'turnstile',
        [
            'turnstile' => get_string('setting:captchaprovider_turnstile', 'quizaccess_proctoring'),
            'recaptcha' => get_string('setting:captchaprovider_recaptcha', 'quizaccess_proctoring'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/turnstilesitekey',
        get_string('setting:turnstilesitekey', 'quizaccess_proctoring'),
        get_string('setting:turnstilesitekey_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_proctoring/turnstilesecretkey',
        get_string('setting:turnstilesecretkey', 'quizaccess_proctoring'),
        get_string('setting:turnstilesecretkey_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/fcheckstartchk',
        get_string('settings:fcheckquizstart', 'quizaccess_proctoring'),
        get_string('settings:fcheckquizstart_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_faceheading',
        get_string('setting:faceheading', 'quizaccess_proctoring'),
        get_string('setting:faceheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_description(
        'quizaccess_proctoring/adminimage',
        get_string('setting:adminimagepage', 'quizaccess_proctoring'),
        html_writer::div(
            html_writer::link(
                new moodle_url('/mod/quiz/accessrule/proctoring/userslist.php'),
                get_string('setting:userslist', 'quizaccess_proctoring')
            ) .
            html_writer::tag(
                'p',
                get_string('setting:adminimagedescription', 'quizaccess_proctoring')
            )
        )
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/autoreconfigurecamshotdelay',
        get_string('setting:camshotdelay', 'quizaccess_proctoring'),
        get_string('setting:camshotdelay_desc', 'quizaccess_proctoring'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/autoreconfigureimagewidth',
        get_string('setting:camshotwidth', 'quizaccess_proctoring'),
        get_string('setting:camshotwidth_desc', 'quizaccess_proctoring'),
        230,
        PARAM_INT
    ));

    $choices = [
        'customapi' => get_string('setting:fc_method_customapi', 'quizaccess_proctoring'),
        'None' => 'None',
    ];
    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/fcmethod',
        get_string('setting:fc_method', 'quizaccess_proctoring'),
        get_string('setting:fc_methoddesc', 'quizaccess_proctoring'),
        get_string('none', 'quizaccess_proctoring'),
        $choices
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/custom_ai_endpoint',
        get_string('setting:custom_ai_endpoint', 'quizaccess_proctoring'),
        get_string('setting:custom_ai_endpoint_desc', 'quizaccess_proctoring'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_proctoring/custom_api_key',
        get_string('setting:custom_api_key', 'quizaccess_proctoring'),
        get_string('setting:custom_api_key_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_idverificationheading',
        get_string('setting:idverificationheading', 'quizaccess_proctoring'),
        get_string('setting:idverificationheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/idverificationenabled',
        get_string('setting:idverificationenabled', 'quizaccess_proctoring'),
        get_string('setting:idverificationenabled_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/idverificationendpoint',
        get_string('setting:idverificationendpoint', 'quizaccess_proctoring'),
        get_string('setting:idverificationendpoint_desc', 'quizaccess_proctoring'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_proctoring/idverificationapikey',
        get_string('setting:idverificationapikey', 'quizaccess_proctoring'),
        get_string('setting:idverificationapikey_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/idverificationrequireback',
        get_string('setting:idverificationrequireback', 'quizaccess_proctoring'),
        get_string('setting:idverificationrequireback_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/idverificationcheckface',
        get_string('setting:idverificationcheckface', 'quizaccess_proctoring'),
        get_string('setting:idverificationcheckface_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/idverificationfacethreshold',
        get_string('setting:idverificationfacethreshold', 'quizaccess_proctoring'),
        get_string('setting:idverificationfacethreshold_desc', 'quizaccess_proctoring'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/idverificationcheckname',
        get_string('setting:idverificationcheckname', 'quizaccess_proctoring'),
        get_string('setting:idverificationcheckname_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/idverificationnamethreshold',
        get_string('setting:idverificationnamethreshold', 'quizaccess_proctoring'),
        get_string('setting:idverificationnamethreshold_desc', 'quizaccess_proctoring'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/idverificationfailuredetails',
        get_string('setting:idverificationfailuredetails', 'quizaccess_proctoring'),
        get_string('setting:idverificationfailuredetails_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/idverificationretentiondays',
        get_string('setting:idverificationretentiondays', 'quizaccess_proctoring'),
        get_string('setting:idverificationretentiondays_desc', 'quizaccess_proctoring'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/threshold',
        get_string('setting:fcthreshold', 'quizaccess_proctoring'),
        get_string('setting:fcthresholddesc', 'quizaccess_proctoring'),
        '68',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/continuousfacecheck',
        get_string('setting:continuousfacecheck', 'quizaccess_proctoring'),
        get_string('setting:continuousfacecheck_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/continuousfacecheckevery',
        get_string('setting:continuousfacecheckevery', 'quizaccess_proctoring'),
        get_string('setting:continuousfacecheckevery_desc', 'quizaccess_proctoring'),
        1,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/awschecknumber',
        get_string('setting:facematch', 'quizaccess_proctoring'),
        get_string('setting:facematchdesc', 'quizaccess_proctoring'),
        '',
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_monitoringheading',
        get_string('setting:monitoringheading', 'quizaccess_proctoring'),
        get_string('setting:monitoringheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/monitorbrowseractivity',
        get_string('setting:monitorbrowseractivity', 'quizaccess_proctoring'),
        get_string('setting:monitorbrowseractivity_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/monitormouseactivity',
        get_string('setting:monitormouseactivity', 'quizaccess_proctoring'),
        get_string('setting:monitormouseactivity_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/blockclipboard',
        get_string('setting:blockclipboard', 'quizaccess_proctoring'),
        get_string('setting:blockclipboard_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/requireentirescreen',
        get_string('setting:requireentirescreen', 'quizaccess_proctoring'),
        get_string('setting:requireentirescreen_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/multimonitormode',
        get_string('setting:multimonitormode', 'quizaccess_proctoring'),
        get_string('setting:multimonitormode_desc', 'quizaccess_proctoring'),
        'warn',
        [
            'off' => get_string('setting:multimonitormode_off', 'quizaccess_proctoring'),
            'log' => get_string('setting:multimonitormode_log', 'quizaccess_proctoring'),
            'warn' => get_string('setting:multimonitormode_warn', 'quizaccess_proctoring'),
            'block' => get_string('setting:multimonitormode_block', 'quizaccess_proctoring'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/blurquizwithmultiplemonitors',
        get_string('setting:blurquizwithmultiplemonitors', 'quizaccess_proctoring'),
        get_string('setting:blurquizwithmultiplemonitors_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/screensharepersistencemode',
        get_string('setting:screensharepersistencemode', 'quizaccess_proctoring'),
        get_string('setting:screensharepersistencemode_desc', 'quizaccess_proctoring'),
        'auto',
        [
            'auto' => get_string('setting:screensharepersistencemode_auto', 'quizaccess_proctoring'),
            'main' => get_string('setting:screensharepersistencemode_main', 'quizaccess_proctoring'),
            'helper' => get_string('setting:screensharepersistencemode_helper', 'quizaccess_proctoring'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/captureviolationdesktop',
        get_string('setting:captureviolationdesktop', 'quizaccess_proctoring'),
        get_string('setting:captureviolationdesktop_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/mobilescreensharemode',
        get_string('setting:mobilescreensharemode', 'quizaccess_proctoring'),
        get_string('setting:mobilescreensharemode_desc', 'quizaccess_proctoring'),
        'bypass',
        [
            'bypass' => get_string('setting:mobilescreensharemode_bypass', 'quizaccess_proctoring'),
            'require' => get_string('setting:mobilescreensharemode_require', 'quizaccess_proctoring'),
            'block' => get_string('setting:mobilescreensharemode_block', 'quizaccess_proctoring'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/blurquizwithoutface',
        get_string('setting:blurquizwithoutface', 'quizaccess_proctoring'),
        get_string('setting:blurquizwithoutface_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/faceblurminscore',
        get_string('setting:faceblurminscore', 'quizaccess_proctoring'),
        get_string('setting:faceblurminscore_desc', 'quizaccess_proctoring'),
        0.3,
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/faceblurmisses',
        get_string('setting:faceblurmisses', 'quizaccess_proctoring'),
        get_string('setting:faceblurmisses_desc', 'quizaccess_proctoring'),
        4,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/faceblurhits',
        get_string('setting:faceblurhits', 'quizaccess_proctoring'),
        get_string('setting:faceblurhits_desc', 'quizaccess_proctoring'),
        1,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/faceblurinitialgrace',
        get_string('setting:faceblurinitialgrace', 'quizaccess_proctoring'),
        get_string('setting:faceblurinitialgrace_desc', 'quizaccess_proctoring'),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_riskheading',
        get_string('setting:riskheading', 'quizaccess_proctoring'),
        get_string('setting:riskheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/riskreviewenabled',
        get_string('setting:riskreviewenabled', 'quizaccess_proctoring'),
        get_string('setting:riskreviewenabled_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/riskreviewthreshold',
        get_string('setting:riskreviewthreshold', 'quizaccess_proctoring'),
        get_string('setting:riskreviewthreshold_desc', 'quizaccess_proctoring'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/studentholdnoticeenabled',
        get_string('setting:studentholdnoticeenabled', 'quizaccess_proctoring'),
        get_string('setting:studentholdnoticeenabled_desc', 'quizaccess_proctoring'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/riskreviewautoreleasedays',
        get_string('setting:riskreviewautoreleasedays', 'quizaccess_proctoring'),
        get_string('setting:riskreviewautoreleasedays_desc', 'quizaccess_proctoring'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/cheatinglockoutenabled',
        get_string('setting:cheatinglockoutenabled', 'quizaccess_proctoring'),
        get_string('setting:cheatinglockoutenabled_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/cheatinglockoutdays',
        get_string('setting:cheatinglockoutdays', 'quizaccess_proctoring'),
        get_string('setting:cheatinglockoutdays_desc', 'quizaccess_proctoring'),
        7,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_aireviewheading',
        get_string('setting:aireviewheading', 'quizaccess_proctoring'),
        get_string('setting:aireviewheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/aireviewenabled',
        get_string('setting:aireviewenabled', 'quizaccess_proctoring'),
        get_string('setting:aireviewenabled_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/aireviewprovider',
        get_string('setting:aireviewprovider', 'quizaccess_proctoring'),
        get_string('setting:aireviewprovider_desc', 'quizaccess_proctoring'),
        'none',
        [
            'none' => get_string('none', 'quizaccess_proctoring'),
            'openai' => get_string('setting:aireviewprovider_openai', 'quizaccess_proctoring'),
            'anthropic' => get_string('setting:aireviewprovider_anthropic', 'quizaccess_proctoring'),
            'compatible' => get_string('setting:aireviewprovider_compatible', 'quizaccess_proctoring'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_proctoring/aireviewdesktopmode',
        get_string('setting:aireviewdesktopmode', 'quizaccess_proctoring'),
        get_string('setting:aireviewdesktopmode_desc', 'quizaccess_proctoring'),
        'threshold',
        [
            'off' => get_string('setting:aireviewdesktopmode_off', 'quizaccess_proctoring'),
            'threshold' => get_string('setting:aireviewdesktopmode_threshold', 'quizaccess_proctoring'),
            'aitool' => get_string('setting:aireviewdesktopmode_aitool', 'quizaccess_proctoring'),
            'all' => get_string('setting:aireviewdesktopmode_all', 'quizaccess_proctoring'),
        ]
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_proctoring/aireviewopenaiapikey',
        get_string('setting:aireviewopenaiapikey', 'quizaccess_proctoring'),
        get_string('setting:aireviewopenaiapikey_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewopenaimodel',
        get_string('setting:aireviewopenaimodel', 'quizaccess_proctoring'),
        get_string('setting:aireviewopenaimodel_desc', 'quizaccess_proctoring'),
        'gpt-4.1-mini',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_proctoring/aireviewanthropicapikey',
        get_string('setting:aireviewanthropicapikey', 'quizaccess_proctoring'),
        get_string('setting:aireviewanthropicapikey_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewanthropicmodel',
        get_string('setting:aireviewanthropicmodel', 'quizaccess_proctoring'),
        get_string('setting:aireviewanthropicmodel_desc', 'quizaccess_proctoring'),
        'claude-sonnet-4-5-20250929',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewcompatibleendpoint',
        get_string('setting:aireviewcompatibleendpoint', 'quizaccess_proctoring'),
        get_string('setting:aireviewcompatibleendpoint_desc', 'quizaccess_proctoring'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_proctoring/aireviewcompatibleapikey',
        get_string('setting:aireviewcompatibleapikey', 'quizaccess_proctoring'),
        get_string('setting:aireviewcompatibleapikey_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewcompatiblemodel',
        get_string('setting:aireviewcompatiblemodel', 'quizaccess_proctoring'),
        get_string('setting:aireviewcompatiblemodel_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewtriggerthreshold',
        get_string('setting:aireviewtriggerthreshold', 'quizaccess_proctoring'),
        get_string('setting:aireviewtriggerthreshold_desc', 'quizaccess_proctoring'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewdecisionthreshold',
        get_string('setting:aireviewdecisionthreshold', 'quizaccess_proctoring'),
        get_string('setting:aireviewdecisionthreshold_desc', 'quizaccess_proctoring'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/aireviewmaximages',
        get_string('setting:aireviewmaximages', 'quizaccess_proctoring'),
        get_string('setting:aireviewmaximages_desc', 'quizaccess_proctoring'),
        6,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_reportingheading',
        get_string('setting:reportingheading', 'quizaccess_proctoring'),
        get_string('setting:reportingheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/dailyreportenabled',
        get_string('setting:dailyreportenabled', 'quizaccess_proctoring'),
        get_string('setting:dailyreportenabled_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtextarea(
        'quizaccess_proctoring/dailyreportemails',
        get_string('setting:dailyreportemails', 'quizaccess_proctoring'),
        get_string('setting:dailyreportemails_desc', 'quizaccess_proctoring'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/dailyreportallowexternal',
        get_string('setting:dailyreportallowexternal', 'quizaccess_proctoring'),
        get_string('setting:dailyreportallowexternal_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/dailyreportincludeall',
        get_string('setting:dailyreportincludeall', 'quizaccess_proctoring'),
        get_string('setting:dailyreportincludeall_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_proctoring/dailyreportsendempty',
        get_string('setting:dailyreportsendempty', 'quizaccess_proctoring'),
        get_string('setting:dailyreportsendempty_desc', 'quizaccess_proctoring'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/rekognitioncostpercheck',
        get_string('setting:rekognitioncostpercheck', 'quizaccess_proctoring'),
        get_string('setting:rekognitioncostpercheck_desc', 'quizaccess_proctoring'),
        '0.001',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_retentionheading',
        get_string('setting:retentionheading', 'quizaccess_proctoring'),
        get_string('setting:retentionheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_proctoring/imageretentiondays',
        get_string('setting:imageretentiondays', 'quizaccess_proctoring'),
        get_string('setting:imageretentiondays_desc', 'quizaccess_proctoring'),
        0,
        PARAM_INT
    ));

    $pageurl = new moodle_url('/mod/quiz/accessrule/proctoring/trigger_delete.php', ['sesskey' => sesskey()]);
    $deletealllinktext = get_string('settingscontroll:deletealllinktext', 'quizaccess_proctoring');

    $deleteallbutton = html_writer::tag('button', $deletealllinktext, [
        'class' => 'btn btn-danger',
        'data-confirmation' => 'modal',
        'data-confirmation-type' => 'delete',
        'data-confirmation-title-str' => json_encode(["delete", "core"]),
        'data-confirmation-content-str' => json_encode(["areyousure_delete_all_record", "quizaccess_proctoring"]),
        'data-confirmation-yes-button-str' => json_encode(["delete", "core"]),
        'data-confirmation-action-url' => $pageurl,
        'data-confirmation-destination' => $pageurl,
    ]);

    $settingdescription = html_writer::div(
        $deleteallbutton .
        html_writer::tag('p', get_string('settingscontroll:deletealldescription', 'quizaccess_proctoring'))
    );

    $dbman = $DB->get_manager();
    $exists = $dbman->table_exists('quizaccess_proctoring_logs') &&
        $DB->record_exists('quizaccess_proctoring_logs', ['deletionprogress' => 0]);
    if ($exists) {
        // Add the box containing the delete message and link.
        $settings->add(new admin_setting_description(
            'quizaccess_proctoring/deleteallimages',
            get_string('settingscontroll:deleteall', 'quizaccess_proctoring'),
            $settingdescription
        ));
    }

    // Build the "AI proctor settings" category with explicit clickable links for all sub-pages.
    // This ensures all items are accessible even in themes that don't render category nodes as links.
    $proctoringcategory = 'quizaccess_proctoring_settings_category';
    $settings->visiblename = get_string('settings', 'quizaccess_proctoring');
    $settings->hidden = true;

    $ADMIN->add('modsettingsquizcat', new quizaccess_proctoring_admin_category(
        $proctoringcategory,
        get_string('mainsettingspagebtn', 'quizaccess_proctoring')
    ));

    // 1. Settings (main settings page).
    $ADMIN->add($proctoringcategory, $settings);

    // 2. Review diagnostics.
    $ADMIN->add($proctoringcategory, new admin_externalpage(
        'quizaccess_proctoring_ai_diagnostics',
        get_string('aireviewdiagnostics', 'quizaccess_proctoring'),
        new moodle_url('/mod/quiz/accessrule/proctoring/ai_review_diagnostics.php'),
        'moodle/site:config'
    ));

    // 3. Cost estimate.
    $ADMIN->add($proctoringcategory, new admin_externalpage(
        'quizaccess_proctoring_cost_estimate',
        get_string('costestimate', 'quizaccess_proctoring'),
        new moodle_url('/mod/quiz/accessrule/proctoring/cost_estimate.php'),
        'moodle/site:config'
    ));

    // 4. Saylor Proctored Quiz Users list.
    $ADMIN->add($proctoringcategory, new admin_externalpage(
        'quizaccess_proctoring_userslist',
        get_string('users_list', 'quizaccess_proctoring'),
        new moodle_url('/mod/quiz/accessrule/proctoring/userslist.php'),
        'moodle/site:config'
    ));

    $settings = null;
}
