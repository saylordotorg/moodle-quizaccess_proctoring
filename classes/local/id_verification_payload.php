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
 * Fitting the ID verification request body into what the endpoint will accept.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * Keeps the ID verification request small enough to be delivered.
 *
 * This lives outside the web service class deliberately. It is pure byte and image work - no
 * request, no session, no capability - and the external class cannot be loaded by a unit test
 * without an isolated process, because Moodle's own lib/externallib.php calls
 * require_phpunit_isolation(). Logic that can be tested directly should not be behind that.
 */
final class id_verification_payload {

    /**
     * Maximum size of the assembled ID verification request body, in bytes.
     *
     * An AWS Lambda function URL - the shape of endpoint this integration is built against -
     * refuses any request over 6 MB and does so before the function is invoked, so the rejection
     * arrives instantly and leaves no trace on the provider side. The images are base64-encoded
     * into JSON, which costs a third on top of their raw size, and the accepted input per image is
     * measured in megabytes: three captures at the sizes students actually produce cleared 6 MB
     * routinely, and every one of those attempts failed with "the provider is unavailable".
     *
     * So the body is budgeted before it is sent. 5 MB leaves room for the envelope and for any
     * proxy that adds to a request in flight, and the transmitted copies of the images are
     * re-encoded to fit it. Only the transmitted copies: the stored evidence keeps the full
     * resolution the student's camera produced.
     */
    const MAX_PAYLOAD_BYTES = 5242880;

    /** Smallest long edge an ID document is reduced to, in pixels. Below this the OCR suffers. */
    const MIN_DOCUMENT_LONG_EDGE = 1400;

    /** Smallest long edge the live comparison photo is reduced to, in pixels. */
    const MIN_LIVE_LONG_EDGE = 480;

    /** Floor for a single image's share of the payload budget, in bytes. */
    const MIN_IMAGE_TARGET_BYTES = 122880;

    /**
     * The number of bytes a raw string costs once base64-encoded.
     *
     * @param int $rawbytes Raw byte count.
     * @return int Encoded byte count.
     */
    public static function encoded_size(int $rawbytes): int {
        return (int)(ceil($rawbytes / 3) * 4);
    }

    /**
     * Re-encode an image so it fits a byte ceiling, giving up quality before giving up detail.
     *
     * Quality is spent first because an ID document's legibility depends far more on its pixel
     * dimensions than on its JPEG quality: a 60-quality 2000px scan still OCRs, a pristine 800px
     * one often does not. Only when quality alone cannot reach the ceiling are dimensions reduced,
     * and never below the caller's floor - past that point sending a smaller image just converts
     * a transport failure into a verification failure, which is worse because it looks like the
     * student's fault.
     *
     * @param string $bytes Raw image bytes.
     * @param int $maxbytes Ceiling to fit under.
     * @param int $minlongedge Smallest acceptable long edge, in pixels.
     * @return string Re-encoded bytes, or the original when GD cannot read it or nothing helped.
     */
    public static function recompress_to_fit(string $bytes, int $maxbytes, int $minlongedge): string {
        if (strlen($bytes) <= $maxbytes || !function_exists('imagecreatefromstring')) {
            return $bytes;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return $bytes;
        }

        $best = $bytes;
        $encode = static function ($resource, int $quality): string {
            ob_start();
            imagejpeg($resource, null, $quality);

            return (string)ob_get_clean();
        };

        $current = $image;
        $scales = [1.0, 0.8, 0.64, 0.5, 0.4];
        foreach ($scales as $scale) {
            if ($scale < 1.0) {
                $width = (int)round(imagesx($image) * $scale);
                $height = (int)round(imagesy($image) * $scale);
                if (max($width, $height) < $minlongedge) {
                    break;
                }
                $scaled = @imagescale($image, $width, $height);
                if ($scaled === false) {
                    break;
                }
                if ($current !== $image) {
                    imagedestroy($current);
                }
                $current = $scaled;
            }

            foreach ([85, 75, 65, 55, 45] as $quality) {
                $candidate = $encode($current, $quality);
                if ($candidate !== '' && strlen($candidate) < strlen($best)) {
                    $best = $candidate;
                }
                if ($candidate !== '' && strlen($candidate) <= $maxbytes) {
                    if ($current !== $image) {
                        imagedestroy($current);
                    }
                    imagedestroy($image);

                    return $candidate;
                }
            }
        }

        if ($current !== $image) {
            imagedestroy($current);
        }
        imagedestroy($image);

        // Still over the ceiling, but the smallest version found is what the caller wants: it may
        // fit once the other images have been reduced too.
        return $best;
    }

