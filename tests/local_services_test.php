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
 * Unit tests for local service classes.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\outbound_endpoint_validator;
use quizaccess_proctoring\local\risk_calculator;

/**
 * Local service class tests.
 */
final class local_services_test extends advanced_testcase {
    /**
     * Risk factors should clamp points to the configured maximum while preserving evidence count.
     *
     * @covers \quizaccess_proctoring\local\risk_calculator
     */
    public function test_risk_factor_points_are_clamped(): void {
        $this->assertSame([
            'label' => 'Screenshare',
            'count' => 3,
            'points' => 10,
            'haspoints' => true,
        ], risk_calculator::build_factor('Screenshare', 3, 5, 10));

        $this->assertSame([
            'label' => 'No face',
            'count' => -2,
            'points' => 0,
            'haspoints' => false,
        ], risk_calculator::build_factor('No face', -2, 8, 24));
    }

    /**
     * Shortcut matching should be case-insensitive and tolerate malformed event details.
     *
     * @covers \quizaccess_proctoring\local\risk_calculator
     */
    public function test_shortcut_matching_is_case_insensitive_and_json_safe(): void {
        $this->assertTrue(risk_calculator::event_has_shortcut('{"shortcut":"f12"}', 'F12'));
        $this->assertTrue(risk_calculator::event_has_shortcut('{"shortcut":"CTRL+C"}', 'ctrl+c'));
        $this->assertFalse(risk_calculator::event_has_shortcut('{"shortcut":"CTRL+V"}', 'CTRL+C'));
        $this->assertFalse(risk_calculator::event_has_shortcut('not-json', 'F12'));
        $this->assertFalse(risk_calculator::event_has_shortcut('{"key":"F12"}', 'F12'));
    }

    /**
     * Compatible AI endpoint roots should normalize to the chat completions route.
     *
     * @covers \quizaccess_proctoring\local\outbound_endpoint_validator
     */
    public function test_compatible_endpoint_normalization(): void {
        $this->assertSame('', outbound_endpoint_validator::normalize_compatible_endpoint('  '));
        $this->assertSame(
            'https://api.example.test/v1/chat/completions',
            outbound_endpoint_validator::normalize_compatible_endpoint(' https://api.example.test ')
        );
        $this->assertSame(
            'https://api.example.test/v1/chat/completions',
            outbound_endpoint_validator::normalize_compatible_endpoint('https://api.example.test/v1')
        );
        $this->assertSame(
            'https://api.example.test/custom/chat',
            outbound_endpoint_validator::normalize_compatible_endpoint('https://api.example.test/custom/chat')
        );
    }

    /**
     * Endpoint validation should be testable without live DNS and should trim accepted endpoints.
     *
     * @covers \quizaccess_proctoring\local\outbound_endpoint_validator
     */
    public function test_outbound_endpoint_validation_supports_injected_resolution(): void {
        $resolvedhosts = [];
        $resolver = static function (string $host) use (&$resolvedhosts): array {
            $resolvedhosts[] = $host;
            return ['8.8.8.8'];
        };

        $this->assertSame(
            'https://api.example.test/v1/chat/completions',
            outbound_endpoint_validator::validate(' https://api.example.test/v1/chat/completions ', $resolver)
        );
        $this->assertSame(['api.example.test'], $resolvedhosts);
    }

    /**
     * Endpoint validation should reject invalid ports before any resolver callback is used.
     *
     * @covers \quizaccess_proctoring\local\outbound_endpoint_validator
     */
    public function test_outbound_endpoint_validation_blocks_invalid_ports(): void {
        $resolvercalled = false;
        $resolver = static function () use (&$resolvercalled): array {
            $resolvercalled = true;
            return ['8.8.8.8'];
        };

        try {
            outbound_endpoint_validator::validate('https://api.example.test:0/v1/chat/completions', $resolver);
            $this->fail('Endpoint with port 0 was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('outboundendpointinvalid', $e->errorcode);
        }

        $this->assertFalse($resolvercalled);
    }

    /**
     * Endpoint validation should reject hostnames that resolve to private infrastructure.
     *
     * @covers \quizaccess_proctoring\local\outbound_endpoint_validator
     */
    public function test_outbound_endpoint_validation_blocks_private_resolved_ips(): void {
        try {
            outbound_endpoint_validator::validate(
                'https://api.example.test/v1/chat/completions',
                static function (): array {
                    return ['10.0.0.5'];
                }
            );
            $this->fail('Endpoint resolving to a private address was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('outboundendpointblocked', $e->errorcode);
        }
    }
}
