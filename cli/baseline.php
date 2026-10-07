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
 * Print the site's proctoring baseline in plain language, or apply the "start low" pilot profile (CPIT-489).
 *
 * Usage:
 *   php mod/quiz/accessrule/proctoring/cli/baseline.php > baseline.md
 *   php mod/quiz/accessrule/proctoring/cli/baseline.php --apply-pilot-profile --confirm
 *
 * Without options it only reads. Credentials and private addresses are never printed.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    ['apply-pilot-profile' => false, 'confirm' => false, 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || $unrecognized) {
    echo "Print the TaView baseline as Markdown, or apply the start-low pilot profile.\n\n" .
        "Options:\n" .
        "  --apply-pilot-profile  Hold only Critical attempts for review; never fail one automatically\n" .
        "  --confirm              Required with --apply-pilot-profile\n" .
        "  -h, --help             This help\n";
    exit($unrecognized ? 1 : 0);
}

if ($options['apply-pilot-profile']) {
    if (!$options['confirm']) {
        cli_error('Add --confirm to change the site settings.');
    }
    $changes = \quizaccess_proctoring\local\baseline::apply_pilot_profile();
    foreach ($changes as $name => [$old, $new]) {
        cli_writeln("{$name}: '{$old}' -> '{$new}'");
    }
    cli_writeln($changes ? 'Pilot profile applied to the site settings.' : 'The site settings already match the pilot profile.');
    $conflicts = \quizaccess_proctoring\local\baseline::pilot_conflicts();
    if ($conflicts) {
        cli_writeln('');
        cli_writeln('These quizzes have their own settings, which win over the site settings, and stay off the pilot profile:');
        foreach ($conflicts as $line) {
            cli_writeln('  - ' . $line);
        }
        cli_writeln('Change them in each quiz\'s settings if they should follow the pilot.');
    }
    exit(0);
}

echo \quizaccess_proctoring\local\baseline::markdown();
