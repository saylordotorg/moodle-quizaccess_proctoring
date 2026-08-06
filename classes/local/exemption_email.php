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
 * Builds and sends the ID verification exception decision emails.
 *
 * Both notifications (approval, decline) share one send-ready table-based HTML
 * shell — logo header, accent card, details panel, optional button — from the
 * approved "ID Exception Request Email" design, plus a plain-text alternative
 * built from the same strings, localized to the recipient's language. Only a
 * staff decision on the Manage overrides page sends mail: students requesting an
 * exception email student support themselves, so nothing is sent automatically.
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

    /** @var string Accent for informational messages and the default accent. */
    const ACCENT_BLUE = '#0f6cbf';

    /** @var string Accent for the approval message. */
    const ACCENT_GREEN = '#357a32';

    /** @var string Accent for the decline message. */
    const ACCENT_RED = '#b3423a';

    /**
     * @var string Timezone every date in these emails is shown in.
     *
     * Not the server timezone and not the student's: staff and students need to read the
     * same wall-clock time when they discuss a request, and the zone is printed alongside
     * so a student elsewhere can convert it. Moodle would otherwise fall back to the
     * server timezone whenever a student has not set one on their profile.
     */
    const DISPLAY_TIMEZONE = 'America/New_York';

    /**
     * Sends the student the approval or decline decision.
     *
     * @param \stdClass $student Requesting student.
     * @param bool $approved True for approved, false for declined.
     * @param string $coursename Formatted course name.
     * @param string $quizname Formatted quiz name.
     * @param int $cmid Quiz course module id.
     * @param string $contact Contact address shown on declines.
     * @param int $requestedat When the student filed the request; 0 to leave it out.
     * @return bool Whether the email was accepted for delivery.
     */
    public static function notify_student_decision(
        \stdClass $student,
        bool $approved,
        string $coursename,
        string $quizname,
        int $cmid,
        string $contact,
        int $requestedat = 0
    ): bool {
        $lang = self::user_language($student);
        $details = [
            self::str($lang, 'idexemptionemail:labelcourse') => $coursename,
            self::str($lang, 'idexemptionemail:labelexam') => $quizname,
        ];
        if ($requestedat > 0) {
            $details[self::str($lang, 'idexemptionemail:labelrequested')] = self::format_time($requestedat, $lang);
        }
        $spec = [
            'lang' => $lang,
            'details' => $details,
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
     * Renders the shared shell and sends the message.
     *
     * Sent from the site noreply address, but with Reply-To pointed at the configured ID
     * exception contact address so a student who just hits reply reaches a person instead
     * of a black hole. The footer still tells them not to reply to the notification itself.
     *
     * @param \stdClass $recipient Recipient user record.
     * @param array $spec Message spec (subject, accent, eyebrow, title, intro,
     *     details, ctaurl, ctalabel, note, preheader, footer, lang).
     * @return bool Whether the email was accepted for delivery.
     */
    private static function email(\stdClass $recipient, array $spec): bool {
        [$html, $text] = self::render($spec);
        $replyto = support_contact::address();
        $replytoname = $replyto === ''
            ? ''
            : support_contact::name((string)($spec['lang'] ?? self::site_language()));

        return (bool)email_to_user(
            $recipient,
            \core_user::get_noreply_user(),
            (string)($spec['subject'] ?? ''),
            $text,
            $html,
            '',
            '',
            true,
            $replyto,
            $replytoname
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
     * Formats a timestamp for a student, in the institution's timezone with the zone shown.
     *
     * @param int $time Unix timestamp.
     * @param string $lang Language code.
     * @return string For example "25 July 2026, 1:20 PM EDT".
     */
    private static function format_time(int $time, string $lang): string {
        return userdate($time, self::str($lang, 'idexemptionemail:timeformat'), self::DISPLAY_TIMEZONE);
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
     * Only claims "do not reply" when there is genuinely nowhere to reply to — with a
     * contact address configured, Reply-To reaches student support and the footer says so.
     *
     * @param string $lang Language code.
     * @return string Footer text.
     */
    private static function footer(string $lang): string {
        $key = support_contact::address() === '' ? 'idexemptionemail:footer' : 'idexemptionemail:footerreplyto';

        return self::str($lang, $key, self::BRAND_NAME);
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
