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

    /** Tries before a strike is given up: about twenty minutes. */
    const MAX_TRIES = 45;

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
     *     setTimeout, clearTimeout, storage (a sessionStorage-like object) and cameraLive()
     *     (whether the camera is sending frames, checked before a strike counts).
     * @returns {Object} pauseStarted, pauseEnded, cameraLost, cameraBack, faceSeen, pageLeaving and state.
     */
    const create = function(options) {
        const now = options.now || Date.now;
        const later = options.setTimeout || window.setTimeout.bind(window);
        const cancel = options.clearTimeout || window.clearTimeout.bind(window);
        const storage = options.storage || null;
        const key = 'quizaccess_proctoring_face_strike_' + options.attemptId;
        const outboxKey = key + '_outbox';
        const minMs = Math.max(1, options.minSeconds || 5) * 1000;
        let limit = options.limit;
        // The server's count when the page loaded, the highest count it has answered with since,
        // the strikes an earlier page could not deliver, and the strikes this page made. A
        // cumulative answer can already include a strike whose own answer is still on its way, so
        // the server's count and the page's own are compared, never added.
        const base = options.strikes || 0;
        let acknowledged = base;
        let recovered = 0;
        let made = 0;
        const count = () => Math.max(acknowledged, base + recovered + made);
        let shown = base;
        let ended = false;
        let paused = false;
        let struck = false;
        // The camera stopped while the quiz was paused: the pause's time does not count meanwhile.
        let suspended = false;
        let pausedAt = 0;
        // Time the current pause had already lasted on earlier pages.
        let inherited = 0;
        let timer = null;

        const read = function(name) {
            if (!storage) {
                return null;
            }
            try {
                return JSON.parse(storage.getItem(name) || 'null');
            } catch (error) {
                return null;
            }
        };
        const write = function(name, value) {
            if (!storage) {
                return;
            }
            try {
                if (value === null) {
                    storage.removeItem(name);
                } else {
                    storage.setItem(name, JSON.stringify(value));
                }
            } catch (error) {
                // Storage unavailable: nothing carries over to the next page.
            }
        };

        // The previous page of this attempt left in the middle of a pause.
        let carry = read(key);
        write(key, null);
        // Strikes the server has not acknowledged yet, by request id. Kept in storage, so a strike
        // still being retried when the page goes is delivered by the next page.
        const saved = read(outboxKey);
        const outbox = saved && typeof saved === 'object' ? saved : {};
        // Taken as not yet on the server, which is what an undelivered strike almost always is.
        // One that landed but whose answer was lost is counted twice here: that can only bring a
        // warning forward, never the end, which only the server decides.
        recovered = Object.keys(outbox).length;
        const saveOutbox = function() {
            write(outboxKey, Object.keys(outbox).length ? outbox : null);
        };

        const show = function(n) {
            if (n > 0 && n !== shown) {
                shown = n;
                options.notify(n, limit);
            }
        };

        const deliver = function(strike, tries) {
            Promise.resolve().then(function() {
                // A fresh capture time on every try: the server accepts a retry by its request id,
                // but not a capture time from long ago.
                return options.send(Object.assign({}, strike, {capturedat: Math.floor(now() / 1000)}));
            }).then(function(answer) {
                delete outbox[strike.requestid];
                saveOutbox();
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
                // Warnings only: that the attempt has ended is the server's to say.
                if (limit > 0) {
                    show(Math.min(count(), limit - 1));
                }
                return;
            }).catch(function() {
                if (ended || tries + 1 >= MAX_TRIES) {
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
            if (options.cameraLive && !options.cameraLive()) {
                // The camera died since the last check: the time until now counts, but not the
                // strike, until the camera is back and the pause is still on.
                suspended = true;
                inherited += now() - pausedAt;
                return;
            }
            struck = true;
            made++;
            const record = {
                number: count(),
                pausedat: pausedAt - inherited,
                requestid: 'strike-' + options.attemptId + '-' + count() + '-' + Math.random().toString(36).slice(2, 10),
            };
            outbox[record.requestid] = record;
            saveOutbox();
            // A warning shows at once; the end waits until the server confirms it.
            if (count() < limit) {
                show(count());
            }
            deliver(record, 0);
        };

        // Deliver what the previous page could not.
        Object.keys(outbox).forEach(function(requestid) {
            deliver(outbox[requestid], 0);
        });

        return {
            /** The quiz was paused for no face. */
            pauseStarted: function() {
                if (paused) {
                    return;
                }
                paused = true;
                struck = false;
                suspended = false;
                inherited = 0;
                pausedAt = now();
                if (ended || limit <= 0) {
                    return;
                }
                // The latest pause left mid-way: by a page before this one, or - restored from the
                // back-forward cache - by this very page, or a page after it.
                const stored = read(key);
                if (stored) {
                    write(key, null);
                    if (!carry || stored.at > carry.at) {
                        carry = stored;
                    }
                }
                const continued = carry && pausedAt - carry.at <= CONTINUATION_MS ? carry : null;
                carry = null;
                if (continued && continued.struck) {
                    // Already counted on the page before: the same pause, not another one.
                    struck = true;
                    return;
                }
                inherited = continued ? Math.max(0, continued.elapsed || 0) : 0;
                timer = later(strike, Math.max(0, minMs - inherited));
            },
            /** The face is back and the pause is over. */
            pauseEnded: function() {
                paused = false;
                struck = false;
                suspended = false;
                inherited = 0;
                if (timer !== null) {
                    cancel(timer);
                    timer = null;
                }
            },
            /**
             * The camera stopped sending frames. That is a camera problem, not a face out of frame:
             * the pause's time stops counting, and what it had counted is kept.
             */
            cameraLost: function() {
                if (!paused || suspended) {
                    return;
                }
                suspended = true;
                if (timer !== null) {
                    cancel(timer);
                    timer = null;
                    inherited += now() - pausedAt;
                }
            },
            /** The camera is sending frames again: a pause still on carries on counting. */
            cameraBack: function() {
                if (!suspended) {
                    return;
                }
                suspended = false;
                if (!paused || struck || ended || limit <= 0) {
                    return;
                }
                pausedAt = now();
                timer = later(strike, Math.max(0, minMs - inherited));
            },
            /** A face was seen: a pause after this is a new one, whatever the page before left. */
            faceSeen: function() {
                if (carry) {
                    carry = null;
                    write(key, null);
                }
            },
            /** The page is going: a pause in progress carries over to the next page. */
            pageLeaving: function() {
                if (!paused || ended) {
                    return;
                }
                carry = {at: now(), struck: struck, elapsed: inherited + (suspended ? 0 : now() - pausedAt)};
                write(key, carry);
                // The next page carries the pause on. This one stops timing it, and keeps its own copy
                // in case it is restored from the back-forward cache: the same pause, not a new one.
                paused = false;
                struck = false;
                suspended = false;
                inherited = 0;
                if (timer !== null) {
                    cancel(timer);
                    timer = null;
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
