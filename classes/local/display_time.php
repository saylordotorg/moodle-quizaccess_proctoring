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
 * One place deciding how a proctoring date is written down.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Formats the dates the plugin shows staff and students.
 *
 * Two decisions live here, because they were previously made separately in each report and each
 * email and the results disagreed:
 *
 * - **Format.** Staff read the proctoring reports against the quiz Grades report, which lists the
 *   same attempts. Reading one against the other should not mean translating between two date
 *   formats, so the format is the one core's quiz reports use.
 * - **Timezone.** The institution's, with the zone printed. Moodle otherwise falls back to the
 *   server timezone whenever a user has not set one on their profile, which is how request times
 *   came to read as Central to reviewers who are working in Eastern - and a bare wall-clock time
 *   with no zone cannot be checked by whoever reads it next.
 */
final class display_time {

    /**
     * @var string Timezone every proctoring date is shown in.
     *
     * Not the server's and not the reader's: staff and students need to read the same wall-clock
     * time when they discuss an attempt or a request, and the zone is printed alongside so a
     * reader elsewhere can convert it.
     */
    const TIMEZONE = 'America/New_York';

    /**
     * A date for a staff report: core's quiz-report format, institution timezone, zone named.
     *
     * @param int $time Unix timestamp. Zero or negative renders as an empty string, so a table
     *                  cell for something that never happened stays blank instead of reading 1970.
     * @param bool $withzone False to leave the zone off, for a column whose header already says it.
     * @return string For example "25 July 2026  1:20 PM EDT".
     */
    public static function staff(int $time, bool $withzone = true): string {
        if ($time <= 0) {
            return '';
        }

        return userdate($time, self::staff_format($withzone), self::TIMEZONE);
    }

    /**
     * The strftime format staff dates use.
     *
     * Core's quiz reports build this by replacing the comma in `strftimedatetime` with a space
     * ({@see \quiz_overview_table::__construct()}), so this mirrors that rather than inventing a
     * format of its own.
     *
     * @param bool $withzone Whether to append the zone.
     * @return string A strftime format string.
     */
    public static function staff_format(bool $withzone = true): string {
        $format = str_replace(',', ' ', get_string('strftimedatetime'));

        return $withzone ? $format . ' %Z' : $format;
    }
}
