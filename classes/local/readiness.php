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
 * Pre-exam device and service readiness checks.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring\local;

/**
 * Provides bounded service probes without transmitting student evidence.
 */
final class readiness {
    /**
     * Whether the optional readiness page is available.
     *
     * @return bool
     */
    public static function enabled(): bool {
        $value = get_config('quizaccess_proctoring', 'readinessenabled');
        return $value === false || $value === '' || (int)$value === 1;
    }

    /**
     * Check access to an enabled proctored quiz.
     *
     * @param int $cmid Course module ID.
     * @return \stdClass Proctoring settings for the quiz.
     */
    public static function require_access(int $cmid): \stdClass {
        global $DB;
        $context = \context_module::instance($cmid);
        require_capability('quizaccess/proctoring:sendcamshot', $context);
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $settings = $DB->get_record('quizaccess_proctoring', ['quizid' => $cm->instance, 'proctoringrequired' => 1]);
        if (!$settings || !self::enabled()) {
            throw new \invalid_parameter_exception('Readiness checks are unavailable for this quiz.');
        }
        return $settings;
    }

    /**
     * Resolve requirements for the current student's next attempt.
     *
     * @param \stdClass|\cm_info $cm Course module from the database or Moodle page context.
     * @param \stdClass $settings Quiz proctoring settings.
     * @param int $userid Student ID.
     * @return array Effective readiness requirements.
     */
    public static function requirements(\stdClass|\cm_info $cm, \stdClass $settings, int $userid): array {
        $screen = (int)($settings->requireentirescreen ?? -1);
        if ($screen === -1) {
            $site = get_config('quizaccess_proctoring', 'requireentirescreen');
            $screen = $site === false || $site === '' ? 1 : (int)$site;
        }
        $mobilemode = get_config('quizaccess_proctoring', 'mobilescreensharemode');
        if (!in_array($mobilemode, ['bypass', 'require', 'block'], true)) {
            $mobilemode = 'bypass';
        }
        // Match preflight: apply the device default before any explicit student override.
        if (
            $mobilemode === 'bypass' && in_array(\core_useragent::get_device_type(), [
                \core_useragent::DEVICETYPE_MOBILE, \core_useragent::DEVICETYPE_TABLET,
            ], true)
        ) {
            $screen = 0;
        }
        return override_resolver::resolve_all((int)$cm->course, (int)$cm->instance, $userid, time(), [
            override_resolver::REQ_WEBCAM => (int)get_config('quizaccess_proctoring', 'fcheckstartchk') === 1,
            override_resolver::REQ_IDVERIFICATION => (int)get_config('quizaccess_proctoring', 'idverificationenabled') === 1,
            override_resolver::REQ_SCREENSHARE => $screen === 1,
        ]);
    }

    /**
     * Check only providers used by this quiz; return no URLs, credentials or raw responses.
     *
     * @param array $requirements Effective student requirements.
     * @return array Safe provider status rows.
     */
    public static function providers(array $requirements): array {
        $faceenabled = get_config('quizaccess_proctoring', 'fcmethod') === 'customapi';
        $definitions = [
            'face' => [
                $faceenabled || !empty($requirements[override_resolver::REQ_WEBCAM]),
                $faceenabled ? (string)get_config('quizaccess_proctoring', 'custom_ai_endpoint') : '',
                (string)get_config('quizaccess_proctoring', 'custom_api_key'),
            ],
            'identity' => [
                !empty($requirements[override_resolver::REQ_IDVERIFICATION]),
                (string)get_config('quizaccess_proctoring', 'idverificationendpoint'),
                (string)get_config('quizaccess_proctoring', 'idverificationapikey'),
            ],
        ];
        $rows = [];
        foreach ($definitions as $name => [$required, $endpoint, $key]) {
            $status = !$required ? 'notrequired' : self::cached_probe(trim($endpoint), $key);
            $rows[] = ['name' => $name, 'status' => $status];
        }
        return $rows;
    }

    /**
     * Share short-lived probe results across students to avoid flooding providers.
     *
     * @param string $endpoint Configured service URL.
     * @param string $apikey Configured API key.
     * @return string Safe readiness status.
     */
    private static function cached_probe(string $endpoint, string $apikey): string {
        if ($endpoint === '') {
            return 'notconfigured';
        }
        $key = hash('sha256', $endpoint . "\0" . $apikey);
        $cache = \cache::make('quizaccess_proctoring', 'readiness');
        $cached = $cache->get($key);
        if ($cached !== false) {
            return $cached;
        }
        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_proctoring_readiness');
        $lock = $factory->get_lock($key, 0);
        if (!$lock) {
            return 'busy';
        }
        try {
            // A concurrent request may have completed while we acquired the lock.
            $cached = $cache->get($key);
            if ($cached !== false) {
                return $cached;
            }
            $status = self::probe($endpoint, $apikey);
            $cache->set($key, $status);
            return $status;
        } finally {
            $lock->release();
        }
    }

    /**
     * Probe HTTPS connectivity with HEAD, without uploading images.
     *
     * HTTP 405 proves the service responds but does not prove verification will succeed.
     *
     * @param string $endpoint Configured endpoint.
     * @param string $apikey Provider API key.
     * @param callable|null $transport Optional bounded transport for offline tests.
     * @return string Safe status; errors never disclose provider details.
     */
    public static function probe(string $endpoint, string $apikey, ?callable $transport = null): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        try {
            $options = outbound_endpoint_validator::request_options($endpoint) + [
                'CURLOPT_CONNECTTIMEOUT' => 3,
                'CURLOPT_TIMEOUT' => 5,
                'CURLOPT_HTTPHEADER' => $apikey === '' ? [] : ['X-API-Key: ' . $apikey],
            ];
            if ($transport) {
                [$code, $errno] = $transport($endpoint, $options);
            } else {
                $curl = new \curl();
                $curl->head($endpoint, $options);
                $code = (int)($curl->get_info()['http_code'] ?? 0);
                $errno = $curl->get_errno();
            }
            if ($errno) {
                return 'unavailable';
            }
            if ($code === 401 || $code === 403) {
                return 'authentication';
            }
            if ($code === 429 || $code === 503) {
                return 'busy';
            }
            return ($code >= 200 && $code < 300) || $code === 405 ? 'reachable' : 'unavailable';
        } catch (\moodle_exception $e) {
            return 'unavailable';
        }
    }
}
