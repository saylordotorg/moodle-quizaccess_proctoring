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
 * Proctoring profiles: named sets of site settings, such as "ACE credit" or "Certificate", that a
 * quiz picks in its settings.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use quizaccess_proctoring\local\profile;

$edit = optional_param('edit', -1, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$component = 'quizaccess_proctoring';

admin_externalpage_setup('quizaccess_proctoring_profiles');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$baseurl = new moodle_url('/mod/quiz/accessrule/proctoring/profiles.php');
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('profiles:title', $component));
$PAGE->set_heading(get_string('profiles:title', $component));

if ($delete > 0) {
    $record = $DB->get_record(profile::TABLE, ['id' => $delete], '*', MUST_EXIST);
    if ($confirm) {
        require_sesskey();
        profile::delete($delete);
        redirect(
            $baseurl,
            get_string('profiles:deleted', $component, format_string($record->name)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    $used = profile::usage_counts()[$delete] ?? 0;
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('profiles:deleteconfirm', $component, (object)['name' => format_string($record->name), 'quizzes' => $used]),
        new moodle_url($baseurl, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

if ($edit >= 0) {
    $form = new \quizaccess_proctoring\form\profile_form(new moodle_url($baseurl, ['edit' => $edit]));
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        profile::save((int)$data->id, (string)$data->name, (string)$data->description, (array)$data);
        redirect(
            $baseurl,
            get_string('profiles:saved', $component, format_string(trim((string)$data->name))),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($edit > 0) {
        $record = $DB->get_record(profile::TABLE, ['id' => $edit], '*', MUST_EXIST);
        $form->set_data(array_merge(
            ['id' => (int)$record->id, 'name' => $record->name, 'description' => (string)$record->description],
            profile::decode((string)$record->settings)
        ));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($edit > 0 ? 'profiles:edit' : 'profiles:add', $component));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('profiles:title', $component));
echo html_writer::tag('p', get_string('profiles:intro', $component));
echo $OUTPUT->single_button(new moodle_url($baseurl, ['edit' => 0]), get_string('profiles:add', $component), 'get');

$profiles = profile::all();
if (!$profiles) {
    echo $OUTPUT->notification(get_string('profiles:none', $component), \core\output\notification::NOTIFY_INFO);
} else {
    $counts = profile::usage_counts();
    $table = new html_table();
    $table->head = [
        get_string('profile:name', $component),
        get_string('profiles:changes', $component),
        get_string('profiles:quizzes', $component),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'generaltable proctoring-profiles';
    foreach ($profiles as $record) {
        $name = html_writer::tag('strong', format_string($record->name));
        if (trim((string)$record->description) !== '') {
            $name .= html_writer::div(s($record->description), 'text-muted small');
        }
        $lines = profile::describe(profile::decode((string)$record->settings));
        $changes = $lines
            ? html_writer::alist(array_map('s', $lines), ['class' => 'mb-0'])
            : html_writer::span(get_string('profiles:nochanges', $component), 'text-muted');
        $actions = html_writer::link(
            new moodle_url($baseurl, ['edit' => $record->id]),
            get_string('edit'),
            ['class' => 'btn btn-secondary btn-sm me-1']
        ) . html_writer::link(
            new moodle_url($baseurl, ['delete' => $record->id]),
            get_string('delete'),
            ['class' => 'btn btn-outline-danger btn-sm']
        );
        $table->data[] = [$name, $changes, (int)($counts[(int)$record->id] ?? 0), $actions];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
