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
 * The emails and notifications TaView sends students, and the wording staff gave them (CPIT-512).
 *
 * @package quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Catalogue of the student messages Student Affairs can reword in Moodle, and their wording.
 *
 * Every message is still a language string; editing one here stores its new wording in the
 * plugin's own table, which wins over the language string - including over Language
 * customisation - until it is reset. Staff write placeholders as [quiz] or [course]; they are
 * stored as the language string's own {$a->quiz} tokens, so the text is filled in exactly as the
 * shipped string would be. An edit must keep every placeholder the shipped text has.
 *
 * Only the words are stored: the edited text is used for every recipient, whatever their
 * language, and in-exam warnings stay on Language customisation.
 */
final class message_texts {
    /** @var string Capability that lets staff edit the wording. */
    const CAPABILITY = 'quizaccess/proctoring:editstudentmessages';

    /** @var int Longest subject that can be saved. */
    const MAX_SUBJECT = 255;

    /** @var int Longest body that can be saved. */
    const MAX_BODY = 4000;

    /**
     * The messages, in the order a student meets them.
     *
     * channel: the Moodle message provider (sent as a notification and an email as the student's
     * notification preferences allow), or 'email' for a message only ever emailed. settings: the
     * plugin settings that decide whether it is sent at all. parts: the language strings staff can
     * reword. scalar: the placeholder name of a string's bare {$a}. optional: placeholders staff
     * may add on top of {@see self::OPTIONAL}, which the shipped wording does not use.
     */
    const MESSAGES = [
        'held' => [
            'channel' => 'holdcreated',
            'settings' => ['holdcreatednotify'],
            'parts' => ['subject' => 'message:heldsubject', 'body' => 'message:heldbody'],
            // The subjects name no exam; a student with two exams in review can tell which (CPIT-512).
            'optional' => ['quiz', 'course'],
        ],
        'released' => [
            'channel' => 'holddecision',
            'settings' => ['holddecisionnotify', 'holddecisionnotifyautorelease'],
            'parts' => ['subject' => 'message:releasedsubject', 'body' => 'message:releasedbody'],
            'optional' => ['quiz', 'course'],
        ],
        'confirmed' => [
            'channel' => 'holddecision',
            'settings' => ['holddecisionnotify'],
            'parts' => ['subject' => 'message:confirmedsubject', 'body' => 'message:confirmedbody'],
            'optional' => ['quiz', 'course'],
        ],
        'autofailed' => [
            'channel' => 'holddecision',
            'settings' => ['holddecisionnotify', 'riskreviewenabled'],
            'parts' => ['subject' => 'message:autofailedsubject', 'body' => 'message:autofailedbody'],
            'optional' => ['quiz', 'course'],
        ],
        // Shares its subject with 'autofailed'.
        'facestrikesautofailed' => [
            'channel' => 'holddecision',
            'settings' => ['holddecisionnotify', 'facestrikes', 'facestrikesoutcome'],
            'parts' => ['body' => 'message:facestrikesautofailedbody'],
            'optional' => ['quiz', 'course'],
        ],
        'photoreset' => [
            'channel' => 'referencereset',
            'settings' => [],
            'parts' => ['subject' => 'referencereset:donesubject', 'body' => 'referencereset:donebody'],
        ],
        'photodeclined' => [
            'channel' => 'referencereset',
            'settings' => [],
            'parts' => ['subject' => 'referencereset:declinedsubject', 'body' => 'referencereset:declinedbody'],
        ],
        'photoreplaced' => [
            'channel' => 'referencereset',
            'settings' => [],
            'parts' => ['subject' => 'referencereset:replacedsubject', 'body' => 'referencereset:replacedbody'],
        ],
        'idexceptionapproved' => [
            'channel' => 'email',
            'settings' => [],
            'parts' => ['subject' => 'idexemptionemail:approved:subject', 'body' => 'idexemptionemail:approved:intro'],
            'scalar' => 'quiz',
            'optional' => ['course'],
        ],
        'idexceptiondeclined' => [
            'channel' => 'email',
            'settings' => [],
            'parts' => ['subject' => 'idexemptionemail:declined:subject', 'body' => 'idexemptionemail:declined:intro'],
            'scalar' => 'quiz',
            'optional' => ['course'],
        ],
    ];

