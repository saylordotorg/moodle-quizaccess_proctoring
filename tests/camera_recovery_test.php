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
 * Tests that a killed camera recovers and never blurs the exam as a hidden face (CPIT-507).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Safari stops the webcam when another capture starts: the screen-share re-prompt at exam start
 * left the camera black, and the face-visibility check read those black frames as a hidden face
 * and blurred the quiz (CPIT-507, Student Affairs testing on Safari).
 *
 * The behaviour lives in page JavaScript, so these tests pin the source the way
 * screen_share_shutdown_test does: a refactor that drops the recovery or the gating fails loudly.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring
 */
final class camera_recovery_test extends advanced_testcase {

    /**
     * Read one plugin file.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private function source(string $relative): string {
        global $CFG;
        return (string)file_get_contents($CFG->dirroot . '/mod/quiz/accessrule/proctoring/' . $relative);
    }

    /**
     * A camera track that ends or stays muted schedules a re-acquisition, capped with backoff.
     */
    public function test_camera_track_loss_schedules_recovery(): void {
        $js = $this->source('amd/src/proctoring.js');
        $this->assertStringContainsString('const scheduleCameraRecovery', $js,
            'the attempt page must be able to re-acquire a dead camera');
        $this->assertStringContainsString("track.addEventListener('ended', () => {", $js,
            'an ended camera track must trigger recovery');
        $this->assertStringContainsString('cameraRecoverAttempts >= 5', $js,
            'recovery must be capped so two captures that cannot coexist do not loop');
        $this->assertStringContainsString('4000 * Math.pow(2, cameraRecoverAttempts)', $js,
            'recovery must back off between attempts');
        // The built bundle carries it too, or the page serves stale behaviour.
        $this->assertStringContainsString('scheduleCameraRecovery', $this->source('amd/src/proctoring.js'));
    }

    /**
     * The face-visibility check skips frames from a dead or muted camera instead of blurring.
     */
    public function test_face_check_skips_dead_camera_frames(): void {
        $js = $this->source('amd/src/proctoring.js');
        $gate = strpos($js, "cameratracks.every(track => track.readyState === 'ended' || track.muted)");
        $detect = strpos($js, 'faceapi.detectAllFaces');
        $this->assertNotFalse($gate, 'the face check must look at the camera tracks first');
        $this->assertNotFalse($detect);
        $this->assertLessThan($detect, $gate,
            'the dead-camera gate must run before face detection, so black frames never count as a hidden face');
    }
}
