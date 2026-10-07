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
 * Tests for finding captures whose image is no longer stored (CPIT-488).
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

/**
 * Missing capture tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_missing_pluginfile_urls
 * @covers ::quizaccess_proctoring_parse_pluginfile_url
 * @covers ::quizaccess_proctoring_stored_file_from_pluginfile_url
 */
final class missing_captures_test extends advanced_testcase {

    /**
     * A stored capture is found; a deleted one is reported missing; a foreign URL is not judged.
     */
    public function test_missing_files_are_found_in_one_pass(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $fs = get_file_storage();
        $urls = [];
        foreach (['kept.png', 'gone.png'] as $name) {
            $file = $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => 'quizaccess_proctoring', 'filearea' => 'picture',
                'itemid' => 5, 'filepath' => '/', 'filename' => $name,
            ], 'image');
            $urls[$name] = \moodle_url::make_pluginfile_url($context->id, 'quizaccess_proctoring', 'picture', 5, '/', $name)
                ->out(false);
            if ($name === 'gone.png') {
                $file->delete();
            }
        }
        $foreign = 'https://example.com/elsewhere.png';

        $missing = quizaccess_proctoring_missing_pluginfile_urls([$urls['kept.png'], $urls['gone.png'], $foreign]);

        $this->assertSame([$urls['gone.png'] => true], $missing);
        $this->assertNotNull(quizaccess_proctoring_stored_file_from_pluginfile_url($urls['kept.png']));
        $this->assertNull(quizaccess_proctoring_stored_file_from_pluginfile_url($urls['gone.png']));
    }
}
