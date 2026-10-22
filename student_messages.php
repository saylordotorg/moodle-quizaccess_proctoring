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
 * Messages to students: every email and notification TaView sends a student, and its wording
 * (CPIT-512). Staff with the editstudentmessages capability reword them here.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

use quizaccess_proctoring\local\message_texts;

$edit = optional_param('edit', '', PARAM_ALPHANUMEXT);
$reset = optional_param('reset', '', PARAM_ALPHANUMEXT);
$component = 'quizaccess_proctoring';

require_login();
$context = context_system::instance();
if (!message_texts::can_view()) {
    throw new required_capability_exception($context, message_texts::CAPABILITY, 'nopermissions', '');
}
$canedit = message_texts::can_edit();

$baseurl = new moodle_url('/mod/quiz/accessrule/proctoring/student_messages.php');
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('messagetexts:title', $component));
$PAGE->set_heading(get_string('messagetexts:title', $component));
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');

foreach ([$edit, $reset] as $id) {
    if ($id !== '' && !isset(message_texts::MESSAGES[$id])) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
}

if ($reset !== '') {
    require_capability(message_texts::CAPABILITY, $context);
    require_sesskey();
    foreach (message_texts::MESSAGES[$reset]['parts'] as $key) {
        message_texts::reset($key);
    }
    redirect(
        new moodle_url($baseurl, [], 'message-' . $reset),
        get_string('messagetexts:resetdone', $component, get_string('messagetexts:message_' . $reset, $component)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($edit !== '') {
    require_capability(message_texts::CAPABILITY, $context);
    $form = new \quizaccess_proctoring\form\message_text_form(new moodle_url($baseurl, ['edit' => $edit]), ['message' => $edit]);
    if ($form->is_cancelled()) {
        redirect(new moodle_url($baseurl, [], 'message-' . $edit));
    }
    if ($data = $form->get_data()) {
        foreach (message_texts::MESSAGES[$edit]['parts'] as $part => $key) {
            message_texts::save($key, (string)$data->$part, (int)$USER->id);
        }
        redirect(
            new moodle_url($baseurl, [], 'message-' . $edit),
            get_string('messagetexts:saved', $component, get_string('messagetexts:message_' . $edit, $component)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // Live preview with sample values, and the placeholder buttons.
    $parts = [];
    foreach (message_texts::MESSAGES[$edit]['parts'] as $part => $key) {
        $parts[$part] = array_values(array_unique(array_values(message_texts::placeholders($key))));
    }
    $PAGE->requires->js_call_amd('quizaccess_proctoring/messagePreview', 'init', [[
        'parts' => $parts,
        'samples' => message_texts::samples(),
    ]]);
    echo $OUTPUT->header();
    echo html_writer::start_div('proctoring-student-messages');
    echo html_writer::link(
        new moodle_url($baseurl, [], 'message-' . $edit),
        get_string('messagetexts:backtolist', $component),
        ['class' => 'btn btn-secondary btn-sm mb-3']
    );
    echo $OUTPUT->heading(get_string('messagetexts:message_' . $edit, $component), 3);
    echo html_writer::tag('p', s(get_string('messagetexts:when_' . $edit, $component)), ['class' => 'text-muted']);
    $form->display();
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
// Links on this page are underlined, not told apart by colour alone (CPIT-512, Student Affairs).
echo html_writer::start_div('proctoring-student-messages');
echo html_writer::link(
    new moodle_url('/mod/quiz/accessrule/proctoring/overall_reports.php'),
    get_string('messagetexts:back', $component),
    ['class' => 'btn btn-secondary btn-sm mb-3']
);
echo html_writer::tag('p', s(get_string($canedit ? 'messagetexts:introedit' : 'messagetexts:introview', $component)));

$canlinksettings = quizaccess_proctoring_can_manage_admin_settings();
foreach (message_texts::MESSAGES as $id => $message) {
    $out = html_writer::tag('h3', s(get_string('messagetexts:message_' . $id, $component)), ['class' => 'h5']);
    $out .= html_writer::tag('p', s(get_string('messagetexts:when_' . $id, $component)), ['class' => 'mb-1']);

    $channel = $message['channel'] === 'email'
        ? get_string('messagetexts:channelemail', $component)
        : get_string('messagetexts:channelnotification', $component);
    $details = html_writer::tag('dt', get_string('messagetexts:sentas', $component), ['class' => 'col-sm-3'])
        . html_writer::tag('dd', s($channel), ['class' => 'col-sm-9']);
    $settings = [];
    foreach ($message['settings'] as $setting) {
        $label = s(get_string('setting:' . $setting, $component));
        $settings[] = $canlinksettings
            ? html_writer::link(
                new moodle_url('/admin/settings.php', ['section' => 'modsettingsquizcatproctoring'], 'admin-' . $setting),
                $label
            )
            : $label;
    }
    $details .= html_writer::tag('dt', get_string('messagetexts:controlledby', $component), ['class' => 'col-sm-3'])
        . html_writer::tag(
            'dd',
            $settings ? implode('; ', $settings) : s(get_string('messagetexts:alwayssent', $component)),
            ['class' => 'col-sm-9']
        );
    $out .= html_writer::tag('dl', $details, ['class' => 'row small mb-2']);

    $customised = false;
    foreach ($message['parts'] as $part => $key) {
        $record = message_texts::edit_record($key);
        $status = get_string('messagetexts:default', $component);
        if ($record) {
            $customised = true;
            $editor = core_user::get_user((int)$record->usermodified);
            $status = get_string('messagetexts:edited', $component, (object)[
                'user' => $editor ? fullname($editor) : '-',
                // In the institution's timezone, zone named, as every other proctoring date.
                'time' => \quizaccess_proctoring\local\display_time::staff((int)$record->timemodified),
            ]);
        }
        $badge = $record ? 'badge badge-info bg-info text-dark' : 'badge badge-light bg-light text-dark';
        $out .= html_writer::div(
            html_writer::tag('strong', s(get_string('messagetexts:' . $part, $component))) . ' '
                . html_writer::span(s($status), $badge),
            'mt-2'
        );
        $out .= html_writer::tag('div', s(message_texts::to_editor($key)), [
            'class' => 'proctoring-message-text border rounded p-2 bg-light',
            'style' => 'white-space: pre-wrap;',
        ]);
    }

    if ($canedit) {
        $buttons = html_writer::link(
            new moodle_url($baseurl, ['edit' => $id]),
            get_string('messagetexts:edit', $component),
            ['class' => 'btn btn-secondary btn-sm']
        );
        if ($customised) {
            $button = new single_button(
                new moodle_url($baseurl, ['reset' => $id, 'sesskey' => sesskey()]),
                get_string('messagetexts:reset', $component)
            );
            $button->add_confirm_action(get_string('messagetexts:resetconfirm', $component));
            $buttons .= ' ' . $OUTPUT->render($button);
        }
        $out .= html_writer::div($buttons, 'mt-2 d-flex gap-2 align-items-start');
    }

    echo html_writer::div($out, 'card card-body mb-3', ['id' => 'message-' . $id]);
}

echo html_writer::end_div();
echo $OUTPUT->footer();
