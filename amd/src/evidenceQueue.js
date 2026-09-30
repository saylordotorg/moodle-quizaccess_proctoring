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
                    problem: lastProblem
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
                dropped++;
                lastProblem = reason;
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
                enqueue: function(request, capturedat) {
                    if (disposed || auth) {
                        return false;
                    }
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
                        dropped++;
                        lastProblem = 'capacity';
                        emit();
                        return false;
                    }
                    while (items.length >= maxItems || snapshot().bytes + bytes > maxBytes) {
                        const oldest = items.find(item => item !== active);
                        if (!oldest) {
                            dropped++;
                            lastProblem = 'capacity';
                            emit();
                            return false;
                        }
                        drop(oldest, 'capacity');
                    }
                    items.push({request: payload, bytes: bytes, created: now(), next: now(), failures: 0,
                        queuedOffline: !online()});
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
