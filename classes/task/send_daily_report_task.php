<?php
// This file is part of Moodle - http://moodle.org/.
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

namespace quizaccess_proctoring\task;

use context_module;
use core\task\scheduled_task;
use core_user;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Sends a daily Saylor Proctored Quiz risk report.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_daily_report_task extends scheduled_task {
    /** @var int Maximum attempt rows rendered in the email body. */
    private const MAX_ROWS = 100;

    /**
     * Returns task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:send_daily_report', 'quizaccess_proctoring');
    }

    /**
     * Sends the daily report email if enabled.
     *
     * @return void
     */
    public function execute() {
        $enabled = (int)get_config('quizaccess_proctoring', 'dailyreportenabled');
        if ($enabled !== 1) {
            mtrace('Saylor Proctored Quiz daily report is disabled.');
            return;
        }

        $recipients = $this->get_recipients((string)get_config('quizaccess_proctoring', 'dailyreportemails'));
        if (empty($recipients)) {
            mtrace('Saylor Proctored Quiz daily report has no valid recipient emails.');
            return;
        }

        $sendempty = (int)get_config('quizaccess_proctoring', 'dailyreportsendempty') === 1;
        $end = time();
        $start = $end - DAYSECS;
        $from = core_user::get_noreply_user();
        $replyto = \quizaccess_proctoring\local\support_contact::address();
        $replytoname = $replyto === '' ? '' : \quizaccess_proctoring\local\support_contact::name();

        $sent = 0;
        $skippedempty = 0;
        foreach ($recipients as $recipient) {
            $data = $this->build_report_data($start, $end, $recipient);
            if (empty($data['rows']) && !$sendempty) {
                $skippedempty++;
                continue;
            }

            $subject = get_string('dailyreport:subject', 'quizaccess_proctoring', userdate($end, '%Y-%m-%d'));
            $messagetext = $this->render_text_report($data, $start, $end);
            $messagehtml = $this->render_html_report($data, $start, $end);
            // Recipients reply to the report asking about a specific attempt, so Reply-To
            // points at the staffed address rather than the site noreply address.
            if (email_to_user(
                $recipient,
                $from,
                $subject,
                $messagetext,
                $messagehtml,
                '',
                '',
                true,
                $replyto,
                $replytoname
            )) {
                $sent++;
            }
        }

        mtrace("Sent Saylor Proctored Quiz daily report to {$sent} recipient(s).");
        if ($skippedempty > 0) {
            mtrace("Skipped {$skippedempty} recipient(s) with no authorized report rows.");
        }
    }

    /**
     * Parses recipient emails into user objects accepted by email_to_user().
     *
     * @param string $rawemails Raw setting value.
     * @return array
     */
    private function get_recipients(string $rawemails): array {
        global $CFG;

        $allowexternal = (int)get_config('quizaccess_proctoring', 'dailyreportallowexternal') === 1;
        $emails = preg_split('/[\s,;]+/', trim($rawemails), -1, PREG_SPLIT_NO_EMPTY);
        $emails = array_unique(array_map('strtolower', $emails ?: []));
        $recipients = [];

        foreach ($emails as $email) {
            if (!validate_email($email)) {
                mtrace("Skipping invalid daily report recipient email: {$email}");
                continue;
            }

            $user = core_user::get_user_by_email($email);
            if ($user) {
                if (!empty($user->deleted) || !empty($user->suspended) || empty($user->confirmed)) {
                    mtrace("Skipping inactive daily report recipient Moodle user: {$email}");
                    continue;
                }

                $recipients[] = $user;
                continue;
            }

            if (!$allowexternal) {
                mtrace("Skipping external daily report recipient email because external recipients are disabled: {$email}");
                continue;
            }

            $recipients[] = (object)[
                'id' => -1,
                'email' => $email,
                'firstname' => get_string('dailyreport:recipientfirstname', 'quizaccess_proctoring'),
                'lastname' => get_string('dailyreport:recipientlastname', 'quizaccess_proctoring'),
                'maildisplay' => 1,
                'mailformat' => 1,
                'deleted' => 0,
                'suspended' => 0,
                'auth' => 'manual',
                'mnethostid' => $CFG->mnet_localhost_id ?? 1,
                'confirmed' => 1,
                'quizaccessproctoringexternal' => true,
            ];
        }

        return $recipients;
    }

    /**
     * Builds report rows and summary numbers.
     *
     * @param int $start Start timestamp.
     * @param int $end End timestamp.
     * @param stdClass|null $recipient Recipient used to scope report rows.
     * @return array
     */
    private function build_report_data(int $start, int $end, ?stdClass $recipient = null): array {
        $attempts = $this->get_recent_attempts($start, $end);
        foreach ($this->get_active_hold_attempts() as $key => $attempt) {
            $attempts[$key] = $attempts[$key] ?? $attempt;
            $attempts[$key]->fromactivehold = true;
        }

        $includeall = (int)get_config('quizaccess_proctoring', 'dailyreportincludeall') === 1;
        $rows = [];
        $summary = [
            'recentattempts' => 0,
            'reportrows' => 0,
            'highrisk' => 0,
            'activeholds' => 0,
            'events' => 0,
            'truncated' => 0,
        ];

        foreach ($attempts as $attempt) {
            if ($recipient && !$this->recipient_can_view_attempt($recipient, $attempt)) {
                continue;
            }

            $row = $this->build_attempt_row($attempt);
            if (empty($attempt->fromactivehold)) {
                $summary['recentattempts']++;
            }
            $summary['events'] += $row['eventcount'];
            if ($row['activehold']) {
                $summary['activeholds']++;
            }
            if ($row['highrisk']) {
                $summary['highrisk']++;
            }

            if (!$includeall && !$row['highrisk'] && !$row['activehold']) {
                continue;
            }

            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['activehold'], $b['riskscore'], $b['lastactivity']] <=>
                [$a['activehold'], $a['riskscore'], $a['lastactivity']];
        });

        $summary['reportrows'] = count($rows);
        if (count($rows) > self::MAX_ROWS) {
            $summary['truncated'] = count($rows) - self::MAX_ROWS;
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * Checks whether a recipient can receive a row for the supplied attempt.
     *
     * @param stdClass $recipient Recipient user object.
     * @param stdClass $attempt Attempt record.
     * @return bool
     */
    private function recipient_can_view_attempt(stdClass $recipient, stdClass $attempt): bool {
        if (!empty($recipient->quizaccessproctoringexternal)) {
            return true;
        }

        if (empty($recipient->id) || (int)$recipient->id < 1) {
            return false;
        }

        $context = context_module::instance((int)$attempt->cmid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        return has_capability('quizaccess/proctoring:viewreport', $context, (int)$recipient->id);
    }

    /**
     * Gets attempts with proctoring activity in the report window.
     *
     * @param int $start Start timestamp.
     * @param int $end End timestamp.
     * @return array
     */
    private function get_recent_attempts(int $start, int $end): array {
        global $DB;

        $sql = "SELECT MIN(e.id) AS reportid,
                       e.courseid,
                       e.quizid AS cmid,
                       e.userid,
                       e.status AS attemptid,
                       MAX(e.timemodified) AS lastactivity,
                       u.firstname,
                       u.lastname,
                       u.firstnamephonetic,
                       u.lastnamephonetic,
                       u.middlename,
                       u.alternatename,
                       u.email,
                       c.fullname AS coursename,
                       q.name AS quizname
                  FROM {quizaccess_proctoring_logs} e
                  JOIN {user} u ON u.id = e.userid
                  JOIN {course_modules} cm ON cm.id = e.quizid
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {course} c ON c.id = e.courseid
                 WHERE e.timemodified >= :starttime
                   AND e.timemodified < :endtime
                   AND e.deletionprogress = :deletionprogress
                   AND e.status > 0
              GROUP BY e.courseid, e.quizid, e.userid, e.status,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       u.email, c.fullname, q.name";

        $records = $DB->get_records_sql($sql, [
            'starttime' => $start,
            'endtime' => $end,
            'deletionprogress' => 0,
        ]);

        return $this->index_attempt_records($records);
    }

    /**
     * Gets all active review holds so unresolved items remain visible daily.
     *
     * @return array
     */
    private function get_active_hold_attempts(): array {
        global $DB;

        $sql = "SELECT h.id,
                       h.reportid,
                       h.courseid,
                       h.quizid AS cmid,
                       h.userid,
                       h.attemptid,
                       h.timemodified AS lastactivity,
                       u.firstname,
                       u.lastname,
                       u.firstnamephonetic,
                       u.lastnamephonetic,
                       u.middlename,
                       u.alternatename,
                       u.email,
                       c.fullname AS coursename,
                       q.name AS quizname
                  FROM {quizaccess_proctoring_risk_holds} h
                  JOIN {user} u ON u.id = h.userid
                  JOIN {course_modules} cm ON cm.id = h.quizid
                  JOIN {quiz} q ON q.id = h.quizinstance
                  JOIN {course} c ON c.id = h.courseid
                 WHERE h.status = :status";

        $records = $DB->get_records_sql($sql, ['status' => 0]);
        return $this->index_attempt_records($records);
    }

    /**
     * Indexes attempt records by the attempt/report identity.
     *
     * @param array $records Records.
     * @return array
     */
    private function index_attempt_records(array $records): array {
        $indexed = [];
        foreach ($records as $record) {
            $key = implode(':', [
                (int)$record->courseid,
                (int)$record->cmid,
                (int)$record->userid,
                (int)$record->attemptid,
                (int)$record->reportid,
            ]);
            $indexed[$key] = $record;
        }

        return $indexed;
    }

    /**
     * Builds one rendered attempt row.
     *
     * @param stdClass $attempt Attempt record.
     * @return array
     */
    private function build_attempt_row(stdClass $attempt): array {
        global $CFG, $DB;

        $risk = quizaccess_proctoring_calculate_attempt_risk(
            (int)$attempt->courseid,
            (int)$attempt->cmid,
            (int)$attempt->userid,
            (int)$attempt->reportid
        );
        $settings = quizaccess_proctoring_get_effective_risk_review_settings((int)$attempt->cmid);
        $hold = quizaccess_proctoring_get_risk_hold(
            (int)$attempt->courseid,
            (int)$attempt->cmid,
            (int)$attempt->userid,
            (int)$attempt->attemptid,
            (int)$attempt->reportid
        );
        $activehold = $hold && (int)$hold->status === QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE;
        $eventcount = $DB->count_records('quizaccess_proctoring_events', [
            'courseid' => (int)$attempt->courseid,
            'quizid' => (int)$attempt->cmid,
            'userid' => (int)$attempt->userid,
            'attemptid' => (int)$attempt->attemptid,
        ]);
        $capturecount = $DB->count_records('quizaccess_proctoring_logs', [
            'courseid' => (int)$attempt->courseid,
            'quizid' => (int)$attempt->cmid,
            'userid' => (int)$attempt->userid,
            'status' => (int)$attempt->attemptid,
            'deletionprogress' => 0,
        ]);

        $reporturl = new moodle_url('/mod/quiz/accessrule/proctoring/report.php', [
            'courseid' => (int)$attempt->courseid,
            'cmid' => (int)$attempt->cmid,
            'studentid' => (int)$attempt->userid,
            'reportid' => (int)$attempt->reportid,
        ]);

        return [
            'courseid' => (int)$attempt->courseid,
            'cmid' => (int)$attempt->cmid,
            'course' => (string)$attempt->coursename,
            'quiz' => (string)$attempt->quizname,
            'student' => fullname($attempt),
            'email' => (string)$attempt->email,
            'riskscore' => (int)$risk['score'],
            'risklevel' => (string)$risk['level'],
            'threshold' => (int)$settings['threshold'],
            'highrisk' => (int)$risk['score'] >= (int)$settings['threshold'],
            'activehold' => $activehold,
            'holdstatus' => $hold
                ? quizaccess_proctoring_get_risk_hold_status_label($hold)
                : get_string('dailyreport:nohold', 'quizaccess_proctoring'),
            'eventcount' => $eventcount,
            'capturecount' => $capturecount,
            'lastactivity' => (int)$attempt->lastactivity,
            'lastactivityformatted' => userdate((int)$attempt->lastactivity),
            'reporturl' => $CFG->wwwroot . $reporturl->out_as_local_url(false),
        ];
    }

    /**
     * Renders a plain text report.
     *
     * @param array $data Report data.
     * @param int $start Start timestamp.
     * @param int $end End timestamp.
     * @return string
     */
    private function render_text_report(array $data, int $start, int $end): string {
        $summary = $data['summary'];
        $lines = [
            get_string('dailyreport:title', 'quizaccess_proctoring'),
            get_string('dailyreport:range', 'quizaccess_proctoring', (object)[
                'start' => userdate($start),
                'end' => userdate($end),
            ]),
            '',
            get_string('dailyreport:summaryrecent', 'quizaccess_proctoring', $summary['recentattempts']),
            get_string('dailyreport:summaryincluded', 'quizaccess_proctoring', $summary['reportrows']),
            get_string('dailyreport:summaryhighrisk', 'quizaccess_proctoring', $summary['highrisk']),
            get_string('dailyreport:summaryactiveholds', 'quizaccess_proctoring', $summary['activeholds']),
            get_string('dailyreport:summaryevents', 'quizaccess_proctoring', $summary['events']),
            '',
        ];

        if (empty($data['rows'])) {
            $lines[] = get_string('dailyreport:noattempts', 'quizaccess_proctoring');
            return implode("\n", $lines);
        }

        foreach ($data['rows'] as $row) {
            $lines[] = "{$row['riskscore']}/100 {$row['risklevel']} | {$row['holdstatus']} | {$row['student']} <{$row['email']}>";
            $lines[] = "{$row['course']} / {$row['quiz']}";
            $lines[] = get_string('dailyreport:textdetails', 'quizaccess_proctoring', (object)[
                'events' => $row['eventcount'],
                'captures' => $row['capturecount'],
                'threshold' => $row['threshold'],
                'lastactivity' => $row['lastactivityformatted'],
            ]);
            $lines[] = $row['reporturl'];
            $lines[] = '';
        }

        if ($summary['truncated'] > 0) {
            $lines[] = get_string('dailyreport:truncated', 'quizaccess_proctoring', $summary['truncated']);
        }

        return implode("\n", $lines);
    }

    /**
     * Renders an HTML report.
     *
     * @param array $data Report data.
     * @param int $start Start timestamp.
     * @param int $end End timestamp.
     * @return string
     */
    private function render_html_report(array $data, int $start, int $end): string {
        $summary = $data['summary'];
        $html = '<h2>' . s(get_string('dailyreport:title', 'quizaccess_proctoring')) . '</h2>';
        $html .= '<p>' . s(get_string('dailyreport:range', 'quizaccess_proctoring', (object)[
            'start' => userdate($start),
            'end' => userdate($end),
        ])) . '</p>';
        $html .= '<ul>';
        $html .= '<li>' . s(get_string('dailyreport:summaryrecent', 'quizaccess_proctoring', $summary['recentattempts'])) . '</li>';
        $html .= '<li>' . s(get_string('dailyreport:summaryincluded', 'quizaccess_proctoring', $summary['reportrows'])) . '</li>';
        $html .= '<li>' . s(get_string('dailyreport:summaryhighrisk', 'quizaccess_proctoring', $summary['highrisk'])) . '</li>';
        $html .= '<li>' .
            s(get_string('dailyreport:summaryactiveholds', 'quizaccess_proctoring', $summary['activeholds'])) . '</li>';
        $html .= '<li>' . s(get_string('dailyreport:summaryevents', 'quizaccess_proctoring', $summary['events'])) . '</li>';
        $html .= '</ul>';

        if (empty($data['rows'])) {
            return $html . '<p>' . s(get_string('dailyreport:noattempts', 'quizaccess_proctoring')) . '</p>';
        }

        $html .= '<table border="1" cellpadding="6" cellspacing="0">';
        $html .= '<thead><tr>';
        foreach (['student', 'course', 'quiz', 'risk', 'hold', 'events', 'captures', 'lastactivity', 'report'] as $column) {
            $html .= '<th>' . s(get_string('dailyreport:col' . $column, 'quizaccess_proctoring')) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($data['rows'] as $row) {
            $html .= '<tr>';
            $html .= '<td>' . s($row['student']) . '<br><small>' . s($row['email']) . '</small></td>';
            $html .= '<td>' . s($row['course']) . '</td>';
            $html .= '<td>' . s($row['quiz']) . '</td>';
            $html .= '<td><strong>' . s($row['riskscore'] . '/100') . '</strong><br><small>' .
                s($row['risklevel'] . ' / ' . get_string('dailyreport:threshold', 'quizaccess_proctoring', $row['threshold'])) .
                '</small></td>';
            $html .= '<td>' . s($row['holdstatus']) . '</td>';
            $html .= '<td>' . s((string)$row['eventcount']) . '</td>';
            $html .= '<td>' . s((string)$row['capturecount']) . '</td>';
            $html .= '<td>' . s($row['lastactivityformatted']) . '</td>';
            $html .= '<td><a href="' . s($row['reporturl']) . '">' .
                s(get_string('dailyreport:viewreport', 'quizaccess_proctoring')) . '</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        if ($summary['truncated'] > 0) {
            $html .= '<p>' . s(get_string('dailyreport:truncated', 'quizaccess_proctoring', $summary['truncated'])) . '</p>';
        }

        return $html;
    }
}
