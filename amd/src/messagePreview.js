// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

/**
 * Live preview and placeholder buttons on the "Messages to students" edit page (CPIT-512).
 *
 * The preview fills each [placeholder] with a sample value and shows the message as the student's
 * email shows it: line breaks kept, web and email addresses as links. It builds DOM nodes, never
 * HTML, so whatever is typed is shown as text.
 *
 * @module quizaccess_proctoring/messagePreview
 */
define([], function() {
    /** Web and email addresses; kept in step with message_texts::LINK_PATTERN. */
    const LINK_PATTERN = /(https?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)]|[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})/;

    /**
     * Fill the placeholders a message has with sample values; any other bracketed word stays.
     *
     * @param {String} text Text with [name] placeholders.
     * @param {Object} samples Sample value by placeholder name.
     * @returns {String} Filled text.
     */
    const fill = function(text, samples) {
        return String(text).replace(/\[([a-z0-9_]+)\]/g, function(match, name) {
            return Object.prototype.hasOwnProperty.call(samples, name) ? String(samples[name]) : match;
        });
    };

    /**
     * Show text in a container the way the email shows it.
     *
     * @param {HTMLElement} container Where to show it.
     * @param {String} text Filled text.
     */
    const render = function(container, text) {
        const doc = container.ownerDocument;
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        String(text).split(/\r\n|\r|\n/).forEach(function(line, index) {
            if (index > 0) {
                container.appendChild(doc.createElement('br'));
            }
            line.split(LINK_PATTERN).forEach(function(piece, i) {
                if (piece === '') {
                    return;
                }
                if (i % 2 === 0) {
                    container.appendChild(doc.createTextNode(piece));
                    return;
                }
                const link = doc.createElement('a');
                link.href = /^https?:\/\//i.test(piece) ? piece : 'mailto:' + piece;
                link.rel = 'noopener';
                link.target = '_blank';
                link.textContent = piece;
                container.appendChild(link);
            });
        });
    };

    /**
     * Insert text at the cursor of a text field, replacing any selection.
     *
     * @param {HTMLInputElement|HTMLTextAreaElement} field The field.
     * @param {String} text Text to insert.
     */
    const insert = function(field, text) {
        const start = typeof field.selectionStart === 'number' ? field.selectionStart : field.value.length;
        const end = typeof field.selectionEnd === 'number' ? field.selectionEnd : start;
        field.value = field.value.slice(0, start) + text + field.value.slice(end);
        const caret = start + text.length;
        if (typeof field.setSelectionRange === 'function') {
            field.setSelectionRange(caret, caret);
        }
        field.focus();
    };

    return {
        fill: fill,
        render: render,
        insert: insert,

        /**
         * Wire up the edit page.
         *
         * @param {Object} config parts (part name => its placeholder names) and samples (name =>
         *     sample value).
         */
        init: function(config) {
            const parts = config.parts || {};
            const samples = config.samples || {};
            const fields = {};
            Object.keys(parts).forEach(function(part) {
                const field = document.getElementById('id_' + part);
                if (field) {
                    fields[part] = field;
                }
            });
            const names = Object.keys(fields);
            if (!names.length) {
                return;
            }
            // Where a placeholder button inserts: the field last typed in, the message otherwise.
            let target = fields.body ? 'body' : names[0];

            const update = function(part) {
                const preview = document.querySelector('[data-proctoring-preview="' + part + '"]');
                if (preview) {
                    render(preview, fill(fields[part].value, samples));
                }
            };
            names.forEach(function(part) {
                fields[part].addEventListener('focus', function() {
                    target = part;
                });
                fields[part].addEventListener('input', function() {
                    update(part);
                });
                update(part);
            });

            document.querySelectorAll('[data-proctoring-placeholder]').forEach(function(button) {
                button.addEventListener('mousedown', function(event) {
                    // Keep the cursor in the field being edited.
                    event.preventDefault();
                });
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    const name = button.getAttribute('data-proctoring-placeholder');
                    // A placeholder goes only where the message has it.
                    let part = target;
                    if ((parts[part] || []).indexOf(name) === -1) {
                        part = names.filter((candidate) => (parts[candidate] || []).indexOf(name) !== -1)[0];
                    }
                    if (!part) {
                        return;
                    }
                    insert(fields[part], '[' + name + ']');
                    target = part;
                    update(part);
                });
            });
        },
    };
});
