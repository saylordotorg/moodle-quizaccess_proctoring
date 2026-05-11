define([], function() {
    const statusStaleMs = 10000;
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

    const isFresh = function(status) {
        return status && status.ts && (Date.now() - status.ts) <= statusStaleMs;
    };

    const isReady = function(status) {
        return isFresh(status) && status.ready === true && status.marker === true && status.stopped !== true;
    };

    return {
        create: function(props, callbacks) {
            callbacks = callbacks || {};

            const monitorUrl = props.screenmonitorurl || '';
            const channelName = props.screenmonitorchannel || '';
            const statusKey = props.screenmonitorstatuskey || '';
            const windowName = props.screenmonitorwindowname || 'quizaccess_proctoring_screen_monitor';
            const popupFeatures = 'popup=yes,width=430,height=390,menubar=no,toolbar=no,location=no,status=no';
            let channel = null;
            let statusTimer = null;
            let screenshotTimer = null;
            let latestScreenshot = '';
            let lastWrongScreenLogged = 0;

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

            const requestStatus = function() {
                postMessage({
                    type: 'status_request',
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

                    const currentUrl = monitorWindow.location.href || '';
                    if (currentUrl === 'about:blank' ||
                            currentUrl.indexOf('/mod/quiz/accessrule/proctoring/screenmonitor.php') === -1) {
                        monitorWindow.location.href = monitorUrl;
                    }
                    monitorWindow.focus();
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
                            ts: Date.now()
                        });
                    }
                };
            }

            window.addEventListener('storage', function(event) {
                if (event.key === statusKey) {
                    handleStatus(parseStatus(event.newValue));
                }
            });

            return {
                start: function() {
                    readAndHandleStoredStatus();
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
                open: open,
                requestStatus: requestStatus,
                getLatestScreenshot: function() {
                    requestScreenshot();
                    return latestScreenshot;
                },
                isReady: function() {
                    return isReady(getStoredStatus(statusKey));
                }
            };
        }
    };
});
