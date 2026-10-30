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
 * Proctoring profiles: named sets of site settings a quiz can opt into.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_proctoring\local;

/**
 * A proctoring profile replaces chosen site settings for the quizzes that use it, so one site can
 * run, say, an "ACE credit" exam with ID verification, a desktop-only rule and a strict risk
 * threshold beside a "Certificate" exam with only the webcam.
 *
 * Resolution
 * ----------
 * site setting -> profile -> the quiz's own setting -> per-student override. A profile stores
 * only the settings it changes; anything it leaves out reads the site setting. A quiz's own
 * "Use site default" choices therefore mean "the profile's value, or the site's". Code that
 * resolves a requirement for a quiz reads {@see self::config()} where it used to read
 * `get_config()`, and everything layered above it is unchanged.
 *
 * Values are stored as `get_config()` would return them (strings), so a caller cannot tell a
 * profile value from a site value.
 */
final class profile {
    /** @var string Component. */
    private const COMPONENT = 'quizaccess_proctoring';

    /** @var string Profiles table. */
    public const TABLE = 'quizaccess_proctoring_profiles';

    /** @var int Longest profile name. */
    public const MAX_NAME_LENGTH = 100;

    /**
     * The site settings a profile can change, in the order the edit form lists them.
     *
     * `options` maps each allowed stored value to its label's string id; `range` allows an
     * integer within bounds instead.
     *
     * @var array<string, array>
     */
    public const SETTINGS = [
        'fcheckstartchk' => [
            'label' => 'settings:fcheckquizstart',
            'options' => ['1' => 'profile:required', '0' => 'profile:notrequired'],
        ],
        'idverificationenabled' => [
            'label' => 'setting:idverificationenabled',
            'options' => ['1' => 'profile:required', '0' => 'profile:notrequired'],
        ],
        'requireentirescreen' => [
            'label' => 'setting:requireentirescreen',
            'options' => ['1' => 'profile:required', '0' => 'profile:notrequired'],
        ],
        'mobilescreensharemode' => [
            'label' => 'setting:mobilescreensharemode',
            'options' => [
                'block' => 'setting:mobilescreensharemode_block',
                'require' => 'setting:mobilescreensharemode_require',
                'bypass' => 'setting:mobilescreensharemode_bypass',
            ],
        ],
        'allowedbrowsers' => [
            'label' => 'setting:allowedbrowsers',
            'options' => [
                'chromeedge' => 'setting:allowedbrowsers_chromeedge',
                'nosafari' => 'setting:allowedbrowsers_nosafari',
                'any' => 'setting:allowedbrowsers_any',
            ],
        ],
        'captchabeforeattemptenabled' => [
            'label' => 'setting:captchabeforeattemptenabled',
            'options' => ['1' => 'profile:required', '0' => 'profile:notrequired'],
        ],
        'multimonitormode' => [
            'label' => 'setting:multimonitormode',
            'options' => [
                'block' => 'setting:multimonitormode_block',
                'warn' => 'setting:multimonitormode_warn',
                'log' => 'setting:multimonitormode_log',
                'off' => 'setting:multimonitormode_off',
            ],
        ],
        'detectphone' => [
            'label' => 'setting:detectphone',
            'options' => ['1' => 'profile:on', '0' => 'profile:off'],
        ],
        'facestrikes' => [
            'label' => 'setting:facestrikes',
            'options' => ['0' => 'facestrikes_off'],
            'range' => [face_strikes::MIN_LIMIT, face_strikes::MAX_LIMIT],
        ],
        'riskreviewenabled' => [
            'label' => 'setting:riskreviewenabled',
            'options' => [
                '2' => 'setting:riskreviewenabled_autofail',
                '1' => 'setting:riskreviewenabled_hold',
                '0' => 'setting:riskreviewenabled_disabled',
            ],
        ],
        'riskreviewthreshold' => [
            'label' => 'setting:riskreviewthreshold',
            'options' => [],
            'range' => [1, 100],
        ],
    ];