    /**
     * Divide a byte allowance between images, taking it from the largest ones first.
     *
     * Shrinking every image by the same ratio is the obvious approach and the wrong one: when one
     * oversized ID capture is what breached the budget, a proportional cut also strips most of the
     * quality out of the selfie beside it, and that selfie is the face matcher's only input. This
     * is max-min fair instead - each image is offered an equal share, anything already smaller than
     * its share keeps its full size, and what it does not use is redistributed to the images that
     * are over. So a 990 KB selfie next to an 8 MB document is left alone entirely and the document
     * absorbs the reduction, which is also where there is the most redundant detail to lose.
     *
     * @param array $images Raw bytes keyed by role.
     * @param int $allowance Total raw bytes available.
     * @return array<string, int> Target byte count per role.
     */
    public static function allocate_budgets(array $images, int $allowance): array {
        $sizes = [];
        foreach ($images as $role => $bytes) {
            $sizes[$role] = strlen($bytes);
        }
        // Smallest first, so each pass hands out the tightest share and frees the remainder.
        asort($sizes);

        $targets = [];
        $remaining = $allowance;
        $count = count($sizes);
        foreach ($sizes as $role => $size) {
            $share = $count > 0 ? (int)floor($remaining / $count) : 0;
            $target = min($size, max(self::MIN_IMAGE_TARGET_BYTES, $share));
            $targets[$role] = $target;
            $remaining -= $target;
            $count--;
        }

        return $targets;
    }

    /**
     * Reduce the transmitted images until the whole request body fits the transport budget.
     *
     * Targets are proportional to each image's current size, so the capture that is actually
     * oversized absorbs most of the reduction and a modest one is left alone.
     *
     * @param array $images Raw bytes keyed by role: 'id_image', 'id_back_image', 'live_image'.
     * @param int $reserve Bytes the rest of the request body occupies.
     * @return array [$images, $fits, $note] - the (possibly re-encoded) images, whether the body
     *               now fits, and a short description of what was done for the debugging log.
     */
    public static function fit(array $images, int $reserve): array {
        $budget = self::MAX_PAYLOAD_BYTES - $reserve;
        if ($budget <= 0) {
            return [$images, false, 'no budget left after the request envelope'];
        }

        $encodedtotal = static function (array $set): int {
            $total = 0;
            foreach ($set as $bytes) {
                $total += self::encoded_size(strlen($bytes));
            }

            return $total;
        };

        $before = $encodedtotal($images);
        if ($before <= $budget) {
            return [$images, true, ''];
        }

        // Work in raw bytes from here: base64 is a fixed 4/3 multiplier, so a raw allowance is the
        // same constraint expressed in the units the encoders actually produce. 0.97 keeps a little
        // headroom so a near-miss does not cost another pass.
        $rawallowance = (int)floor((($budget * 3) / 4) * 0.97);
        $floors = [
            'id_image' => self::MIN_DOCUMENT_LONG_EDGE,
            'id_back_image' => self::MIN_DOCUMENT_LONG_EDGE,
            'live_image' => self::MIN_LIVE_LONG_EDGE,
        ];

        // Three passes at most. Each pass re-targets against what the previous one achieved, which
        // converges quickly and cannot loop forever on an image that will not shrink.
        for ($pass = 0; $pass < 3; $pass++) {
            $rawtotal = 0;
            foreach ($images as $bytes) {
                $rawtotal += strlen($bytes);
            }
            if ($rawtotal <= $rawallowance) {
                break;
            }

            $targets = self::allocate_budgets($images, $rawallowance);
            foreach ($images as $role => $bytes) {
                $target = $targets[$role] ?? strlen($bytes);
                if (strlen($bytes) <= $target) {
                    continue;
                }
                $images[$role] = self::recompress_to_fit(
                    $bytes,
                    $target,
                    $floors[$role] ?? self::MIN_LIVE_LONG_EDGE
                );
            }
        }

        $after = $encodedtotal($images);
        $note = sprintf(
            'request body reduced from %d to %d encoded bytes against a %d budget',
            $before,
            $after,
            $budget
        );

        return [$images, $after <= $budget, $note];
    }
}
