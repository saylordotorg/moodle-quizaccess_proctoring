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
 * Tests for budgeting the ID verification request body.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring;

use quizaccess_proctoring\local\id_verification_payload;

defined('MOODLE_INTERNAL') || die();

/**
 * The endpoint refuses a request over 6 MB before it runs, so the body is budgeted before it is
 * sent. These tests cover the three things that budgeting has to get right: a body that already
 * fits is not touched, a body that does not fit is brought under the budget, and the reduction is
 * taken from the images that are actually oversized rather than spread across all of them.
 *
 * @covers \quizaccess_proctoring\local\id_verification_payload
 */
final class id_verification_payload_test extends \basic_testcase {

    /**
     * Build a JPEG of roughly a target size, with enough noise that it will not compress away.
     *
     * Flat colour compresses to almost nothing whatever its dimensions, which would make a size
     * test meaningless, so every pixel gets its own value.
     *
     * @param int $width Width in pixels.
     * @param int $height Height in pixels.
     * @param int $quality JPEG quality.
     * @return string JPEG bytes.
     */
    private function noisy_jpeg(int $width, int $height, int $quality = 100): string {
        $image = imagecreatetruecolor($width, $height);
        // A fixed seed keeps the generated fixture identical between runs, so a failure is
        // reproducible rather than a coin toss.
        mt_srand(20260820);
        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $colour = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, $colour);
            }
        }
        ob_start();
        imagejpeg($image, null, $quality);
        $bytes = (string)ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Skip the test when the PHP build cannot make the fixtures.
     */
    private function require_gd(): void {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD with JPEG support is required.');
        }
    }

    /**
     * base64_encoded_size() reports what base64_encode() actually produces.
     */
    public function test_encoded_size_matches_base64_encode(): void {
        foreach ([0, 1, 2, 3, 4, 100, 999, 1000, 65536] as $length) {
            $raw = str_repeat('x', $length);
            $this->assertSame(
                strlen(base64_encode($raw)),
                id_verification_payload::encoded_size($length),
                'encoded size mismatch for a ' . $length . ' byte string'
            );
        }
    }

    /**
     * A body that already fits is returned byte-identical.
     *
     * Re-encoding a capture that would have worked is a quality regression for no benefit, and the
     * captures that worked before this change must keep working exactly as they did.
     */
    public function test_a_body_that_fits_is_left_alone(): void {
        $this->require_gd();

        $images = [
            'id_image' => $this->noisy_jpeg(400, 300, 80),
            'live_image' => $this->noisy_jpeg(200, 200, 80),
        ];
        [$fitted, $fits, $note] = id_verification_payload::fit($images, 2048);

        $this->assertTrue($fits);
        $this->assertSame('', $note, 'a body that fits should report no reduction');
        $this->assertSame($images['id_image'], $fitted['id_image']);
        $this->assertSame($images['live_image'], $fitted['live_image']);
    }

    /**
     * An oversized body is brought under the budget.
     */
    public function test_an_oversized_body_is_reduced_under_the_budget(): void {
        $this->require_gd();

        $budget = id_verification_payload::MAX_PAYLOAD_BYTES;
        $reserve = 2048;
        $images = [
            'id_image' => $this->noisy_jpeg(2200, 1700),
            'id_back_image' => $this->noisy_jpeg(2200, 1700),
            'live_image' => $this->noisy_jpeg(800, 1000),
        ];

        $before = 0;
        foreach ($images as $bytes) {
            $before += id_verification_payload::encoded_size(strlen($bytes));
        }
        $this->assertGreaterThan(
            $budget,
            $before + $reserve,
            'the fixture must start over the budget or the test proves nothing'
        );

        [$fitted, $fits, $note] = id_verification_payload::fit($images, $reserve);

        $after = 0;
        foreach ($fitted as $bytes) {
            $after += id_verification_payload::encoded_size(strlen($bytes));
        }

        $this->assertTrue($fits, 'the body should fit after reduction: ' . $note);
        $this->assertLessThanOrEqual($budget, $after + $reserve);
        $this->assertNotSame('', $note, 'a reduction should be described for the debugging log');
    }

    /**
     * Every image stays readable: reduction never drops an image below its dimension floor.
     */
    public function test_reduction_keeps_images_decodable_and_above_the_floor(): void {
        $this->require_gd();

        $images = [
            'id_image' => $this->noisy_jpeg(2200, 1700),
            'id_back_image' => $this->noisy_jpeg(2200, 1700),
            'live_image' => $this->noisy_jpeg(800, 1000),
        ];
        [$fitted] = id_verification_payload::fit($images, 2048);

        $floors = [
            'id_image' => id_verification_payload::MIN_DOCUMENT_LONG_EDGE,
            'id_back_image' => id_verification_payload::MIN_DOCUMENT_LONG_EDGE,
            'live_image' => id_verification_payload::MIN_LIVE_LONG_EDGE,
        ];
        foreach ($fitted as $role => $bytes) {
            $info = getimagesizefromstring($bytes);
            $this->assertIsArray($info, $role . ' should still be a decodable image');
            $this->assertGreaterThanOrEqual(
                $floors[$role],
                max((int)$info[0], (int)$info[1]),
                $role . ' was reduced below its long-edge floor'
            );
        }
    }

    /**
     * The allowance is taken from the largest images, not shared out in proportion.
     *
     * This is the behaviour that protects the face matcher: when one oversized document breaches
     * the budget, the selfie beside it keeps its full quality rather than being cut by the same
     * ratio. A proportional split would shrink it too, and it is the only input the face check has.
     */
    public function test_a_small_image_is_untouched_when_a_large_one_is_the_problem(): void {
        $small = str_repeat('s', 100000);
        $large = str_repeat('l', 8000000);
        $allowance = 3000000;

        $targets = id_verification_payload::allocate_budgets(
            ['id_image' => $large, 'live_image' => $small],
            $allowance
        );

        $this->assertSame(
            strlen($small),
            $targets['live_image'],
            'the small image should keep its full size'
        );
        $this->assertLessThan(
            strlen($large),
            $targets['id_image'],
            'the large image should absorb the reduction'
        );
        $this->assertLessThanOrEqual(
            $allowance,
            $targets['id_image'] + $targets['live_image'],
            'the allocation must not exceed the allowance'
        );
    }

    /**
     * With no room left for images at all, the body is reported as not fitting rather than sent.
     */
    public function test_no_remaining_budget_reports_failure(): void {
        $budget = id_verification_payload::MAX_PAYLOAD_BYTES;
        [, $fits, $note] = id_verification_payload::fit(
            ['id_image' => 'x', 'live_image' => 'y'],
            $budget + 1
        );

        $this->assertFalse($fits);
        $this->assertNotSame('', $note);
    }
}
