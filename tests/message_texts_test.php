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
 * Tests for the student messages Student Affairs can reword in Moodle (CPIT-512).
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace quizaccess_proctoring;

use advanced_testcase;
use quizaccess_proctoring\local\message_texts;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/proctoring/lib.php');

/**
 * Message wording tests.
 *
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \quizaccess_proctoring\local\message_texts
 * @covers ::quizaccess_proctoring_notify_hold_decision
 * @covers \quizaccess_proctoring\local\exemption_email
 */
final class message_texts_test extends advanced_testcase {
    /**
     * Every catalogue message has its title and description, and every string it rewords exists.
     */
    public function test_the_catalogue_is_complete(): void {
        $manager = get_string_manager();
        foreach (message_texts::MESSAGES as $id => $message) {
            $this->assertTrue($manager->string_exists('messagetexts:message_' . $id, 'quizaccess_proctoring'), $id);
            $this->assertTrue($manager->string_exists('messagetexts:when_' . $id, 'quizaccess_proctoring'), $id);
            foreach ($message['settings'] as $setting) {
                $this->assertTrue($manager->string_exists('setting:' . $setting, 'quizaccess_proctoring'), $setting);
            }
            foreach ($message['parts'] as $part => $key) {
                $this->assertContains($part, ['subject', 'body']);
                $this->assertTrue($manager->string_exists($key, 'quizaccess_proctoring'), $key);
                $this->assertSame([$id, $part], message_texts::locate($key));
                // The edit page explains every placeholder and previews it with a sample.
                foreach (message_texts::placeholders($key) as $name) {
                    $this->assertNotSame('', message_texts::describe($name), $name);
                    $this->assertArrayHasKey($name, message_texts::samples(), $name);
                }
            }
        }
        $this->assertNull(message_texts::locate('idexemptionemail:approved:eyebrow'));
    }

