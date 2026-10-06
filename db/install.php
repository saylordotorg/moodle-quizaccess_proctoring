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
 * Post-installation steps for quizaccess_proctoring.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Record install-time state that upgrade steps record on existing sites.
 *
 * @return bool
 */
function xmldb_quizaccess_proctoring_install() {
    // Every reference photo on a fresh site is registered from now on, so all of them are
    // covered by the abandoned-reference cleanup (CPIT-464).
    set_config('abandonedreferencesince', time(), 'quizaccess_proctoring');
    return true;
}
