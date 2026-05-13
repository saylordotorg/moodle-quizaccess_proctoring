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
 * AI image review diagnostics page.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('quizaccess_proctoring_ai_diagnostics');
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');

/**
 * Return provider model for diagnostics.
 *
 * @param string $provider Provider key.
 * @param array $settings AI review settings.
 * @return string Configured model.
 */
function quizaccess_proctoring_ai_diagnostics_model(string $provider, array $settings): string {
    switch ($provider) {
        case 'anthropic':
            return (string)$settings['anthropicmodel'];
        case 'compatible':
            return (string)$settings['compatiblemodel'];
        case 'openai':
        default:
            return (string)$settings['openaimodel'];
    }
}

/**
 * Return missing configuration notes for a provider.
 *
 * @param string $provider Provider key.
 * @param array $settings AI review settings.
 * @return string Missing configuration note.
 */
function quizaccess_proctoring_ai_diagnostics_missing(string $provider, array $settings): string {
    $missing = [];
    switch ($provider) {
        case 'openai':
            if (empty($settings['openaiapikey'])) {
                $missing[] = get_string('aireviewdiagnostics:apikeymissing', 'quizaccess_proctoring');
            }
            if (empty($settings['openaimodel'])) {
                $missing[] = get_string('aireviewdiagnostics:modelmissing', 'quizaccess_proctoring');
            }
            break;
        case 'anthropic':
            if (empty($settings['anthropicapikey'])) {
                $missing[] = get_string('aireviewdiagnostics:apikeymissing', 'quizaccess_proctoring');
            }
            if (empty($settings['anthropicmodel'])) {
                $missing[] = get_string('aireviewdiagnostics:modelmissing', 'quizaccess_proctoring');
            }
            break;
        case 'compatible':
            if (empty($settings['compatibleendpoint'])) {
                $missing[] = get_string('aireviewdiagnostics:endpointmissing', 'quizaccess_proctoring');
            }
            if (empty($settings['compatiblemodel'])) {
                $missing[] = get_string('aireviewdiagnostics:modelmissing', 'quizaccess_proctoring');
            }
            break;
    }

    return implode(', ', $missing);
}

/**
 * Render one diagnostics result table from provider comparison rows.
 *
 * @param array $rows Provider result rows.
 * @return string HTML table.
 */
function quizaccess_proctoring_ai_diagnostics_results_table(array $rows): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [
        get_string('aireviewdiagnostics:provider', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:status', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:score', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:decision', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:summary', 'quizaccess_proctoring'),
    ];
    $table->data = $rows;

    return html_writer::table($table);
}

$settings = quizaccess_proctoring_get_ai_review_settings();
$providers = ['openai', 'anthropic', 'compatible'];
$selfurl = new moodle_url('/mod/quiz/accessrule/proctoring/ai_review_diagnostics.php');
$reportid = optional_param('reportid', 0, PARAM_INT);
$maximages = max(1, min(12, optional_param('maximages', 3, PARAM_INT)));
$runcomparison = optional_param('runcomparison', 0, PARAM_INT);
$comparisonrows = [];
$comparisonnotice = '';

