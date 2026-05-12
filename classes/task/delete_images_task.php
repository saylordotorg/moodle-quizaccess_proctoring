<?php
// This file is part of Moodle - http://moodle.org/.
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

namespace quizaccess_proctoring\task;

use core\task\scheduled_task;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Scheduled task to delete all data.
 * @package    quizaccess_proctoring
 * @author     Saylor Academy <saylor.org>
 * @copyright  2021 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_images_task extends scheduled_task {
    /**
     * Returns name of task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:delete_images', 'quizaccess_proctoring');
    }

    /**
     * Executes the task to delete proctoring logs and associated images.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        try {
            $this->queue_expired_attempt_images();

            // Select 10 random rows from proctoring logs where deletionprogress = 1.
            $sql = "SELECT id, webcampicture, status
            FROM {quizaccess_proctoring_logs}
            WHERE deletionprogress = :deletionprogress
            LIMIT 10";

            $params = ['deletionprogress' => 1];
            $records = $DB->get_records_sql($sql, $params);
            if (!empty($records)) {
                $fs = get_file_storage();
                $ids = [];
                $attemptids = [];
                foreach ($records as $record) {

                    $this->delete_file($fs, $record->webcampicture, 'quizaccess_proctoring', 'picture');
                    $faceparams = [
                        'parentid'    => $record->id,
                        'parent_type' => 'camshot_image',
                    ];
                    // Fetch the record using Moodle's DML API.
                    $faceimagerecord = $DB->get_record(
                        'quizaccess_proctoring_face_images',
                        $faceparams
                    );

                    if (($faceimagerecord)) {
                        $this->delete_file($fs, $faceimagerecord->faceimage, 'quizaccess_proctoring', 'face_image');
                    } else {
                         mtrace("No face image found for this picture.");
                    }

                     $DB->delete_records('quizaccess_proctoring_face_images',
                         ['parentid' => $record->id, 'parent_type' => 'camshot_image']);
                    $ids[] = $record->id;
                    if ((int)$record->status > 0) {
                        $attemptids[] = (int)$record->status;
                    }
                }
                // Delete associated face images from the database after processing all records.
                if (!empty($ids)) {
                    list($insql, $params) = $DB->get_in_or_equal($ids);
                    $attemptids = array_values(array_unique($attemptids));

                    $events = $DB->get_records_select(
                        'quizaccess_proctoring_events',
                        "reportid $insql",
                        $params,
                        '',
                        'id, screenshoturl'
                    );
                    if (!empty($attemptids)) {
                        list($attemptsql, $attemptparams) = $DB->get_in_or_equal($attemptids);
                        $attemptevents = $DB->get_records_select(
                            'quizaccess_proctoring_events',
                            "attemptid $attemptsql",
                            $attemptparams,
                            '',
                            'id, screenshoturl'
                        );
                        foreach ($attemptevents as $event) {
                            $events[$event->id] = $event;
                        }
                    }
                    foreach ($events as $event) {
                        $this->delete_file($fs, $event->screenshoturl, 'quizaccess_proctoring', 'violation_screenshot');
                    }

                    // Delete the log records from quizaccess_proctoring_logs.
                    $DB->delete_records_list('quizaccess_proctoring_events', 'reportid', $ids);
                    $DB->delete_records_list('quizaccess_proctoring_ai_reviews', 'reportid', $ids);
                    $DB->delete_records_list('quizaccess_proctoring_fm_warnings', 'reportid', $ids);
                    $DB->delete_records_list('quizaccess_proctoring_facematch_task', 'reportid', $ids);
                    if (!empty($attemptids)) {
                        $DB->delete_records_list('quizaccess_proctoring_events', 'attemptid', $attemptids);
                        $DB->delete_records_list('quizaccess_proctoring_ai_reviews', 'attemptid', $attemptids);
                    }
                    $DB->delete_records_select('quizaccess_proctoring_logs', "id $insql", $params);
                    mtrace("Deleted " . count($ids) . " records from quizaccess_proctoring_logs and associated files.");
                }
            } else {
                mtrace("No records found for deletion.");
            }
        } catch (Exception $e) {
            mtrace("An error occurred while deleting images: " . $e->getMessage());
        }
    }

    /**
     * Marks images from finished quiz attempts for deletion after the configured retention window.
     *
     * @return void
     */
    private function queue_expired_attempt_images(): void {
        global $DB;

        $retentiondays = (int)get_config('quizaccess_proctoring', 'imageretentiondays');
        if ($retentiondays <= 0) {
            return;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);
        $sql = "SELECT l.id
                  FROM {quizaccess_proctoring_logs} l
                  JOIN {quiz_attempts} qa ON qa.id = l.status AND qa.userid = l.userid
             LEFT JOIN {quizaccess_proctoring_risk_holds} rh
                    ON rh.quizid = l.quizid
                   AND rh.userid = l.userid
                   AND rh.status = :activehold
                   AND (rh.attemptid = qa.id OR rh.reportid = l.id)
                 WHERE l.deletionprogress = 0
                   AND qa.timefinish > 0
                   AND qa.timefinish <= :cutoff
                   AND rh.id IS NULL
              ORDER BY qa.timefinish ASC, l.id ASC";

        $records = $DB->get_records_sql($sql, [
            'activehold' => QUIZACCESS_PROCTORING_RISK_HOLD_ACTIVE,
            'cutoff' => $cutoff,
        ], 0, 250);
        if (empty($records)) {
            return;
        }

        $ids = array_keys($records);
        list($insql, $params) = $DB->get_in_or_equal($ids);
        $DB->set_field_select('quizaccess_proctoring_logs', 'deletionprogress', 1, "id $insql", $params);
        mtrace('Queued ' . count($ids) . ' expired proctoring image record(s) for deletion.');
    }

    /**
     * Helper function to delete a file based on its URL and file area.
     *
     * @param object $fs Moodle file storage object.
     * @param string $fileurl The file URL.
     * @param string $component The component name.
     * @param string $filearea The file area (e.g., 'picture' or 'face_image').
     * @return void
     */
    private function delete_file($fs, $fileurl, $component, $filearea) {
        if (!empty($fileurl)) {
            // Extract the relative path from the file URL.
            $fileinfo = parse_url($fileurl, PHP_URL_PATH);
            if (empty($fileinfo)) {
                mtrace("Invalid file path: " . $fileurl);
                return;
            }
            $fileparts = explode('/', trim($fileinfo, '/'));
            $fileparts = array_reverse($fileparts);
            // Validate the path before attempting deletion.
            if (count($fileparts) >= 5 && $fileparts[3] === $component && $fileparts[2] === $filearea) {
                $contextid = $fileparts[4];
                $itemid = $fileparts[1];
                $filename = $fileparts[0];

                // File record details.
                $filedata = [
                    'component' => $component,
                    'filearea' => $filearea,
                    'contextid' => $contextid,
                    'itemid' => $itemid,
                    'filepath' => '/',
                    'filename' => $filename,
                ];

                // Attempt to delete the file.
                $storedfile = $fs->get_file(
                    $filedata['contextid'],
                    $filedata['component'],
                    $filedata['filearea'],
                    $filedata['itemid'],
                    $filedata['filepath'],
                    $filedata['filename']
                );

                if ($storedfile) {
                    $storedfile->delete();
                    mtrace("Deleted file: " .$filearea. " " . $fileurl);
                } else {
                    mtrace("File not found: " . $fileurl);
                }
            } else {
                mtrace("Invalid file path: " . $fileurl);
            }
        } else {
            mtrace("Found empty url.");
        }
    }
}