    /**
     * Placeholders every message can use although its shipped wording does not: a greeting by
     * name (CPIT-512, Student Affairs). Staff may add or leave them out.
     */
    const OPTIONAL = ['firstname', 'fullname'];

    /**
     * Values of the optional placeholders that come from the student.
     *
     * @param \stdClass $user The student.
     * @return array Value by placeholder name.
     */
    public static function student_values(\stdClass $user): array {
        return [
            'firstname' => (string)($user->firstname ?? ''),
            'fullname' => fullname($user),
        ];
    }

    /**
     * A message's wording, filled in: the edited text when staff changed it, the language string
     * otherwise. Use it wherever one of the catalogue's strings is sent to a student.
     *
     * @param string $key Language string key.
     * @param mixed $a Placeholder values, as for get_string().
     * @param string|null $lang Language for the language string; the edited text has one wording.
     * @param array $optional Values of the optional placeholders, by name; only edited text has them.
     * @return string Text.
     */
    public static function get(string $key, $a = null, ?string $lang = null, array $optional = []): string {
        $stored = self::locate($key) === null ? null : self::stored($key);
        if ($stored === null) {
            return get_string_manager()->get_string($key, 'quizaccess_proctoring', $a, $lang);
        }
        return self::fill(self::fill($stored, $a), (object)$optional);
    }

