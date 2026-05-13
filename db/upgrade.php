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
 * Quiz access proctoring plugin upgrade code
 *
 * @package     quizaccess_proctoring
 * @author      Saylor Academy <saylor.org>
 * @copyright   2020 Saylor Academy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the database schema for the quizaccess_proctoring plugin.
 *
 * This function checks the old version of the plugin and applies necessary changes to the database schema, such as
 * adding new fields or tables, modifying existing ones, or performing other schema adjustments required for the upgrade.
 *
 * @param int $oldversion The version of the plugin we are upgrading from.
 *
 * @return bool True on success, false on failure.
 */
function xmldb_quizaccess_proctoring_upgrade($oldversion) {
    global $CFG, $DB;

    require_once($CFG->libdir.'/db/upgradelib.php'); // Core Upgrade-related functions.
    $dbman = $DB->get_manager(); // Loads ddl manager and xmldb classes.

    if ($oldversion < 2021061102) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('quizaccess_proctoring_logs');
        $field1 = new xmldb_field('awsscore', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $field2 = new xmldb_field('awsflag', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);

        // Conditionally launch add field forcedownload.
        if (!$dbman->field_exists($table, $field1)) {
            $dbman->add_field($table, $field1);
        }

        if (!$dbman->field_exists($table, $field2)) {
            $dbman->add_field($table, $field2);
        }

        upgrade_plugin_savepoint(true, 2021061102, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2021061104) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('proctoring_facematch_task');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, true, true, null, null);
        $table->add_field('refimageurl', XMLDB_TYPE_TEXT, '500', null, true, false, null, null);
        $table->add_field('targetimageurl', XMLDB_TYPE_TEXT, '500', null, true, false, null, null);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // Conditionally launch create table for fees.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2021061104, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2021061106) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('proctoring_screenshot_logs');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, true, true, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('screenshot', XMLDB_TYPE_TEXT, '10', null, true, false, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);

        $table->add_key('id', XMLDB_KEY_PRIMARY, ['id']);

        upgrade_plugin_savepoint(true, 2021061106, 'quizaccess', 'proctoring');
    }
    if ($oldversion < 2021071405) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('proctoring_fm_warnings');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, true, true, null, null);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // Conditionally launch create table for fees.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2021071405, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2021112601) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('proctoring_screenshot_logs');

        // Drop table proctoring_screenshot_logs.
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        upgrade_plugin_savepoint(true, 2021112601, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2021112603) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('proctoring_user_images');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, true, true, null, null);
        $table->add_field('user_id', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('photo_draft_id', XMLDB_TYPE_INTEGER, '20', null, true, false, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // Conditionally launch create table for fees.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2021112603, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2021112604) {
        // Define field output to be added to task_log.
        $table = new xmldb_table('proctoring_face_images');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, true, true, null, null);
        $table->add_field('parent_type', XMLDB_TYPE_CHAR, '20', null, true, false, 0, null);
        $table->add_field('parentid', XMLDB_TYPE_INTEGER, '20', null, true, false, 0, null);
        $table->add_field('faceimage', XMLDB_TYPE_TEXT, '256', null, true, false, null, null);
        $table->add_field('facefound', XMLDB_TYPE_INTEGER, '2', null, true, false, 0, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // Conditionally launch create table for fees.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2021112604, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2024100102) {
        $table = new xmldb_table('proctoring_facematch_task');
        $dbman->rename_table($table, 'quizaccess_proctoring_facematch_task');

        upgrade_plugin_savepoint(true,  2024100102, 'quizaccess', 'proctoring');
    }
    if ($oldversion < 2024100103) {
        $table = new xmldb_table('proctoring_fm_warnings');
        $dbman->rename_table($table, 'quizaccess_proctoring_fm_warnings');
        $table = new xmldb_table('proctoring_user_images');
        $dbman->rename_table($table, 'quizaccess_proctoring_user_images');
        $table = new xmldb_table('proctoring_face_images');
        $dbman->rename_table($table, 'quizaccess_proctoring_face_images');

        upgrade_plugin_savepoint(true,  2024100103, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2024100104) {
        $table = new xmldb_table('aws_api_log');

        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        // Upgrade plugin version.
        upgrade_plugin_savepoint(true, 2024100104, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2025011005) {
        // Define field deletationprogress to be added to quizaccess_proctoring_logs.
        $table = new xmldb_table('quizaccess_proctoring_logs');
        $field = new xmldb_field('deletionprogress', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        // Check if the field exists, and if not, add it with default value 0.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2025011005, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2025030606) {
        // Fetch the scheduled task record that needs to be updated.
        $task = $DB->get_record('task_scheduled', ['classname' => '\quizaccess_proctoring\task\DeleteImagesTask']);
        // If the record exists, update it.
        if ($task) {
            $task->classname = '\quizaccess_proctoring\task\delete_images_task'; // New classname.
            $DB->update_record('task_scheduled', $task);
        }

        $task2 = $DB->get_record('task_scheduled', ['classname' => '\quizaccess_proctoring\task\ExecuteFacematchTask']);
        if ($task2) {
            $task2->classname = '\quizaccess_proctoring\task\execute_facematch_task'; // New classname.
            $DB->update_record('task_scheduled', $task2);
        }

        $task3 = $DB->get_record('task_scheduled', ['classname' => '\quizaccess_proctoring\task\InitiateFacematchTask']);
        if ($task3) {
            $task3->classname = '\quizaccess_proctoring\task\initiate_face_match_task'; // New classname.
            $DB->update_record('task_scheduled', $task3);
        }

        // Upgrade Moodle's internal version to mark the change.
        upgrade_plugin_savepoint(true, 2025030606, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051100) {
        // No schema changes. Savepoint ensures Moodle refreshes plugin settings and AMD cache after this update.
        upgrade_plugin_savepoint(true, 2026051100, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051101) {
        // No schema changes. Adds continuous face-check settings and the cost estimate admin page.
        upgrade_plugin_savepoint(true, 2026051101, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051102) {
        // No schema changes. Refreshes the displayed plugin name.
        upgrade_plugin_savepoint(true, 2026051102, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051103) {
        // No schema changes. Adds quality checks before saving a self-registered reference image.
        upgrade_plugin_savepoint(true, 2026051103, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051104) {
        // No schema changes. Loosens self-registration quality thresholds to avoid false rejects.
        upgrade_plugin_savepoint(true, 2026051104, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051105) {
        $table = new xmldb_table('quizaccess_proctoring_events');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, true, true, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '40', null, true, false, '', null);
        $table->add_field('eventdetail', XMLDB_TYPE_TEXT, null, null, false, false, null, null);
        $table->add_field('pagevisibility', XMLDB_TYPE_CHAR, '20', null, true, false, '', null);
        $table->add_field('currenturl', XMLDB_TYPE_TEXT, null, null, false, false, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, true, false, 0, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
        $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
        $table->add_index('reportid', XMLDB_INDEX_NOTUNIQUE, ['reportid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051105, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051106) {
        // No schema changes. Adds the full-screen-share preflight requirement setting.
        upgrade_plugin_savepoint(true, 2026051106, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051107) {
        $table = new xmldb_table('quizaccess_proctoring');
        $field = new xmldb_field(
            'requireentirescreen',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            -1,
            'proctoringrequired'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026051107, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051108) {
        $table = new xmldb_table('quizaccess_proctoring_events');
        $field = new xmldb_field(
            'screenshoturl',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'currenturl'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026051108, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051109) {
        // No schema changes. Adds the copy/cut/paste blocking setting.
        upgrade_plugin_savepoint(true, 2026051109, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051110) {
        // No schema changes. Adds shared-monitor marker verification.
        upgrade_plugin_savepoint(true, 2026051110, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051111) {
        // No schema changes. Adds the persistent screen monitor window.
        upgrade_plugin_savepoint(true, 2026051111, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051112) {
        // No schema changes. Hardens right-click and keyboard clipboard blocking.
        upgrade_plugin_savepoint(true, 2026051112, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051113) {
        // Remove retired third-party face match settings from existing installations.
        $fcmethod = get_config('quizaccess_proctoring', 'fcmethod');
        if ($fcmethod !== false && !in_array($fcmethod, ['customapi', 'None'], true)) {
            set_config('fcmethod', 'None', 'quizaccess_proctoring');
        }
        unset_config('bsapi', 'quizaccess_proctoring');
        unset_config('bs_api_key', 'quizaccess_proctoring');
        unset_config('username', 'quizaccess_proctoring');
        unset_config('password', 'quizaccess_proctoring');

        upgrade_plugin_savepoint(true, 2026051113, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051114) {
        // No schema changes. Refreshes the Saylor AI endpoint setting label.
        upgrade_plugin_savepoint(true, 2026051114, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051115) {
        // No schema changes. Refreshes display name and removes old promotional copy.
        upgrade_plugin_savepoint(true, 2026051115, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051116) {
        // No schema changes. Adds the student report overview table.
        upgrade_plugin_savepoint(true, 2026051116, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051117) {
        // No schema changes. Adds the configurable pre-quiz integrity statement.
        upgrade_plugin_savepoint(true, 2026051117, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051118) {
        // No schema changes. Adds per-attempt risk scoring to the proctoring report.
        upgrade_plugin_savepoint(true, 2026051118, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051119) {
        // No schema changes. Renames the custom face match method label to Saylor AI API.
        upgrade_plugin_savepoint(true, 2026051119, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051120) {
        $table = new xmldb_table('quizaccess_proctoring');
        $field = new xmldb_field(
            'riskreviewmode',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '-1',
            'requireentirescreen'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'riskreviewthreshold',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '-1',
            'riskreviewmode'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('quizaccess_proctoring_risk_holds');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizinstance', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('riskscore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('threshold', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('originalgrade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
            $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $table->add_index('reportid', XMLDB_INDEX_NOTUNIQUE, ['reportid']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051120, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051121) {
        // No schema changes. Adds the daily proctoring email report scheduled task and settings.
        upgrade_plugin_savepoint(true, 2026051121, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051122) {
        // No schema changes. Adds confirmed-violation retake lockout settings and review action.
        upgrade_plugin_savepoint(true, 2026051122, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051123) {
        $table = new xmldb_table('quizaccess_proctoring');
        $field = new xmldb_field(
            'captchamode',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '-1',
            'riskreviewthreshold'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026051123, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051124) {
        // No schema changes. Adds Turnstile CAPTCHA and mobile/tablet screen-share behavior settings.
        upgrade_plugin_savepoint(true, 2026051124, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051125) {
        // No schema changes. Adds a collapsible screen monitor popup and suppresses it on mobile/tablet.
        upgrade_plugin_savepoint(true, 2026051125, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051126) {
        // No schema changes. Moves desktop proctoring indicators into the quiz navigation panel.
        upgrade_plugin_savepoint(true, 2026051126, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051127) {
        // No schema changes. Adds optional quiz blurring when the webcam face is not visible.
        upgrade_plugin_savepoint(true, 2026051127, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051128) {
        // No schema changes. Improves the start-attempt preflight requirement checklist.
        upgrade_plugin_savepoint(true, 2026051128, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051129) {
        // No schema changes. Keeps the start-attempt button visible but disabled during precheck.
        upgrade_plugin_savepoint(true, 2026051129, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051130) {
        // No schema changes. Renders Turnstile explicitly in the start-attempt modal.
        upgrade_plugin_savepoint(true, 2026051130, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051131) {
        // No schema changes. Preserves profile image aspect ratio in circular report thumbnails.
        upgrade_plugin_savepoint(true, 2026051131, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051132) {
        // No schema changes. Removes the minimize control from the screen monitor popup.
        upgrade_plugin_savepoint(true, 2026051132, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051133) {
        // No schema changes. Redesigns the start-attempt precheck as guided steps.
        upgrade_plugin_savepoint(true, 2026051133, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051134) {
        $table = new xmldb_table('quizaccess_proctoring_ai_reviews');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('holdid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('riskscore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('triggerthreshold', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('provider', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, '');
            $table->add_field('model', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, '');
            $table->add_field('reviewscore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decision', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, '');
            $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('evidence', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('rawresponse', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timereviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
            $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
            $table->add_index('reportid', XMLDB_INDEX_NOTUNIQUE, ['reportid']);
            $table->add_index('holdid', XMLDB_INDEX_NOTUNIQUE, ['holdid']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051134, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051135) {
        // No schema changes. Adds Claude and OpenAI-compatible AI image review providers.
        upgrade_plugin_savepoint(true, 2026051135, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051136) {
        // No schema changes. Adds configurable proctoring image retention cleanup.
        upgrade_plugin_savepoint(true, 2026051136, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051137) {
        // No schema changes. Reorganizes Saylor Proctored Quiz admin settings.
        upgrade_plugin_savepoint(true, 2026051137, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051138) {
        // No schema changes. Applies retake lockouts immediately after high-risk review holds.
        upgrade_plugin_savepoint(true, 2026051138, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051139) {
        // No schema changes. Keeps the screen-share marker visible when quiz navigation collapses.
        upgrade_plugin_savepoint(true, 2026051139, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051140) {
        // No schema changes. Adds configurable face-blur sensitivity settings.
        upgrade_plugin_savepoint(true, 2026051140, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051141) {
        // No schema changes. Ensures face-blur sensitivity settings have upgrade defaults.
        if (get_config('quizaccess_proctoring', 'faceblurminscore') === false) {
            set_config('faceblurminscore', 0.3, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'faceblurmisses') === false) {
            set_config('faceblurmisses', 4, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'faceblurhits') === false) {
            set_config('faceblurhits', 1, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'faceblurinitialgrace') === false) {
            set_config('faceblurinitialgrace', 10, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026051141, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051142) {
        // No schema changes. Widens the desktop start-attempt precheck popup.
        upgrade_plugin_savepoint(true, 2026051142, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051143) {
        // No schema changes. Adds AI review diagnostics and report-list status.
        upgrade_plugin_savepoint(true, 2026051143, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051144) {
        // No schema changes. Adds configurable browser-supported multi-monitor detection.
        if (get_config('quizaccess_proctoring', 'multimonitormode') === false) {
            set_config('multimonitormode', 'warn', 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026051144, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051145) {
        // No schema changes. Improves mobile start-attempt precheck layout and portrait face capture.
        upgrade_plugin_savepoint(true, 2026051145, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051146) {
        // No schema changes. Skips multi-monitor unavailable logging on mobile and tablet devices.
        upgrade_plugin_savepoint(true, 2026051146, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051147) {
        // No schema changes. Clarifies face match status labels in reports and AI review inputs.
        upgrade_plugin_savepoint(true, 2026051147, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051148) {
        $table = new xmldb_table('quizaccess_proctoring_ai_reviews');

        $eventidfield = new xmldb_field(
            'eventid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'reportid'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $eventidfield)) {
            $dbman->add_field($table, $eventidfield);
        }

        $reviewtypefield = new xmldb_field(
            'reviewtype',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'attempt',
            'eventid'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $reviewtypefield)) {
            $dbman->add_field($table, $reviewtypefield);
        }

        $eventidindex = new xmldb_index('eventid', XMLDB_INDEX_NOTUNIQUE, ['eventid']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $eventidindex)) {
            $dbman->add_index($table, $eventidindex);
        }

        $reviewtypeindex = new xmldb_index('reviewtype', XMLDB_INDEX_NOTUNIQUE, ['reviewtype']);
        if ($dbman->table_exists($table) && !$dbman->index_exists($table, $reviewtypeindex)) {
            $dbman->add_index($table, $reviewtypeindex);
        }

        if (get_config('quizaccess_proctoring', 'aireviewdesktopmode') === false) {
            set_config('aireviewdesktopmode', 'threshold', 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026051148, 'quizaccess', 'proctoring');
    }

    return true;
}
