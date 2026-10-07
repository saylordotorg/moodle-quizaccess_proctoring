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
 * A student's own proctoring reference photo, and a way to ask for a new one (CPIT-476).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

use quizaccess_proctoring\local\reference_photo;

require_login(null, false);
if (isguestuser()) {
    throw new require_login_exception('Guests have no proctoring photo.');
}

$url = new moodle_url('/mod/quiz/accessrule/proctoring/my_photo.php');
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myphoto:title', 'quizaccess_proctoring'));
$PAGE->set_heading(fullname($USER));

$hasphoto = reference_photo::exists((int)$USER->id);

if (optional_param('action', '', PARAM_ALPHA) === 'request') {
    require_sesskey();
    if ($hasphoto && reference_photo::can_request((int)$USER->id)) {
        reference_photo::request_reset((int)$USER->id, optional_param('reason', '', PARAM_TEXT));
        redirect($url, get_string('myphoto:requestsent', 'quizaccess_proctoring'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($url);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('myphoto:title', 'quizaccess_proctoring'));
echo html_writer::tag('p', get_string('myphoto:intro', 'quizaccess_proctoring'));

$photourl = $hasphoto ? quizaccess_proctoring_get_image_url((int)$USER->id) : false;
if (!$photourl) {
    echo $OUTPUT->notification(get_string('myphoto:none', 'quizaccess_proctoring'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    return;
}

echo html_writer::empty_tag('img', [
    'src' => $photourl,
    'alt' => get_string('myphoto:title', 'quizaccess_proctoring'),
    'class' => 'img-thumbnail mb-3 d-block',
    'width' => 320,
]);
$days = (int)get_config('quizaccess_proctoring', 'referenceretentiondays');
if ($days > 0) {
    echo html_writer::tag('p', get_string('privacynotice:retentionreference', 'quizaccess_proctoring', $days),
        ['class' => 'text-muted']);
}

echo $OUTPUT->heading(get_string('myphoto:requestheading', 'quizaccess_proctoring'), 3);
if (!reference_photo::can_request((int)$USER->id)) {
    echo $OUTPUT->notification(get_string('myphoto:requestpending', 'quizaccess_proctoring'),
        \core\output\notification::NOTIFY_INFO);
} else {
    echo html_writer::tag('p', get_string('myphoto:requestintro', 'quizaccess_proctoring'));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'request']);
    echo html_writer::label(get_string('myphoto:reason', 'quizaccess_proctoring'), 'proctoring-myphoto-reason');
    echo html_writer::tag('textarea', '', [
        'id' => 'proctoring-myphoto-reason',
        'name' => 'reason',
        'rows' => 3,
        'maxlength' => 1000,
        'class' => 'form-control mb-2',
    ]);
    echo html_writer::tag('button', get_string('myphoto:requestbutton', 'quizaccess_proctoring'), [
        'type' => 'submit',
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
