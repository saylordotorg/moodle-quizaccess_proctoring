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
 * Tests for how a rejected ID image upload reads to the student (CPIT-501).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/external.php');

/**
 * A rejected ID upload tells the student what is wrong instead of throwing (CPIT-501).
 *
 * Students upload the photos their phones actually produce: a stock iPhone photo sits just
 * over the pixel cap, high-resolution modes pass the byte cap, and an iPhone export is often
 * HEIC bytes in a file named .jpg. These used to surface as Moodle's "Invalid parameter value
 * detected" dialog; the live image, which the page captures itself, still throws, because its
 * failure really is a coding error.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring_external::verify_id
 */
final class id_upload_validation_test extends advanced_testcase {

    /** @var string A one-pixel PNG, as the page's camera path produces. */
    private const PNG = 'data:image/png;base64,' .
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

    /**
     * Create an enrolled student on a quiz with ID verification configured.
     *
     * @return array
     */
    private function fixture(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, $course->id, false, MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);
        set_config('idverificationenabled', 1, 'quizaccess_proctoring');
        set_config('idverificationendpoint', 'https://8.8.8.8/verify', 'quizaccess_proctoring');
        set_config('idverificationapikey', 'mock-key', 'quizaccess_proctoring');
        return [$course, $cm, $user];
    }

    /**
     * A file that is not an image the server can read asks for the camera or a JPG/PNG.
     */
    public function test_an_unreadable_id_file_reads_as_instructions(): void {
        [$course, $cm] = $this->fixture();

        $notanimage = 'data:image/jpeg;base64,' . base64_encode('HEIC bytes in a file named .jpg');
        $result = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, $notanimage, self::PNG, 1);

        $this->assertSame('retry', $result['status']);
        $this->assertSame(0, (int)$result['verificationid'], 'nothing must be stored for a rejected upload');
        $this->assertSame(
            get_string('idverificationunreadablefile', 'quizaccess_proctoring'),
            $result['message']
        );
    }

    /**
     * An oversized upload reads as the too-large message, and the back image is covered too.
     */
    public function test_an_oversized_id_file_reads_as_too_large(): void {
        [$course, $cm] = $this->fixture();

        // Larger than the 8MB ID cap allows even before decoding.
        $oversize = 'data:image/jpeg;base64,' . str_repeat('A', 12 * 1024 * 1024);
        $result = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, $oversize, self::PNG, 1);
        $this->assertSame('retry', $result['status']);
        $this->assertSame(
            get_string('modal:idverificationimagetoolarge', 'quizaccess_proctoring'),
            $result['message']
        );

        $back = \quizaccess_proctoring_external::verify_id($course->id, $cm->id, 0, self::PNG, self::PNG, 1, $oversize);
        $this->assertSame('retry', $back['status']);
        $this->assertSame(
            get_string('modal:idverificationimagetoolarge', 'quizaccess_proctoring'),
            $back['message']
        );
    }

    /**
     * The live image is captured by the page itself, so its failure stays a coding error.
     */
    public function test_a_bad_live_image_still_throws(): void {
        [$course, $cm] = $this->fixture();

        $this->expectException(\invalid_parameter_exception::class);
        \quizaccess_proctoring_external::verify_id(
            $course->id,
            $cm->id,
            0,
            self::PNG,
            'data:image/png;base64,' . base64_encode('not an image'),
            1
        );
    }
}