if ($runcomparison) {
    require_sesskey();
    $log = $DB->get_record(
        'quizaccess_proctoring_logs',
        ['id' => $reportid],
        'id, courseid, quizid, userid, status',
        IGNORE_MISSING
    );

    if (!$log) {
        $comparisonnotice = $OUTPUT->notification(
            get_string('aireviewdiagnostics:reportnotfound', 'quizaccess_proctoring'),
            \core\output\notification::NOTIFY_ERROR
        );
    } else {
        $risk = quizaccess_proctoring_calculate_attempt_risk(
            (int)$log->courseid,
            (int)$log->quizid,
            (int)$log->userid,
            (int)$log->id
        );
        $review = (object)[
            'id' => 0,
            'courseid' => (int)$log->courseid,
            'quizid' => (int)$log->quizid,
            'userid' => (int)$log->userid,
            'attemptid' => (int)$risk['attemptid'],
            'reportid' => (int)$log->id,
            'holdid' => 0,
            'riskscore' => (int)$risk['score'],
            'triggerthreshold' => (int)$settings['triggerthreshold'],
        ];
        $images = quizaccess_proctoring_collect_ai_review_images($review, $maximages);

        if (empty($images)) {
            $comparisonnotice = $OUTPUT->notification(
                get_string('aireview:noimages', 'quizaccess_proctoring'),
                \core\output\notification::NOTIFY_WARNING
            );
        } else {
            $comparisonnotice = $OUTPUT->notification(
                get_string('aireviewdiagnostics:imagessent', 'quizaccess_proctoring', count($images)),
                \core\output\notification::NOTIFY_INFO
            );
            foreach ($providers as $provider) {
                $providerlabel = quizaccess_proctoring_get_ai_review_provider_label($provider);
                $model = quizaccess_proctoring_ai_diagnostics_model($provider, $settings);
                if (!quizaccess_proctoring_ai_review_provider_configured($provider, $settings)) {
                    $comparisonrows[] = [
                        $providerlabel,
                        get_string('aireviewdiagnostics:skipped', 'quizaccess_proctoring'),
                        '-',
                        '-',
                        quizaccess_proctoring_ai_diagnostics_missing($provider, $settings),
                    ];
                    continue;
                }

                try {
                    $providersettings = $settings;
                    $providersettings['provider'] = $provider;
                    $result = quizaccess_proctoring_call_ai_review_provider($provider, $review, $images, $providersettings);
                    $score = max(0, min(100, (int)($result['review_score'] ?? 0)));
                    $decision = quizaccess_proctoring_get_ai_review_decision_label((string)($result['decision'] ?? 'inconclusive'));
                    $summary = (string)($result['summary'] ?? '');
                    $comparisonrows[] = [
                        s($providerlabel) . html_writer::div(s($model), 'proctoring-ai-review-meta'),
                        get_string('aireviewdiagnostics:success', 'quizaccess_proctoring'),
                        $score . '/100',
                        s($decision),
                        s($summary),
                    ];
                } catch (Throwable $e) {
                    $comparisonrows[] = [
                        s($providerlabel) . html_writer::div(s($model), 'proctoring-ai-review-meta'),
                        get_string('aireviewdiagnostics:error', 'quizaccess_proctoring'),
                        '-',
                        '-',
                        s($e->getMessage()),
                    ];
                }
            }
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aireviewdiagnostics', 'quizaccess_proctoring'));
echo html_writer::tag('p', get_string('aireviewdiagnostics:intro', 'quizaccess_proctoring'), ['class' => 'text-muted']);

$configtable = new html_table();
$configtable->attributes['class'] = 'generaltable';
$configtable->head = [
    get_string('aireviewdiagnostics:provider', 'quizaccess_proctoring'),
    get_string('aireviewdiagnostics:status', 'quizaccess_proctoring'),
    get_string('aireviewdiagnostics:model', 'quizaccess_proctoring'),
    get_string('aireviewdiagnostics:notes', 'quizaccess_proctoring'),
];
foreach ($providers as $provider) {
    $configured = quizaccess_proctoring_ai_review_provider_configured($provider, $settings);
    $configtable->data[] = [
        quizaccess_proctoring_get_ai_review_provider_label($provider),
        $configured
            ? get_string('aireviewdiagnostics:configured', 'quizaccess_proctoring')
            : get_string('aireviewdiagnostics:notconfigured', 'quizaccess_proctoring'),
        quizaccess_proctoring_ai_diagnostics_model($provider, $settings) ?: '-',
        $configured ? '' : quizaccess_proctoring_ai_diagnostics_missing($provider, $settings),
    ];
}

echo $OUTPUT->heading(get_string('aireviewdiagnostics:configuration', 'quizaccess_proctoring'), 3);
echo html_writer::table($configtable);

echo $OUTPUT->heading(get_string('aireviewdiagnostics:testing', 'quizaccess_proctoring'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $selfurl->out(false), 'class' => 'mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'runcomparison', 'value' => 1]);
echo html_writer::start_div('form-group row');
echo html_writer::tag(
    'label',
    get_string('aireviewdiagnostics:reportid', 'quizaccess_proctoring'),
    ['for' => 'id_reportid', 'class' => 'col-sm-3 col-form-label']
);
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'reportid',
        'id' => 'id_reportid',
        'class' => 'form-control',
        'min' => 1,
        'value' => $reportid ?: '',
        'required' => 'required',
    ]),
    'col-sm-4'
);
echo html_writer::end_div();
echo html_writer::start_div('form-group row');
echo html_writer::tag(
    'label',
    get_string('aireviewdiagnostics:maximages', 'quizaccess_proctoring'),
    ['for' => 'id_maximages', 'class' => 'col-sm-3 col-form-label']
);
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'maximages',
        'id' => 'id_maximages',
        'class' => 'form-control',
        'min' => 1,
        'max' => 12,
        'value' => $maximages,
    ]),
    'col-sm-4'
);
echo html_writer::end_div();
echo html_writer::start_div('form-group row');
echo html_writer::div('', 'col-sm-3');
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('aireviewdiagnostics:runtest', 'quizaccess_proctoring'),
    ]),
    'col-sm-9'
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($comparisonnotice !== '') {
    echo $comparisonnotice;
}
if (!empty($comparisonrows)) {
    echo $OUTPUT->heading(get_string('aireviewdiagnostics:testresults', 'quizaccess_proctoring'), 4);
    echo quizaccess_proctoring_ai_diagnostics_results_table($comparisonrows);
}

echo $OUTPUT->heading(get_string('aireviewdiagnostics:recenterrors', 'quizaccess_proctoring'), 3);
$failures = $DB->get_records(
    'quizaccess_proctoring_ai_reviews',
    ['status' => QUIZACCESS_PROCTORING_AI_REVIEW_FAILED],
    'timemodified DESC',
    '*',
    0,
    25
);
if (!$failures) {
    echo $OUTPUT->notification(
        get_string('aireviewdiagnostics:norecenterrors', 'quizaccess_proctoring'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    $errortable = new html_table();
    $errortable->attributes['class'] = 'generaltable';
    $errortable->head = [
        get_string('dateverified', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:provider', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:model', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:reportid', 'quizaccess_proctoring'),
        get_string('aireviewdiagnostics:error', 'quizaccess_proctoring'),
    ];
    foreach ($failures as $failure) {
        $reporturl = new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
            'courseid' => (int)$failure->courseid,
            'cmid' => (int)$failure->quizid,
            'studentid' => (int)$failure->userid,
            'reportid' => (int)$failure->reportid,
        ]);
        $errortable->data[] = [
            userdate((int)$failure->timemodified),
            quizaccess_proctoring_get_ai_review_provider_label((string)$failure->provider),
            s($failure->model),
            html_writer::link($reporturl, (string)$failure->reportid),
            s($failure->errormessage),
        ];
    }
    echo html_writer::table($errortable);
}

echo $OUTPUT->footer();
