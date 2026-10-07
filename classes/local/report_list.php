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

namespace quizaccess_proctoring\local;

use html_writer;
use moodle_url;
use stdClass;

/**
 * The rows of the per-quiz proctoring report (report.php).
 *
 * The report used to score every student in the quiz, one attempt at a time and about twenty
 * queries each, plus a hold, certificate, AI review and event-count lookup per row, and only then
 * cut the list down to the 30 rows on the page. A quiz with a few thousand attempts took minutes
 * (CPIT-473). Now the cheap columns are built for every row, the sort and the page cut happen next,
 * and only the visible rows are scored, in bulk through {@see risk_calculator::calculate_many()}.
 * Sorting by risk or findings still needs every row's score; those are scored in bulk too.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_list {
    /** @var string[] Row sort fields that only exist once a row has been risk-scored. */
    private const RISK_SORT_FIELDS = ['sortriskscore', 'sortfindingcount'];

    /** @var int Ids per IN () list. */
    private const CHUNK = 1000;

    /** @var array<string, string> Sort column from quizaccess_proctoring_report_order_by() => row field. */
    private const SORT_FIELDS = [
        'lastname' => 'sortlastname',
        'firstname' => 'sortfirstname',
        'email' => 'sortemail',
        'timemodified' => 'sorttimemodified',
        'riskscore' => 'sortriskscore',
        'findingcount' => 'sortfindingcount',
        'eventcount' => 'sorteventcount',
        'score' => 'sortscore',
        'accountage' => 'sortaccountage',
    ];

    /**
     * Build the report rows for one page.
     *
     * @param iterable $records The report query's rows (studentid, firstname, lastname, email, warningid,
     *                          reportid, timemodified), one per student.
     * @param array $options courseid, cmid, quiz (quiz record), context, baseurl (moodle_url of the
     *                       report), islistview, firstnameinitial, lastnameinitial, sort, dir, offset, perpage.
     * @return array ['rows' => the page's template rows, 'total' => rows across every page]
     */
    public static function build(iterable $records, array $options): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $courseid = (int)$options['courseid'];
        $cmid = (int)$options['cmid'];
        $islistview = !empty($options['islistview']);
        $firstinitial = (string)($options['firstnameinitial'] ?? '');
        $lastinitial = (string)($options['lastnameinitial'] ?? '');

        // Everything that costs no more than one grouped query for the whole list.
        $rows = [];
        foreach ($records as $info) {
            // Apply the A–Z name-initial filter on the all-users list view (Requirement 13.3).
            if (
                $islistview && !quizaccess_proctoring_name_matches_initials(
                    (string)$info->firstname,
                    (string)$info->lastname,
                    $firstinitial,
                    $lastinitial
                )
            ) {
                continue;
            }
            $rows[] = self::base_row($info, $courseid);
        }
        $total = count($rows);
        if (!$rows) {
            return ['rows' => [], 'total' => 0];
        }

        $attemptids = self::attempt_ids(array_column($rows, 'reportid'));
        $eventcounts = self::event_counts($courseid, $cmid, array_column($rows, 'studentid'));
        foreach ($rows as $index => $row) {
            $rows[$index]['attemptid'] = $attemptids[$row['reportid']] ?? 0;
            $rows[$index]['eventcount'] = $eventcounts[$row['studentid']] ?? 0;
            $rows[$index]['sorteventcount'] = $rows[$index]['eventcount'];
        }
        self::add_grades_and_account_ages($rows, $options['quiz']);

        // Sort over the full result set (Requirement 13.2), then cut the page. Only a sort on the
        // risk score or the findings needs every row scored first.
        $sortpairs = self::sort_pairs((string)($options['sort'] ?? ''), (string)($options['dir'] ?? ''));
        $sortneedsrisk = (bool)array_intersect(array_column($sortpairs, 0), self::RISK_SORT_FIELDS);
        if ($sortneedsrisk) {
            self::add_risk($rows, $courseid, $cmid);
        }
        self::sort_rows($rows, $sortpairs);
        $rows = array_values(array_slice($rows, (int)$options['offset'], (int)$options['perpage']));
        if (!$sortneedsrisk) {
            self::add_risk($rows, $courseid, $cmid);
        }

        self::add_page_details($rows, $courseid, $cmid, $options['context'], $options['baseurl']);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The columns that come straight from the report query.
     *
     * @param stdClass $info One report query row.
     * @param int $courseid Course id.
     * @return array
     */
    private static function base_row(stdClass $info, int $courseid): array {
        global $CFG;

        $row = [];
        $row['studentid'] = (int)$info->studentid;
        $row['reportid'] = (int)$info->reportid;
        $row['userlink'] = $CFG->wwwroot . '/user/view.php?id=' . $info->studentid . '&course=' . $courseid;
        $row['fullname'] = $info->firstname . ' ' . $info->lastname;
        $row['email'] = $info->email;
        // Use Moodle's locale/timezone-aware date formatting so the proctoring report matches
        // the quiz results report (Requirement 18.1).
        $row['timemodified'] = userdate((int)$info->timemodified);
        // Raw values used only for the PHP-side column sort (Requirement 13.2); harmless in the template.
        $row['sortlastname'] = \core_text::strtolower((string)$info->lastname);
        $row['sortfirstname'] = \core_text::strtolower((string)$info->firstname);
        $row['sorttimemodified'] = (int)$info->timemodified;
        $row['sortemail'] = \core_text::strtolower((string)$info->email);
        $row['warningicon'] = ($info->warningid == '') ? true : false;
        // Identity Mismatch rendered as a localized Yes/No (Requirement 18.2). A present
        // face-match warning row (non-empty warningid) means the identity did not match.
        $row['identitymismatch'] = quizaccess_proctoring_identity_mismatch_label($info->warningid);
        return $row;
    }

    /**
     * The quiz attempt each report row's capture belongs to.
     *
     * @param int[] $reportids quizaccess_proctoring_logs ids.
     * @return array<int, int> report id => attempt id (0 when the capture has none)
     */
    public static function attempt_ids(array $reportids): array {
        global $DB;

        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $reportids))), self::CHUNK) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'rep');
            $statuses = $DB->get_records_select_menu('quizaccess_proctoring_logs', "id {$insql}", $params, '', 'id, status');
            foreach ($statuses as $id => $status) {
                $out[(int)$id] = (int)$status;
            }
        }
        return $out;
    }

    /**
     * How many scoring browser events each student has in the quiz.
     *
     * Only the event types that can score, which is what the report body shows and what the
     * site-wide dashboard counts. Counting every stored row instead made this column read "724
     * suspicious activities" for an attempt with four findings.
     *
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param int[] $userids Students on the report.
     * @return array<int, int> user id => event count; students without events are absent
     */
    public static function event_counts(int $courseid, int $cmid, array $userids): array {
        global $DB;

        [$evtsql, $evtparams] = $DB->get_in_or_equal(overall_report::SUSPICIOUS_EVENT_TYPES, SQL_PARAMS_NAMED, 'evt');
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $userids))), self::CHUNK) as $chunk) {
            [$usersql, $userparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'evu');
            $counts = $DB->get_records_sql_menu(
                "SELECT userid, COUNT(1)
                   FROM {quizaccess_proctoring_events}
                  WHERE courseid = :courseid AND quizid = :quizid AND userid {$usersql} AND eventtype {$evtsql}
               GROUP BY userid",
                ['courseid' => $courseid, 'quizid' => $cmid] + $userparams + $evtparams
            );
            foreach ($counts as $userid => $count) {
                $out[(int)$userid] = (int)$count;
            }
        }
        return $out;
    }

    /**
     * What the attempt scored and how old the account was.
     *
     * A reviewer asks these two questions about every row, and until now had to open the grade
     * report and the profile to answer them. One query each for the whole list.
     *
     * @param array $rows Report rows, updated in place.
     * @param stdClass $quiz The quiz record.
     */
    private static function add_grades_and_account_ages(array &$rows, stdClass $quiz): void {
        global $CFG, $DB;
        // Function quiz_rescale_grade() turns a raw sumgrades into the grade the quiz reports show.
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attemptids = array_values(array_filter(array_unique(array_column($rows, 'attemptid'))));
        $userids = array_values(array_unique(array_column($rows, 'studentid')));
        $attempts = [];
        foreach (array_chunk($attemptids, self::CHUNK) as $chunk) {
            $attempts += $DB->get_records_list('quiz_attempts', 'id', $chunk, '', 'id, sumgrades');
        }
        $users = [];
        foreach (array_chunk($userids, self::CHUNK) as $chunk) {
            $users += $DB->get_records_list('user', 'id', $chunk, '', 'id, timecreated');
        }

        foreach ($rows as $index => $row) {
            $attempt = $attempts[(int)($row['attemptid'] ?? 0)] ?? null;
            $rows[$index]['scorelabel'] = '';
            $rows[$index]['sortscore'] = -1.0;
            if ($attempt) {
                $rows[$index]['scorelabel'] = $attempt->sumgrades === null
                    ? get_string('overallreport:notgraded', 'quizaccess_proctoring')
                    : get_string('overallreport:scoreoutof', 'quizaccess_proctoring', (object)[
                        'score' => quiz_rescale_grade($attempt->sumgrades, $quiz, true),
                        'max' => format_float($quiz->grade, $quiz->decimalpoints),
                    ]);
                if ($attempt->sumgrades !== null) {
                    $rows[$index]['sortscore'] = (float)$attempt->sumgrades;
                }
            }

            // Keyed off the signup date, not the elapsed time: an account created minutes ago has an
            // age of about zero, which format_time() renders as "now" - the truth, where a blank cell
            // would read as "we do not know".
            $created = isset($users[(int)$row['studentid']]) ? (int)$users[(int)$row['studentid']]->timecreated : 0;
            $rows[$index]['accountage'] = $created > 0 ? format_time(max(0, time() - $created)) : '';
            $rows[$index]['accountcreated'] = display_time::staff($created);
            // Older account first when sorting ascending, so the sort reads the way the column does.
            $rows[$index]['sortaccountage'] = $created > 0 ? $created : PHP_INT_MAX;
        }
    }

    /**
     * Risk score and finding columns, for the given rows only, in bulk.
     *
     * @param array $rows Report rows, updated in place.
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     */
    private static function add_risk(array &$rows, int $courseid, int $cmid): void {
        $requests = [];
        foreach ($rows as $index => $row) {
            $requests[$index] = [
                'courseid' => $courseid,
                'cmid' => $cmid,
                'userid' => (int)$row['studentid'],
                'reportid' => (int)$row['reportid'],
                'attemptid' => (int)$row['attemptid'],
            ];
        }
        $risks = risk_calculator::calculate_many($requests);

        foreach ($rows as $index => $row) {
            $risk = $risks[$index];
            $rows[$index]['riskscore'] = $risk['score'];
            $rows[$index]['risklevel'] = $risk['level'];
            $rows[$index]['riskbadgeclass'] = $risk['badgeclass'];
            $rows[$index]['timetaken'] = $risk['durationformatted'];
            // The findings the report body actually shows: risk factors that scored, minus any a
            // reviewer has already dismissed as a false positive. This is the number the reviewer
            // is counting on screen, so it leads the column and the raw event total sits behind it.
            $findingcount = 0;
            foreach ($risk['factors'] as $factor) {
                if (!empty($factor['falsepositive'])) {
                    continue;
                }
                if ((int)($factor['points'] ?? 0) > 0) {
                    $findingcount++;
                }
            }
            $rows[$index]['findingcount'] = $findingcount;
            $rows[$index]['findinglabel'] = $findingcount > 0
                ? (string)$findingcount
                : get_string('report:nofindings', 'quizaccess_proctoring');
            $rows[$index]['eventcountlabel'] = get_string('report:fromevents', 'quizaccess_proctoring', (int)$row['eventcount']);
            $rows[$index]['findingtitle'] = get_string('report:findingcounttitle', 'quizaccess_proctoring', (object)[
                'findings' => $findingcount,
                'events' => (int)$row['eventcount'],
            ]);
            $rows[$index]['eventwarning'] = $findingcount > 0;
            $rows[$index]['attemptid'] = (int)$risk['attemptid'];
            $rows[$index]['attempturl'] = (int)$risk['attemptid'] > 0
                ? (new moodle_url('/mod/quiz/review.php', ['attempt' => (int)$risk['attemptid']]))->out(false)
                : '';
            // Raw values used only for the PHP-side column sort (Requirement 13.2).
            $rows[$index]['sortriskscore'] = (int)$risk['score'];
            $rows[$index]['sortfindingcount'] = $findingcount;
        }
    }

    /**
     * Hold status, AI review and the row actions, for the rows on the page.
     *
     * @param array $rows Report rows, updated in place.
     * @param int $courseid Course id.
     * @param int $cmid Quiz course-module id.
     * @param \context $context Quiz context.
     * @param moodle_url $baseurl The report's URL.
     */
    private static function add_page_details(array &$rows, int $courseid, int $cmid, \context $context, moodle_url $baseurl): void {
        global $OUTPUT;

        $candelete = has_capability('quizaccess/proctoring:deletecamshots', $context);
        foreach ($rows as $index => $row) {
            $studentid = (int)$row['studentid'];
            $reportid = (int)$row['reportid'];
            $attemptid = (int)$row['attemptid'];

            $hold = quizaccess_proctoring_get_risk_hold($courseid, $cmid, $studentid, $attemptid, $reportid);
            if ($hold) {
                $cert = quizaccess_proctoring_resolve_certificate_label($courseid, $cmid, $studentid, $attemptid, $reportid);
                if ($cert['label'] !== '') {
                    $rows[$index]['riskholdstatus'] = $cert['label'];
                    $rows[$index]['riskholdactive'] = $cert['state'] === 'held' ||
                        ($cert['state'] === 'conflict' && (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE);
                }
            }
            $aireview = quizaccess_proctoring_get_ai_review($courseid, $cmid, $studentid, $attemptid, $reportid);
            if ($aireview) {
                $rows[$index]['aireview'] = quizaccess_proctoring_format_ai_review_for_template($aireview);
            }

            $viewurl = new moodle_url($baseurl, [
                'courseid' => $courseid,
                'quizid' => $cmid,
                'cmid' => $cmid,
                'studentid' => $studentid,
                'reportid' => $reportid,
            ]);

            // View report is the primary, emphasized action (Requirement 18.3): rendered as a
            // prominent primary button rather than hidden inside the kebab menu.
            $viewbutton = html_writer::link(
                $viewurl,
                $OUTPUT->pix_icon('i/report', '', 'moodle') . ' '
                    . get_string('viewimages', 'quizaccess_proctoring'),
                [
                    'class' => 'btn btn-primary btn-sm',
                    'role' => 'button',
                ]
            );

            $deleteform = '';
            if ($candelete) {
                $deleteurl = new moodle_url('/mod/quiz/accessrule/proctoring/report.php');
                $deleteparams = [
                    'courseid' => $courseid,
                    'cmid' => $cmid,
                    'studentid' => $studentid,
                    'reportid' => $reportid,
                    'logaction' => 'delete',
                    'sesskey' => sesskey(),
                ];
                $deleteform = html_writer::start_tag('form', [
                    'method' => 'post',
                    'action' => $deleteurl->out(false),
                    'class' => 'd-inline ml-2',
                ]);
                foreach ($deleteparams as $name => $value) {
                    $deleteform .= html_writer::empty_tag('input', [
                        'type' => 'hidden',
                        'name' => $name,
                        'value' => $value,
                    ]);
                }
                $deleteform .= html_writer::tag(
                    'button',
                    $OUTPUT->pix_icon('t/delete', '') . ' ' . get_string('delete'),
                    [
                        'type' => 'submit',
                        // De-emphasized (Requirement 18.3): a muted link, not a prominent/danger button.
                        // The destructive confirm() guard is retained.
                        'class' => 'btn btn-link btn-sm text-muted p-0',
                        'onclick' => 'return confirm(' . json_encode(get_string(
                            'areyousure_delete_record',
                            'quizaccess_proctoring'
                        )) . ');',
                    ]
                );
                $deleteform .= html_writer::end_tag('form');
            }

            // View report primary/emphasized first, Delete de-emphasized after it (Requirement 18.3).
            $rows[$index]['actionmenu'] = $viewbutton . $deleteform;
        }
    }

    /**
     * The row fields and directions to sort by.
     *
     * The safe ORDER BY fragment comes from the allowlist helper and is translated into an in-PHP
     * sort so PHP-computed columns (risk score, violation count) sort consistently with SQL columns
     * (name, date). Unknown/blank sort keys fall back to newest-first (Requirement 13.1).
     *
     * @param string $sort Sort key from the request.
     * @param string $dir Sort direction from the request.
     * @return array<int, array{0: string, 1: string}> [row field, 'ASC'|'DESC'] pairs
     */
    public static function sort_pairs(string $sort, string $dir): array {
        $pairs = [];
        foreach (explode(',', quizaccess_proctoring_report_order_by($sort, $dir)) as $fragment) {
            $bits = preg_split('/\s+/', trim($fragment));
            if (empty($bits[0]) || !isset(self::SORT_FIELDS[$bits[0]])) {
                continue;
            }
            $direction = (isset($bits[1]) && strtoupper($bits[1]) === 'ASC') ? 'ASC' : 'DESC';
            $pairs[] = [self::SORT_FIELDS[$bits[0]], $direction];
        }
        return $pairs;
    }

    /**
     * Sort the rows in place.
     *
     * @param array $rows Report rows.
     * @param array $sortpairs From {@see self::sort_pairs()}.
     */
    private static function sort_rows(array &$rows, array $sortpairs): void {
        if (empty($sortpairs)) {
            return;
        }
        usort($rows, function ($a, $b) use ($sortpairs) {
            foreach ($sortpairs as [$key, $direction]) {
                $avalue = $a[$key] ?? null;
                $bvalue = $b[$key] ?? null;
                if (is_string($avalue) || is_string($bvalue)) {
                    $cmp = strcmp((string)$avalue, (string)$bvalue);
                } else {
                    $cmp = $avalue <=> $bvalue;
                }
                if ($cmp !== 0) {
                    return $direction === 'ASC' ? $cmp : -$cmp;
                }
            }
            return 0;
        });
    }
}
