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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Readiness check AJAX function.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use quizaccess_proctoring\local\readiness as readiness_service;

/**
 * Checks authenticated Moodle connectivity and configured provider reachability.
 */
final class readiness extends external_api {
    /**
     * Declare a bounded, non-biometric upload probe.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Proctored quiz course module'),
            'providers' => new external_value(PARAM_BOOL, 'Also probe configured services', VALUE_DEFAULT, false),
            'payload' => new external_value(PARAM_RAW, 'Synthetic connectivity test data, at most 64 KiB', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Return readiness without creating an attempt or storing test device data.
     *
     * @param int $cmid Course module ID.
     * @param bool $providers Whether to check services.
     * @param string $payload Non-sensitive test data.
     * @return array Readiness response.
     */
    public static function execute(int $cmid, bool $providers = false, string $payload = ''): array {
        global $USER;
        if (strlen($payload) > 65536) {
            throw new \invalid_parameter_exception('Readiness payload exceeds 64 KiB.');
        }
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'providers', 'payload'));
        $context = \context_module::instance($params['cmid']);
        self::validate_context($context);
        $settings = readiness_service::require_access($params['cmid']);
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $requirements = readiness_service::requirements($cm, $settings, (int)$USER->id);
        return [
            'receivedbytes' => strlen($params['payload']),
            'providers' => $params['providers'] ? readiness_service::providers($requirements) : [],
        ];
    }

    /**
     * Declare safe, bounded response values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'receivedbytes' => new external_value(PARAM_INT, 'Synthetic bytes received'),
            'providers' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_ALPHANUMEXT, 'Configured service role'),
                'status' => new external_value(PARAM_ALPHANUMEXT, 'Readiness status'),
            ])),
        ]);
    }
}
