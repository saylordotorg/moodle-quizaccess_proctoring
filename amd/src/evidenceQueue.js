// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

/**
 * Short, bounded, memory-only recovery for proctoring evidence uploads.
 *
 * @module quizaccess_proctoring/evidenceQueue
 */
define([], function() {
    const authCodes = ['nopermissions', 'requireloginerror', 'servicerequireslogin', 'invalidsesskey',
        'sessionerror', 'sessionexpired', 'loggedout', 'invalidtoken', 'accessexception'];

    /** Classify failures before considering a retry. Unknown application errors fail closed. */
    const classify = function(error) {
        const code = error && (error.errorcode || (error.exception && error.exception.errorcode));
        const status = Number(error && (error.status || error.statusCode));
        if (authCodes.includes(code) || status === 401 || status === 403) {
            return 'auth';
        }
        if (code) {
            return ['ratelimitexceeded', 'servicetemporarilyunavailable'].includes(code) ? 'temporary' : 'rejected';
        }
        if (status >= 500 || status === 408 || status === 429) {
            return 'temporary';
        }
        if (status >= 400) {
            return 'rejected';
        }
        // Moodle core/ajax drops the jqXHR status and may reject with just errorThrown.
        const message = String((error && error.message) || error || '').toLowerCase();
        if (!message || /timeout|network|failed to fetch|load failed|bad gateway|service unavailable|gateway timeout|internal server error/.test(message)) {
            return 'temporary';
        }
        return 'rejected';
    };

    const requestId = function() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-' +
            Math.random().toString(36).slice(2);
    };

    return {
        classify: classify,
        /**
         * Create one queue per attempt page. Payloads never enter persistent browser storage.
         *
         * @param {Object} options send, online, onState and optional clock/timer functions
         * @returns {Object} enqueue, flush, dispose and snapshot
         */
        create: function(options) {
            const now = options.now || Date.now;
            const later = options.setTimeout || window.setTimeout.bind(window);
            const cancel = options.clearTimeout || window.clearTimeout.bind(window);
            const online = options.online || (() => navigator.onLine !== false);
            const maxItems = options.maxItems || 4;
            const maxBytes = options.maxBytes || 4 * 1024 * 1024;
            const ttl = options.ttl || 120000;
            let items = [];
            let active = null;
            let timer = null;
            let disposed = false;
            let auth = false;
            let dropped = 0;
            let recovered = 0;
            let lastProblem = '';
            let lastDropAt = 0;
            let lastRecoveredAt = 0;

            const snapshot = function() {
                return {
                    pending: items.length,
                    bytes: items.reduce((total, item) => total + item.bytes, 0),
                    dropped: dropped,
                    recovered: recovered,
                    auth: auth,
                    disposed: disposed,
                    offline: !online(),
                    retrying: items.some(item => item.failures > 0),
                    problem: lastProblem,
                    // When the trouble happened, so a banner can stand down once uploads have been
                    // healthy again instead of outliving a hiccup by a whole exam (CPIT-503).
                    lastdropat: lastDropAt,
                    lastrecoveredat: lastRecoveredAt
                };
            };
            const emit = function() {
                if (options.onState) {
                    options.onState(snapshot());
                }
            };
            const drop = function(item, reason) {
                items = items.filter(entry => entry !== item);
                item.request = null;
                if (item.expendable) {
                    // However a diagnostic leaves - evicted, expired, refused - its discard is
                    // never evidence loss: counting it would re-raise the very banner whose
                    // logging it was (Codex review).
                    return;
                }
                dropped++;
                lastProblem = reason;
                lastDropAt = now();
            };
            // Context events worth less than the capacity they occupy: under pressure these go
            // first, so a burst of them cannot push captured evidence out of the queue (CPIT-503).
            const lowValue = function(item) {
                const args = item.request && item.request.args;
                return !!args && ['mouse_left_window', 'mouse_returned_window'].includes(args.eventtype);
            };
            const expire = function() {
                items.slice().forEach(item => {
                    // An active request has a transport timeout; never start a second copy in parallel.
                    if (item !== active && now() - item.created >= ttl) {
                        drop(item, 'expired');
                    }
                });
            };
            let pump;
            const schedule = function() {
                if (timer !== null) {
                    cancel(timer);
                    timer = null;
                }
                if (disposed || auth || !items.length) {
                    return;
                }
                const waiting = items.filter(item => item !== active);
                if (!waiting.length) {
                    return;
                }
                let due = Math.min(...waiting.map(item => item.created + ttl));
                if (!active && online()) {
                    due = Math.min(due, items[0].next);
                }
                timer = later(pump, Math.max(1, due - now()));
            };
            const finish = function(item, error, response) {
                if (disposed) {
                    return;
                }
                active = null;
                if (!error && !(response && response.warnings && response.warnings.length)) {
                    items = items.filter(entry => entry !== item);
                    if (item.failures > 0 || item.queuedOffline) {
                        recovered++;
                        lastRecoveredAt = now();
                    }
                    item.request = null;
                } else {
                    const kind = error ? classify(error) : 'rejected';
                    if (kind === 'auth') {
                        auth = true;
                        items.slice().forEach(entry => drop(entry, 'auth'));
                    } else if (kind === 'temporary' && now() - item.created < ttl) {
                        item.failures++;
                        item.next = now() + Math.min(30000, 2000 * Math.pow(2, item.failures - 1));
                    } else {
                        drop(item, kind === 'temporary' ? 'expired' : 'rejected');
                    }
                }
                expire();
                emit();
                schedule();
            };
            pump = function() {
                if (timer !== null) {
                    cancel(timer);
                }
                timer = null;
                if (disposed || auth) {
                    return;
                }
                expire();
                if (!active && online() && items.length && items[0].next <= now()) {
                    active = items[0];
                    const item = active;
                    try {
                        Promise.resolve(options.send(item.request)).then(
                            response => finish(item, null, response),
                            error => finish(item, error || {message: 'network'}, null)
                        );
                    } catch (error) {
                        finish(item, error, null);
                    }
                }
                emit();
                schedule();
            };
            return {
                snapshot: snapshot,
                enqueue: function(request, capturedat, opts) {
                    if (disposed || auth) {
                        return false;
                    }
                    // An expendable item is a diagnostic about the queue itself (a coverage-banner
                    // log): it never makes room for itself by evicting evidence, and discarding one
                    // is not evidence loss - counting it would re-raise the very banner whose
                    // logging it was, evicting more captures each cycle (Codex review).
                    const expendable = !!(opts && opts.expendable);
                    expire();
                    const payload = {
                        methodname: request.methodname,
                        args: Object.assign({}, request.args, {
                            capturedat: capturedat || Math.floor(now() / 1000),
                            requestid: request.args.requestid || requestId()
                        })
                    };
                    // UTF-16 string size is a conservative bound for serialized base64/JSON evidence.
                    const bytes = JSON.stringify(payload).length * 2;
                    if (bytes > maxBytes) {
                        if (expendable) {
                            return false;
                        }
                        dropped++;
                        lastProblem = 'capacity';
                        lastDropAt = now();
                        emit();
                        return false;
                    }
                    while (items.length >= maxItems || snapshot().bytes + bytes > maxBytes) {
                        const sacrificial = items.find(item => item !== active && item.expendable);
                        if (!sacrificial && expendable) {
                            return false;
                        }
                        const oldest = sacrificial
                            || items.find(item => item !== active && lowValue(item))
                            || items.find(item => item !== active);
                        if (!oldest) {
                            dropped++;
                            lastProblem = 'capacity';
                            lastDropAt = now();
                            emit();
                            return false;
                        }
                        drop(oldest, 'capacity');
                    }
                    items.push({request: payload, bytes: bytes, created: now(), next: now(), failures: 0,
                        queuedOffline: !online(), expendable: expendable});
                    pump();
                    return true;
                },
                flush: function() {
                    if (timer !== null) {
                        cancel(timer);
                        timer = null;
                    }
                    if (!active && items.length) {
                        items[0].next = now();
                    }
                    pump();
                },
                dispose: function() {
                    disposed = true;
                    if (timer !== null) {
                        cancel(timer);
                    }
                    items.forEach(item => { item.request = null; });
                    items = [];
                    active = null;
                    timer = null;
                }
            };
        }
    };
});
