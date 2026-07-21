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
 * Builds and sends the ID verification exception emails.
 *
 * All four notifications (staff request alert, student confirmation, approval,
 * decline) share one send-ready table-based HTML shell — logo header, accent
 * card, details panel, optional button — from the approved "ID Exception
 * Request Email" design, plus a plain-text alternative built from the same
 * strings. Every message is localized to its recipient's language.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exemption_email {
    /** @var string Logo shown in the email header. */
    const LOGO_URL = 'https://resources.saylor.org/logos/saylor-university.png';

    /** @var string Brand name shown in the email footer. */
    const BRAND_NAME = 'Saylor University';

    /** @var string Postal address shown in the email footer. */
    const BRAND_ADDRESS = '1041 SE 17th Street, Suite 100, Fort Lauderdale, Florida 33316';

    /** @var string Accent for informational messages (request, received). */
    const ACCENT_BLUE = '#0f6cbf';

    /** @var string Accent for the approval message. */
    const ACCENT_GREEN = '#357a32';

    /** @var string Accent for the decline message. */
    const ACCENT_RED = '#b3423a';

    /**
     * Emails the configured contact about a new exception request.
     *
     * @param string $contact Contact email address (already validated).
     * @param \stdClass $student Requesting student.
     * @param string $coursename Formatted course name.
     * @param string $quizname Formatted quiz name.
     * @param int $cmid Quiz course module id.
     * @param int $requesttime Request timestamp.
     * @return bool Whether the email was accepted for delivery.
     */
    public static function notify_staff_request(
        string $contact,
        \stdClass $student,
        string $coursename,
        string $quizname,
        int $cmid,
        int $requesttime
    ): bool {
        $lang = self::site_language();
        $overridesurl = new \moodle_url('/mod/quiz/accessrule/proctoring/manage_overrides.php', ['cmid' => $cmid]);
        $names = (object)['student' => fullname($student), 'quiz' => $quizname];

        return self::email(self::external_recipient($contact, self::str($lang, 'idexemptioncontactname')), [
            'subject' => self::str($lang, 'idexemptionemailsubject', $names),
            'preheader' => self::str($lang, 'idexemptionemail:staff:preheader', $names),
            'accent' => self::ACCENT_BLUE,
            'eyebrow' => self::str($lang, 'idexemptionemail:staff:eyebrow'),
            'title' => self::str($lang, 'idexemptionemail:staff:title'),
            'intro' => self::str($lang, 'idexemptionemail:staff:intro'),
            'details' => [
                self::str($lang, 'idexemptionemail:labelstudent') =>
                    fullname($student) . ' · ' . $student->email . ' · id ' . (int)$student->id,
                self::str($lang, 'idexemptionemail:labelcourse') => $coursename,
                self::str($lang, 'idexemptionemail:labelexam') => $quizname,
                self::str($lang, 'idexemptionemail:labelrequested') => userdate($requesttime),
            ],
            'ctaurl' => $overridesurl->out(false),
            'ctalabel' => self::str($lang, 'idexemptionemail:staff:cta'),
            'note' => self::str($lang, 'idexemptionemail:staff:note'),
            'footer' => self::footer($lang),
        ]);
    }

    /**
     * Sends the student their "request received" confirmation.
     *
     * @param \stdClass $student Requesting student.
     * @param string $coursename Formatted course name.
     * @param string $quizname Formatted quiz name.
     * @param int $requesttime Request timestamp.
     * @return bool Whether the email was accepted for delivery.
     */
    public static function notify_student_received(
        \stdClass $student,
        string $coursename,
        string $quizname,
        int $requesttime
    ): bool {
        $lang = self::user_language($student);

        return self::email($student, [
            'subject' => self::str($lang, 'idexemptionemail:received:subject', $quizname),
            'preheader' => self::str($lang, 'idexemptionemail:received:title'),
            'accent' => self::ACCENT_BLUE,
            'eyebrow' => self::str($lang, 'idexemptionemail:received:eyebrow'),
            'title' => self::str($lang, 'idexemptionemail:received:title'),
            'intro' => self::str($lang, 'idexemptionemail:received:intro', $student->email),
            'details' => [
                self::str($lang, 'idexemptionemail:labelcourse') => $coursename,
                self::str($lang, 'idexemptionemail:labelexam') => $quizname,
                self::str($lang, 'idexemptionemail:labelrequested') =>
                    userdate($requesttime, '', $student->timezone ?? 99),
            ],
            'note' => self::str($lang, 'idexemptionemail:received:note'),
            'footer' => self::footer($lang),
        ]);
    }

    /**
     * Sends the student the approval or decline decision.
     *
     * @param \stdClass $student Requesting student.
     * @param bool $approved True for approved, false for declined.
     * @param string $coursename Formatted course name.
     * @param string $quizname Formatted quiz name.
     * @param int $cmid Quiz course module id.
     * @param string $contact Contact address shown on declines.
     * @return bool Whether the email was accepted for delivery.
     */
    public static function notify_student_decision(
        \stdClass $student,
        bool $approved,
        string $coursename,
        string $quizname,
        int $cmid,
        string $contact
    ): bool {
        $lang = self::user_language($student);
        $spec = [
            'details' => [
                self::str($lang, 'idexemptionemail:labelcourse') => $coursename,
                self::str($lang, 'idexemptionemail:labelexam') => $quizname,
            ],
            'footer' => self::footer($lang),
        ];

        if ($approved) {
            $quizurl = new \moodle_url('/mod/quiz/view.php', ['id' => $cmid]);
            $spec += [
                'subject' => self::str($lang, 'idexemptionemail:approved:subject', $quizname),
                'preheader' => self::str($lang, 'idexemptionemail:approved:title'),
                'accent' => self::ACCENT_GREEN,
                'eyebrow' => self::str($lang, 'idexemptionemail:approved:eyebrow'),
                'title' => self::str($lang, 'idexemptionemail:approved:title'),
                'intro' => self::str($lang, 'idexemptionemail:approved:intro', $quizname),
                'ctaurl' => $quizurl->out(false),
                'ctalabel' => self::str($lang, 'idexemptionemail:approved:cta'),
            ];
        } else {
            $spec += [
                'subject' => self::str($lang, 'idexemptionemail:declined:subject', $quizname),
                'preheader' => self::str($lang, 'idexemptionemail:declined:title'),
                'accent' => self::ACCENT_RED,
                'eyebrow' => self::str($lang, 'idexemptionemail:declined:eyebrow'),
                'title' => self::str($lang, 'idexemptionemail:declined:title'),
                'intro' => self::str($lang, 'idexemptionemail:declined:intro', (object)[
                    'quiz' => $quizname,
                    'contact' => $contact,
                ]),
            ];
        }

        return self::email($student, $spec);
    }

    /**
     * Builds a deliverable-only recipient for an external address.
     *
     * @param string $email Destination address.
     * @param string $name Display name.
     * @return \stdClass Recipient usable with email_to_user().
     */
    public static function external_recipient(string $email, string $name): \stdClass {
        $recipient = clone \core_user::get_noreply_user();
        $recipient->email = $email;
        $recipient->firstname = $name;
        $recipient->lastname = '';
        $recipient->maildisplay = 1;
        $recipient->emailstop = 0;
        $recipient->mailformat = 1;

        return $recipient;
    }

    /**
     * Renders the shared shell and sends the message.
     *
     * @param \stdClass $recipient Recipient user record.
     * @param array $spec Message spec (subject, accent, eyebrow, title, intro,
     *     details, ctaurl, ctalabel, note, preheader, footer).
     * @return bool Whether the email was accepted for delivery.
     */
    private static function email(\stdClass $recipient, array $spec): bool {
        [$html, $text] = self::render($spec);

        return (bool)email_to_user(
            $recipient,
            \core_user::get_noreply_user(),
            (string)($spec['subject'] ?? ''),
            $text,
            $html
        );
    }

    /**
     * Renders the design's email shell with the given content.
     *
     * @param array $spec Message spec.
     * @return array [html, text]
     */
    private static function render(array $spec): array {
        global $SITE;

        $accent = $spec['accent'] ?? self::ACCENT_BLUE;
        $sitename = format_string($SITE->fullname);
        $font = 'font-family:Arial, Helvetica, sans-serif;';
        $lh = 'mso-line-height-rule:exactly;';

        $detailrows = '';
        foreach (($spec['details'] ?? []) as $label => $value) {
            $detailrows .= '<tr>' .
                '<td class="detail-label" width="110" valign="top" style="padding:0 0 12px; font-size:13px; ' .
                    'font-weight:bold; color:#5b6470; text-transform:uppercase; letter-spacing:0.5px; ' .
                    $lh . ' line-height:20px;">' . s($label) . '</td>' .
                '<td class="detail-value" valign="top" style="padding:0 0 12px; font-size:15px; color:#1d2125; ' .
                    $lh . ' line-height:20px;">' . s($value) . '</td>' .
                '</tr>';
        }

        $button = '';
        if (!empty($spec['ctaurl']) && !empty($spec['ctalabel'])) {
            $button = '<tr><td class="px" align="center" style="padding:28px 40px 0;">' .
                '<table role="presentation" cellpadding="0" cellspacing="0" border="0">' .
                '<tr><td bgcolor="' . $accent . '" style="border-radius:8px;">' .
                '<a href="' . s($spec['ctaurl']) . '" style="display:block; padding:13px 32px; ' . $font .
                ' font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">' .
                s($spec['ctalabel']) . '</a>' .
                '</td></tr></table></td></tr>';
        }

        $note = '';
        if (!empty($spec['note'])) {
            $note = '<tr><td class="px" style="padding:18px 40px 30px; ' . $font . '">' .
                '<div align="center" style="font-size:13px; color:#5b6470; ' . $lh . ' line-height:20px;">' .
                s($spec['note']) . '</div></td></tr>';
        } else {
            $note = '<tr><td style="padding:0 0 30px; font-size:0; line-height:0;">&nbsp;</td></tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width, initial-scale=1">' .
            '<meta name="color-scheme" content="light dark">' .
            '<title>' . s($spec['title'] ?? '') . '</title>' .
            '<!--[if mso]><style>table{border-collapse:collapse;}td{mso-line-height-rule:exactly;}</style><![endif]-->' .
            '<style>@media only screen and (max-width: 620px) {' .
            ' .wrap { width: 100% !important; }' .
            ' .px { padding-left: 20px !important; padding-right: 20px !important; }' .
            ' .detail-label { display: block !important; width: 100% !important; padding-bottom: 2px !important; }' .
            ' .detail-value { display: block !important; width: 100% !important; padding-bottom: 12px !important; }' .
            '}</style></head>' .
            '<body style="margin:0; padding:0; background-color:#eef1f4;">' .
            '<span style="display:none; font-size:1px; color:#eef1f4; line-height:1px; max-height:0; max-width:0; ' .
                'opacity:0; overflow:hidden;">' . s($spec['preheader'] ?? '') . '</span>' .
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" ' .
                'style="background-color:#eef1f4;"><tr><td align="center" style="padding:32px 12px;">' .
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" class="wrap" ' .
                'style="width:600px; max-width:600px;">' .
            '<tr><td align="center" style="padding:8px 0 24px;">' .
            '<img src="' . self::LOGO_URL . '" alt="' . s($sitename) . '" width="220" ' .
                'style="display:block; width:220px; height:auto; border:0;"></td></tr>' .
            '<tr><td style="background-color:#ffffff; border-radius:10px; border:1px solid #dde3e9;">' .
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">' .
            '<tr><td style="border-top:4px solid ' . $accent . '; border-radius:10px 10px 0 0; font-size:0; ' .
                'line-height:0;">&nbsp;</td></tr>' .
            '<tr><td class="px" style="padding:28px 40px 0; ' . $font . '">' .
            '<div style="font-size:12px; font-weight:bold; letter-spacing:1px; text-transform:uppercase; color:' .
                $accent . '; ' . $lh . ' line-height:16px;">' . s($spec['eyebrow'] ?? '') . '</div></td></tr>' .
            '<tr><td class="px" style="padding:10px 40px 0; ' . $font . '">' .
            '<div style="font-size:24px; font-weight:bold; color:#1d2125; ' . $lh . ' line-height:32px;">' .
                s($spec['title'] ?? '') . '</div></td></tr>' .
            '<tr><td class="px" style="padding:14px 40px 0; ' . $font . '">' .
            '<div style="font-size:15px; color:#3c434a; ' . $lh . ' line-height:23px;">' .
                s($spec['intro'] ?? '') . '</div></td></tr>' .
            '<tr><td class="px" style="padding:24px 40px 0;">' .
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" ' .
                'style="background-color:#f5f8fa; border:1px solid #e1e8ee; border-radius:8px;">' .
            '<tr><td style="padding:18px 22px 6px;">' .
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="' . $font . '">' .
            $detailrows .
            '</table></td></tr></table></td></tr>' .
            $button .
            $note .
            '</table></td></tr>' .
            '<tr><td class="px" style="padding:22px 40px; ' . $font . '">' .
            '<div align="center" style="font-size:12px; color:#7a828b; ' . $lh . ' line-height:18px;">' .
                s($spec['footer'] ?? '') . '<br>' .
                s(self::BRAND_NAME . ', ' . self::BRAND_ADDRESS) . '</div></td></tr>' .
            '</table></td></tr></table></body></html>';

        $textlines = [
            strtoupper((string)($spec['eyebrow'] ?? '')),
            (string)($spec['title'] ?? ''),
            '',
            (string)($spec['intro'] ?? ''),
            '',
        ];
        foreach (($spec['details'] ?? []) as $label => $value) {
            $textlines[] = $label . ': ' . $value;
        }
        if (!empty($spec['ctaurl']) && !empty($spec['ctalabel'])) {
            $textlines[] = '';
            $textlines[] = $spec['ctalabel'] . ': ' . $spec['ctaurl'];
        }
        if (!empty($spec['note'])) {
            $textlines[] = '';
            $textlines[] = (string)$spec['note'];
        }
        $textlines[] = '';
        $textlines[] = (string)($spec['footer'] ?? '');
        $textlines[] = self::BRAND_NAME . ', ' . self::BRAND_ADDRESS;

        return [$html, implode("\n", $textlines)];
    }

    /**
     * Localized string in a specific language.
     *
     * @param string $lang Language code.
     * @param string $key String key.
     * @param mixed $a Placeholder value.
     * @return string Localized string.
     */
    private static function str(string $lang, string $key, $a = null): string {
        return get_string_manager()->get_string($key, 'quizaccess_proctoring', $a, $lang);
    }

    /**
     * Footer line for a language.
     *
     * @param string $lang Language code.
     * @return string Footer text.
     */
    private static function footer(string $lang): string {
        return self::str($lang, 'idexemptionemail:footer', self::BRAND_NAME);
    }

    /**
     * The site default language.
     *
     * @return string Language code.
     */
    private static function site_language(): string {
        global $CFG;

        return $CFG->lang ?: 'en';
    }

    /**
     * A user's preferred language.
     *
     * @param \stdClass $user User record.
     * @return string Language code.
     */
    private static function user_language(\stdClass $user): string {
        return !empty($user->lang) ? (string)$user->lang : self::site_language();
    }
}