    /** @var array<int, array<string, string>> Request cache: quiz id => the settings its profile changes. */
    private static array $quizcache = [];

    /**
     * A setting as it applies to one quiz: its profile's value, or the site's.
     *
     * @param int $quizid Quiz instance id; 0 reads the site setting.
     * @param string $name Setting name.
     * @return mixed What get_config() would return for it.
     */
    public static function config(int $quizid, string $name) {
        if ($quizid > 0 && isset(self::SETTINGS[$name])) {
            $values = self::values_for_quiz($quizid);
            if (array_key_exists($name, $values)) {
                return $values[$name];
            }
        }
        return get_config(self::COMPONENT, $name);
    }

    /**
     * The settings a quiz's profile changes, empty when it has none.
     *
     * @param int $quizid Quiz instance id.
     * @return array<string, string> Setting => stored value.
     */
    public static function values_for_quiz(int $quizid): array {
        global $DB;

        if (!array_key_exists($quizid, self::$quizcache)) {
            $json = $DB->get_field_sql(
                'SELECT p.settings
                   FROM {quizaccess_proctoring} q
                   JOIN {' . self::TABLE . '} p ON p.id = q.profileid
                  WHERE q.quizid = :quizid',
                ['quizid' => $quizid]
            );
            self::$quizcache[$quizid] = $json === false ? [] : self::decode((string)$json);
        }
        return self::$quizcache[$quizid];
    }

