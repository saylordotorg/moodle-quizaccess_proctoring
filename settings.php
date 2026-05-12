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


if ($hassiteconfig) {
    // Plugin description and name.
    $plugindescription = get_string('plugin_description', 'quizaccess_proctoring');

    // Add the plugin name and description.
    $settings->add(new admin_setting_heading(
        'pluginnameheading',
        '',
        $plugindescription
    ));


    $settings->add(new admin_setting_heading(
        'additional_settings',
        get_string('additional_settings', 'quizaccess_proctoring'),
        ''
    ));

    $settings->add(new admin_setting_description(
        'quizaccess_proctoring/adminimage',
        get_string('setting:adminimagepage', 'quizaccess_proctoring'),
        html_writer::div(
            html_writer::link(
                new moodle_url('/mod/quiz/accessrule/proctoring/userslist.php'),
                get_string('setting:userslist', 'quizaccess_proctoring')
            ) .
            html_writer::tag('p',
                get_string('setting:adminimagedescription', 'quizaccess_proctoring')
            )
        )
    ));

    // Settings for the plugin.
    $settings->add(new admin_setting_configtext('quizaccess_proctoring/autoreconfigurecamshotdelay',
        get_string('setting:camshotdelay', 'quizaccess_proctoring'),
        get_string('setting:camshotdelay_desc', 'quizaccess_proctoring'), 30, PARAM_INT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/autoreconfigureimagewidth',
        get_string('setting:camshotwidth', 'quizaccess_proctoring'),
        get_string('setting:camshotwidth_desc', 'quizaccess_proctoring'), 230, PARAM_INT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/imageretentiondays',
        get_string('setting:imageretentiondays', 'quizaccess_proctoring'),
        get_string('setting:imageretentiondays_desc', 'quizaccess_proctoring'), 0, PARAM_INT));

    // Face recognition method choice.
    $choices = [
        'customapi' => get_string('setting:fc_method_customapi', 'quizaccess_proctoring'),
        'None' => 'None',
    ];
    $settings->add(new admin_setting_configselect('quizaccess_proctoring/fcmethod',
        get_string('setting:fc_method', 'quizaccess_proctoring'),
        get_string('setting:fc_methoddesc', 'quizaccess_proctoring'),
        get_string('none', 'quizaccess_proctoring'),
        $choices
    ));

    // Saylor AI API settings.
    $settings->add(new admin_setting_configtext('quizaccess_proctoring/custom_ai_endpoint',
        get_string('setting:custom_ai_endpoint', 'quizaccess_proctoring'),
        get_string('setting:custom_ai_endpoint_desc', 'quizaccess_proctoring'), '', PARAM_URL));

    $settings->add(new admin_setting_configpasswordunmask('quizaccess_proctoring/custom_api_key',
        get_string('setting:custom_api_key', 'quizaccess_proctoring'),
        get_string('setting:custom_api_key_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    // Face recognition threshold.
    $settings->add(new admin_setting_configtext('quizaccess_proctoring/threshold',
        get_string('setting:fcthreshold', 'quizaccess_proctoring'),
        get_string('setting:fcthresholddesc', 'quizaccess_proctoring'), '68', PARAM_INT));

    // Continuous face checks during quiz attempts.
    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/continuousfacecheck',
        get_string('setting:continuousfacecheck', 'quizaccess_proctoring'),
        get_string('setting:continuousfacecheck_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/continuousfacecheckevery',
        get_string('setting:continuousfacecheckevery', 'quizaccess_proctoring'),
        get_string('setting:continuousfacecheckevery_desc', 'quizaccess_proctoring'), 1, PARAM_INT));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/blurquizwithoutface',
        get_string('setting:blurquizwithoutface', 'quizaccess_proctoring'),
        get_string('setting:blurquizwithoutface_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/monitorbrowseractivity',
        get_string('setting:monitorbrowseractivity', 'quizaccess_proctoring'),
        get_string('setting:monitorbrowseractivity_desc', 'quizaccess_proctoring'), 1));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/blockclipboard',
        get_string('setting:blockclipboard', 'quizaccess_proctoring'),
        get_string('setting:blockclipboard_desc', 'quizaccess_proctoring'), 1));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/captureviolationdesktop',
        get_string('setting:captureviolationdesktop', 'quizaccess_proctoring'),
        get_string('setting:captureviolationdesktop_desc', 'quizaccess_proctoring'), 1));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/requireentirescreen',
        get_string('setting:requireentirescreen', 'quizaccess_proctoring'),
        get_string('setting:requireentirescreen_desc', 'quizaccess_proctoring'), 1));

    $settings->add(new admin_setting_configselect('quizaccess_proctoring/mobilescreensharemode',
        get_string('setting:mobilescreensharemode', 'quizaccess_proctoring'),
        get_string('setting:mobilescreensharemode_desc', 'quizaccess_proctoring'), 'bypass', [
            'bypass' => get_string('setting:mobilescreensharemode_bypass', 'quizaccess_proctoring'),
            'require' => get_string('setting:mobilescreensharemode_require', 'quizaccess_proctoring'),
            'block' => get_string('setting:mobilescreensharemode_block', 'quizaccess_proctoring'),
        ]));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/captchabeforeattemptenabled',
        get_string('setting:captchabeforeattemptenabled', 'quizaccess_proctoring'),
        get_string('setting:captchabeforeattemptenabled_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configselect('quizaccess_proctoring/captchaprovider',
        get_string('setting:captchaprovider', 'quizaccess_proctoring'),
        get_string('setting:captchaprovider_desc', 'quizaccess_proctoring'), 'turnstile', [
            'turnstile' => get_string('setting:captchaprovider_turnstile', 'quizaccess_proctoring'),
            'recaptcha' => get_string('setting:captchaprovider_recaptcha', 'quizaccess_proctoring'),
        ]));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/turnstilesitekey',
        get_string('setting:turnstilesitekey', 'quizaccess_proctoring'),
        get_string('setting:turnstilesitekey_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configpasswordunmask('quizaccess_proctoring/turnstilesecretkey',
        get_string('setting:turnstilesecretkey', 'quizaccess_proctoring'),
        get_string('setting:turnstilesecretkey_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/riskreviewenabled',
        get_string('setting:riskreviewenabled', 'quizaccess_proctoring'),
        get_string('setting:riskreviewenabled_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/riskreviewthreshold',
        get_string('setting:riskreviewthreshold', 'quizaccess_proctoring'),
        get_string('setting:riskreviewthreshold_desc', 'quizaccess_proctoring'), 80, PARAM_INT));

    $settings->add(new admin_setting_heading(
        'quizaccess_proctoring_aireviewheading',
        get_string('setting:aireviewheading', 'quizaccess_proctoring'),
        get_string('setting:aireviewheading_desc', 'quizaccess_proctoring')
    ));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/aireviewenabled',
        get_string('setting:aireviewenabled', 'quizaccess_proctoring'),
        get_string('setting:aireviewenabled_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configselect('quizaccess_proctoring/aireviewprovider',
        get_string('setting:aireviewprovider', 'quizaccess_proctoring'),
        get_string('setting:aireviewprovider_desc', 'quizaccess_proctoring'), 'none', [
            'none' => get_string('none', 'quizaccess_proctoring'),
            'openai' => get_string('setting:aireviewprovider_openai', 'quizaccess_proctoring'),
            'anthropic' => get_string('setting:aireviewprovider_anthropic', 'quizaccess_proctoring'),
            'compatible' => get_string('setting:aireviewprovider_compatible', 'quizaccess_proctoring'),
        ]));

    $settings->add(new admin_setting_configpasswordunmask('quizaccess_proctoring/aireviewopenaiapikey',
        get_string('setting:aireviewopenaiapikey', 'quizaccess_proctoring'),
        get_string('setting:aireviewopenaiapikey_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewopenaimodel',
        get_string('setting:aireviewopenaimodel', 'quizaccess_proctoring'),
        get_string('setting:aireviewopenaimodel_desc', 'quizaccess_proctoring'), 'gpt-4.1-mini', PARAM_TEXT));

    $settings->add(new admin_setting_configpasswordunmask('quizaccess_proctoring/aireviewanthropicapikey',
        get_string('setting:aireviewanthropicapikey', 'quizaccess_proctoring'),
        get_string('setting:aireviewanthropicapikey_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewanthropicmodel',
        get_string('setting:aireviewanthropicmodel', 'quizaccess_proctoring'),
        get_string('setting:aireviewanthropicmodel_desc', 'quizaccess_proctoring'), 'claude-sonnet-4-5-20250929', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewcompatibleendpoint',
        get_string('setting:aireviewcompatibleendpoint', 'quizaccess_proctoring'),
        get_string('setting:aireviewcompatibleendpoint_desc', 'quizaccess_proctoring'), '', PARAM_URL));

    $settings->add(new admin_setting_configpasswordunmask('quizaccess_proctoring/aireviewcompatibleapikey',
        get_string('setting:aireviewcompatibleapikey', 'quizaccess_proctoring'),
        get_string('setting:aireviewcompatibleapikey_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewcompatiblemodel',
        get_string('setting:aireviewcompatiblemodel', 'quizaccess_proctoring'),
        get_string('setting:aireviewcompatiblemodel_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewtriggerthreshold',
        get_string('setting:aireviewtriggerthreshold', 'quizaccess_proctoring'),
        get_string('setting:aireviewtriggerthreshold_desc', 'quizaccess_proctoring'), 80, PARAM_INT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewdecisionthreshold',
        get_string('setting:aireviewdecisionthreshold', 'quizaccess_proctoring'),
        get_string('setting:aireviewdecisionthreshold_desc', 'quizaccess_proctoring'), 80, PARAM_INT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/aireviewmaximages',
        get_string('setting:aireviewmaximages', 'quizaccess_proctoring'),
        get_string('setting:aireviewmaximages_desc', 'quizaccess_proctoring'), 6, PARAM_INT));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/cheatinglockoutenabled',
        get_string('setting:cheatinglockoutenabled', 'quizaccess_proctoring'),
        get_string('setting:cheatinglockoutenabled_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/cheatinglockoutdays',
        get_string('setting:cheatinglockoutdays', 'quizaccess_proctoring'),
        get_string('setting:cheatinglockoutdays_desc', 'quizaccess_proctoring'), 7, PARAM_INT));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/dailyreportenabled',
        get_string('setting:dailyreportenabled', 'quizaccess_proctoring'),
        get_string('setting:dailyreportenabled_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configtextarea('quizaccess_proctoring/dailyreportemails',
        get_string('setting:dailyreportemails', 'quizaccess_proctoring'),
        get_string('setting:dailyreportemails_desc', 'quizaccess_proctoring'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/dailyreportincludeall',
        get_string('setting:dailyreportincludeall', 'quizaccess_proctoring'),
        get_string('setting:dailyreportincludeall_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/dailyreportsendempty',
        get_string('setting:dailyreportsendempty', 'quizaccess_proctoring'),
        get_string('setting:dailyreportsendempty_desc', 'quizaccess_proctoring'), 0));

    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/honorstatementrequired',
        get_string('setting:honorstatementrequired', 'quizaccess_proctoring'),
        get_string('setting:honorstatementrequired_desc', 'quizaccess_proctoring'), 1));

    $settings->add(new admin_setting_configtextarea('quizaccess_proctoring/honorstatement',
        get_string('setting:honorstatement', 'quizaccess_proctoring'),
        get_string('setting:honorstatement_desc', 'quizaccess_proctoring'),
        get_string('honorstatement:default', 'quizaccess_proctoring'), PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/honoragreementlabel',
        get_string('setting:honoragreementlabel', 'quizaccess_proctoring'),
        get_string('setting:honoragreementlabel_desc', 'quizaccess_proctoring'),
        get_string('honorstatement:agreementdefault', 'quizaccess_proctoring'), PARAM_TEXT));

    $settings->add(new admin_setting_configtext('quizaccess_proctoring/rekognitioncostpercheck',
        get_string('setting:rekognitioncostpercheck', 'quizaccess_proctoring'),
        get_string('setting:rekognitioncostpercheck_desc', 'quizaccess_proctoring'), '0.001', PARAM_FLOAT));

    // AWS face matching settings.
    $settings->add(new admin_setting_configtext('quizaccess_proctoring/awschecknumber',
        get_string('setting:facematch', 'quizaccess_proctoring'),
        get_string('setting:facematchdesc', 'quizaccess_proctoring'), '', PARAM_INT));

    // Checkbox for quiz start face check.
    $settings->add(new admin_setting_configcheckbox('quizaccess_proctoring/fcheckstartchk',
        get_string('settings:fcheckquizstart', 'quizaccess_proctoring'),
        get_string('settings:fcheckquizstart_desc', 'quizaccess_proctoring'), 0));

    // Add an external page under quiz settings for the proctoring users list.
    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_proctoring_page',
        get_string('users_list', 'quizaccess_proctoring'),
        new moodle_url('/mod/quiz/accessrule/proctoring/userslist.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_proctoring_cost_estimate',
        get_string('costestimate', 'quizaccess_proctoring'),
        new moodle_url('/mod/quiz/accessrule/proctoring/cost_estimate.php'),
        'moodle/site:config'
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

    // Box containing the delete all images button styled like the upload image message.
    $pageurl = new moodle_url('/mod/quiz/accessrule/proctoring/trigger_delete.php', ['sesskey' => sesskey()]);
    $deleteicon = html_writer::tag('i', '', ['class' => 'fa fa-trash mr-2']);
    $deletealltext = get_string('settingscontroll:deleteall', 'quizaccess_proctoring');
    $deletealllinktext = get_string('settingscontroll:deletealllinktext', 'quizaccess_proctoring');
    $deletealllink = html_writer::tag('button', $deletealllinktext, [
        'class' => 'btn btn-danger',
        'data-confirmation' => 'modal',
        'data-confirmation-type' => 'delete',
        'data-confirmation-title-str' => json_encode(["delete", "core"]),
        'data-confirmation-content-str' => json_encode(["areyousure_delete_all_record", "quizaccess_proctoring"]),
        'data-confirmation-yes-button-str' => json_encode(["delete", "core"]),
        'data-confirmation-action-url' => $pageurl,
        'data-confirmation-destination' => $pageurl,
    ]);

    $deleteallmessage = html_writer::div(
        $deleteicon . ' ' . $deletealltext . ' ' . $deletealllink,
        'p-1'
    );

    $settingdescription = html_writer::div(
        $deleteallbutton .
        html_writer::tag('p', get_string('settingscontroll:deletealldescription', 'quizaccess_proctoring'))
    );

    global $DB;
    $dbman = $DB->get_manager();
    $exists = $dbman->table_exists('quizaccess_proctoring_logs') && $DB->record_exists('quizaccess_proctoring_logs', ['deletionprogress' => 0]);
    if ($exists) {
        // Add the box containing the delete message and link.
        $settings->add(new admin_setting_description(
            'quizaccess_proctoring/deleteallimages',
            get_string('settingscontroll:deleteall', 'quizaccess_proctoring'),
            $settingdescription
        ));
    }

}
