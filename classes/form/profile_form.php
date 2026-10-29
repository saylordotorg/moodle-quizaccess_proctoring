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
 * Create or edit a proctoring profile.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\form;

use quizaccess_proctoring\local\profile;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/formslib.php");

/**
 * A profile's name, description, and the site settings it changes. Every setting starts on
 * "Use the site setting", which names the site's current value.
 *
 * @package   quizaccess_proctoring
 * @copyright 2026 Saylor Academy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_form extends \moodleform {
    /**
     * Define the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'quizaccess_proctoring';

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('profile:name', $component), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', null, 'maxlength', profile::MAX_NAME_LENGTH, 'client');

        $mform->addElement('textarea', 'description', get_string('profile:description', $component), ['rows' => 3, 'cols' => 60]);
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'profile:description', $component);

        $mform->addElement('header', 'settingsheader', get_string('profile:settingsheading', $component));
        $mform->setExpanded('settingsheader');
        $mform->addElement('static', 'settingsintro', '', get_string('profile:settingsintro', $component));

        foreach (profile::SETTINGS as $name => $definition) {
            $label = get_string($definition['label'], $component);
            $inherit = get_string('profile:usesite', $component, self::site_value($name));
            if ($definition['options'] === [] && isset($definition['range'])) {
                // A number: blank uses the site setting.
                $mform->addElement('text', $name, $label, ['size' => 4, 'placeholder' => $inherit]);
                $mform->setType($name, PARAM_RAW_TRIMMED);
                $mform->addElement('static', $name . 'hint', '', $inherit);
                continue;
            }
            $options = ['' => $inherit];
            foreach ($definition['options'] as $value => $stringid) {
                $options[$value] = get_string($stringid, $component);
            }
            if ($name === 'facestrikes') {
                foreach (range($definition['range'][0], $definition['range'][1]) as $limit) {
                    $options[(string)$limit] = get_string('facestrikes_limit', $component, $limit);
                }
            }
            $mform->addElement('select', $name, $label, $options);
            $mform->setType($name, PARAM_RAW);
        }

        $this->add_action_buttons();
    }

    /**
     * Check the name is unique and every value is one the setting allows.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors by field.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $component = 'quizaccess_proctoring';

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = get_string('required');
        } else if (profile::name_taken($name, (int)($data['id'] ?? 0))) {
            $errors['name'] = get_string('profile:nametaken', $component);
        }

        $clean = profile::clean($data);
        foreach (array_keys(profile::SETTINGS) as $setting) {
            $submitted = trim((string)($data[$setting] ?? ''));
            if ($submitted !== '' && !array_key_exists($setting, $clean)) {
                $errors[$setting] = get_string('profile:invalidvalue', $component);
            }
        }
        return $errors;
    }

    /**
     * The site's current value of a setting, as the profile form names it.
     *
     * @param string $name Setting name.
     * @return string Readable value.
     */
    private static function site_value(string $name): string {
        $component = 'quizaccess_proctoring';
        $definition = profile::SETTINGS[$name];
        $value = (string)get_config($component, $name);
        if (isset($definition['options'][$value])) {
            return get_string($definition['options'][$value], $component);
        }
        if ($name === 'facestrikes' && (int)$value > 0) {
            return get_string('facestrikes_limit', $component, (int)$value);
        }
        return $value === '' ? get_string('profile:notset', $component) : $value;
    }
}
