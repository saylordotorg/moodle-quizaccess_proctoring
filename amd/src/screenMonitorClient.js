define([], function() {
    const statusStaleMs = 20000;
    // On page load the stored status may be whatever the throttled helper last wrote, possibly
    // close to a minute ago. Trust it only when it is this recent; otherwise wait for a live reply.
    const startupStatusMaxAgeMs = 5000;
    const statusPollMs = 2000;
    const screenshotPollMs = 5000;

    const parseStatus = function(value) {
        if (!value) {
            return null;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return null;
        }
    };

    const getStoredStatus = function(statusKey) {
        if (!statusKey) {
            return null;
        }

        try {
            return parseStatus(window.localStorage.getItem(statusKey));
        } catch (error) {
            return null;
        }
    };

    const isFresh = function(status, maxAgeMs = statusStaleMs) {
        return status && status.ts && (Date.now() - status.ts) <= maxAgeMs;
    };

    const isReady = function(status, maxAgeMs = statusStaleMs) {
        return isFresh(status, maxAgeMs) && status.ready === true && status.marker === true && status.stopped !== true;
    };

    const isMobileClient = function() {
        const userAgent = navigator.userAgent || '';
        const mobileAgent = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(userAgent);
        const mobileViewport = window.matchMedia &&
            window.matchMedia('(max-width: 768px), (pointer: coarse) and (max-width: 1024px)').matches;

        return mobileAgent || mobileViewport;
    };

    return {
        /**
         * Tell the helper window the exam is over, so it stops sharing and closes itself.
         *
         * Called on a finished attempt's pages (summary, review), where monitoring never starts:
         * without it the helper keeps the student's screen shared until they find the browser's
         * own stop control (CPIT-508). Fire-and-forget: with no helper listening, nobody hears it.
         *
         * @param {String} key The monitor key the attempt pages used (cm<cmid>user<userid>).
         * @param {Number} attemptid The finished attempt. The helper only honours the shutdown
         *        while it still serves this attempt: a preflight for the next attempt claims the
         *        helper first (as attempt 0), so an old review page cannot end the new share.
         */
        shutdown: function(key, attemptid) {
            if (!key || !window.BroadcastChannel) {
                return;
            }
            try {
                const channel = new BroadcastChannel('quizaccess_proctoring_screen_' + key);
                channel.postMessage({
                    type: 'shutdown',
                    attemptid: Number(attemptid) || 0,
                    ts: Date.now()
                });
                // Leave the channel open briefly: closing in the same tick can drop the message.
                window.setTimeout(function() {
                    channel.close();
                }, 1000);
            } catch (error) {
                // The helper is optional; a shutdown failure must not interrupt the review page.
            }
        },
        /**
         * Claim the helper for this attempt without monitoring it.
         *
         * Used when desktop capture is off, so no full client runs on the attempt pages, but the
         * preflight may still have opened the helper for the share requirement: without a claim,
         * a stale preflight page left open behind a quiz popup holds the helper as attempt 0
         * forever and blocks the finished attempt's shutdown (CPIT-508, Codex review).
         *
         * @param {Object} props Setup properties (screenmonitorkey and the attempt id).
         * @return {Number|null} The interval id keeping the claim fresh, or null when not started.
         */
        claim: function(props) {
            const monitorKey = (props && props.screenmonitorkey) || '';
            const attemptid = Number(props && (props.screenmonitorattempt || props.status || props.attemptid)) || 0;
            if (!monitorKey || !attemptid || !window.BroadcastChannel) {
                return null;
            }
            try {
                const channel = new BroadcastChannel('quizaccess_proctoring_screen_' + monitorKey);
                const send = function() {
                    channel.postMessage({type: 'status_request', attemptid: attemptid, ts: Date.now()});
                };
                send();
                return window.setInterval(send, 10000);
            } catch (error) {
                return null;
            }
        },
        create: function(props, callbacks) {
            callbacks = callbacks || {};

            const monitorUrl = props.screenmonitorurl || '';
            // The attempt page sends only the short key and the names are built from it here, to keep
            // its JavaScript arguments within Moodle's size limit; the precheck still sends them whole.
            const monitorKey = props.screenmonitorkey || '';
            const channelName = props.screenmonitorchannel ||
                (monitorKey ? 'quizaccess_proctoring_screen_' + monitorKey : '');
            const statusKey = props.screenmonitorstatuskey ||
                (monitorKey ? 'quizaccess_proctoring_screen_status_' + monitorKey : '');
            const windowName = props.screenmonitorwindowname || channelName || 'quizaccess_proctoring_screen_monitor';
            const popupFeatures = 'popup=yes,width=430,height=390,menubar=no,toolbar=no,location=no,status=no';
            let channel = null;
            let statusTimer = null;
            let screenshotTimer = null;
            let latestScreenshot = '';
            let lastWrongScreenLogged = 0;
            let running = false;
            const mobileClient = isMobileClient();

            const postMessage = function(message) {
                if (!channel) {
                    return;
                }

                try {
                    channel.postMessage(message);
                } catch (error) {
                    // The screen monitor is optional; a post failure should not interrupt the quiz page.
                }
            };

            // Every status poll claims the helper for this page's attempt (a preflight with no
            // attempt row yet claims 0; one resuming an existing attempt, such as an overdue
            // attempt in its grace period, claims that attempt), so a finished attempt's shutdown
            // can tell whether the helper still serves it or the next attempt (CPIT-508).
            const claimattempt = Number(props.screenmonitorattempt || props.status || props.attemptid) || 0;

            const requestStatus = function() {
                postMessage({
                    type: 'status_request',
                    attemptid: claimattempt,
                    ts: Date.now()
                });
            };

            const requestScreenshot = function() {
                postMessage({
                    type: 'screenshot_request',
                    ts: Date.now()
                });
            };

            const handleStatus = function(status) {
                if (isReady(status)) {
                    if (callbacks.onReady) {
                        callbacks.onReady(status);
                    }
                    return;
                }

                if (isFresh(status) && status.ready === true && status.marker === false) {
                    const now = Date.now();
                    if (callbacks.onWrongScreen && now - lastWrongScreenLogged > statusStaleMs) {
                        lastWrongScreenLogged = now;
                        callbacks.onWrongScreen(status);
                    }
                    return;
                }

                if (callbacks.onUnavailable) {
                    callbacks.onUnavailable(status);
                }
            };

            const readAndHandleStoredStatus = function() {
                handleStatus(getStoredStatus(statusKey));
            };

            const open = function() {
                if (mobileClient) {
                    if (callbacks.onUnavailable) {
                        callbacks.onUnavailable();
                    }
                    return null;
                }

                if (!monitorUrl) {
                    if (callbacks.onOpenBlocked) {
                        callbacks.onOpenBlocked();
                    }
                    return null;
                }

                let monitorWindow = null;
                try {
                    monitorWindow = window.open('', windowName, popupFeatures);
                    if (!monitorWindow) {
                        if (callbacks.onOpenBlocked) {
                            callbacks.onOpenBlocked();
                        }
                        return null;
                    }

                    // window.open() with a name returns the existing helper when one is already
                    // running, so this is both "create" and "re-attach". Only a window we actually
                    // had to point at the monitor URL is new.
                    const currentUrl = monitorWindow.location.href || '';
                    const isNewWindow = currentUrl === 'about:blank' ||
                        currentUrl.indexOf('/mod/quiz/accessrule/proctoring/screenmonitor.php') === -1;
                    if (isNewWindow) {
                        monitorWindow.location.href = monitorUrl;
                        // Focus only on creation, so the student sees the window they were just
                        // asked to allow. Focusing an already-running helper pulls it in front of
                        // the exam for no reason - and because this runs whenever the share gate is
                        // answered, that reads as the quiz losing focus over and over. The helper
                        // does not need focus to keep working: it answers status requests through
                        // message handlers, which browsers do not throttle in background windows.
                        monitorWindow.focus();
                    }
                } catch (error) {
                    monitorWindow = window.open(monitorUrl, windowName, popupFeatures);
                    if (!monitorWindow) {
                        if (callbacks.onOpenBlocked) {
                            callbacks.onOpenBlocked();
                        }
                        return null;
                    }
                }

                if (callbacks.onOpened) {
                    callbacks.onOpened();
                }
                window.setTimeout(requestStatus, 1000);

                return monitorWindow;
            };

            if (channelName && window.BroadcastChannel) {
                channel = new BroadcastChannel(channelName);
                channel.onmessage = function(event) {
                    if (!running) {
                        return;
                    }
                    const message = event.data || {};
                    if (message.type === 'status') {
                        handleStatus(message);
                    } else if (message.type === 'screenshot' && message.image) {
                        latestScreenshot = message.image;
                        if (callbacks.onScreenshot) {
                            callbacks.onScreenshot(message);
                        }
                    } else if (message.type === 'marker_missing') {
                        handleStatus({
                            ready: true,
                            marker: false,
                            stopped: false,
                            // What the helper's scan saw, for the page's diagnostics (CPIT-498).
                            scan: message.scan || null,
                            ts: Date.now()
                        });
                    }
                };
            }

            window.addEventListener('storage', function(event) {
                if (running && event.key === statusKey) {
                    handleStatus(parseStatus(event.newValue));
                }
            });

            return {
                start: function() {
                    running = true;
                    if (mobileClient) {
                        return;
                    }

                    const storedStatus = getStoredStatus(statusKey);
                    if (isFresh(storedStatus, startupStatusMaxAgeMs)) {
                        handleStatus(storedStatus);
                    }
                    requestStatus();

                    if (statusTimer) {
                        window.clearInterval(statusTimer);
                    }
                    statusTimer = window.setInterval(function() {
                        readAndHandleStoredStatus();
                        requestStatus();
                    }, statusPollMs);

                    if (screenshotTimer) {
                        window.clearInterval(screenshotTimer);
                    }
                    screenshotTimer = window.setInterval(requestScreenshot, screenshotPollMs);
                },
                stop: function() {
                    running = false;
                    window.clearInterval(statusTimer);
                    window.clearInterval(screenshotTimer);
                    statusTimer = null;
                    screenshotTimer = null;
                    latestScreenshot = '';
                },
                open: open,
                requestStatus: requestStatus,
                getLatestScreenshot: function() {
                    requestScreenshot();
                    return latestScreenshot;
                },
                isReady: function() {
                    return isReady(getStoredStatus(statusKey), startupStatusMaxAgeMs);
                }
            };
        }
    };
});
