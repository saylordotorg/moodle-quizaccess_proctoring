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
 * Builds the plugin's admin settings pages outside Moodle's admin tree.
 *
 * Core builds the module settings tree only for users holding moodle/site:config
 * (admin/settings/plugins.php wraps the whole activity-modules section in $hassiteconfig), so
 * mod/quiz/settings.php - and with it this plugin's settings.php - is never included for a
 * manager, whatever req_capability the page would carry. The capability
 * quizaccess/proctoring:manageadminsettings therefore could not open
 * /admin/settings.php?section=modsettingsquizcatproctoring, however it was granted.
 *
 * This builds the very same pages from the very same settings.php into a private admin_root,
 * so adminsettings.php can render and save them for capability holders. $hassiteconfig is
 * passed in: with false, settings.php leaves out every credential field and the AI review
 * section, exactly as it does when a capability holder's request builds the page.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_settings_pages {

    /** @var string The main settings page's section name, as core names it in the admin tree. */
    public const MAIN_SECTION = 'modsettingsquizcatproctoring';

    /** @var string The risk factor scoring page's section name. */
    public const RISKFACTORS_SECTION = 'quizaccess_proctoring_riskfactors';

    /**
     * Build the plugin's settings pages as settings.php defines them.
     *
     * @param bool $hassiteconfig Whether to build the site-administrator version, credential
     *                            fields and all. Pass false for a capability holder.
     * @return \admin_root A private tree holding this plugin's pages only.
     */
    public static function build(bool $hassiteconfig): \admin_root {
        global $CFG, $PAGE, $DB;
        require_once($CFG->libdir . '/adminlib.php');

        $ADMIN = new \admin_root(true);
        // The parent category settings.php adds the plugin's own category to; core calls it
        // modsettingsquizcat ("Quiz" under Activity modules).
        $ADMIN->add('root', new \admin_category('modsettingsquizcat', 'quizaccess_proctoring'));
        // The page core would have created before including settings.php, same name and
        // capabilities; settings.php relaxes req_capability itself, but starting from the same
        // pair keeps this faithful to the tree a site administrator sees.
        $settings = new \admin_settingpage(
            self::MAIN_SECTION,
            get_string('pluginname', 'quizaccess_proctoring'),
            ['moodle/site:config', 'quizaccess/proctoring:manageadminsettings']
        );

        // Our settings.php reads $hassiteconfig, $settings and $ADMIN from the including scope,
        // exactly as it does when mod/quiz/settings.php includes it.
        include($CFG->dirroot . '/mod/quiz/accessrule/proctoring/settings.php');

        return $ADMIN;
    }
}