    /**
     * The profile a quiz uses.
     *
     * @param int $quizid Quiz instance id.
     * @return \stdClass|null Profile row, or null when the quiz uses the site settings.
     */
    public static function for_quiz(int $quizid): ?\stdClass {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT p.*
               FROM {quizaccess_proctoring} q
               JOIN {' . self::TABLE . '} p ON p.id = q.profileid
              WHERE q.quizid = :quizid',
            ['quizid' => $quizid]
        );
        return $record ?: null;
    }

    /**
     * Every profile, by name.
     *
     * @return \stdClass[] Profile rows keyed by id.
     */
    public static function all(): array {
        global $DB;

        return $DB->get_records(self::TABLE, null, 'name ASC, id ASC');
    }

    /**
     * Options for the quiz settings form's profile menu.
     *
     * @return array<int, string> Profile id => name, with 0 for the site settings.
     */
    public static function menu(): array {
        $menu = [0 => get_string('profile:none', self::COMPONENT)];
        foreach (self::all() as $profile) {
            $menu[(int)$profile->id] = format_string($profile->name, true, ['context' => \context_system::instance()]);
        }
        return $menu;
    }

    /**
     * How many quizzes use each profile.
     *
     * @return array<int, int> Profile id => quiz count.
     */
    public static function usage_counts(): array {
        global $DB;

        $rows = $DB->get_records_sql_menu(
            'SELECT profileid, COUNT(1)
               FROM {quizaccess_proctoring}
              WHERE profileid > 0
           GROUP BY profileid'
        );
        return array_map('intval', $rows);
    }

    /**
     * Whether any quiz takes a risk action from its profile: one whose profile sets the action
     * and whose own setting is "Use site default".
     *
     * @param int $action One of the QUIZACCESS_PROCTORING_RISK_ACTION_* values.
     * @return bool True when some quiz inherits that action from its profile.
     */
    public static function quiz_inherits_risk_action(int $action): bool {
        global $DB;

        $ids = [];
        foreach (self::all() as $profile) {
            $values = self::decode((string)$profile->settings);
            if (($values['riskreviewenabled'] ?? null) === (string)$action) {
                $ids[] = (int)$profile->id;
            }
        }
        if (!$ids) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'prof');
        return $DB->record_exists_select('quizaccess_proctoring', "profileid {$insql} AND riskreviewmode = -1", $params);
    }

    /**
     * Read a profile's stored settings, dropping anything not allowed.
     *
     * @param string $json Stored settings.
     * @return array<string, string> Setting => value.
     */
    public static function decode(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? self::clean($decoded) : [];
    }

    /**
     * Keep only allowed settings with allowed values. An empty string or null means "use the
     * site setting" and is dropped.
     *
     * @param array $values Setting => submitted value.
     * @return array<string, string> Setting => value, in SETTINGS order.
     */
    public static function clean(array $values): array {
        $clean = [];
        foreach (self::SETTINGS as $name => $definition) {
            if (!array_key_exists($name, $values) || $values[$name] === null || trim((string)$values[$name]) === '') {
                continue;
            }
            $value = trim((string)$values[$name]);
            if (array_key_exists($value, $definition['options'])) {
                $clean[$name] = $value;
            } else if (isset($definition['range']) && preg_match('/^\d+$/', $value)) {
                [$min, $max] = $definition['range'];
                if ((int)$value >= $min && (int)$value <= $max) {
                    $clean[$name] = (string)(int)$value;
                }
            }
        }
        return $clean;
    }

    /**
     * Create or update a profile.
     *
     * @param int $id Profile id, 0 for a new one.
     * @param string $name Name, unique on the site.
     * @param string $description Plain-text description.
     * @param array $values Setting => value; see {@see self::clean()}.
     * @return int Profile id.
     */
    public static function save(int $id, string $name, string $description, array $values): int {
        global $DB;

        $name = trim($name);
        if ($name === '' || \core_text::strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \invalid_parameter_exception('Profile name must be 1 to ' . self::MAX_NAME_LENGTH . ' characters.');
        }
        if (self::name_taken($name, $id)) {
            throw new \invalid_parameter_exception('A profile with that name already exists.');
        }
        $now = time();
        $record = (object)[
            'name' => $name,
            'description' => trim($description),
            'settings' => json_encode(self::clean($values)),
            'timemodified' => $now,
        ];
        if ($id > 0) {
            $DB->get_record(self::TABLE, ['id' => $id], 'id', MUST_EXIST);
            $record->id = $id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->timecreated = $now;
            $id = (int)$DB->insert_record(self::TABLE, $record);
        }
        self::reset_cache();
        return $id;
    }

    /**
     * Whether another profile already has this name, ignoring case.
     *
     * @param string $name Name.
     * @param int $exceptid Profile being edited, 0 for none.
     * @return bool True when the name is taken.
     */
    public static function name_taken(string $name, int $exceptid = 0): bool {
        global $DB;

        return $DB->record_exists_select(
            self::TABLE,
            $DB->sql_like('name', ':name', false, false) . ' AND id <> :id',
            ['name' => $DB->sql_like_escape(trim($name)), 'id' => $exceptid]
        );
    }

    /**
     * Delete a profile. Quizzes that used it go back to the site settings.
     *
     * @param int $id Profile id.
     */
    public static function delete(int $id): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field('quizaccess_proctoring', 'profileid', 0, ['profileid' => $id]);
        $DB->delete_records(self::TABLE, ['id' => $id]);
        $transaction->allow_commit();
        self::reset_cache();
    }

    /**
     * A one-line summary of what a profile changes, for the profiles list and the quiz page.
     *
     * @param array<string, string> $values Setting => value.
     * @return string[] One "Setting: value" line per changed setting.
     */
    public static function describe(array $values): array {
        $lines = [];
        foreach (self::clean($values) as $name => $value) {
            $definition = self::SETTINGS[$name];
            $label = get_string($definition['label'], self::COMPONENT);
            if (isset($definition['options'][$value])) {
                $shown = get_string($definition['options'][$value], self::COMPONENT);
            } else if ($name === 'facestrikes') {
                $shown = get_string('facestrikes_limit', self::COMPONENT, $value);
            } else {
                $shown = $value;
            }
            $lines[] = get_string('profile:summaryline', self::COMPONENT, (object)['setting' => $label, 'value' => $shown]);
        }
        return $lines;
    }

    /**
     * Forget cached quiz profiles, after a profile or a quiz's choice changes.
     */
    public static function reset_cache(): void {
        self::$quizcache = [];
    }
}
