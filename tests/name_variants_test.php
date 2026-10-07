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
 * Tests for the profile name variants sent to the ID verification service (CPIT-477).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/classes/external.php');

/**
 * Name variant tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring_external
 */
final class name_variants_test extends advanced_testcase {

    /**
     * The variants for a user.
     *
     * @param string $firstname First name.
     * @param string $lastname Last name.
     * @return string[]
     */
    private function variants(string $firstname, string $lastname): array {
        $user = (object)['firstname' => $firstname, 'lastname' => $lastname];
        foreach (\core_user\fields::get_name_fields() as $field) {
            $user->$field = $user->$field ?? '';
        }
        $method = new \ReflectionMethod(\quizaccess_proctoring_external::class, 'get_profile_name_variants');
        $method->setAccessible(true);
        return $method->invoke(null, $user);
    }

    /**
     * A name in another script is also sent in Latin letters, which the ID text reader can read.
     */
    public function test_non_latin_names_are_also_sent_in_latin(): void {
        if (!class_exists('\Transliterator')) {
            $this->markTestSkipped('The intl extension is required.');
        }
        $this->assertContains('Ivan Petrov', $this->variants('Иван', 'Петров'));
        $this->assertNotEmpty(array_filter(
            $this->variants('محمد', 'علي'),
            fn($variant) => preg_match('/^[A-Za-z\' -]+$/', $variant)
        ));
    }

    /**
     * A Latin name gets no duplicate "transliterated" copy.
     */
    public function test_latin_names_are_unchanged(): void {
        $variants = $this->variants('John', 'Smith');
        $this->assertContains('John Smith', $variants);
        $this->assertSame(count($variants), count(array_unique($variants)));
        $this->assertNotContains('', $variants);
    }
}
