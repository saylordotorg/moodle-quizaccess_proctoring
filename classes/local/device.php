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
 * Whether the student is on a phone or tablet, for the phone/tablet screen-sharing setting (CPIT-495).
 *
 * The browser's user agent identifies most phones and tablets. A tablet asking for the desktop
 * site (Chrome on Android tablets does by default, and iPadOS Safari) reports a Linux or Mac
 * desktop instead, so the server takes it for a computer and asks it to share a screen it cannot
 * share. The precheck page then reports the touch screen it sees, and the session is treated as a
 * tablet from then on. Only a user agent that such a tablet could send is accepted, only while the
 * site lets phones and tablets skip screen sharing, and every report is logged.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class device {
    /** @var string Session key holding the tablet report. */
    private const SESSION_KEY = 'quizaccess_proctoring_reportedtablet';

    /**
     * The site's phone/tablet screen-sharing setting: bypass, require or block.
     *
     * @return string
     */
    public static function mobile_mode(): string {
        $mode = get_config('quizaccess_proctoring', 'mobilescreensharemode');
        return in_array($mode, ['bypass', 'require', 'block'], true) ? $mode : 'bypass';
    }

    /**
     * Whether the current request comes from a phone or tablet.
     *
     * @return bool
     */
    public static function is_phone_or_tablet(): bool {
        if (in_array(\core_useragent::get_device_type(), [
            \core_useragent::DEVICETYPE_MOBILE,
            \core_useragent::DEVICETYPE_TABLET,
        ], true)) {
            return true;
        }
        return self::reported_tablet();
    }

    /**
     * Whether this session's precheck reported a tablet that asks for the desktop site.
     *
     * @return bool
     */
    public static function reported_tablet(): bool {
        global $SESSION, $USER;
        $report = $SESSION->{self::SESSION_KEY} ?? null;
        return is_array($report) && (int)($report['userid'] ?? 0) === (int)($USER->id ?? -1)
            && (int)($USER->id ?? 0) > 0 && self::desktop_mode_tablet_agent((string)($report['useragent'] ?? ''))
            && (string)($report['useragent'] ?? '') === self::user_agent();
    }

    /**
     * Whether a user agent is one a tablet asking for the desktop site sends: Linux (Android) or
     * a Mac (iPad), never Windows or ChromeOS, which are computers.
     *
     * @param string $useragent User agent.
     * @return bool
     */
    public static function desktop_mode_tablet_agent(string $useragent): bool {
        return (bool)preg_match('/Macintosh|X11; Linux|Android/', $useragent)
            && !preg_match('/Windows|CrOS/', $useragent);
    }

    /**
     * Record that the precheck page found a tablet without screen sharing, when the site lets
     * phones and tablets skip it. Logged for reviewers.
     *
     * @param \context_module $context The quiz.
     * @return bool Whether the report was accepted.
     */
    public static function report_tablet(\context_module $context): bool {
        global $SESSION, $USER;
        $useragent = self::user_agent();
        if (self::mobile_mode() !== 'bypass' || !self::desktop_mode_tablet_agent($useragent)) {
            return false;
        }
        $SESSION->{self::SESSION_KEY} = ['userid' => (int)$USER->id, 'useragent' => $useragent];
        \quizaccess_proctoring\event\tablet_reported::create([
            'context' => $context,
            'other' => ['useragent' => \core_text::substr($useragent, 0, 255)],
        ])->trigger();
        return true;
    }

    /**
     * The current request's user agent.
     *
     * @return string
     */
    private static function user_agent(): string {
        return (string)(\core_useragent::get_user_agent_string() ?: '');
    }
}