    /**
     * Fill a text's placeholders the way get_string() does.
     *
     * @param string $text Text with {$a} or {$a->name} tokens.
     * @param mixed $a Placeholder values.
     * @return string Filled text.
     */
    public static function fill(string $text, $a): string {
        if ($a === null) {
            return $text;
        }
        if (is_scalar($a)) {
            return str_replace('{$a}', (string)$a, $text);
        }
        $values = [];
        foreach ((array)$a as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $values['{$a->' . $name . '}'] = (string)$value;
            }
        }
        return strtr($text, $values);
    }

    /**
     * Web and email addresses in a message, which become links in its HTML version.
     *
     * Kept in step with the preview on the edit page (amd/src/messagePreview.js).
     */
    const LINK_PATTERN = '~(https?://[^\s<>"\']+[^\s<>"\'.,;:!?)]|[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})~';

    /**
     * The HTML version of a plain-text message: escaped, with web and email addresses as links
     * and each line break kept, so a list typed one item per line arrives as one.
     *
     * @param string $text Plain text.
     * @param bool $wrap Whether to wrap it in a div, as text_to_html() does.
     * @return string HTML.
     */
    public static function html(string $text, bool $wrap = true): string {
        $html = '';
        foreach (preg_split(self::LINK_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $piece) {
            if ($i % 2 === 0) {
                $html .= s($piece);
                continue;
            }
            $href = preg_match('~^https?://~i', $piece) ? $piece : 'mailto:' . $piece;
            $html .= \html_writer::link($href, s($piece));
        }
        $html = nl2br($html);
        return $wrap ? \html_writer::div($html, 'text_to_html') : $html;
    }

    /**
     * Sample values for the edit page's preview, by placeholder name.
     *
     * The ones that depend on settings - the review window, the questions sentence and the
     * support address - are what a student would receive today.
     *
     * @return array For example ['quiz' => 'Final Exam'].
     */
    public static function samples(): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
        $component = 'quizaccess_proctoring';
        $days = quizaccess_proctoring_get_risk_review_auto_release_days();
        $contact = support_contact::address();
        return [
            'firstname' => get_string('messagetexts:samplefirstname', $component),
            'fullname' => get_string('messagetexts:samplefullname', $component),
            'quiz' => get_string('messagetexts:samplequiz', $component),
            'course' => get_string('messagetexts:samplecourse', $component),
            'window' => $days > 0 ? get_string('message:heldwindow', $component, $days) : '',
            'questions' => support_contact::questions_sentence(),
            'contact' => $contact !== '' ? $contact : get_string('messagetexts:samplecontact', $component),
        ];
    }

    /**
     * What a placeholder becomes when the message is sent.
     *
     * @param string $name Placeholder name.
     * @return string Description.
     */
    public static function describe(string $name): string {
        $key = 'messagetexts:placeholder_' . $name;
        return get_string_manager()->string_exists($key, 'quizaccess_proctoring')
            ? get_string($key, 'quizaccess_proctoring')
            : '';
    }

    /**
     * The wording staff stored for a string, or null when it is not edited.
     *
     * @param string $key Language string key.
     * @return string|null Stored text, with {$a} tokens.
     */
    public static function stored(string $key): ?string {
        global $DB;

        $text = $DB->get_field('quizaccess_proctoring_msg_texts', 'text', ['stringid' => $key]);
        return $text === false ? null : (string)$text;
    }

    /**
     * The message and part a string belongs to, or null when it is not in the catalogue.
     *
     * @param string $key Language string key.
     * @return array|null [message id, part name].
     */
    public static function locate(string $key): ?array {
        foreach (self::MESSAGES as $id => $message) {
            foreach ($message['parts'] as $part => $stringid) {
                if ($stringid === $key) {
                    return [$id, $part];
                }
            }
        }
        return null;
    }

    /**
     * The shipped wording, with {$a} tokens: the language string as Language customisation has
     * it, in the site language.
     *
     * @param string $key Language string key.
     * @return string Text.
     */
    public static function default_text(string $key): string {
        return get_string_manager()->get_string($key, 'quizaccess_proctoring');
    }

    /**
     * The placeholders a string's shipped wording has, token => name.
     *
     * @param string $key Language string key.
     * @return array For example ['{$a->quiz}' => 'quiz'].
     */
    public static function placeholders(string $key): array {
        $located = self::locate($key);
        $scalar = $located ? (self::MESSAGES[$located[0]]['scalar'] ?? 'value') : 'value';
        preg_match_all('/\{\$a(?:->([a-z0-9_]+))?\}/i', self::default_text($key), $matches, PREG_SET_ORDER);
        $placeholders = [];
        foreach ($matches as $match) {
            $placeholders[$match[0]] = isset($match[1]) && $match[1] !== '' ? $match[1] : $scalar;
        }
        return $placeholders;
    }

    /**
     * The placeholders staff may add to a string, token => name: those its shipped wording does
     * not already have.
     *
     * @param string $key Language string key.
     * @return array For example ['{$a->firstname}' => 'firstname'].
     */
    public static function optional_placeholders(string $key): array {
        $located = self::locate($key);
        $names = array_merge(self::OPTIONAL, $located ? (self::MESSAGES[$located[0]]['optional'] ?? []) : []);
        $names = array_diff($names, array_values(self::placeholders($key)));
        $optional = [];
        foreach ($names as $name) {
            $optional['{$a->' . $name . '}'] = $name;
        }
        return $optional;
    }

    /**
     * Every placeholder a string can use, token => name: the ones it must keep, then the optional ones.
     *
     * @param string $key Language string key.
     * @return array Name by token.
     */
    public static function all_placeholders(string $key): array {
        return self::placeholders($key) + self::optional_placeholders($key);
    }

    /**
     * Wording as staff see it, with [name] placeholders.
     *
     * @param string $key Language string key.
     * @param string|null $text Text with {$a} tokens; the current wording when null.
     * @return string Text.
     */
    public static function to_editor(string $key, ?string $text = null): string {
        $text = $text ?? (self::stored($key) ?? self::default_text($key));
        $replace = [];
        foreach (self::all_placeholders($key) as $token => $name) {
            $replace[$token] = '[' . $name . ']';
        }
        return strtr($text, $replace);
    }

    /**
     * Wording as stored, with the language string's {$a} tokens.
     *
     * @param string $key Language string key.
     * @param string $text Text with [name] placeholders.
     * @return string Text.
     */
    public static function from_editor(string $key, string $text): string {
        $replace = [];
        foreach (self::all_placeholders($key) as $token => $name) {
            $replace['[' . $name . ']'] = $token;
        }
        return strtr($text, $replace);
    }

    /**
     * Check an edit: what is wrong with it, or '' when it can be saved.
     *
     * @param string $key Language string key.
     * @param string $text Text with [name] placeholders.
     * @param bool $issubject Whether it is a subject line.
     * @return string Error message, or ''.
     */
    public static function validate(string $key, string $text, bool $issubject): string {
        $text = trim($text);
        if ($text === '') {
            return get_string('messagetexts:errorempty', 'quizaccess_proctoring');
        }
        $max = $issubject ? self::MAX_SUBJECT : self::MAX_BODY;
        if (\core_text::strlen($text) > $max) {
            return get_string('messagetexts:errorlength', 'quizaccess_proctoring', $max);
        }
        if ($issubject && preg_match('/[\r\n]/', $text)) {
            return get_string('messagetexts:errorsubjectline', 'quizaccess_proctoring');
        }
        $required = array_unique(array_values(self::placeholders($key)));
        $missing = [];
        foreach ($required as $name) {
            if (strpos($text, '[' . $name . ']') === false) {
                $missing[] = '[' . $name . ']';
            }
        }
        if ($missing) {
            return get_string('messagetexts:errormissing', 'quizaccess_proctoring', implode(', ', $missing));
        }
        // A bracketed word that is no placeholder of this message is most likely a misspelled one,
        // which would reach the student as written.
        $names = array_unique(array_values(self::all_placeholders($key)));
        preg_match_all('/\[([a-z0-9_]+)\]/', $text, $matches);
        $unknown = array_diff(array_unique($matches[1]), $names);
        if ($unknown) {
            return get_string('messagetexts:errorunknown', 'quizaccess_proctoring', (object)[
                'unknown' => '[' . implode('], [', $unknown) . ']',
                'allowed' => $names ? '[' . implode('], [', $names) . ']' : '-',
            ]);
        }
        return '';
    }

    /**
     * Store new wording for a string. Wording that matches the shipped text resets it.
     *
     * @param string $key Language string key.
     * @param string $text Text with [name] placeholders, already validated.
     * @param int $userid Who edited it.
     */
    public static function save(string $key, string $text, int $userid): void {
        global $DB;

        $stored = self::from_editor($key, trim($text));
        if ($stored === self::default_text($key)) {
            self::reset($key);
            return;
        }
        $existing = $DB->get_record('quizaccess_proctoring_msg_texts', ['stringid' => $key]);
        $record = (object)[
            'stringid' => $key,
            'text' => $stored,
            'usermodified' => $userid,
            'timemodified' => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('quizaccess_proctoring_msg_texts', $record);
        } else {
            $DB->insert_record('quizaccess_proctoring_msg_texts', $record);
        }
    }

    /**
     * Go back to the shipped wording.
     *
     * @param string $key Language string key.
     */
    public static function reset(string $key): void {
        global $DB;

        $DB->delete_records('quizaccess_proctoring_msg_texts', ['stringid' => $key]);
    }

    /**
     * Who last edited a string and when, or null when it is not edited.
     *
     * @param string $key Language string key.
     * @return \stdClass|null Record with usermodified and timemodified.
     */
    public static function edit_record(string $key): ?\stdClass {
        global $DB;

        $record = $DB->get_record('quizaccess_proctoring_msg_texts', ['stringid' => $key], 'id, usermodified, timemodified');
        return $record ?: null;
    }

    /**
     * Whether the current user may see the messages page.
     *
     * Its readers are the staff who already decide what the messages announce, and the editors.
     *
     * @return bool
     */
    public static function can_view(): bool {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
        return has_any_capability([self::CAPABILITY, 'quizaccess/proctoring:reviewacrosscourses'], \context_system::instance())
            || quizaccess_proctoring_can_manage_admin_settings();
    }

    /**
     * Whether the current user may reword the messages.
     *
     * @return bool
     */
    public static function can_edit(): bool {
        return has_capability(self::CAPABILITY, \context_system::instance());
    }
}
