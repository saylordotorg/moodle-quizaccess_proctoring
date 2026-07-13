<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Create/edit form for per-student proctoring overrides.
 *
 * @package   quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\form;

use quizaccess_proctoring\local\override_resolver;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/formslib.php");

/**
 * Reviewer form for creating and editing a single per-student proctoring override.
 *
 * The form collects the override target (a student enrolled in the course context), an optional
 * quiz scope, the five per-requirement tri-states (each defaulting to inherit), an optional
 * expiry, and a required justification. Its {@see override_form::validation()} mirrors the
 * checks performed by {@see \quizaccess_proctoring\local\override_manager} so that invalid input
 * is surfaced as friendly, field-level errors before the manager is ever called.
 *
 * Expected `$this->_customdata` entries:
 * - `courseid` (int): course the override is created in (used for validation/context).
 * - `context` (\context_module): module context, when available.
 * - `cmid` (int): course-module id, when available.
 * - `students` (array<int, string>): map of enrollable user id => display name. When omitted,
 *   the enrolled users are read from the course context.
 * - `quizzes` (array<int, string>): map of quiz id => name for the optional quiz selector.
 *
 * @package   quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_form extends \moodleform {

    /** @var int Maximum allowed justification length, in characters. */
    const MAX_JUSTIFICATION_LENGTH = 2000;

    /**
     * Define the override form elements.
     *
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $component = 'quizaccess_proctoring';

        $courseid = isset($this->_customdata['courseid']) ? (int)$this->_customdata['courseid'] : 0;
        $cmid = isset($this->_customdata['cmid']) ? (int)$this->_customdata['cmid'] : 0;

        // Section header.
        $mform->addElement('header', 'overridegeneral', get_string('override_formheader', $component));

        // Hidden routing fields so the page can round-trip its context and (for edits) the id.
        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'overrideid', 0);
        $mform->setType('overrideid', PARAM_INT);

        // Target student selector: enrolled users in the course context.
        $students = $this->get_student_options();
        $studentselect = $mform->addElement(
            'autocomplete',
            'userid',
            get_string('override_targetstudent', $component),
            $students
        );
        $studentselect->setMultiple(false);
        $mform->setType('userid', PARAM_INT);
        $mform->addRule('userid', get_string('override_error_invalidtarget', $component), 'required', null, 'client');

        // Optional quiz scope selector: course-wide (0) plus each quiz in the course.
        $quizoptions = [0 => get_string('override_scopecoursewide', $component)] + $this->get_quiz_options();
        $mform->addElement('select', 'quizid', get_string('override_targetquiz', $component), $quizoptions);
        $mform->setType('quizid', PARAM_INT);
        $mform->setDefault('quizid', 0);
        $mform->addHelpButton('quizid', 'override_targetquiz', $component);

        // The five per-requirement tri-state selects, each defaulting to inherit.
        $stateoptions = [
            override_resolver::STATE_INHERIT => get_string('override_state_inherit', $component),
            override_resolver::STATE_DISABLED => get_string('override_state_disabled', $component),
            override_resolver::STATE_ENABLED => get_string('override_state_enabled', $component),
        ];
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $mform->addElement('select', $column, get_string('override_' . $column, $component), $stateoptions);
            $mform->setType($column, PARAM_INT);
            $mform->setDefault($column, override_resolver::STATE_INHERIT);
        }

        // Optional expiry. When the reviewer leaves it disabled the selector yields 0 = no expiry.
        $mform->addElement(
            'date_time_selector',
            'expiry',
            get_string('override_expiry', $component),
            ['optional' => true]
        );
        $mform->addHelpButton('expiry', 'override_expiry', $component);

        // Required justification textarea.
        $mform->addElement(
            'textarea',
            'justification',
            get_string('override_justification', $component),
            ['rows' => 5, 'cols' => 60, 'maxlength' => self::MAX_JUSTIFICATION_LENGTH]
        );
        $mform->setType('justification', PARAM_TEXT);
        $mform->addRule('justification', get_string('override_error_invalidjustification', $component), 'required', null, 'client');
        $mform->addHelpButton('justification', 'override_justification', $component);

        $this->add_action_buttons();
    }

    /**
     * Build the target student options (enrolled users in the course context).
     *
     * Uses the pre-computed `students` custom data map when provided; otherwise reads the
     * enrolled users from the course context.
     *
     * @return array<int, string> Map of user id => display name.
     */
    private function get_student_options(): array {
        if (isset($this->_customdata['students']) && is_array($this->_customdata['students'])) {
            return $this->_customdata['students'];
        }

        $context = $this->get_course_context();
        if ($context === null) {
            return [];
        }

        $options = [];
        foreach (get_enrolled_users($context) as $user) {
            $options[(int)$user->id] = fullname($user);
        }
        return $options;
    }

    /**
     * Build the optional quiz scope options (quizzes in the course).
     *
     * @return array<int, string> Map of quiz id => quiz name.
     */
    private function get_quiz_options(): array {
        if (isset($this->_customdata['quizzes']) && is_array($this->_customdata['quizzes'])) {
            return $this->_customdata['quizzes'];
        }
        return [];
    }

    /**
     * Resolve the course context from the supplied custom data.
     *
     * @return \context_course|null The course context, or null when it cannot be resolved.
     */
    private function get_course_context(): ?\context_course {
        $courseid = isset($this->_customdata['courseid']) ? (int)$this->_customdata['courseid'] : 0;
        if ($courseid <= 0) {
            return null;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context ?: null;
    }

    /**
     * Server-side validation mirroring the {@see \quizaccess_proctoring\local\override_manager}
     * checks, surfaced as field-level errors.
     *
     * This does not call the manager; it reproduces its validation logic so the reviewer sees a
     * friendly message next to the offending field. The manager remains the authoritative guard
     * (including full enrolled-user existence) at write time.
     *
     * @param array $data Submitted form data.
     * @param array $files Uploaded files.
     * @return array Errors keyed by form field name (empty when valid).
     */
    public function validation($data, $files) {
        $errors = [];
        $component = 'quizaccess_proctoring';

        // Target student must be present (>0). Full enrolled-user existence is validated by the manager.
        $userid = isset($data['userid']) ? (int)$data['userid'] : 0;
        if ($userid <= 0) {
            $errors['userid'] = get_string('override_error_invalidtarget', $component);
        }

        // Justification: non-blank after trim and within the maximum length.
        $justification = isset($data['justification']) ? (string)$data['justification'] : '';
        $trimmed = trim($justification);
        if ($trimmed === '' || \core_text::strlen($trimmed) > self::MAX_JUSTIFICATION_LENGTH) {
            $errors['justification'] = get_string('override_error_invalidjustification', $component);
        }

        // Each of the five tri-state values must be one of {-1, 0, 1}.
        $validstates = [
            override_resolver::STATE_INHERIT,
            override_resolver::STATE_DISABLED,
            override_resolver::STATE_ENABLED,
        ];
        foreach (override_resolver::STATE_COLUMNS as $column) {
            $state = isset($data[$column]) ? $data[$column] : override_resolver::STATE_INHERIT;
            $isintegerlike = is_int($state) || (is_string($state) && preg_match('/^-?\d+$/', $state) === 1);
            if (!$isintegerlike || !in_array((int)$state, $validstates, true)) {
                $errors[$column] = get_string('override_error_invalidstate', $component);
            }
        }

        // Expiry, when enabled/non-zero, must be strictly in the future.
        $expiry = isset($data['expiry']) ? (int)$data['expiry'] : 0;
        if ($expiry !== 0 && $expiry <= time()) {
            $errors['expiry'] = get_string('override_error_expiryinpast', $component);
        }

        return $errors;
    }
}
