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
 * The staffed address that replies to proctoring mail should reach.
 *
 * Every message this plugin sends goes out from the site noreply address, which means a
 * reply lands nowhere unless Reply-To says otherwise - and people do reply, students and
 * staff alike. All of them point here: the configured ID exception contact address, which
 * is also the address students are told to write to, so there is one address to keep
 * current rather than several that drift apart.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class support_contact {
    /**
     * The Reply-To address, when one is configured and usable.
     *
     * @return string Validated address, or an empty string to leave Reply-To unset.
     */
    public static function address(): string {
        $contact = trim((string)get_config('quizaccess_proctoring', 'idexemptioncontactemail'));

        return validate_email($contact) ? $contact : '';
    }

    /**
     * Display name shown beside the Reply-To address.
     *
     * @param string|null $lang Language code, or null for the current language.
     * @return string Localized label.
     */
    public static function name(?string $lang = null): string {
        if ($lang === null) {
            return get_string('idexemptionemail:replytoname', 'quizaccess_proctoring');
        }

        return get_string_manager()->get_string(
            'idexemptionemail:replytoname',
            'quizaccess_proctoring',
            null,
            $lang
        );
    }
}
