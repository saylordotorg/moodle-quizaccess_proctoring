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
 * Structural tests for the attempts report template.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use DOMDocument;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the attempts report template and checks the markup holds together.
 *
 * Mustache lint catches malformed markup, but only in CI, and twice now a template change has
 * reached master because that lane runs later than the test suite does: an invalid <label> owning
 * two inputs, and a stray </div> left behind when the card wrapper became a table. Both are cheap
 * to catch here, against the template's own example context, before anything is pushed.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class overall_report_template_test extends advanced_testcase {

    /** @var string Template this covers. */
    private const TEMPLATE = 'quizaccess_proctoring/overall_reports';

    /**
     * Render the template against the example context documented in its own header.
     *
     * @return string Rendered HTML.
     */
    private function render(): string {
        global $CFG, $OUTPUT, $PAGE;

        $PAGE->set_url('/');
        $PAGE->set_context(\context_system::instance());

        $source = file_get_contents(
            $CFG->dirroot . '/mod/quiz/accessrule/proctoring/templates/overall_reports.mustache'
        );
        $start = strpos($source, '{', strpos($source, 'Example context (json):'));
        $depth = 0;
        $end = null;
        for ($i = $start; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } else if ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $context = json_decode(substr($source, $start, $end - $start + 1), true);
        $this->assertIsArray($context, 'the template example context must be valid JSON');

        return $OUTPUT->render_from_template(self::TEMPLATE, $context);
    }

    /**
     * The rendered markup parses with no stray or mismatched tags.
     */
    public function test_rendered_markup_is_structurally_sound(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render();

        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new DOMDocument();
        $doc->loadHTML(
            '<!doctype html><html><head><meta charset="utf-8"><title>t</title></head><body>'
                . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $structural = [];
        foreach (libxml_get_errors() as $error) {
            if (stripos($error->message, 'Unexpected end tag') !== false
                    || stripos($error->message, 'Opening and ending tag mismatch') !== false
                    || stripos($error->message, 'Premature end') !== false) {
                $structural[] = trim($error->message) . ' (line ' . $error->line . ')';
            }
        }
        libxml_clear_errors();

        $this->assertSame([], $structural, 'the rendered template has structural markup errors');
        $this->assertSame(
            substr_count($html, '<div'),
            substr_count($html, '</div>'),
            'opening and closing div counts must match'
        );
    }

    /**
     * No label owns more than one form control, which is invalid and ambiguous for screen readers.
     */
    public function test_no_label_owns_more_than_one_control(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render();

        preg_match_all('#<label\b[^>]*>(.*?)</label>#s', $html, $labels);
        $this->assertNotEmpty($labels[1], 'the filter form should render labels');
        foreach ($labels[1] as $inner) {
            $this->assertLessThanOrEqual(
                1,
                preg_match_all('#<(input|select|textarea|button|meter|output|progress)\b#', $inner),
                'a label may own at most one control'
            );
        }
    }

    /**
     * Every proctoring class the template emits has a rule behind it, so a CSS cleanup cannot
     * silently strip the styling off a state the markup still asks for.
     */
    public function test_every_emitted_class_is_styled(): void {
        global $CFG;

        $tpl = file_get_contents(
            $CFG->dirroot . '/mod/quiz/accessrule/proctoring/templates/overall_reports.mustache'
        );
        $css = file_get_contents($CFG->dirroot . '/mod/quiz/accessrule/proctoring/styles.css');

        $dynamic = [
            'proctoring-overview-status-' => ['needs', 'flagged', 'reviewed', 'escalated', 'clean'],
            'proctoring-risk-' => ['low', 'moderate', 'high', 'critical'],
        ];
        $classes = [];
        preg_match_all('#class="([^"]*)"#', $tpl, $attrs);
        foreach ($attrs[1] as $attr) {
            foreach (preg_split('#\s+#', trim($attr)) as $token) {
                if (strpos($token, '{{') !== false) {
                    foreach ($dynamic as $prefix => $values) {
                        if (strpos($token, $prefix) === 0) {
                            foreach ($values as $value) {
                                $classes[$prefix . $value] = true;
                            }
                        }
                    }
                    continue;
                }
                if (strpos($token, 'proctoring') === 0) {
                    $classes[$token] = true;
                }
            }
        }

        // The match has to stop at a CSS identifier boundary. A plain substring search would let
        // ".proctoring-overview-tablewrap" vouch for ".proctoring-overview-table", so deleting the
        // shorter rule would slip past the very check meant to catch it.
        $unstyled = [];
        foreach (array_keys($classes) as $class) {
            $pattern = '/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/';
            if (!preg_match($pattern, $css)) {
                $unstyled[] = $class;
            }
        }

        $this->assertSame([], $unstyled, 'these classes are emitted but have no CSS rule');
    }
}
