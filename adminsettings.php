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
 * The proctoring settings for quizaccess/proctoring:manageadminsettings holders.
 *
 * Core only puts /admin/settings.php?section=modsettingsquizcatproctoring in the admin tree of
 * moodle/site:config holders, so this page serves the same settings pages - built by
 * {@see \quizaccess_proctoring\local\admin_settings_pages} from the same settings.php - to the
 * roles the capability was made for. Credential fields and the AI review section are never
 * built for a capability holder; a site administrator is sent to the real admin page, so the
 * settings are only ever edited through one surface per person.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

$section = optional_param('section', \quizaccess_proctoring\local\admin_settings_pages::MAIN_SECTION, PARAM_SAFEDIR);

require_login(null, false);
$systemcontext = context_system::instance();
if (has_capability('moodle/site:config', $systemcontext)) {
    // Site administrators have the real page; one editing surface each.
    redirect(new moodle_url('/admin/settings.php', ['section' => $section]));
}
require_capability(QUIZACCESS_PROCTORING_CAP_ADMIN_SETTINGS, $systemcontext);

$pageurl = new moodle_url('/mod/quiz/accessrule/proctoring/adminsettings.php', ['section' => $section]);
$PAGE->set_context($systemcontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');

// Built without $hassiteconfig: no credential field exists on these pages, not even hidden.
$root = \quizaccess_proctoring\local\admin_settings_pages::build(false);
$page = $root->locate($section);
if (!($page instanceof admin_settingpage) || !$page->check_access()) {
    throw new required_capability_exception($systemcontext, QUIZACCESS_PROCTORING_CAP_ADMIN_SETTINGS, 'nopermissions', '');
}

$PAGE->set_title($page->visiblename);
$PAGE->set_heading($page->visiblename);
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');

// Save, the way admin/settings.php does: only settings this page defines can be written, each
// write is validated by the setting itself, and config_write logs every change to the config log.
if ($data = data_submitted()) {
    require_sesskey();
    $data = (array)$data;
    $errors = [];
    foreach (admin_find_write_settings($page, $data) as $fullname => $setting) {
        $original = $setting->get_setting();
        $error = $setting->write_setting($data[$fullname]);
        if ($error !== '') {
            $errors[] = $error;
        }
        $setting->post_write_settings($original);
    }
    if (empty($errors)) {
        redirect($pageurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $saveerrors = implode(' ', $errors);
}

echo $OUTPUT->header();

// The plugin's pages a capability holder can work with, the settings pages served here and the
// stand-alone pages that check the capability themselves.
$tabs = [
    new tabobject(
        \quizaccess_proctoring\local\admin_settings_pages::MAIN_SECTION,
        new moodle_url('/mod/quiz/accessrule/proctoring/adminsettings.php',
            ['section' => \quizaccess_proctoring\local\admin_settings_pages::MAIN_SECTION]),
        get_string('settings', 'quizaccess_proctoring')
    ),
    new tabobject(
        \quizaccess_proctoring\local\admin_settings_pages::RISKFACTORS_SECTION,
        new moodle_url('/mod/quiz/accessrule/proctoring/adminsettings.php',
            ['section' => \quizaccess_proctoring\local\admin_settings_pages::RISKFACTORS_SECTION]),
        get_string('riskfactorspage', 'quizaccess_proctoring')
    ),
    new tabobject(
        'overallreports',
        new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php'),
        get_string('overallreports', 'quizaccess_proctoring')
    ),
    new tabobject(
        'userslist',
        new moodle_url('/mod/quiz/accessrule/proctoring/userslist.php'),
        get_string('users_list', 'quizaccess_proctoring')
    ),
];
echo $OUTPUT->tabtree($tabs, $section);

if (!empty($saveerrors)) {
    echo $OUTPUT->notification($saveerrors, \core\output\notification::NOTIFY_ERROR);
}

echo html_writer::start_tag('form', [
    'action' => $pageurl->out(false),
    'method' => 'post',
    'id' => 'adminsettings',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => $section]);
echo html_writer::start_div('settingsform');
echo $page->output_html();
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('savechanges', 'admin'),
    ]),
    'form-buttons mt-3'
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
