// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

/**
 * Escalating "face out of frame" warnings that end the attempt on the Nth time (CPIT-510).
 *
 * A strike is a face pause - the quiz blurred because no face was in view - that lasts the
 * configured number of seconds, counted once per pause. The server keeps the count and alone
 * decides that the attempt has ended; this module times the pauses, says which warning to show,
 * delivers each strike until the server has it, and calls end() once the server says so.
 *
 * @module quizaccess_proctoring/faceStrikes
 */
define([], function() {
    /** A pause that began this soon after the previous page left mid-pause carries that pause on. */
    const CONTINUATION_MS = 30000;

    /** Delays between attempts to deliver a strike the server has not acknowledged. */
    const RETRY_DELAYS_MS = [2000, 5000, 10000, 20000, 30000];

    /** @var {Object|null} The attempt page's settings, from configure(). */
    let config = null;

    /**
     * Which warning a strike shows.
     *
     * @param {Number} count Strikes so far, this one included.
     * @param {Number} limit Strikes that end the attempt.
     * @returns {String} Language string key.
     */
    const noticeKey = function(count, limit) {
        if (count >= limit) {
            return 'facestrike:ended';
        }
        if (count === limit - 1) {
            return count === 1 ? 'facestrike:onlywarning' : 'facestrike:final';
        }
        return count === 1 ? 'facestrike:first' : 'facestrike:again';
    };

    /**
     * The values a warning's language string is filled with.
     *
     * @param {Number} count Strikes so far.
     * @param {Number} limit Strikes that end the attempt.
     * @param {String} consequence What happens when the attempt ends, as a clause.
     * @returns {Object} count, warnings, remaining, limit and consequence.
     */
    const noticeParams = function(count, limit, consequence) {
        return {
            count: count,
            warnings: limit - 1,
            remaining: Math.max(0, limit - count),
            limit: limit,
            consequence: consequence,
        };
    };

    /**
     * Track one attempt page's pauses.
     *
     * @param {Object} options limit, strikes, minSeconds, attemptId, send(strike) returning a
     *     promise of the log_event answer, notify(count, limit), end(), and optional now,
     *     setTimeout, clearTimeout and storage (a sessionStorage-like object).
     * @returns {Object} pauseStarted, pauseEnded, faceSeen, pageLeaving and state.
     */
    const create = function(options) {
        const now = options.now || Date.now;
        const later = options.setTimeout || window.setTimeout.bind(window);
        const cancel = options.clearTimeout || window.clearTimeout.bind(window);
        const storage = options.storage || null;
        const key = 'quizaccess_proctoring_face_strike_' + options.attemptId;
        const minMs = Math.max(1, options.minSeconds || 5) * 1000;
        let limit = options.limit;
        // What the server has acknowledged, and the strikes of this page still on their way to it.
        let acknowledged = options.strikes || 0;
        let pending = 0;
        const count = () => acknowledged + pending;
        let shown = acknowledged;
        let ended = false;
        let paused = false;
        let struck = false;
        let pausedAt = 0;
        let timer = null;

        // The previous page of this attempt left in the middle of a pause.
        let carry = null;
        if (storage) {
            try {
                carry = JSON.parse(storage.getItem(key) || 'null');
                storage.removeItem(key);
            } catch (error) {
                carry = null;
            }
        }

        const show = function(n) {
            if (n > 0 && n !== shown) {
                shown = n;
                options.notify(n, limit);
            }
        };

        const deliver = function(strike, tries) {
            Promise.resolve().then(() => options.send(strike)).then(function(answer) {
                pending = Math.max(0, pending - 1);
                if (ended || !answer) {
                    return;
                }
                if (typeof answer.facestrikelimit === 'number') {
                    limit = answer.facestrikelimit;
                }
                if (typeof answer.facestrikes === 'number') {
                    // Answers can arrive out of order; the server's count only ever grows.
                    acknowledged = Math.max(acknowledged, answer.facestrikes);
                }
                if (answer.attemptended) {
                    ended = true;
                    show(Math.max(count(), limit));
                    options.end();
                    return;
                }
                if (shown >= limit && count() < limit) {
                    // Told the attempt ended, but the server disagrees (or turned the rule off):
                    // show where it really stands.
                    shown = 0;
                    show(count());
                }
                return;
            }).catch(function() {
                if (ended) {
                    return;
                }
                later(() => deliver(strike, tries + 1), RETRY_DELAYS_MS[Math.min(tries, RETRY_DELAYS_MS.length - 1)]);
            });
        };

        const strike = function() {
            timer = null;
            if (!paused || struck || ended || limit <= 0) {
                return;
            }
            struck = true;
            pending++;
            show(count());
            deliver({
                number: count(),
                pausedat: pausedAt,
                capturedat: Math.floor(now() / 1000),
                requestid: 'strike-' + options.attemptId + '-' + count() + '-' + Math.random().toString(36).slice(2, 10),
            }, 0);
        };

        return {
            /** The quiz was paused for no face. */
            pauseStarted: function() {
                if (paused) {
                    return;
                }
                paused = true;
                struck = false;
                pausedAt = now();
                if (ended || limit <= 0) {
                    return;
                }
                const continued = carry && pausedAt - carry.at <= CONTINUATION_MS ? carry : null;
                carry = null;
                if (continued && continued.struck) {
                    // Already counted on the page before: the same pause, not another one.
                    struck = true;
                    return;
                }
                const elapsed = continued ? Math.max(0, continued.elapsed || 0) : 0;
                timer = later(strike, Math.max(0, minMs - elapsed));
            },
            /** The face is back and the pause is over. */
            pauseEnded: function() {
                paused = false;
                struck = false;
                if (timer !== null) {
                    cancel(timer);
                    timer = null;
                }
            },
            /** A face was seen: a pause after this is a new one, whatever the page before left. */
            faceSeen: function() {
                carry = null;
            },
            /** The page is going: a pause in progress carries over to the next page. */
            pageLeaving: function() {
                if (!storage || !paused || ended) {
                    return;
                }
                try {
                    storage.setItem(key, JSON.stringify({at: now(), struck: struck, elapsed: now() - pausedAt}));
                } catch (error) {
                    // Storage unavailable: the next page counts its pause on its own.
                }
            },
            /** Where things stand, for tests and diagnostics. */
            state: function() {
                return {count: count(), limit: limit, ended: ended, paused: paused, struck: struck};
            },
        };
    };

    return {
        noticeKey: noticeKey,
        noticeParams: noticeParams,
        create: create,
        /**
         * Settings for the attempt page, from rule.php.
         *
         * @param {Object} settings limit, strikes, minseconds, outcome and lockoutdays.
         */
        configure: function(settings) {
            config = settings || null;
        },
        /**
         * The attempt page's settings, or null when the rule does not apply.
         *
         * @returns {Object|null} Settings.
         */
        settings: function() {
            return config && config.limit > 0 ? config : null;
        },
    };
});
