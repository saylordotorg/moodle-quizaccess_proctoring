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

        // The placeholders this message has, what each becomes, and a button to insert it.
        $mform->addElement('html', $this->placeholder_bank($id));

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

        // How the student will see it, with sample values (filled in as the text is typed).
        $preview = \html_writer::tag('h4', s(get_string('messagetexts:preview', $component)), ['class' => 'h6'])
            . \html_writer::tag('p', s(get_string('messagetexts:previewhelp', $component)), ['class' => 'small text-muted']);
        foreach (array_keys(message_texts::MESSAGES[$id]['parts']) as $part) {
            $preview .= \html_writer::div(s(get_string('messagetexts:' . $part, $component)), 'small fw-bold font-weight-bold')
                . \html_writer::div('', 'border rounded p-2 mb-2 bg-white', ['data-proctoring-preview' => $part]);
        }
        $mform->addElement('html', \html_writer::div($preview, 'proctoring-message-preview mb-3'));

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * The table of this message's placeholders: where each can go, what it becomes, a sample.
     *
     * @param string $id Catalogue message id.
     * @return string HTML.
     */
    private function placeholder_bank(string $id): string {
        $component = 'quizaccess_proctoring';
        $uses = [];
        foreach (message_texts::MESSAGES[$id]['parts'] as $part => $key) {
            foreach (array_unique(array_values(message_texts::placeholders($key))) as $name) {
                $uses[$name][] = get_string('messagetexts:' . $part, $component);
            }
        }
        $heading = \html_writer::tag('h4', s(get_string('messagetexts:placeholdersheading', $component)), ['class' => 'h6']);
        if (!$uses) {
            return \html_writer::div(
                $heading . \html_writer::tag('p', s(get_string('messagetexts:noplaceholdersmessage', $component))),
                'mb-3'
            );
        }

        $samples = message_texts::samples();
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table-sm';
        $table->head = [
            get_string('messagetexts:placeholder', $component),
            get_string('messagetexts:placeholdermeans', $component),
            get_string('messagetexts:placeholdersample', $component),
            get_string('messagetexts:placeholderuse', $component),
            '',
        ];
        foreach ($uses as $name => $parts) {
            $sample = (string)($samples[$name] ?? '');
            $table->data[] = [
                \html_writer::tag('code', s('[' . $name . ']')),
                s(message_texts::describe($name)),
                $sample !== '' ? s($sample) : \html_writer::span(s(get_string('messagetexts:sampleempty', $component)), 'text-muted'),
                s(implode(', ', $parts)),
                \html_writer::tag('button', s(get_string('messagetexts:insert', $component)), [
                    'type' => 'button',
                    'class' => 'btn btn-secondary btn-sm',
                    'data-proctoring-placeholder' => $name,
                    'aria-label' => get_string('messagetexts:insertlabel', $component, '[' . $name . ']'),
                ]),
            ];
        }
        return \html_writer::div(
            $heading
                . \html_writer::tag('p', s(get_string('messagetexts:placeholdershelp', $component)), ['class' => 'small text-muted'])
                . \html_writer::table($table),
            'proctoring-placeholder-bank mb-3'
        );
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
