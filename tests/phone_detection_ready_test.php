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
 * Tests for the phone-detection readiness check (CPIT-484).
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
 * Phone detection readiness tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::quizaccess_proctoring_phone_detection_ready
 */
final class phone_detection_ready_test extends advanced_testcase {

    /**
     * A directory holding the libraries and a model whose manifest names two shards.
     *
     * @return string
     */
    private function install(): string {
        $dir = make_request_directory();
        mkdir($dir . '/model');
        file_put_contents($dir . '/tf.min.js', '//');
        file_put_contents($dir . '/coco-ssd.min.js', '//');
        file_put_contents($dir . '/model/model.json', json_encode(['weightsManifest' => [
            ['paths' => ['group1-shard1of2.bin', 'group1-shard2of2.bin']],
        ]]));
        file_put_contents($dir . '/model/group1-shard1of2.bin', 'x');
        file_put_contents($dir . '/model/group1-shard2of2.bin', 'x');
        return $dir;
    }

    /**
     * Ready only when every file, and every shard the manifest names, is present (PR #54 review).
     */
    public function test_ready_needs_every_weight_shard(): void {
        $dir = $this->install();
        $this->assertTrue(quizaccess_proctoring_phone_detection_ready($dir));

        unlink($dir . '/model/group1-shard2of2.bin');
        $this->assertFalse(quizaccess_proctoring_phone_detection_ready($dir));
    }

    /**
     * A manifest with no shards, or one pointing outside the model directory, is not ready.
     */
    public function test_manifest_without_usable_shards_is_not_ready(): void {
        $dir = $this->install();
        file_put_contents($dir . '/model/model.json', json_encode(['weightsManifest' => []]));
        $this->assertFalse(quizaccess_proctoring_phone_detection_ready($dir));

        file_put_contents($dir . '/model/model.json', json_encode(['weightsManifest' => [['paths' => ['../tf.min.js']]]]));
        $this->assertFalse(quizaccess_proctoring_phone_detection_ready($dir));

        file_put_contents($dir . '/model/model.json', 'not json');
        $this->assertFalse(quizaccess_proctoring_phone_detection_ready($dir));
    }

    /**
     * The plugin does not ship the model, so a plain install reports not ready.
     */
    public function test_plugin_without_model_is_not_ready(): void {
        global $CFG;
        if (is_file($CFG->dirroot . '/mod/quiz/accessrule/proctoring/thirdpartylibs/objectdetect/model/model.json')) {
            $this->markTestSkipped('The phone-detection model is installed on this site.');
        }
        $this->assertFalse(quizaccess_proctoring_phone_detection_ready());
    }
}
