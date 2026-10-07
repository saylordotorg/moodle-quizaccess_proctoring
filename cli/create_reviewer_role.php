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
 * Create (or bring up to date) the proctoring reviewer role for Student Affairs (CPIT-474).
 *
 * The role reviews proctoring holds and reports in every course it is assigned over, without any
 * site administration right. Assign it at system level for every course, or on a course category
 * for that category's courses only. Running the script again resets the role's capabilities to
 * the list below; it never assigns the role to anyone.
 *
 * Usage: php mod/quiz/accessrule/proctoring/cli/create_reviewer_role.php [--shortname=proctoringreviewer]
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

[$options, $unrecognized] = cli_get_params(['shortname' => 'proctoringreviewer', 'help' => false], ['h' => 'help']);
if ($options['help'] || $unrecognized) {
    echo "Create or update the proctoring reviewer role.\n\n" .
        "Options:\n  --shortname=NAME  Role short name (default proctoringreviewer)\n  -h, --help        This help\n";
    exit($unrecognized ? 1 : 0);
}

$role = \quizaccess_proctoring\local\reviewer_role::ensure((string)$options['shortname']);
cli_writeln("Role '{$role->shortname}' (id {$role->id}) is ready. Assign it at Site administration > Users > " .
    "Permissions > Assign system roles, or on a course category.");
