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
 * A student's device was taken for a tablet, so screen sharing was skipped (CPIT-495).
 *
 * @property-read array $other {
 *      - string useragent: the browser's user agent.
 * }
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tablet_reported extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:tabletreported', 'quizaccess_proctoring');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' started the exam setup of the quiz with course module id " .
            "'{$this->contextinstanceid}' on a tablet that cannot share its screen, so screen sharing is skipped. " .
            'User agent: ' . s($this->other['useragent'] ?? '');
    }

    /**
     * Mapping for backup and restore of the other data.
     *
     * @return array|false
     */
    public static function get_other_mapping() {
        return false;
    }
}
