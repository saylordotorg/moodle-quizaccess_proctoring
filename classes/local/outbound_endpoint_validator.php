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
 * Outbound endpoint validation service.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates configured outbound API endpoints before proctoring data is sent.
 */
final class outbound_endpoint_validator {

    /**
     * Normalize an OpenAI-compatible endpoint to the chat completions route when only the service root is configured.
     *
     * @param string $endpoint Configured endpoint URL.
     * @return string Endpoint URL to call.
     */
    public static function normalize_compatible_endpoint(string $endpoint): string {
        $endpoint = rtrim(trim($endpoint), '/');
        if ($endpoint === '') {
            return '';
        }

        $path = (string)(parse_url($endpoint, PHP_URL_PATH) ?: '');
        if ($path === '' || $path === '/') {
            return $endpoint . '/v1/chat/completions';
        }
        if (preg_match('#/v1$#', $path)) {
            return $endpoint . '/chat/completions';
        }

        return $endpoint;
    }

    /**
     * Validates a configured outbound endpoint before the server sends proctoring images to it.
     *
     * @param string $endpoint Endpoint URL.
     * @param callable|null $resolver Optional host resolver for tests.
     * @return string Trimmed endpoint URL.
     * @throws \moodle_exception If the endpoint is invalid or resolves to a blocked address.
     */
    public static function validate(string $endpoint, ?callable $resolver = null): string {
        $endpoint = trim($endpoint);
        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }
        if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }

        $host = trim((string)$parts['host'], '[]');
        if ($host === '' || strtolower($host) === 'localhost') {
            throw new \moodle_exception('outboundendpointblocked', 'quizaccess_proctoring');
        }

        $ips = $resolver ? (array)$resolver($host) : self::resolve_host_ips($host);
        if (!$ips) {
            throw new \moodle_exception('outboundendpointunresolved', 'quizaccess_proctoring');
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \moodle_exception('outboundendpointblocked', 'quizaccess_proctoring');
            }
        }

        return $endpoint;
    }

    /**
     * Resolves a host to IP addresses for outbound endpoint validation.
     *
     * @param string $host Hostname or IP address.
     * @return array IP addresses.
     */
    public static function resolve_host_ips(string $host): array {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (!$ips) {
            $records = @gethostbynamel($host);
            if (is_array($records)) {
                $ips = array_merge($ips, $records);
            }
        }

        return array_values(array_unique(array_filter($ips, 'strlen')));
    }
}
