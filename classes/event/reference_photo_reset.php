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

namespace quizaccess_proctoring\event;

/**
 * A reviewer reset a student's reference photo (CPIT-476).
 *
 * @property-read array $other {
 *      - string reason: why the reviewer reset it.
 * }
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reference_photo_reset extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:referencephotoreset', 'quizaccess_proctoring');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' reset the proctoring reference photo of the user with id " .
            "'{$this->relateduserid}'. Reason: " . s($this->other['reason'] ?? '');
    }

    /**
     * Validate the event data.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
        }
    }

    /**
     * Mapping for backup and restore of the other data.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return false;
    }
}
