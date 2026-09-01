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

    require_once($CFG->libdir . '/db/upgradelib.php'); // Core Upgrade-related functions.
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

        upgrade_plugin_savepoint(true, 2024100102, 'quizaccess', 'proctoring');
    }
    if ($oldversion < 2024100103) {
        $table = new xmldb_table('proctoring_fm_warnings');
        $dbman->rename_table($table, 'quizaccess_proctoring_fm_warnings');
        $table = new xmldb_table('proctoring_user_images');
        $dbman->rename_table($table, 'quizaccess_proctoring_user_images');
        $table = new xmldb_table('proctoring_face_images');
        $dbman->rename_table($table, 'quizaccess_proctoring_face_images');

        upgrade_plugin_savepoint(true, 2024100103, 'quizaccess', 'proctoring');
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
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '40', null, true, false, 'unknown', null);
        $table->add_field('eventdetail', XMLDB_TYPE_TEXT, null, null, false, false, null, null);
        $table->add_field('pagevisibility', XMLDB_TYPE_CHAR, '20', null, true, false, 'unknown', null);
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
            $table->add_field('provider', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'none');
            $table->add_field('model', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, 'none');
            $table->add_field('reviewscore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('decision', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, 'pending');
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

    if ($oldversion < 2026051149) {
        upgrade_plugin_savepoint(true, 2026051149, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051150) {
        upgrade_plugin_savepoint(true, 2026051150, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051151) {
        if (get_config('quizaccess_proctoring', 'studentholdnoticeenabled') === false) {
            set_config('studentholdnoticeenabled', 1, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'riskreviewautoreleasedays') === false) {
            set_config('riskreviewautoreleasedays', 7, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026051151, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051152) {
        // No schema changes. Groups quiz-level proctoring controls under their own form section.
        upgrade_plugin_savepoint(true, 2026051152, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051153) {
        // No schema changes. Fixes submission risk review lookup for quiz-level proctoring settings.
        upgrade_plugin_savepoint(true, 2026051153, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051154) {
        // No schema changes. Normalizes OpenAI-compatible AI review endpoint URLs.
        upgrade_plugin_savepoint(true, 2026051154, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051155) {
        // No schema changes. Compresses images sent to AI review providers.
        upgrade_plugin_savepoint(true, 2026051155, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051156) {
        // No schema changes. Queues AI review for focus-loss desktop screenshots in threshold mode.
        upgrade_plugin_savepoint(true, 2026051156, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051157) {
        // No schema changes. Improves AI-panel detection prompt and desktop screenshot readability.
        upgrade_plugin_savepoint(true, 2026051157, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051158) {
        if (get_config('quizaccess_proctoring', 'privacynoticerequired') === false) {
            set_config('privacynoticerequired', 1, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'privacynotice') === false) {
            set_config(
                'privacynotice',
                get_string('privacynotice:default', 'quizaccess_proctoring'),
                'quizaccess_proctoring'
            );
        }
        if (get_config('quizaccess_proctoring', 'privacyagreementlabel') === false) {
            set_config(
                'privacyagreementlabel',
                get_string('privacynotice:agreementdefault', 'quizaccess_proctoring'),
                'quizaccess_proctoring'
            );
        }

        upgrade_plugin_savepoint(true, 2026051158, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051159) {
        if (get_config('quizaccess_proctoring', 'screensharepersistencemode') === false) {
            set_config('screensharepersistencemode', 'auto', 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026051159, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051900) {
        // No schema changes. Stable v1.0.0 release with P0/P1 security hardening.
        upgrade_plugin_savepoint(true, 2026051900, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051901) {
        $table = new xmldb_table('quizaccess_proctoring_face_images');

        if ($dbman->table_exists($table)) {
            $field = new xmldb_field(
                'facefound',
                XMLDB_TYPE_INTEGER,
                '2',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'faceimage'
            );
            if ($dbman->field_exists($table, $field)) {
                $dbman->change_field_type($table, $field);
                $dbman->change_field_default($table, $field);
            }

            $field = new xmldb_field(
                'timemodified',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'facefound'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026051901, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051902) {
        // No schema changes. Capability risk metadata updated for personal proctoring data.
        upgrade_plugin_savepoint(true, 2026051902, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026051903) {
        if (get_config('quizaccess_proctoring', 'dailyreportallowexternal') === false) {
            set_config('dailyreportallowexternal', 0, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026051903, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060400) {
        $table = new xmldb_table('quizaccess_proctoring_idv');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('facescore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('namescore', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('extractedname', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('romanizedname', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('matchedprofilename', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('namematchreason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('profilename', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('idimageurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('idbackimageurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('liveimageurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

            $dbman->create_table($table);
        }

        if (get_config('quizaccess_proctoring', 'idverificationenabled') === false) {
            set_config('idverificationenabled', 0, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'idverificationfacethreshold') === false) {
            set_config('idverificationfacethreshold', 80, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'idverificationnamethreshold') === false) {
            set_config('idverificationnamethreshold', 80, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'idverificationretentiondays') === false) {
            set_config('idverificationretentiondays', 30, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026060400, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060500) {
        if (get_config('quizaccess_proctoring', 'idverificationfailuredetails') === false) {
            set_config('idverificationfailuredetails', 0, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026060500, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060501) {
        if (get_config('quizaccess_proctoring', 'idverificationcheckface') === false) {
            set_config('idverificationcheckface', 1, 'quizaccess_proctoring');
        }
        if (get_config('quizaccess_proctoring', 'idverificationcheckname') === false) {
            set_config('idverificationcheckname', 1, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026060501, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060502) {
        $table = new xmldb_table('quizaccess_proctoring_idv');
        $field = new xmldb_field(
            'idbackimageurl',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'idimageurl'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        if (get_config('quizaccess_proctoring', 'idverificationrequireback') === false) {
            set_config('idverificationrequireback', 0, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026060502, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060512) {
        if (get_config('quizaccess_proctoring', 'blurquizwithmultiplemonitors') === false) {
            set_config('blurquizwithmultiplemonitors', 0, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026060512, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060513) {
        // No schema changes. Reorganizes the proctoring admin settings navigation.
        upgrade_plugin_savepoint(true, 2026060513, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060514) {
        // No schema changes. Adds admin settings shortcuts and tabbed section navigation.
        upgrade_plugin_savepoint(true, 2026060514, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060515) {
        if (get_config('quizaccess_proctoring', 'monitormouseactivity') === false) {
            set_config('monitormouseactivity', 0, 'quizaccess_proctoring');
        }

        upgrade_plugin_savepoint(true, 2026060515, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026060516) {
        $table = new xmldb_table('quizaccess_proctoring_idv');
        $fields = [
            new xmldb_field(
                'romanizedname',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null,
                null,
                null,
                'extractedname'
            ),
            new xmldb_field(
                'matchedprofilename',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null,
                null,
                null,
                'romanizedname'
            ),
            new xmldb_field(
                'namematchreason',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null,
                null,
                null,
                'matchedprofilename'
            ),
        ];

        if ($dbman->table_exists($table)) {
            foreach ($fields as $field) {
                if (!$dbman->field_exists($table, $field)) {
                    $dbman->add_field($table, $field);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026060516, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026062403) {
        // Grant the webcam-photo capability to existing teacher and editing-teacher roles so staff
        // can take proctored quizzes for testing. New roles inherit it from the db/access.php archetypes.
        // Overwrite is false, so any existing per-role override is left untouched.
        $systemcontext = context_system::instance();
        $staffroles = array_merge(
            get_archetype_roles('editingteacher'),
            get_archetype_roles('teacher')
        );
        foreach ($staffroles as $staffrole) {
            assign_capability(
                'quizaccess/proctoring:sendcamshot',
                CAP_ALLOW,
                $staffrole->id,
                $systemcontext->id,
                false
            );
        }

        upgrade_plugin_savepoint(true, 2026062403, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026062405) {
        // Add auto-release ceiling annotation fields to the risk holds table so retained holds can
        // record the risk score and reason they were not auto-released.
        $table = new xmldb_table('quizaccess_proctoring_risk_holds');

        $scorefield = new xmldb_field(
            'autoreleaseblockedscore',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'timereviewed'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $scorefield)) {
            $dbman->add_field($table, $scorefield);
        }

        $reasonfield = new xmldb_field(
            'autoreleaseblockedreason',
            XMLDB_TYPE_CHAR,
            '40',
            null,
            null,
            null,
            null,
            'autoreleaseblockedscore'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $reasonfield)) {
            $dbman->add_field($table, $reasonfield);
        }

        upgrade_plugin_savepoint(true, 2026062405, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026062406) {
        // Add the per-student proctoring override tables: the overrides themselves and the
        // append-only audit trail. Both creations are guarded by table_exists so the step is
        // idempotent and safe to co-exist with install.xml on fresh installs.
        $table = new xmldb_table('quizaccess_proctoring_overrides');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('captchastate', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
            $table->add_field('webcamstate', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
            $table->add_field('idverificationstate', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
            $table->add_field('screensharestate', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
            $table->add_field('multimonitorstate', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1');
            $table->add_field('justification', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
            $table->add_field('expiry', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revokedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('grantedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
            $table->add_index('useridcourse', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('revoked', XMLDB_INDEX_NOTUNIQUE, ['revoked']);

            $dbman->create_table($table);
        }

        $audittable = new xmldb_table('quizaccess_proctoring_override_audit');
        if (!$dbman->table_exists($audittable)) {
            $audittable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $audittable->add_field('overrideid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $audittable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $audittable->add_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'create');
            $audittable->add_field('fieldname', XMLDB_TYPE_CHAR, '40', null, null, null, null);
            $audittable->add_field('oldvalue', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $audittable->add_field('newvalue', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $audittable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $audittable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $audittable->add_index('overrideid', XMLDB_INDEX_NOTUNIQUE, ['overrideid']);
            $audittable->add_index('actorid', XMLDB_INDEX_NOTUNIQUE, ['actorid']);

            $dbman->create_table($audittable);
        }

        // No legacy exemption data to migrate. The proctoring-feedback-improvements spec's
        // Requirement 9 webcam/ID "Override_Exemption" was never implemented (its task plan
        // shipped only P0 and P2 items), so there is no prior storage or rule.php consultation
        // path to migrate from or retire. This per-student overrides layer therefore supersedes
        // that concept from the outset: quizaccess_proctoring_overrides is the single source of
        // truth for per-student waivers, resolved exclusively through override_resolver.
        upgrade_plugin_savepoint(true, 2026062406, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072000) {
        // No schema changes. Adds the automatic-failure high-risk action while preserving the
        // existing 0 (disabled) and 1 (hold for review) configuration values.
        upgrade_plugin_savepoint(true, 2026072000, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072006) {
        // Webcam phone detection: add the per-student override tri-state for the new
        // phone-detection requirement (default -1 = inherit the site/quiz state).
        $table = new xmldb_table('quizaccess_proctoring_overrides');
        $field = new xmldb_field(
            'phonedetectionstate',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '-1',
            'multimonitorstate'
        );
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026072006, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072007) {
        // False-positive finding reviews: reviewer verdicts that exclude a risk factor's
        // evidence from an attempt's recomputed risk score.
        $table = new xmldb_table('quizaccess_proctoring_finding_reviews');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('factorkey', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('verdict', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'false_positive');
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('reviewerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revoked', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('revokedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timerevoked', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
            $table->add_index('factorkey', XMLDB_INDEX_NOTUNIQUE, ['factorkey']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026072007, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072105) {
        // No schema changes (settings-page heading removal only). This no-op savepoint advances
        // the stored plugin version for sites upgrading from an earlier release so the plugin is
        // not left reported as pending upgrade.
        upgrade_plugin_savepoint(true, 2026072105, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072106) {
        // No schema changes (Risk factor scoring row-hiding fix and settings toggle overflow fix
        // are UI-only). No-op savepoint to advance the stored plugin version.
        upgrade_plugin_savepoint(true, 2026072106, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072107) {
        // No schema changes (Risk factor scoring validation-feedback relocation is UI-only).
        // No-op savepoint to advance the stored plugin version.
        upgrade_plugin_savepoint(true, 2026072107, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072121) {
        // No schema changes. Covers the UI-only 2026072108-2026072120 releases (which shipped
        // without their own savepoints) and the report action button relabel from "View images"
        // to "View report". No-op savepoint to advance the stored plugin version.
        upgrade_plugin_savepoint(true, 2026072121, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072122) {
        // No schema changes: the attempts report rework is queries, filters and templates, and
        // attempt-level review sign-offs reuse the existing reviewer-verdict table. The bump only
        // refreshes caches for the new strings. upgrade_plugins() writes the version back itself
        // once this function returns, so this savepoint is not what advances it - it keeps the
        // per-version record in this file unbroken, which is how every release here is logged.
        upgrade_plugin_savepoint(true, 2026072122, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072124) {
        // No schema changes. ID exception requests still live in quizaccess_proctoring_events
        // - the eventdetail JSON gained "reason", "category", "detail", and "alternatives"
        // keys - and the honesty statement, handbook link, and day/days changes are text and
        // settings only. No-op savepoint to advance the stored plugin version so the new
        // settings' defaults are applied and the changed web service signature is picked up.
        upgrade_plugin_savepoint(true, 2026072124, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072125) {
        // The ID exception contact address gained a default of contact@saylor.org, but a default
        // declared in settings.php only reaches config when defaults are applied: a CLI upgrade
        // does that, while a web upgrade only does it if the admin walks the "new settings" page
        // and saves it. Every place the address is read treats "not set" as "feature off", so on a
        // site that clicked past that page the self-service ID exception link would stay hidden and
        // decision emails would keep going out with no Reply-To, with nothing to indicate why.
        //
        // Only fill it in when nothing is stored at all. An admin who deliberately emptied the
        // field has chosen to hide the option - that is what the setting's own description
        // promises - so an empty string is a decision to respect, not a gap to fill.
        if (get_config('quizaccess_proctoring', 'idexemptioncontactemail') === false) {
            set_config('idexemptioncontactemail', 'contact@saylor.org', 'quizaccess_proctoring');
        }
        upgrade_plugin_savepoint(true, 2026072125, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072126) {
        // The screen check marker became an admin setting, off by default, having previously
        // been forced on for every desktop attempt. Record that explicitly rather than leaving
        // it to "unset means off": the marker has to be visible in the captured frames, so
        // anything in front of the quiz window - the browser's own share picker or sharing
        // bubble, a notification, another app taking focus as the share is granted - was
        // reported as sharing the wrong screen.
        //
        // Only fill it in when nothing is stored, so a site that has already made a choice
        // keeps it. Sites that want the check back can tick the setting; students still have
        // to share an entire screen either way, and desktop evidence is still captured.
        if (get_config('quizaccess_proctoring', 'requirescreenmarker') === false) {
            set_config('requirescreenmarker', 0, 'quizaccess_proctoring');
        }
        upgrade_plugin_savepoint(true, 2026072126, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072127) {
        // No schema changes: the attempts report moved from cards to a table and gained columns for
        // exam score, duration and account age, all read from existing tables. The bump refreshes
        // the string cache for the new column headings.
        upgrade_plugin_savepoint(true, 2026072127, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026072128) {
        // No schema changes. The student notice for a confirmed violation is strings and markup,
        // and the new quizaccess/proctoring:manageadminsettings capability is installed from
        // db/access.php by the same upgrade this savepoint records. No-op savepoint to advance the
        // stored plugin version.
        upgrade_plugin_savepoint(true, 2026072128, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026081900) {
        // The attempt risk score is no longer capped at 100. The 100 boundary is a presentation
        // choice rather than a measurement, and while the scoring model is still being evaluated
        // the raw total is the useful number: clamping it makes an attempt that scored 240
        // indistinguishable from one that scored 100, which is exactly the comparison the review
        // team needs to make.
        //
        // Written unconditionally, once. The previous shipped default was 1, so a stored 1 cannot
        // be told apart from "never touched" - and this is the change the review team asked for on
        // the live site, not only on fresh installs. Sites that want the cap back tick "Cap attempt
        // risk score at 100" on the Risk factor scoring page; nothing else about scoring changes.
        set_config('riskscorecapenabled', 0, 'quizaccess_proctoring');
        upgrade_plugin_savepoint(true, 2026081900, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026081901) {
        // Reviewer notes. Student Affairs asked for "a place to add a note" on a report: without
        // one, everything a reviewer worked out while reading an attempt lived in their head or in
        // a Zendesk ticket nobody else opening the report would find.
        $table = new xmldb_table('quizaccess_proctoring_notes');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('notetext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('coursequizuser', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'quizid', 'userid']);
        $table->add_index('attemptid', XMLDB_INDEX_NOTUNIQUE, ['attemptid']);
        $table->add_index('authorid', XMLDB_INDEX_NOTUNIQUE, ['authorid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026081901, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026081902) {
        // No schema changes: the ID verification block on the report, the docked in-exam banner,
        // the device notice, the camera aspect-ratio fixes and the feedback link are markup, CSS
        // and JavaScript. No-op savepoint so the new strings and the new "Student feedback form
        // URL" setting's (empty) default are picked up.
        upgrade_plugin_savepoint(true, 2026081902, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082000) {
        // No schema changes: the ID verification request body is now budgeted before it is sent,
        // which is code only. No-op savepoint so the new student-facing string is picked up.
        upgrade_plugin_savepoint(true, 2026082000, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082001) {
        // No schema changes: the ID verification verdict is code, and the three new settings take
        // their defaults from settings.php. No-op savepoint so those defaults are applied and the
        // new strings are picked up.
        upgrade_plugin_savepoint(true, 2026082001, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082700) {
        // No schema changes and no behaviour changes: the ID verification payload budgeting moved
        // from the web service class into local\id_verification_payload so it can be unit tested
        // without an isolated process, and three test expectations were corrected. No-op savepoint
        // to advance the stored version.
        upgrade_plugin_savepoint(true, 2026082700, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082701) {
        // No schema changes. A name mismatch no longer fails an attempt by default: the new
        // idverificationnameblocks setting takes its default of 0 from settings.php, and the
        // verdict, the report and the strings are code. No-op savepoint so the default is applied.
        upgrade_plugin_savepoint(true, 2026082701, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082702) {
        // No schema changes: Start attempt stepper layout and the screen monitor helper's focus
        // behaviour are JavaScript and CSS. No-op savepoint to advance the stored version.
        upgrade_plugin_savepoint(true, 2026082702, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082800) {
        // No schema changes: the settings page's required capability is a code fix. No-op savepoint
        // to advance the stored version.
        upgrade_plugin_savepoint(true, 2026082800, 'quizaccess', 'proctoring');
    }

    if ($oldversion < 2026082801) {
        // No schema changes: a dead login session behind the quiz tab now surfaces as one
        // actionable banner instead of a permissions modal per failed upload. JavaScript and a
        // string; no-op savepoint so the string is picked up.
        upgrade_plugin_savepoint(true, 2026082801, 'quizaccess', 'proctoring');
    }

    return true;
}
