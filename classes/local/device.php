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

    /** @var string Browser family: Microsoft Edge. */
    public const BROWSER_EDGE = 'edge';

    /** @var string Browser family: Google Chrome. */
    public const BROWSER_CHROME = 'chrome';

    /** @var string Browser family: Mozilla Firefox. */
    public const BROWSER_FIREFOX = 'firefox';

    /** @var string Browser family: Safari, and every browser on an iPhone or iPad, which runs Safari's engine. */
    public const BROWSER_SAFARI = 'safari';

    /** @var string Browser family: anything else (Opera, Samsung Internet, unknown). */
    public const BROWSER_OTHER = 'other';

    /** @var string Browser policy: any browser may start the exam. */
    public const BROWSERS_ANY = 'any';

    /** @var string Browser policy: Chrome, Edge or Firefox; not Safari. */
    public const BROWSERS_NO_SAFARI = 'nosafari';

    /** @var string Browser policy: Chrome or Edge only. */
    public const BROWSERS_CHROME_EDGE = 'chromeedge';

    /** @var array<string, string[]> Browser policy => families allowed to start an attempt. */
    public const BROWSER_POLICIES = [
        self::BROWSERS_NO_SAFARI => [self::BROWSER_CHROME, self::BROWSER_EDGE, self::BROWSER_FIREFOX],
        self::BROWSERS_CHROME_EDGE => [self::BROWSER_CHROME, self::BROWSER_EDGE],
    ];

    /**
     * The browser family a user agent belongs to.
     *
     * Every browser on an iPhone or iPad runs Safari's engine and has Safari's limits whatever its
     * name, so Chrome, Edge and Firefox there count as Safari. Brave and Vivaldi report themselves
     * as Chrome and are Chrome underneath, so they count as Chrome. A user agent can be changed
     * by the student, so this is a gate for honest students, not proof.
     *
     * @param string|null $useragent User agent; null reads the current request's.
     * @return string One of the BROWSER_* constants.
     */
    public static function browser(?string $useragent = null): string {
        $useragent = $useragent ?? self::user_agent();
        if (preg_match('~iPhone|iPad|iPod|CriOS/|FxiOS/|EdgiOS/~', $useragent)) {
            return self::BROWSER_SAFARI;
        }
        if (preg_match('~\bEdg(?:e|A)?/~', $useragent)) {
            return self::BROWSER_EDGE;
        }
        if (preg_match('~\b(?:OPR|Opera|SamsungBrowser|UCBrowser|YaBrowser)/~', $useragent)) {
            return self::BROWSER_OTHER;
        }
        if (preg_match('~\bFirefox/~', $useragent)) {
            return self::BROWSER_FIREFOX;
        }
        if (preg_match('~\b(?:Chrome|Chromium)/~', $useragent)) {
            return self::BROWSER_CHROME;
        }
        if (preg_match('~\bVersion/[\d.]+.*\bSafari/~', $useragent)) {
            return self::BROWSER_SAFARI;
        }
        return self::BROWSER_OTHER;
    }

    /**
     * Whether a browser policy lets a browser family start an attempt.
     *
     * @param string $policy One of the BROWSERS_* constants; anything unknown allows every browser.
     * @param string $browser One of the BROWSER_* constants.
     * @return bool True when the browser may start.
     */
    public static function browser_allowed(string $policy, string $browser): bool {
        return !isset(self::BROWSER_POLICIES[$policy]) || in_array($browser, self::BROWSER_POLICIES[$policy], true);
    }

    /**
     * The phone/tablet screen-sharing setting: bypass, require or block. A quiz's proctoring
     * profile can change the site's.
     *
     * @param int $quizid Quiz id; 0 reads the site setting.
     * @return string
     */
    public static function mobile_mode(int $quizid = 0): string {
        $mode = profile::config($quizid, 'mobilescreensharemode');
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
        global $DB, $SESSION, $USER;
        $useragent = self::user_agent();
        $quizid = (int)$DB->get_field('course_modules', 'instance', ['id' => $context->instanceid]);
        if (self::mobile_mode($quizid) !== 'bypass' || !self::desktop_mode_tablet_agent($useragent)) {
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
