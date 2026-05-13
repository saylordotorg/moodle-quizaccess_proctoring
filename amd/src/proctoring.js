// @SuppressWarnings("javascript:S4144");
let isCameraAllowed = false;

define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'quizaccess_proctoring/screenMonitorClient'],
    function($, Ajax, Notification, Str, ScreenMonitorClient) {
        const loadStrings = async function() {
            const stringkeys = [
                {key: 'facenotfoundoncam', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_image', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_screenshot', component: 'quizaccess_proctoring'},
                {key: 'enable_web_camera_before_submitting', component: 'quizaccess_proctoring'},
                {key: 'webcam', component: 'quizaccess_proctoring'},
                {key: 'videonotavailable', component: 'quizaccess_proctoring'},
                {key: 'desktopcaptureprompt', component: 'quizaccess_proctoring'},
                {key: 'desktopcapturetitle', component: 'quizaccess_proctoring'},
                {key: 'entirescreenrequired', component: 'quizaccess_proctoring'},
                {key: 'modal:shareentirescreen', component: 'quizaccess_proctoring'},
                {key: 'screenshareaccepted', component: 'quizaccess_proctoring'},
                {key: 'screensharedenied', component: 'quizaccess_proctoring'},
                {key: 'screensharenotsupported', component: 'quizaccess_proctoring'},
                {key: 'screensharestopped', component: 'quizaccess_proctoring'},
                {key: 'screenmarkerlabel', component: 'quizaccess_proctoring'},
                {key: 'screenmarkerwrongmonitor', component: 'quizaccess_proctoring'},
                {key: 'screenmonitor:windowopened', component: 'quizaccess_proctoring'},
                {key: 'screenmonitor:popupblocked', component: 'quizaccess_proctoring'},
                {key: 'faceblurmessage', component: 'quizaccess_proctoring'},
            ];
            try {
                const strings = await Str.get_strings(stringkeys);
                return {
                    facenotfoundoncam: strings[0],
                    wrongduringtakingimage: strings[1],
                    wrongduringtakingscreenshot: strings[2],
                    enablewebcamerabeforesubmitting: strings[3],
                    webcam: strings[4],
                    videonotavailable: strings[5],
                    desktopcaptureprompt: strings[6],
                    desktopcapturetitle: strings[7],
                    entirescreenrequired: strings[8],
                    shareentirescreen: strings[9],
                    screenshareaccepted: strings[10],
                    screensharedenied: strings[11],
                    screensharenotsupported: strings[12],
                    screensharestopped: strings[13],
                    screenmarkerlabel: strings[14],
                    screenmarkerwrongmonitor: strings[15],
                    screenmonitorwindowopened: strings[16],
                    screenmonitorpopupblocked: strings[17],
                    faceblurmessage: strings[18],
                };
            } catch (error) {
                Notification.exception(error);
                return {}; // Return an empty object in case of an error.
            }
        };

        $('#id_submitbutton').prop("disabled", true);
        $(function() {
            $('#id_submitbutton').prop("disabled", true);
            $('#id_proctoring').on('change', function() {
                if (this.checked && isCameraAllowed) {
                    $('#id_submitbutton').prop("disabled", false);
                } else {
                    $('#id_submitbutton').prop("disabled", true);
                }
            });
        });

        /**
         * Function hideButtons
         */
        async function hideButtons() {
            const strings = await loadStrings();
            $('.mod_quiz-next-nav').prop("disabled", true);
            $('.submitbtns').html(`<p class="text text-red red">${strings.enablewebcamerabeforesubmitting}</p>`);
        }

        const showNotification = (message, type) => {
            removeNotifications();
            Notification.addNotification({
                message,
                type
            });
        };

        const removeNotifications = () => {
            try {
                const alertElements = document.getElementsByClassName('alert');
                if (alertElements.length > 0) {
                    Array.from(alertElements).forEach(alertDiv => {
                        alertDiv.style.display = 'none';
                    });
                }
            } catch (error) {
                Notification.exception(error);
            }
        };

        let firstcalldelay = 3000; // 3 seconds after the page load.
        let takepicturedelay = 30000; // 30 seconds.

        // Function to draw image from the box data.
        const extractFaceFromBox = async(imageRef, box, croppedImage) => {
            const regionsToExtract = [
                // eslint-disable-next-line no-undef
                new faceapi.Rect(box.x, box.y, box.width, box.height)
            ];
            // eslint-disable-next-line no-undef
            let faceImages = await faceapi.extractFaces(imageRef, regionsToExtract);

            if (faceImages.length !== 0) {
                faceImages.forEach((cnv) => {
                    croppedImage.src = cnv.toDataURL();
                });
            }
        };

        const detectface = async(input, croppedImage) => {
            // eslint-disable-next-line no-undef
            const output = await faceapi.detectAllFaces(input);
            if (output.length !== 0) {
                let detections = output[0].box;
                await extractFaceFromBox(input, detections, croppedImage);
            }
        };

        const getDesktopPanelSlot = function(slot) {
            if (!window.matchMedia || !window.matchMedia('(min-width: 992px)').matches) {
                return null;
            }

            const navBlock = document.getElementById('mod_quiz_navblock');
            if (!navBlock || !navBlock.parentNode) {
                return null;
            }

            let panel = document.getElementById('proctoring-desktop-status-panel');
            if (!panel) {
                panel = document.createElement('section');
                panel.id = 'proctoring-desktop-status-panel';
                panel.className = 'proctoring-desktop-status-panel';
                panel.setAttribute('aria-label', 'Saylor proctoring status');
                panel.innerHTML =
                    '<div class="proctoring-desktop-status-panel-inner">' +
                        '<div class="proctoring-desktop-status-slot proctoring-desktop-screen-slot"></div>' +
                        '<div class="proctoring-desktop-status-slot proctoring-desktop-webcam-slot"></div>' +
                    '</div>';
                navBlock.parentNode.insertBefore(panel, navBlock.nextSibling);
            } else if (panel.previousElementSibling !== navBlock) {
                navBlock.parentNode.insertBefore(panel, navBlock.nextSibling);
            }

            return panel.querySelector('.proctoring-desktop-' + slot + '-slot');
        };

        const initSuspiciousActivityMonitoring = function(props, strings) {
            let lastLogged = {};
            let hiddenStarted = 0;
            const throttleMs = 5000;
            const aiPattern = /(gemini|chatgpt|openai|copilot|claude|perplexity|bard|ask\s+gemini|ask\s+ai)/i;
            const monitorActivity = parseInt(props.monitorbrowseractivity, 10) === 1;
            const blockClipboard = parseInt(props.blockclipboard, 10) === 1;
            const captureDesktop = parseInt(props.captureviolationdesktop, 10) === 1;
            const clipboardEvents = [
                'clipboard_copy',
                'clipboard_cut',
                'clipboard_paste'
            ];
            const clipboardShortcutEvents = {
                c: 'clipboard_copy',
                x: 'clipboard_cut',
                v: 'clipboard_paste'
            };
            const screenShareEvents = [
                'screen_marker_missing',
                'screen_share_stopped'
            ];
            const desktopCaptureEvents = [
                'tab_hidden',
                'focus_lost',
                'clipboard_copy',
                'clipboard_cut',
                'clipboard_paste',
                'contextmenu',
                'shortcut',
                'possible_ai_tool',
                'page_exit',
                'screen_marker_missing'
            ];
            let screenStream = null;
            let screenVideo = null;
            let screenCanvas = null;
            let screenReady = false;
            let markerCheckTimer = null;
            let screenGateTimer = null;
            let screenMonitorClient = null;
            let latestDesktopFrame = '';
            const markerToken = Math.random().toString(36).slice(2, 8).toUpperCase();

            const positionScreenMarker = function(markerElement) {
                if (!markerElement) {
                    return;
                }

                const fallback = function() {
                    markerElement.classList.remove('is-panel-aligned');
                    markerElement.style.top = '8px';
                    markerElement.style.right = '8px';
                    markerElement.style.left = 'auto';
                    markerElement.style.width = '220px';
                };

                if (!window.matchMedia || !window.matchMedia('(min-width: 992px)').matches) {
                    fallback();
                    return;
                }

                const navBlock = document.getElementById('mod_quiz_navblock');
                if (!navBlock) {
                    fallback();
                    return;
                }

                const rect = navBlock.getBoundingClientRect();
                const navStyle = window.getComputedStyle ? window.getComputedStyle(navBlock) : null;
                const navHidden = navStyle && (navStyle.display === 'none' || navStyle.visibility === 'hidden');
                if (navHidden || rect.width < 80 || rect.height < 40) {
                    fallback();
                    return;
                }

                const markerWidth = Math.min(220, Math.max(180, rect.width));
                const markerHeight = markerElement.offsetHeight || 96;
                const top = Math.min(
                    Math.max(8, rect.bottom + 12),
                    Math.max(8, window.innerHeight - markerHeight - 16)
                );
                const left = Math.min(
                    Math.max(8, rect.left),
                    Math.max(8, window.innerWidth - markerWidth - 8)
                );

                markerElement.classList.add('is-panel-aligned');
                markerElement.style.top = top + 'px';
                markerElement.style.left = left + 'px';
                markerElement.style.right = 'auto';
                markerElement.style.width = markerWidth + 'px';
            };

            const bindScreenMarkerPositioning = function(markerElement) {
                if (!markerElement || markerElement.dataset.proctoringPositionBound === '1') {
                    return;
                }

                markerElement.dataset.proctoringPositionBound = '1';
                positionScreenMarker(markerElement);

                window.addEventListener('resize', function() {
                    positionScreenMarker(markerElement);
                });
                window.addEventListener('scroll', function() {
                    positionScreenMarker(markerElement);
                }, true);
                window.setInterval(function() {
                    positionScreenMarker(markerElement);
                }, 1500);
            };

            const initScreenMarker = function() {
                if (!captureDesktop || document.getElementById('proctoring-screen-verification-marker')) {
                    return;
                }

                const marker = $(
                    '<div id="proctoring-screen-verification-marker" ' +
                        'class="proctoring-screen-verification-marker" aria-hidden="true">' +
                        `<div class="proctoring-screen-marker-label">${strings.screenmarkerlabel}</div>` +
                        '<div class="proctoring-screen-marker-colors">' +
                            '<span class="proctoring-screen-marker-swatch proctoring-screen-marker-magenta"></span>' +
                            '<span class="proctoring-screen-marker-swatch proctoring-screen-marker-cyan"></span>' +
                            '<span class="proctoring-screen-marker-swatch proctoring-screen-marker-yellow"></span>' +
                        '</div>' +
                        `<div class="proctoring-screen-marker-token">${markerToken}</div>` +
                        '</div>'
                );

                // Keep the verification marker outside Moodle's collapsible quiz navigation.
                // Otherwise page navigation can hide the marker and falsely fail the screen check.
                $('body').append(marker);
                bindScreenMarkerPositioning(marker[0]);
            };

            const captureDesktopFrame = function(eventType) {
                if (!captureDesktop || !desktopCaptureEvents.includes(eventType) || !screenReady) {
                    return '';
                }

                if (screenMonitorClient) {
                    return screenMonitorClient.getLatestScreenshot() || latestDesktopFrame;
                }

                if (!screenVideo) {
                    return '';
                }

                const sourceWidth = screenVideo.videoWidth || 0;
                const sourceHeight = screenVideo.videoHeight || 0;
                if (!sourceWidth || !sourceHeight) {
                    return '';
                }

                if (!screenCanvas) {
                    screenCanvas = document.createElement('canvas');
                }

                const targetWidth = Math.min(1280, sourceWidth);
                const targetHeight = Math.round(sourceHeight * (targetWidth / sourceWidth));
                screenCanvas.width = targetWidth;
                screenCanvas.height = targetHeight;
                screenCanvas.getContext('2d').drawImage(screenVideo, 0, 0, targetWidth, targetHeight);

                return screenCanvas.toDataURL('image/jpeg', 0.75);
            };

            const drawScreenFrame = function() {
                const sourceWidth = screenVideo ? screenVideo.videoWidth || 0 : 0;
                const sourceHeight = screenVideo ? screenVideo.videoHeight || 0 : 0;
                if (!sourceWidth || !sourceHeight) {
                    return null;
                }

                if (!screenCanvas) {
                    screenCanvas = document.createElement('canvas');
                }

                const targetWidth = Math.min(1280, sourceWidth);
                const targetHeight = Math.round(sourceHeight * (targetWidth / sourceWidth));
                screenCanvas.width = targetWidth;
                screenCanvas.height = targetHeight;
                const context = screenCanvas.getContext('2d');
                context.drawImage(screenVideo, 0, 0, targetWidth, targetHeight);

                return context.getImageData(0, 0, targetWidth, targetHeight);
            };

            const tileKey = function(x, y) {
                return x + ':' + y;
            };

            const countMarkerTiles = function(imageData, tileSize) {
                const data = imageData.data;
                const width = imageData.width;
                const height = imageData.height;
                const tiles = {};

                for (let y = 0; y < height; y += 2) {
                    for (let x = 0; x < width; x += 2) {
                        const offset = ((y * width) + x) * 4;
                        const red = data[offset];
                        const green = data[offset + 1];
                        const blue = data[offset + 2];
                        let color = '';

                        if (red > 210 && green < 90 && blue > 150) {
                            color = 'magenta';
                        } else if (red < 90 && green > 180 && blue > 150) {
                            color = 'cyan';
                        } else if (red > 210 && green > 180 && blue < 90) {
                            color = 'yellow';
                        }

                        if (!color) {
                            continue;
                        }

                        const key = tileKey(Math.floor(x / tileSize), Math.floor(y / tileSize));
                        tiles[key] = tiles[key] || {magenta: 0, cyan: 0, yellow: 0};
                        tiles[key][color]++;
                    }
                }

                return tiles;
            };

            const sharedScreenContainsMarker = function() {
                if (!captureDesktop || !screenVideo) {
                    return true;
                }

                const imageData = drawScreenFrame();
                if (!imageData) {
                    return false;
                }

                const tileSize = Math.max(8, Math.floor(imageData.width / 80));
                const tiles = countMarkerTiles(imageData, tileSize);
                const maxTileX = Math.ceil(imageData.width / tileSize);
                const maxTileY = Math.ceil(imageData.height / tileSize);

                for (let tileY = 0; tileY < maxTileY; tileY++) {
                    for (let tileX = 0; tileX < maxTileX; tileX++) {
                        const totals = {magenta: 0, cyan: 0, yellow: 0};

                        for (let yOffset = 0; yOffset < 4; yOffset++) {
                            for (let xOffset = 0; xOffset < 6; xOffset++) {
                                const tile = tiles[tileKey(tileX + xOffset, tileY + yOffset)];
                                if (!tile) {
                                    continue;
                                }
                                totals.magenta += tile.magenta;
                                totals.cyan += tile.cyan;
                                totals.yellow += tile.yellow;
                            }
                        }

                        if (totals.magenta >= 18 && totals.cyan >= 18 && totals.yellow >= 18) {
                            return true;
                        }
                    }
                }

                return false;
            };

            const waitForScreenFrame = async function() {
                for (let attempts = 0; attempts < 20; attempts++) {
                    if (screenVideo && screenVideo.videoWidth && screenVideo.videoHeight) {
                        return true;
                    }
                    await new Promise((resolve) => window.setTimeout(resolve, 100));
                }

                return false;
            };

            const setScreenShareStatus = function(message, type) {
                const status = document.getElementById('proctoring-screen-share-status');
                if (status) {
                    status.className = `proctoring-screen-share-status text-${type}`;
                    status.textContent = message;
                }
            };

            const showScreenShareGate = function() {
                if (screenGateTimer) {
                    window.clearTimeout(screenGateTimer);
                    screenGateTimer = null;
                }
                const gate = document.getElementById('proctoring-screen-share-gate');
                if (gate) {
                    gate.style.display = 'flex';
                }
            };

            const hideScreenShareGate = function() {
                if (screenGateTimer) {
                    window.clearTimeout(screenGateTimer);
                    screenGateTimer = null;
                }
                const gate = document.getElementById('proctoring-screen-share-gate');
                if (gate) {
                    gate.style.display = 'none';
                }
            };

            const scheduleScreenShareGate = function(delay) {
                if (screenGateTimer) {
                    window.clearTimeout(screenGateTimer);
                }
                screenGateTimer = window.setTimeout(function() {
                    screenGateTimer = null;
                    if (!screenReady) {
                        showScreenShareGate();
                    }
                }, delay || 2500);
            };

            const stopScreenStream = function() {
                if (markerCheckTimer) {
                    window.clearInterval(markerCheckTimer);
                    markerCheckTimer = null;
                }
                if (screenStream) {
                    screenStream.getTracks().forEach((track) => track.stop());
                    screenStream = null;
                }
                screenReady = false;
            };

            const requireCorrectSharedScreen = function(reason) {
                logEvent('screen_marker_missing', {
                    reason: reason,
                    note: 'The shared monitor did not contain the visible Moodle quiz screen marker.'
                });
                stopScreenStream();
                setScreenShareStatus(strings.screenmarkerwrongmonitor, 'danger');
                showScreenShareGate();
            };

            const startMarkerChecks = function() {
                if (!captureDesktop) {
                    return;
                }

                if (markerCheckTimer) {
                    window.clearInterval(markerCheckTimer);
                }

                markerCheckTimer = window.setInterval(function() {
                    if (screenReady && !sharedScreenContainsMarker()) {
                        requireCorrectSharedScreen('periodic_marker_check_failed');
                    }
                }, 15000);
            };

            const requestScreenShare = async function(event) {
                if (event) {
                    event.preventDefault();
                }

                if (screenMonitorClient) {
                    screenMonitorClient.open();
                    setScreenShareStatus(strings.screenmonitorwindowopened, 'info');
                    return;
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                    setScreenShareStatus(strings.screensharenotsupported, 'danger');
                    return;
                }

                stopScreenStream();

                try {
                    screenStream = await navigator.mediaDevices.getDisplayMedia({
                        video: {
                            displaySurface: 'monitor'
                        },
                        audio: false
                    });
                } catch (error) {
                    setScreenShareStatus(strings.screensharedenied, 'danger');
                    return;
                }

                const videoTrack = screenStream.getVideoTracks()[0];
                const settings = videoTrack && videoTrack.getSettings ? videoTrack.getSettings() : {};
                if (!videoTrack || settings.displaySurface !== 'monitor') {
                    stopScreenStream();
                    setScreenShareStatus(strings.entirescreenrequired, 'danger');
                    return;
                }

                if (!screenVideo) {
                    screenVideo = document.createElement('video');
                    screenVideo.muted = true;
                    screenVideo.playsInline = true;
                }
                screenVideo.srcObject = screenStream;
                try {
                    await screenVideo.play();
                } catch (error) {
                    stopScreenStream();
                    setScreenShareStatus(strings.screensharedenied, 'danger');
                    return;
                }

                if (!await waitForScreenFrame() || !sharedScreenContainsMarker()) {
                    requireCorrectSharedScreen('initial_marker_check_failed');
                    return;
                }

                screenReady = true;

                videoTrack.addEventListener('ended', function() {
                    screenReady = false;
                    setScreenShareStatus(strings.screensharestopped, 'danger');
                    showScreenShareGate();
                    logEvent('screen_share_stopped', {
                        reason: 'screen_share_ended'
                    });
                });

                setScreenShareStatus(strings.screenshareaccepted, 'success');
                hideScreenShareGate();
                startMarkerChecks();
            };

            const initScreenShareGate = function() {
                if (!captureDesktop || document.getElementById('proctoring-screen-share-gate')) {
                    return;
                }

                initScreenMarker();

                $('body').append(
                    '<div id="proctoring-screen-share-gate" class="proctoring-screen-share-gate"' +
                        (props.screenmonitorurl ? ' style="display:none;"' : '') + '>' +
                        '<div class="proctoring-screen-share-panel">' +
                            `<h3>${strings.desktopcapturetitle}</h3>` +
                            `<p>${strings.desktopcaptureprompt}</p>` +
                            '<div id="proctoring-screen-share-status" class="proctoring-screen-share-status"></div>' +
                            '<button id="proctoring-screen-share-button" class="btn btn-primary">' +
                                strings.shareentirescreen +
                            '</button>' +
                        '</div>' +
                    '</div>'
                );

                $('#proctoring-screen-share-button').on('click', requestScreenShare);

                if (props.screenmonitorurl && !screenMonitorClient) {
                    screenMonitorClient = ScreenMonitorClient.create(props, {
                        onReady: function() {
                            screenReady = true;
                            setScreenShareStatus(strings.screenshareaccepted, 'success');
                            hideScreenShareGate();
                        },
                        onUnavailable: function() {
                            if (screenReady) {
                                screenReady = false;
                                logEvent('screen_share_stopped', {
                                    reason: 'persistent_monitor_unavailable'
                                });
                                setScreenShareStatus(strings.screensharestopped, 'danger');
                                showScreenShareGate();
                            } else {
                                scheduleScreenShareGate(2500);
                            }
                        },
                        onWrongScreen: function() {
                            screenReady = false;
                            logEvent('screen_marker_missing', {
                                reason: 'persistent_monitor_marker_missing',
                                note: 'The persistent screen monitor did not see the Moodle quiz screen marker.'
                            });
                            setScreenShareStatus(strings.screenmarkerwrongmonitor, 'danger');
                            showScreenShareGate();
                        },
                        onScreenshot: function(message) {
                            latestDesktopFrame = message.image || '';
                        },
                        onOpenBlocked: function() {
                            setScreenShareStatus(strings.screenmonitorpopupblocked, 'danger');
                        },
                        onOpened: function() {
                            setScreenShareStatus(strings.screenmonitorwindowopened, 'info');
                        }
                    });
                    screenMonitorClient.start();
                    if (screenMonitorClient.isReady()) {
                        screenReady = true;
                        hideScreenShareGate();
                    } else {
                        scheduleScreenShareGate(3000);
                    }
                }
            };

            const getSelectedTextLength = function() {
                try {
                    const selection = window.getSelection();
                    return selection ? selection.toString().length : 0;
                } catch (error) {
                    return 0;
                }
            };

            const getShortcutName = function(event) {
                const parts = [];
                if (event.ctrlKey) {
                    parts.push('Ctrl');
                }
                if (event.metaKey) {
                    parts.push('Meta');
                }
                if (event.altKey) {
                    parts.push('Alt');
                }
                if (event.shiftKey) {
                    parts.push('Shift');
                }
                parts.push((event.key || '').toUpperCase());
                return parts.join('+');
            };

            const blockClipboardAction = function(event) {
                event.preventDefault();
                event.stopPropagation();
                if (event.stopImmediatePropagation) {
                    event.stopImmediatePropagation();
                }
            };

            const logEvent = function(eventType, detail) {
                if (!monitorActivity && !(blockClipboard && clipboardEvents.includes(eventType)) &&
                        !(captureDesktop && screenShareEvents.includes(eventType))) {
                    return;
                }

                const now = Date.now();
                const detailText = JSON.stringify(detail || {});
                const throttleKey = eventType + ':' + detailText.substring(0, 120);

                if (lastLogged[throttleKey] && now - lastLogged[throttleKey] < throttleMs) {
                    return;
                }
                lastLogged[throttleKey] = now;

                const args = {
                    courseid: parseInt(props.courseid, 10) || 0,
                    quizid: parseInt(props.quizid, 10) || 0,
                    attemptid: parseInt(props.status, 10) || 0,
                    reportid: parseInt(props.id, 10) || 0,
                    eventtype: eventType,
                    eventdetail: detailText,
                    pagevisibility: document.visibilityState || '',
                    currenturl: window.location.href,
                    screenshot: captureDesktopFrame(eventType)
                };

                Ajax.call([{
                    methodname: 'quizaccess_proctoring_log_event',
                    args: args
                }])[0].fail(function() {
                    // Do not interrupt the quiz attempt if activity logging is unavailable.
                });
            };

            initScreenShareGate();

            if (monitorActivity) {
                document.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') {
                        hiddenStarted = Date.now();
                        logEvent('tab_hidden', {
                            reason: 'document_hidden',
                            note: 'Quiz tab was hidden. This can indicate tab switching or opening another browser surface.'
                        });
                    } else if (document.visibilityState === 'visible') {
                        logEvent('tab_visible', {
                            reason: 'document_visible',
                            hiddenms: hiddenStarted ? Date.now() - hiddenStarted : 0
                        });
                        hiddenStarted = 0;
                    }
                }, true);

                window.addEventListener('blur', function() {
                    logEvent('focus_lost', {
                        reason: 'window_blur',
                        note: 'Browser focus left the quiz. This can include another tab, another window, or a browser AI panel.'
                    });
                }, true);

                window.addEventListener('focus', function() {
                    logEvent('focus_returned', {
                        reason: 'window_focus'
                    });
                }, true);
            }

            document.addEventListener('copy', function(event) {
                if (blockClipboard) {
                    blockClipboardAction(event);
                }
                logEvent('clipboard_copy', {
                    selectionlength: getSelectedTextLength(),
                    blocked: blockClipboard
                });
            }, true);

            document.addEventListener('cut', function(event) {
                if (blockClipboard) {
                    blockClipboardAction(event);
                }
                logEvent('clipboard_cut', {
                    selectionlength: getSelectedTextLength(),
                    blocked: blockClipboard
                });
            }, true);

            document.addEventListener('paste', function(event) {
                if (blockClipboard) {
                    blockClipboardAction(event);
                }
                let pastedLength = 0;
                try {
                    const clipboard = event.clipboardData || window.clipboardData;
                    pastedLength = clipboard ? clipboard.getData('text').length : 0;
                } catch (error) {
                    pastedLength = 0;
                }

                logEvent('clipboard_paste', {
                    pastedlength: pastedLength,
                    blocked: blockClipboard
                });
            }, true);

            document.addEventListener('beforeinput', function(event) {
                if (blockClipboard && event.inputType === 'insertFromPaste') {
                    blockClipboardAction(event);
                    logEvent('clipboard_paste', {
                        source: 'beforeinput',
                        blocked: true
                    });
                }
            }, true);

            if (monitorActivity || blockClipboard) {
                document.addEventListener('contextmenu', function(event) {
                    if (blockClipboard) {
                        blockClipboardAction(event);
                    }
                    logEvent('contextmenu', {
                        reason: 'right_click',
                        blocked: blockClipboard
                    });
                }, true);

                document.addEventListener('keydown', function(event) {
                    const key = (event.key || '').toLowerCase();
                    const shortcut = getShortcutName(event);
                    const ctrlOrMeta = event.ctrlKey || event.metaKey;
                    const clipboardEventType = ctrlOrMeta ? clipboardShortcutEvents[key] || '' : '';

                    if (blockClipboard && clipboardEventType) {
                        blockClipboardAction(event);
                        logEvent(clipboardEventType, {
                            source: 'keyboard_shortcut',
                            shortcut: shortcut,
                            blocked: true
                        });
                    }

                    const monitored = event.key === 'F12' ||
                        (event.altKey && key === 'tab') ||
                        (ctrlOrMeta && ['c', 'x', 'v', 'a', 'l', 't', 'n', 'w', 'r'].includes(key)) ||
                        (ctrlOrMeta && event.shiftKey && ['i', 'j', 'c'].includes(key));

                    if (monitored) {
                        logEvent('shortcut', {
                            shortcut: shortcut
                        });
                    }
                }, true);

                document.addEventListener('click', function(event) {
                    const target = event.target && event.target.closest
                        ? event.target.closest('a, button, [role="button"], [aria-label], [title]')
                        : null;

                    if (!target) {
                        return;
                    }

                    const label = [
                        target.innerText || '',
                        target.getAttribute('aria-label') || '',
                        target.getAttribute('title') || '',
                        target.getAttribute('href') || ''
                    ].join(' ').trim();

                    if (aiPattern.test(label)) {
                        logEvent('possible_ai_tool', {
                            label: label.substring(0, 200)
                        });
                    }
                }, true);

                window.addEventListener('pagehide', function() {
                    logEvent('page_exit', {
                        reason: 'pagehide'
                    });
                }, true);
            }
        };

        return {
            async setup(props, modelurl) {
                const strings = await loadStrings();
                let faceModelReady = false;
                if (modelurl !== null) {
                    try {
                        // eslint-disable-next-line no-undef
                        await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
                        faceModelReady = true;
                    } catch (error) {
                        Notification.exception(error);
                    }
                }
                takepicturedelay = props.camshotdelay;
                // Skip for summary page.
                if (document.getElementById("page-mod-quiz-summary") !== null &&
                    document.getElementById("page-mod-quiz-summary").innerHTML.length) {
                    return false;
                }
                if (document.getElementById("page-mod-quiz-review") !== null &&
                    document.getElementById("page-mod-quiz-review").innerHTML.length) {
                    return false;
                }

                if (parseInt(props.monitorbrowseractivity, 10) === 1 ||
                        parseInt(props.blockclipboard, 10) === 1 ||
                        parseInt(props.captureviolationdesktop, 10) === 1) {
                    initSuspiciousActivityMonitoring(props, strings);
                }

                const width = props.image_width;
                let height = 0; // This will be computed based on the input stream.
                let streaming = false;
                let data = null;

                const webcamBox = $(`<div class="proctoring-fixed-webcam-box d-flex">`
                    + `<video id="video">${strings.videonotavailable}</video>`
                    + '<img id="cropimg" src="" alt=""/><canvas id="canvas" style="display:none;"></canvas>'
                    + '<div class="output" style="display:none;">'
                    + '<img id="photo" alt="The picture will appear in this box."/></div></div>');
                const webcamSlot = getDesktopPanelSlot('webcam');
                if (webcamSlot) {
                    webcamBox.addClass('is-docked');
                    $(webcamSlot).append(webcamBox);
                } else {
                    $('body').append(webcamBox);
                }

                const video = document.getElementById('video');
                const canvas = document.getElementById('canvas');
                const photo = document.getElementById('photo');
                const blurWhenNoFace = parseInt(props.blurquizwithoutface || 0, 10) === 1;
                let faceBlurTimer = null;
                let faceBlurChecking = false;
                let facePresentCount = 0;
                let faceMissingCount = 0;

                const setQuizBlurredForFace = (blurred) => {
                    document.body.classList.toggle('proctoring-face-blur-active', blurred);
                    const notice = document.getElementById('proctoring-face-blur-notice');
                    if (notice) {
                        notice.style.display = blurred ? 'block' : 'none';
                    }
                };

                const initFaceVisibilityBlur = () => {
                    if (!blurWhenNoFace || !faceModelReady || !video || faceBlurTimer) {
                        return;
                    }

                    if (!document.getElementById('proctoring-face-blur-notice')) {
                        $('body').append(
                            `<div id="proctoring-face-blur-notice" class="proctoring-face-blur-notice" role="alert">` +
                                strings.faceblurmessage +
                            '</div>'
                        );
                    }

                    setQuizBlurredForFace(true);

                    const checkFaceVisibility = async() => {
                        if (faceBlurChecking) {
                            return;
                        }

                        if (!video.videoWidth || !video.videoHeight) {
                            setQuizBlurredForFace(true);
                            return;
                        }

                        faceBlurChecking = true;
                        try {
                            // eslint-disable-next-line no-undef
                            const detections = await faceapi.detectAllFaces(video);
                            const faceVisible = detections.some((detection) => detection.score >= 0.45);
                            if (faceVisible) {
                                facePresentCount++;
                                faceMissingCount = 0;
                                if (facePresentCount >= 1) {
                                    setQuizBlurredForFace(false);
                                }
                            } else {
                                faceMissingCount++;
                                facePresentCount = 0;
                                if (faceMissingCount >= 2) {
                                    setQuizBlurredForFace(true);
                                }
                            }
                        } catch (error) {
                            faceMissingCount++;
                            facePresentCount = 0;
                            if (faceMissingCount >= 2) {
                                setQuizBlurredForFace(true);
                            }
                        } finally {
                            faceBlurChecking = false;
                        }
                    };

                    faceBlurTimer = window.setInterval(checkFaceVisibility, 1500);
                    checkFaceVisibility();
                };

                const makeElementDraggable = (element) => {
                let pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;

                    const dragMouseDown = (e) => {
                        e.preventDefault();
                        pos3 = e.clientX;
                        pos4 = e.clientY;

                        document.onmouseup = closeDragElement;
                        document.onmousemove = elementDrag;
                    };

                    const elementDrag = (e) => {
                        e.preventDefault();
                        pos1 = pos3 - e.clientX;
                        pos2 = pos4 - e.clientY;
                        pos3 = e.clientX;
                        pos4 = e.clientY;

                        element.style.top = element.offsetTop - pos2 + "px";
                        element.style.left = element.offsetLeft - pos1 + "px";
                        element.style.bottom = element.offsetTop - pos2 + 200 + "px";
                        element.style.right = element.offsetLeft - pos1 + 200 + "px";
                    };

                    const closeDragElement = () => {
                        document.onmouseup = null;
                        document.onmousemove = null;
                    };

                    element.onmousedown = dragMouseDown;
                };
                if (video && !video.closest('.proctoring-fixed-webcam-box.is-docked')) {
                    makeElementDraggable(video);
                }

                const clearphoto = () => {
                    const context = canvas.getContext('2d');
                    context.fillStyle = "#AAA";
                    context.fillRect(0, 0, canvas.width, canvas.height);
                    data = canvas.toDataURL('image/png');
                    photo.setAttribute('src', data);
                };

                const takepicture = async() => {
                    const context = canvas.getContext('2d');
                    if (width && height) {
                        canvas.width = width;
                        canvas.height = height;
                        context.drawImage(video, 0, 0, width, height);
                        data = canvas.toDataURL('image/png');
                        photo.setAttribute('src', data);
                        props.webcampicture = data;

                        let croppedImage = $('#cropimg');
                        if (faceModelReady) {
                            await detectface(photo, croppedImage);
                        }
                        let faceFound;
                        let faceImage;
                        if (croppedImage.src) {
                            if (faceModelReady) {
                                removeNotifications();
                            }
                            faceFound = 1;
                            faceImage = croppedImage.src;
                        } else {
                            if (faceModelReady) {
                                showNotification(strings.facenotfoundoncam, 'error');
                            }
                            faceFound = 0;
                            faceImage = "";
                        }
                        var wsfunction = 'quizaccess_proctoring_send_camshot';
                        var params = {
                            'courseid': props.courseid,
                            'screenshotid': props.id,
                            'quizid': props.quizid,
                            'webcampicture': data,
                            'imagetype': 1,
                            'parenttype': 'camshot_image',
                            'faceimage': faceImage,
                            'facefound': faceFound,
                        };

                        var request = {
                            methodname: wsfunction,
                            args: params
                        };

                        Ajax.call([request])[0].done(function(res) {
                            if (res.warnings.length >= 1) {
                                if (video) {
                                    Notification.addNotification({
                                        message: strings.wrongduringtakingimage,
                                        type: 'error'
                                    });
                                }
                            }
                        }).fail(Notification.exception);
                    } else {
                        clearphoto();
                    }
                };

                navigator.mediaDevices.getUserMedia({video: true, audio: false})
                    // eslint-disable-next-line promise/always-return
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.play();
                        isCameraAllowed = true;
                        initFaceVisibilityBlur();
                    })
                    .catch(function() {
                        hideButtons();
                    });

                if (video) {
                    video.addEventListener('canplay', function() {
                        if (!streaming) {
                            height = video.videoHeight / (video.videoWidth / width);
                            // Firefox currently has a bug where the height can't be read from.
                            // The video, so we will make assumptions if this happens.
                            if (isNaN(height)) {
                                height = width / (4 / 3);
                            }
                            video.setAttribute('width', width);
                            video.setAttribute('height', height);
                            canvas.setAttribute('width', width);
                            canvas.setAttribute('height', height);
                            streaming = true;
                        }
                    }, false);

                    // Allow to click picture.
                    video.addEventListener('click', async function(ev) {
                        await takepicture();
                        ev.preventDefault();
                    }, false);
                    setTimeout(takepicture, firstcalldelay);
                    setInterval(takepicture, takepicturedelay);
                } else {
                    hideButtons();
                }

                return true;
            },
            async init(props) {
                let height = 0; // This will be computed based on the input stream.
                let streaming = false;
                let video = null;
                let canvas = null;
                let photo = null;
                let data = null;
                const width = props.image_width;

                /**
                 * Startup
                 */
                async function startup() {
                    video = document.getElementById('video');
                    canvas = document.getElementById('canvas');
                    photo = document.getElementById('photo');

                    if (video) {
                        navigator.mediaDevices.getUserMedia({video: true, audio: false})
                            // eslint-disable-next-line promise/always-return
                            .then(function(stream) {
                                video.srcObject = stream;
                                video.play();
                                isCameraAllowed = true;

                                Notification.addNotification({
                                    message: props.cameraallow,
                                    type: 'success' // Success notification type.
                                });
                            })
                            .catch(function() {
                                Notification.addNotification({
                                    message: props.allowcamerawarning,
                                    type: 'warning'
                                });
                                hideButtons();
                            });

                        video.addEventListener('canplay', function() {
                            if (!streaming) {
                                height = video.videoHeight / (video.videoWidth / width);
                                // Firefox currently has a bug where the height can't be read from.
                                // The video, so we will make assumptions if this happens.
                                if (isNaN(height)) {
                                    height = width / (4 / 3);
                                }
                                video.setAttribute('width', width);
                                video.setAttribute('height', height);
                                canvas.setAttribute('width', width);
                                canvas.setAttribute('height', height);
                                streaming = true;
                            }
                        }, false);

                        // Allow to click picture.
                        video.addEventListener('click', async function(ev) {
                            await takepicture();
                            ev.preventDefault();
                        }, false);
                    } else {
                        hideButtons();
                    }
                    clearphoto();
                }

                /**
                 * Clearphoto
                 */
                function clearphoto() {
                    if (isCameraAllowed) {
                        var context = canvas.getContext('2d');
                        context.fillStyle = "#AAA";
                        context.fillRect(0, 0, canvas.width, canvas.height);

                        data = canvas.toDataURL('image/png');
                        photo.setAttribute('src', data);
                    } else {
                        hideButtons();
                    }
                }

                /**
                 * Takepicture
                 */
                async function takepicture() {

                    const strings = await loadStrings();

                    var context = canvas.getContext('2d');
                    if (width && height) {
                        $(document).trigger("screenshoottaken");
                        canvas.width = width;
                        canvas.height = height;
                        context.drawImage(video, 0, 0, width, height);
                        data = canvas.toDataURL('image/png');
                        photo.setAttribute('src', data);

                        var wsfunction = 'quizaccess_proctoring_send_camshot';
                        var params = {
                            'courseid': props.courseid,
                            'screenshotid': props.id,
                            'quizid': props.quizid,
                            'webcampicture': data,
                            'imagetype': 1
                        };

                        var request = {
                            methodname: wsfunction,
                            args: params
                        };

                        Ajax.call([request])[0].done(async function(res) {
                            if (res.warnings.length >= 1) {
                                Notification.addNotification({
                                    message: strings.wrongduringtakingscreenshot,
                                    type: 'error'
                                });
                            }
                        }).fail(Notification.exception);

                    } else {
                        clearphoto();
                    }
                }

                await startup();

                return data;
            }
        };
    });
