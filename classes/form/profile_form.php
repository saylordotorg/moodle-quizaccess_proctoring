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
use quizaccess_proctoring\local\risk_calculator;

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

        foreach (profile::definitions() as $name => $definition) {
            if (isset($definition['section'])) {
                continue;
            }
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

        $this->add_scoring_section();
        $this->add_levels_section();

        $this->add_action_buttons();
    }

    /**
     * One row per risk factor: whether it scores, its points per event and its cap. Each blank
     * field follows the site's Risk factor scoring page, whose value the field shows.
     */
    private function add_scoring_section(): void {
        $mform = $this->_form;
        $component = 'quizaccess_proctoring';

        $mform->addElement('header', 'scoringheader', get_string('profile:scoringheading', $component));
        $mform->setExpanded('scoringheader', false);
        $mform->addElement('static', 'scoringintro', '', get_string('profile:scoringintro', $component));

        foreach (risk_calculator::FACTOR_DEFAULTS as $factor => $unused) {
            $prefix = 'riskfactor_' . $factor;
            $siteon = get_string(risk_calculator::factor_enabled($factor) ? 'profile:on' : 'profile:off', $component);
            $elements = [
                $mform->createElement('select', $prefix . '_enabled', '', [
                    '' => get_string('profile:usesite', $component, $siteon),
                    '1' => get_string('profile:on', $component),
                    '0' => get_string('profile:off', $component),
                ], ['aria-label' => profile::label($prefix . '_enabled')]),
                $mform->createElement('text', $prefix . '_points', '', [
                    'size' => 22,
                    'placeholder' => get_string('profile:sitepoints', $component, risk_calculator::factor_points($factor)),
                    'aria-label' => profile::label($prefix . '_points'),
                ]),
                $mform->createElement('text', $prefix . '_cap', '', [
                    'size' => 18,
                    'placeholder' => get_string('profile:sitecap', $component, risk_calculator::factor_cap($factor)),
                    'aria-label' => profile::label($prefix . '_cap'),
                ]),
            ];
            $mform->addGroup($elements, self::group_name($factor), get_string('riskscore:' . $factor, $component), ' ', false);
            $mform->setType($prefix . '_enabled', PARAM_RAW);
            $mform->setType($prefix . '_points', PARAM_RAW_TRIMMED);
            $mform->setType($prefix . '_cap', PARAM_RAW_TRIMMED);
        }
    }

    /**
     * The three risk level boundaries; blank follows the site.
     */
    private function add_levels_section(): void {
        $mform = $this->_form;
        $component = 'quizaccess_proctoring';

        $mform->addElement('header', 'levelsheader', get_string('profile:levelsheading', $component));
        $mform->setExpanded('levelsheader', false);
        $mform->addElement('static', 'levelsintro', '', get_string('profile:levelsintro', $component));

        $site = risk_calculator::get_level_boundaries();
        foreach (profile::LEVEL_SETTINGS as $name) {
            $inherit = get_string('profile:usesite', $component, $site[substr($name, strlen('risklevel'))]);
            $mform->addElement('text', $name, profile::label($name), ['size' => 4, 'placeholder' => $inherit]);
            $mform->setType($name, PARAM_RAW_TRIMMED);
            $mform->addElement('static', $name . 'hint', '', $inherit);
        }
    }

    /**
     * Open the scoring and level sections when the profile already changes something in them.
     */
    public function definition_after_data() {
        parent::definition_after_data();
        $mform = $this->_form;
        // The factor fields sit in groups, so read the raw values rather than the elements.
        $values = array_merge((array)$mform->_defaultValues, (array)$mform->_submitValues);
        $open = [profile::SECTION_SCORING => false, profile::SECTION_LEVELS => false];
        foreach (profile::definitions() as $name => $definition) {
            if (isset($definition['section']) && is_scalar($values[$name] ?? null) && trim((string)$values[$name]) !== '') {
                $open[$definition['section']] = true;
            }
        }
        if ($open[profile::SECTION_SCORING]) {
            $mform->setExpanded('scoringheader', true);
        }
        if ($open[profile::SECTION_LEVELS]) {
            $mform->setExpanded('levelsheader', true);
        }
    }

    /**
     * The form group holding one factor's three fields.
     *
     * @param string $factor Factor key.
     * @return string Group name.
     */
    private static function group_name(string $factor): string {
        return 'riskfactorgroup_' . $factor;
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
        foreach (profile::definitions() as $setting => $definition) {
            $submitted = trim((string)($data[$setting] ?? ''));
            if ($submitted !== '' && !array_key_exists($setting, $clean)) {
                // A factor's fields share one group, which is where Moodle shows the error.
                $field = isset($definition['factor']) ? self::group_name($definition['factor']) : $setting;
                $errors[$field] = get_string('profile:invalidvalue', $component);
            }
        }

        // The levels must still rise once the site's values fill in the blanks. The site clamps an
        // inverted boundary down, which would quietly empty a band this profile meant to set.
        $site = risk_calculator::get_level_boundaries();
        $previous = 0;
        foreach (profile::LEVEL_SETTINGS as $name) {
            $value = isset($clean[$name]) ? (int)$clean[$name] : $site[substr($name, strlen('risklevel'))];
            if ($value < $previous && !isset($errors[$name])) {
                $errors[$name] = get_string('profile:levelsorder', $component);
            }
            $previous = max($previous, $value);
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
        $definition = profile::definitions()[$name];
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
