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
 * Edit form for the wording of one student message (CPIT-512).
 *
 * @package   quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\form;

use quizaccess_proctoring\local\message_texts;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/formslib.php");

/**
 * The subject and body of one catalogue message, with its placeholders checked on save.
 *
 * Expected `$this->_customdata` entries:
 * - `message` (string): the catalogue id, a key of {@see message_texts::MESSAGES}.
 *
 * @package   quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_text_form extends \moodleform {
    /**
     * Define the form: one field per editable part, with the placeholders it must keep.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'quizaccess_proctoring';
        $id = (string)$this->_customdata['message'];

        $mform->addElement('hidden', 'edit', $id);
        $mform->setType('edit', PARAM_ALPHANUMEXT);

        foreach (message_texts::MESSAGES[$id]['parts'] as $part => $key) {
            $label = get_string('messagetexts:' . $part, $component);
            if ($part === 'subject') {
                $mform->addElement('text', $part, $label, ['size' => 80, 'maxlength' => message_texts::MAX_SUBJECT]);
            } else {
                $mform->addElement('textarea', $part, $label, ['rows' => 8, 'cols' => 80]);
            }
            $mform->setType($part, PARAM_TEXT);
            $mform->setDefault($part, message_texts::to_editor($key));
            $names = array_unique(array_values(message_texts::placeholders($key)));
            $mform->addElement('static', $part . 'placeholders', '', s(get_string(
                $names ? 'messagetexts:placeholders' : 'messagetexts:noplaceholders',
                $component,
                '[' . implode('], [', $names) . ']'
            )));
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Check every part: not empty, not too long, and keeping its placeholders.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $id = (string)$this->_customdata['message'];
        foreach (message_texts::MESSAGES[$id]['parts'] as $part => $key) {
            $error = message_texts::validate($key, (string)($data[$part] ?? ''), $part === 'subject');
            if ($error !== '') {
                $errors[$part] = $error;
            }
        }
        return $errors;
    }
}
