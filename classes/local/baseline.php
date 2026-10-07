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

/**
 * The site's live proctoring settings in plain language, and the "start low" pilot profile (CPIT-489).
 *
 * Student Affairs asked for a baseline of what is actually configured: what stops a student from
 * starting, what only flags an attempt for review, and what holds a grade or certificate.
 * {@see self::markdown()} writes that page from the live configuration, read-only.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class baseline {
    /** @var string[] Setting names never printed: credentials and keys. */
    private const SECRET_PATTERNS = ['key', 'secret', 'token', 'password', 'endpoint'];

    /**
     * A setting's stored value, or a label when it was never set.
     *
     * @param string $name Setting name.
     * @return string
     */
    private static function value(string $name): string {
        $value = get_config('quizaccess_proctoring', $name);
        return $value === false || $value === null ? '(default)' : (string)$value;
    }

    /**
     * Whether a 0/1 setting is on, given its default.
     *
     * @param string $name Setting name.
     * @param bool $default Value when it was never set.
     * @return string 'on' or 'off'
     */
    private static function onoff(string $name, bool $default = false): string {
        $value = get_config('quizaccess_proctoring', $name);
        $on = ($value === false || $value === null || $value === '') ? $default : (int)$value === 1;
        return $on ? 'on' : 'off';
    }

    /**
     * The baseline page, in Markdown.
     *
     * @return string
     */
    public static function markdown(): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

        $lines = ['# TaView baseline', '', 'Generated ' . userdate(time()) . ' from ' . $CFG->wwwroot . '.', ''];

        $lines[] = '## What stops a student from starting';
        $lines[] = '';
        $lines[] = '- Privacy notice must be accepted: ' . self::onoff('privacynoticerequired', true);
        $lines[] = '- Honesty statement must be accepted: ' . self::onoff('honorstatementrequired');
        $lines[] = '- CAPTCHA before an attempt: ' . self::onoff('captchabeforeattemptenabled');
        $lines[] = '- Photo ID verification: ' . self::onoff('idverificationenabled') .
            '; face threshold ' . self::value('idverificationfacethreshold') .
            ', name threshold ' . self::value('idverificationnamethreshold') .
            ', name mismatch blocks: ' . self::onoff('idverificationnameblocks');
        $lines[] = '- Face check against the reference photo before starting: ' . self::onoff('fcheckstartchk') .
            '; match threshold ' . self::value('threshold');
        $lines[] = '- Entire screen must be shared: ' . self::onoff('requireentirescreen') .
            '; screen marker required: ' . self::onoff('requirescreenmarker') .
            '; phones and tablets: ' . self::value('mobilescreensharemode');
        $lines[] = '- More than one monitor: mode ' . self::value('multimonitormode');
        $lines[] = '';

        $lines[] = '## What only flags an attempt for review';
        $lines[] = '';
        $lines[] = 'Each factor adds points per event up to its cap. Score cap at 100: ' .
            self::onoff('riskscorecapenabled', true) . '.';
        $lines[] = '';
        $lines[] = '| Factor | Scored | Points per event | Cap |';
        $lines[] = '| --- | --- | --- | --- |';
        foreach (array_keys(risk_calculator::FACTOR_DEFAULTS) as $key) {
            $lines[] = '| ' . get_string('riskscore:' . $key, 'quizaccess_proctoring') . ' | ' .
                (risk_calculator::factor_enabled($key) ? 'yes' : 'no') . ' | ' .
                risk_calculator::factor_points($key) . ' | ' . risk_calculator::factor_cap($key) . ' |';
        }
        $lines[] = '';
        $lines[] = 'Detectors: browser activity ' . self::onoff('monitorbrowseractivity', true) .
            ', desktop captures at events ' . self::onoff('captureviolationdesktop', true) .
            ', multiple faces ' . self::onoff('detectmultiplefaces') .
            ', phone ' . self::onoff('detectphone') .
            ' (model ' . (quizaccess_proctoring_phone_detection_ready() ? 'installed' : 'not installed') . ')' .
            ', blur the quiz without a face ' . self::onoff('blurquizwithoutface') .
            ', speed ' . self::onoff('speedreviewenabled') . '.';
        $bands = risk_calculator::get_level_boundaries();
        $lines[] = '';
        $lines[] = 'Risk bands: moderate from ' . $bands['moderate'] . ', high from ' . $bands['high'] .
            ', critical from ' . $bands['critical'] . '.';
        $lines[] = '';

        $lines[] = '## What holds a grade or certificate';
        $lines[] = '';
        $actions = [0 => 'nothing (flag only)', 1 => 'hold the grade for review', 2 => 'fail the attempt automatically'];
        $action = (int)get_config('quizaccess_proctoring', 'riskreviewenabled');
        $lines[] = '- At or above risk score ' . self::value('riskreviewthreshold') . ': ' . ($actions[$action] ?? $action);
        $lines[] = '- A hold is released automatically after ' . quizaccess_proctoring_get_risk_review_auto_release_days() .
            ' days unless reviewed (0 = never); holds at or above ' . quizaccess_proctoring_get_risk_review_ceiling() .
            ' are kept for review.';
        $lines[] = '- Lockout after a confirmed violation: ' . self::onoff('cheatinglockoutenabled') .
            ' (' . self::value('cheatinglockoutdays') . ' days)';
        $lines[] = '';

        $lines[] = '## Retention';
        $lines[] = '';
        $lines[] = '- Attempt evidence: ' . self::value('imageretentiondays') . ' days';
        $lines[] = '- Photo ID images: ' . self::value('idverificationretentiondays') . ' days';
        $lines[] = '- Reference photos: ' . self::value('referenceretentiondays') . ' days after last use';
        $lines[] = '';

        $lines[] = '## Quizzes with their own settings';
        $lines[] = '';
        $quizzes = $DB->get_records_sql(
            "SELECT p.*, q.name AS quizname, c.shortname
               FROM {quizaccess_proctoring} p
               JOIN {quiz} q ON q.id = p.quizid
               JOIN {course} c ON c.id = q.course
              WHERE p.requireentirescreen <> -1 OR p.captchamode <> -1 OR p.riskreviewmode <> -1
                    OR p.riskreviewthreshold <> -1 OR p.expectedtools <> 0
           ORDER BY c.shortname, q.name"
        );
        if (!$quizzes) {
            $lines[] = 'None: every proctored quiz uses the site settings.';
        }
        foreach ($quizzes as $quiz) {
            $own = [];
            foreach (['requireentirescreen', 'captchamode', 'riskreviewmode', 'riskreviewthreshold'] as $field) {
                if ((int)$quiz->$field !== -1) {
                    $own[] = $field . ' ' . $quiz->$field;
                }
            }
            if ((int)$quiz->expectedtools !== 0) {
                $own[] = 'expectedtools ' . $quiz->expectedtools;
            }
            $lines[] = '- ' . format_string($quiz->shortname) . ', ' . format_string($quiz->quizname) . ': ' . implode(', ', $own);
        }
        $lines[] = '';
        $lines[] = 'Active per-student overrides: ' . $DB->count_records_select(
            'quizaccess_proctoring_overrides',
            'revoked = 0 AND (expiry IS NULL OR expiry = 0 OR expiry > :now)',
            ['now' => time()]
        ) . '.';
        $lines[] = '';

        $lines[] = '## Every setting';
        $lines[] = '';
        $lines[] = '| Setting | Value |';
        $lines[] = '| --- | --- |';
        $config = (array)get_config('quizaccess_proctoring');
        ksort($config);
        foreach ($config as $name => $value) {
            if (self::is_secret((string)$name)) {
                continue;
            }
            $value = str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], \core_text::substr(strip_tags((string)$value), 0, 120));
            $lines[] = '| ' . $name . ' | ' . $value . ' |';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Whether a setting holds a credential or a private address, which the baseline never shows.
     *
     * @param string $name Setting name.
     * @return bool
     */
    public static function is_secret(string $name): bool {
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (stripos($name, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The "start low" pilot profile: hold only Critical attempts for review, never fail one.
     *
     * Only the grade action changes; detectors and what blocks a student stay as they are.
     *
     * @return array<string, array{0: string, 1: string}> Setting => [old value, new value].
     */
    public static function apply_pilot_profile(): array {
        $critical = risk_calculator::get_level_boundaries()['critical'];
        $target = [
            'riskreviewenabled' => (string)QUIZACCESS_PROCTORING_RISK_ACTION_HOLD,
            'riskreviewthreshold' => (string)$critical,
        ];
        $changes = [];
        foreach ($target as $name => $value) {
            $old = (string)get_config('quizaccess_proctoring', $name);
            if ($old !== $value) {
                set_config($name, $value, 'quizaccess_proctoring');
                $changes[$name] = [$old, $value];
            }
        }
        return $changes;
    }
}
