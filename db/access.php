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
 * Access for the quizaccess_proctoring plugin.
 *
 * This file defines the capabilities for the quizaccess_proctoring plugin in Moodle,
 * which allows monitoring and proctoring of quizzes by capturing webcam camshot,
 * viewing proctoring logs, and deleting them when needed. These capabilities ensure
 * that only authorized roles can perform certain actions on the proctoring logs and images.
 *
 * @package    quizaccess_proctoring
 * @category   access
 * @copyright  2024 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// List of capabilities for the quizaccess_proctoring plugin.
$capabilities = [
    // This allows the student or manager to send a webcam screenshot when proctoring is active.
    'quizaccess/proctoring:sendcamshot' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW, // Editing teachers may send camshots when testing a proctored quiz.
            'teacher' => CAP_ALLOW, // Non-editing teachers may send camshots when testing a proctored quiz.
            'student' => CAP_ALLOW, // Students are allowed to send camshot.
            'manager' => CAP_ALLOW, // Managers can also send camshot.
        ],
    ],

    // This capability allows teachers, editing teachers, and managers to view the proctoring report.
    'quizaccess/proctoring:viewreport' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW, // Teachers can view the report.
            'editingteacher' => CAP_ALLOW, // Editing teachers can view the report.
            'manager' => CAP_ALLOW, // Managers can view the report.
        ],
    ],

    // This capability allows editing teachers and managers to delete webcam camshot.
    'quizaccess/proctoring:deletecamshots' => [
        'riskbitmask' => RISK_DATALOSS | RISK_PERSONAL, // Action involves potential personal-data loss.
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW, // Editing teachers can delete camshot.
            'manager' => CAP_ALLOW, // Managers can delete camshot.
        ],
    ],

    // This capability allows teachers, editing teachers, and managers to analyze the webcam camshot.
    'quizaccess/proctoring:analyzeimages' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW, // Teachers can analyze images.
            'editingteacher' => CAP_ALLOW, // Editing teachers can analyze images.
            'manager' => CAP_ALLOW, // Managers can analyze images.
        ],
    ],

    // This capability allows editing teachers and managers to release high-risk grade holds after review.
    'quizaccess/proctoring:reviewriskholds' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // This capability allows editing teachers and managers to grant, edit, and revoke per-student proctoring overrides.
    'quizaccess/proctoring:manageoverrides' => [
        'riskbitmask' => RISK_PERSONAL, // Justifications may contain personal accommodation data.
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW, // Editing teachers can manage per-student overrides.
            'manager' => CAP_ALLOW, // Managers can manage per-student overrides.
        ],
    ],

    // This capability allows managers to open the plugin's site administration settings without
    // holding moodle/site:config. It is a system-context capability because the settings are
    // site-wide. Settings that store provider credentials (the API key and secret-key fields) and
    // the AI review section remain gated on moodle/site:config, so this capability never exposes
    // a secret; see settings.php.
    'quizaccess/proctoring:manageadminsettings' => [
        'riskbitmask' => RISK_CONFIG, // Holders change site-wide proctoring enforcement.
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW, // Managers can administer the proctoring settings.
        ],
    ],

    // This capability lets the SIS web-service user read per-attempt proctoring summaries for every
    // student (SIS-204). System context and no archetype: it is granted to one integration role on
    // purpose, never inherited by managers or teachers. Summaries carry no images or notes, but they
    // are still per-student integrity data, hence RISK_PERSONAL.
    'quizaccess/proctoring:exportsummaries' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    // Opens the cross-course review queue and attempts report without site administration rights,
    // for a central integrity office such as Student Affairs (CPIT-474). Granted at system level, or
    // on a category to limit it to that category's courses. It shows only the courses where the
    // holder also has viewreport, reviewriskholds or manageoverrides, so it widens no access by
    // itself.
    'quizaccess/proctoring:reviewacrosscourses' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