    /**
     * The HTML version escapes the text, links web and email addresses, and keeps line breaks.
     */
    public function test_the_html_version_links_and_escapes(): void {
        $html = message_texts::html("Read <b>this</b>:\n- https://learn.saylor.org/a?b=1&c=2.\n- Write to help@example.org.");
        $this->assertStringContainsString('&lt;b&gt;this&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('<a href="https://learn.saylor.org/a?b=1&amp;c=2">https://learn.saylor.org/a?b=1&amp;c=2</a>.', $html);
        $this->assertStringContainsString('<a href="mailto:help@example.org">help@example.org</a>.', $html);
        $this->assertSame(2, substr_count($html, '<br />'));
        $this->assertStringStartsWith('<div class="text_to_html">', $html);
        $this->assertStringNotContainsString('<div', message_texts::html('plain', false));
    }

    /**
     * Staff see [name] placeholders; the stored text keeps the language string's own tokens.
     */
    public function test_placeholders_are_shown_by_name(): void {
        $this->assertSame(
            ['{$a->quiz}' => 'quiz', '{$a->course}' => 'course', '{$a->window}' => 'window'],
            message_texts::placeholders('message:heldbody')
        );
        // A bare {$a} is named by the catalogue.
        $this->assertSame(['{$a}' => 'quiz'], message_texts::placeholders('idexemptionemail:approved:subject'));
        $this->assertSame([], message_texts::placeholders('message:heldsubject'));

        $editor = message_texts::to_editor('message:releasedbody');
        $this->assertStringContainsString('[quiz]', $editor);
        $this->assertStringContainsString('[course]', $editor);
        $this->assertStringNotContainsString('{$a', $editor);
        $this->assertSame(
            message_texts::default_text('message:releasedbody'),
            message_texts::from_editor('message:releasedbody', $editor)
        );
    }

    /**
     * An edit must keep its placeholders, use no unknown ones, and fit.
     */
    public function test_an_edit_is_checked(): void {
        $key = 'message:confirmedbody';
        $this->assertSame('', message_texts::validate($key, 'Your attempt for [quiz] in [course] failed. [questions]', false));
        $this->assertStringContainsString(
            '[course]',
            message_texts::validate($key, 'Your attempt for [quiz] failed. [questions]', false)
        );
        $this->assertStringContainsString(
            '[quizz]',
            message_texts::validate($key, 'Your attempt for [quizz] in [course] failed. [quiz] [questions]', false)
        );
        $this->assertNotSame('', message_texts::validate($key, '   ', false));
        $this->assertNotSame('', message_texts::validate($key, str_repeat('x', message_texts::MAX_BODY + 1), false));
        $this->assertNotSame('', message_texts::validate('message:confirmedsubject', "Two\nlines", true));
        $this->assertSame('', message_texts::validate('message:confirmedsubject', 'Review complete', true));
    }

    /**
     * A greeting by name can be added to any message, and the ID exception emails can name the
     * course; neither has to be kept (CPIT-512, Student Affairs).
     */
    public function test_optional_placeholders(): void {
        $this->assertSame(
            ['{$a->firstname}' => 'firstname', '{$a->fullname}' => 'fullname'],
            message_texts::optional_placeholders('referencereset:donesubject')
        );
        // A hold message's subject can name the exam its body already names.
        $this->assertSame(
            [
                '{$a->firstname}' => 'firstname',
                '{$a->fullname}' => 'fullname',
                '{$a->quiz}' => 'quiz',
                '{$a->course}' => 'course',
            ],
            message_texts::optional_placeholders('message:heldsubject')
        );
        $this->assertSame(
            ['{$a->firstname}' => 'firstname', '{$a->fullname}' => 'fullname', '{$a->course}' => 'course'],
            message_texts::optional_placeholders('idexemptionemail:approved:subject')
        );
        // A message that must keep [course] does not offer it a second time.
        $this->assertArrayNotHasKey('{$a->course}', message_texts::optional_placeholders('message:heldbody'));

        $key = 'message:confirmedbody';
        $this->assertSame('', message_texts::validate($key, 'Hi [firstname], [quiz] in [course]. [questions]', false));
        $this->assertSame('', message_texts::validate($key, 'Your attempt for [quiz] in [course] failed. [questions]', false));
        $this->assertStringContainsString(
            '[firstnam]',
            message_texts::validate($key, 'Hi [firstnam], [quiz] [course] [questions]', false)
        );
        $this->assertSame('', message_texts::validate('message:heldsubject', 'Held for review: [quiz], [course]', true));
        $this->assertStringContainsString('[course]', message_texts::validate('referencereset:donesubject', 'About [course]', true));

        $text = 'Hi [firstname], about [quiz]';
        $this->assertSame($text, message_texts::to_editor($key, message_texts::from_editor($key, $text)));
    }

    /**
     * Saved wording is what get() fills in; reset, or saving the shipped text, goes back to it.
     */
    public function test_saved_wording_wins_until_reset(): void {
        global $DB;

        $this->resetAfterTest();
        $key = 'referencereset:donebody';
        $a = (object)['contact' => 'help@example.org'];
        $default = get_string($key, 'quizaccess_proctoring', $a);
        $this->assertSame($default, message_texts::get($key, $a));

        message_texts::save($key, 'New photo next time. Ask [contact].', 7);
        $this->assertSame('New photo next time. Ask help@example.org.', message_texts::get($key, $a));
        $this->assertSame('New photo next time. Ask [contact].', message_texts::to_editor($key));
        $this->assertSame(7, (int)message_texts::edit_record($key)->usermodified);

        message_texts::reset($key);
        $this->assertSame($default, message_texts::get($key, $a));

        message_texts::save($key, 'Again [contact].', 7);
        message_texts::save($key, message_texts::to_editor($key, message_texts::default_text($key)), 7);
        $this->assertFalse($DB->record_exists('quizaccess_proctoring_msg_texts', ['stringid' => $key]));

        // A bare {$a} is filled too.
        message_texts::save('idexemptionemail:approved:subject', 'Approved: [quiz]', 7);
        $this->assertSame('Approved: Final exam', message_texts::get('idexemptionemail:approved:subject', 'Final exam'));
    }

    /**
     * Student Affairs rewords "violation confirmed", a hold is confirmed, and the student receives
     * the new words - which the per-student report keeps as sent (the ticket's acceptance check).
     */
    public function test_a_confirmed_hold_sends_the_edited_text(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('holddecisionnotify', 1, 'quizaccess_proctoring');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Business 101']);
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Final exam']);
        $student = $generator->create_user(['firstname' => 'Dana', 'lastname' => 'Okafor']);

        message_texts::save('message:confirmedsubject', '[fullname], your review of [course] is complete', 2);
        message_texts::save('message:confirmedbody', 'Hi [firstname], we reviewed [quiz] in [course]. [questions]', 2);

        $sink = $this->redirectMessages();
        quizaccess_proctoring_notify_hold_decision((object)[
            'courseid' => (int)$course->id,
            'quizid' => (int)$quiz->cmid,
            'quizinstance' => (int)$quiz->id,
            'userid' => (int)$student->id,
            'attemptid' => 4242,
        ], 'confirmed');
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame('Dana Okafor, your review of Business 101 is complete', $messages[0]->subject);
        $expected = 'Hi Dana, we reviewed Final exam in Business 101. '
            . \quizaccess_proctoring\local\support_contact::questions_sentence();
        $this->assertSame($expected, $messages[0]->fullmessage);

        $event = $DB->get_record('quizaccess_proctoring_events', ['eventtype' => 'notification_sent', 'userid' => $student->id]);
        $detail = json_decode((string)$event->eventdetail, true);
        $this->assertSame($expected, $detail['body']);
    }

    /**
     * The ID exception email uses the edited subject and opening paragraph; its other lines stay.
     */
    public function test_the_id_exception_email_uses_the_edited_text(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['firstname' => 'Dana']);
        message_texts::save('idexemptionemail:approved:subject', 'Good news about [quiz] ([course])', 2);
        message_texts::save('idexemptionemail:approved:intro', 'Hi [firstname], you can take [quiz] without the ID step.', 2);

        $sink = $this->redirectEmails();
        \quizaccess_proctoring\local\exemption_email::notify_student_decision($student, true, 'Business 101', 'Final exam', 99, '');
        $emails = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $emails);
        $this->assertSame('Good news about Final exam (Business 101)', $emails[0]->subject);
        $body = quoted_printable_decode($emails[0]->body);
        $this->assertStringContainsString('Hi Dana, you can take Final exam without the ID step.', $body);
        $this->assertStringContainsString(get_string('idexemptionemail:approved:title', 'quizaccess_proctoring'), $body);
    }

    /**
     * Erasing a staff member keeps the wording they set and removes only who set it.
     */
    public function test_privacy_keeps_the_wording_and_drops_the_editor(): void {
        global $DB;

        $this->resetAfterTest();
        $editor = $this->getDataGenerator()->create_user();
        message_texts::save('message:releasedsubject', 'Your grade is back', (int)$editor->id);

        $contexts = \quizaccess_proctoring\privacy\provider::get_contexts_for_userid((int)$editor->id);
        $this->assertContains((int)\context_system::instance()->id, array_map('intval', $contexts->get_contextids()));

        $userlist = new \core_privacy\local\request\userlist(\context_system::instance(), 'quizaccess_proctoring');
        \quizaccess_proctoring\privacy\provider::get_users_in_context($userlist);
        $this->assertContains((int)$editor->id, array_map('intval', $userlist->get_userids()));

        \quizaccess_proctoring\privacy\provider::delete_data_for_users(new \core_privacy\local\request\approved_userlist(
            \context_system::instance(),
            'quizaccess_proctoring',
            [(int)$editor->id]
        ));
        $record = $DB->get_record('quizaccess_proctoring_msg_texts', ['stringid' => 'message:releasedsubject'], '*', MUST_EXIST);
        $this->assertSame('Your grade is back', $record->text);
        $this->assertSame(0, (int)$record->usermodified);
    }

    /**
     * An editor's export lists what they reworded, and erasing the whole system context keeps
     * the wording but not who wrote it.
     */
    public function test_privacy_export_and_context_erasure(): void {
        global $DB;

        $this->resetAfterTest();
        $editor = $this->getDataGenerator()->create_user();
        message_texts::save('message:releasedsubject', 'Your grade is back', (int)$editor->id);
        $system = \context_system::instance();

        \quizaccess_proctoring\privacy\provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            $editor,
            'quizaccess_proctoring',
            [$system->id]
        ));
        $exported = \core_privacy\local\request\writer::with_context($system)->get_data([
            get_string('quizaccess_proctoring', 'quizaccess_proctoring'),
            'proctoring_student_message_edits',
            1,
        ]);
        $this->assertSame('message:releasedsubject', $exported->stringid);
        $this->assertSame('Your grade is back', $exported->text);

        \quizaccess_proctoring\privacy\provider::delete_data_for_all_users_in_context($system);
        $record = $DB->get_record('quizaccess_proctoring_msg_texts', ['stringid' => 'message:releasedsubject'], '*', MUST_EXIST);
        $this->assertSame('Your grade is back', $record->text);
        $this->assertSame(0, (int)$record->usermodified);
    }
}
