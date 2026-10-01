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
        if (preg_match('/[\x00-\x20\x7f]/', $endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }
        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }
        if (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535)) {
            throw new \moodle_exception('outboundendpointinvalid', 'quizaccess_proctoring');
        }

        $scheme = strtolower((string)$parts['scheme']);
        // Requests contain biometric images and credentials; never send them in cleartext.
        if ($scheme !== 'https') {
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
            if (!self::is_public_ip((string)$ip)) {
                throw new \moodle_exception('outboundendpointblocked', 'quizaccess_proctoring');
            }
        }

        return $endpoint;
    }

    /**
     * Validate and pin a request to the same addresses that passed validation.
     *
     * Moodle's curl wrapper still applies its configured host blocks and proxy settings. A proxy
     * which resolves the destination itself must enforce the same network restrictions: cURL's
     * local DNS pins cannot control DNS resolution performed by that proxy.
     *
     * @param string $endpoint Endpoint URL.
     * @param callable|null $resolver Optional host resolver for tests.
     * @return array cURL options, to merge into the request options without overriding these keys.
     */
    public static function request_options(string $endpoint, ?callable $resolver = null): array {
        $ips = [];
        $endpoint = self::validate($endpoint, static function (string $host) use ($resolver, &$ips): array {
            $ips = $resolver ? (array)$resolver($host) : self::resolve_host_ips($host);
            return $ips;
        });
        $host = trim((string)parse_url($endpoint, PHP_URL_HOST), '[]');
        $port = (int)(parse_url($endpoint, PHP_URL_PORT) ?: 443);
        // Pinning replaces the DNS entries used by Moodle's curl wrapper. Check every pinned
        // address against Moodle's site policy too, so a later DNS answer cannot mask a blocked IP.
        $security = new \core\files\curl_security_helper();
        foreach ($ips as $ip) {
            $iphost = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
            if ($security->url_is_blocked('https://' . $iphost . ':' . $port . '/')) {
                throw new \moodle_exception('outboundendpointblocked', 'quizaccess_proctoring');
            }
        }
        $options = [
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTPS,
        ];
        // Literal addresses have no second DNS lookup to pin. IPv6 literal host pins require a
        // much newer libcurl than the versions supported by Moodle.
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = array_map(static function (string $ip): string {
                return strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
            }, $ips);
            $options['CURLOPT_RESOLVE'] = [$host . ':' . $port . ':' . implode(',', $addresses)];
        }
        return $options;
    }

    /**
     * Reject private, local, multicast and address-translation destinations on supported PHP versions.
     *
     * @param string $ip Resolved IP address.
     * @return bool Whether the address may receive proctoring data.
     */
    private static function is_public_ip(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $packed = inet_pton($ip);
        if (strlen($packed) === 4) {
            $octets = array_values(unpack('C4', $packed));
            // FILTER_FLAG_NO_RES_RANGE does not reject shared address space or multicast.
            return !($octets[0] >= 224 ||
                ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127) ||
                ($octets[0] === 198 && ($octets[1] === 18 || $octets[1] === 19)));
        }
        // Restrict IPv6 to native global unicast. This also excludes IPv4-mapped, NAT64,
        // deprecated site-local and multicast addresses even on older PHP runtimes.
        if ((ord($packed[0]) & 0xe0) !== 0x20) {
            return false;
        }
        // Transition addresses can route an apparently public IPv6 destination to private IPv4.
        return substr($packed, 0, 2) !== "\x20\x02" &&
            substr($packed, 0, 4) !== "\x20\x01\x00\x00";
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
