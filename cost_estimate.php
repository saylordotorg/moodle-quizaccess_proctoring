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
 * Cost estimate page for AI face matching.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('quizaccess_proctoring_cost_estimate');

$defaultinterval = (int)get_config('quizaccess_proctoring', 'autoreconfigurecamshotdelay');
$defaultinterval = $defaultinterval > 0 ? $defaultinterval : 30;
$defaultcheckevery = (int)get_config('quizaccess_proctoring', 'continuousfacecheckevery');
$defaultcheckevery = $defaultcheckevery > 0 ? $defaultcheckevery : 1;
$defaultunitcost = get_config('quizaccess_proctoring', 'rekognitioncostpercheck') ?: '0.001';

$students = max(1, optional_param('students', 30, PARAM_INT));
$durationminutes = max(1, optional_param('durationminutes', 60, PARAM_INT));
$intervalseconds = max(1, optional_param('intervalseconds', $defaultinterval, PARAM_INT));
$checkevery = max(1, optional_param('checkevery', $defaultcheckevery, PARAM_INT));
$continuous = optional_param('continuous', (int)get_config('quizaccess_proctoring', 'continuousfacecheck'), PARAM_INT) ? 1 : 0;
$includepreflight = optional_param('includepreflight', (int)get_config('quizaccess_proctoring', 'fcheckstartchk'), PARAM_INT) ? 1 : 0;
$unitcost = max(0, (float)optional_param('unitcost', $defaultunitcost, PARAM_RAW));

$capturesperstudent = (int)ceil(($durationminutes * 60) / $intervalseconds);
$continuouschecks = $continuous ? (int)ceil($capturesperstudent / $checkevery) : 0;
$preflightchecks = $includepreflight ? 1 : 0;
$checksperstudent = $continuouschecks + $preflightchecks;
$totalchecks = $checksperstudent * $students;
$costperstudent = $checksperstudent * $unitcost;
$totalcost = $totalchecks * $unitcost;

$selfurl = new moodle_url('/mod/quiz/accessrule/proctoring/cost_estimate.php');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('costestimate', 'quizaccess_proctoring'));

echo html_writer::tag('p', get_string('costestimate:note', 'quizaccess_proctoring'), ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $selfurl->out(false), 'class' => 'mb-4']);
echo html_writer::start_tag('fieldset', ['class' => 'border p-3']);
echo html_writer::tag('legend', get_string('costestimate:formheading', 'quizaccess_proctoring'), ['class' => 'w-auto px-2']);

$formrows = [
    ['students', get_string('costestimate:students', 'quizaccess_proctoring'), 'number', $students, ['min' => 1]],
    ['durationminutes', get_string('costestimate:durationminutes', 'quizaccess_proctoring'), 'number', $durationminutes, ['min' => 1]],
    ['intervalseconds', get_string('costestimate:intervalseconds', 'quizaccess_proctoring'), 'number', $intervalseconds, ['min' => 1]],
    ['checkevery', get_string('setting:continuousfacecheckevery', 'quizaccess_proctoring'), 'number', $checkevery, ['min' => 1]],
    ['unitcost', get_string('costestimate:unitcost', 'quizaccess_proctoring'), 'text', sprintf('%.6F', $unitcost), []],
];

foreach ($formrows as [$name, $label, $type, $value, $attrs]) {
    $attrs = array_merge([
        'type' => $type,
        'name' => $name,
        'id' => 'id_' . $name,
        'value' => $value,
        'class' => 'form-control',
    ], $attrs);
    echo html_writer::start_div('form-group row');
    echo html_writer::tag('label', $label, ['for' => 'id_' . $name, 'class' => 'col-sm-4 col-form-label']);
    echo html_writer::div(html_writer::empty_tag('input', $attrs), 'col-sm-4');
    echo html_writer::end_div();
}

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'continuous', 'value' => 0]);
$continuousattrs = ['type' => 'checkbox', 'name' => 'continuous', 'id' => 'id_continuous', 'value' => 1];
if ($continuous) {
    $continuousattrs['checked'] = 'checked';
}
echo html_writer::start_div('form-group row');
echo html_writer::div('', 'col-sm-4');
echo html_writer::div(
    html_writer::tag('label', html_writer::empty_tag('input', $continuousattrs) . ' ' .
        get_string('setting:continuousfacecheck', 'quizaccess_proctoring'), ['for' => 'id_continuous']),
    'col-sm-8'
);
echo html_writer::end_div();

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'includepreflight', 'value' => 0]);
$preflightattrs = ['type' => 'checkbox', 'name' => 'includepreflight', 'id' => 'id_includepreflight', 'value' => 1];
if ($includepreflight) {
    $preflightattrs['checked'] = 'checked';
}
echo html_writer::start_div('form-group row');
echo html_writer::div('', 'col-sm-4');
echo html_writer::div(
    html_writer::tag('label', html_writer::empty_tag('input', $preflightattrs) . ' ' .
        get_string('costestimate:includepreflight', 'quizaccess_proctoring'), ['for' => 'id_includepreflight']),
    'col-sm-8'
);
echo html_writer::end_div();

echo html_writer::start_div('form-group row');
echo html_writer::div('', 'col-sm-4');
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('costestimate:recalculate', 'quizaccess_proctoring'),
    ]),
    'col-sm-8'
);
echo html_writer::end_div();

echo html_writer::end_tag('fieldset');
echo html_writer::end_tag('form');

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = [
    [get_string('costestimate:capturesperstudent', 'quizaccess_proctoring'), $capturesperstudent],
    [get_string('costestimate:continuouschecks', 'quizaccess_proctoring'), $continuouschecks],
    [get_string('costestimate:preflightchecks', 'quizaccess_proctoring'), $preflightchecks],
    [get_string('costestimate:totalchecks', 'quizaccess_proctoring'), $totalchecks],
    [get_string('costestimate:perstudent', 'quizaccess_proctoring'), '$' . number_format($costperstudent, 4)],
    [get_string('costestimate:estimatedtotal', 'quizaccess_proctoring'), '$' . number_format($totalcost, 2)],
];

echo html_writer::table($table);
echo $OUTPUT->footer();
