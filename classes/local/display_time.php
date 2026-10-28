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
 * They read as Moodle's own dates do (CPIT-512, Student Affairs): in the reader's timezone - their
 * profile's, or the site default - and in the format core's quiz reports use, so a reviewer moving
 * between an attempt's results page and its proctoring report reads the same time in the same
 * form. Until 1.18.1 every date was printed in one fixed institution timezone with the zone named,
 * which disagreed with the rest of the site for anyone set to another timezone.
 */
final class display_time {

    /**
     * A date for a staff report: core's quiz-report format, in the reader's timezone.
     *
     * @param int $time Unix timestamp. Zero or negative renders as an empty string, so a table
     *                  cell for something that never happened stays blank instead of reading 1970.
     * @param bool $withzone True to name the timezone after the time.
     * @return string For example "25 July 2026  1:20 PM".
     */
    public static function staff(int $time, bool $withzone = false): string {
        if ($time <= 0) {
            return '';
        }

        return userdate($time, self::staff_format($withzone));
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
    public static function staff_format(bool $withzone = false): string {
        $format = str_replace(',', ' ', get_string('strftimedatetime'));

        return $withzone ? $format . ' %Z' : $format;
    }
}
