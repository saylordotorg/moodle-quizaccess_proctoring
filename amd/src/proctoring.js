// @SuppressWarnings("javascript:S4144");
let isCameraAllowed = false;
// Module-scoped handle to the MediaStream acquired for the Pre-Check modal (Req 6.2).
let precheckStream = null;

define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'quizaccess_proctoring/screenMonitorClient',
    'quizaccess_proctoring/evidenceQueue'],
    function($, Ajax, Notification, Str, ScreenMonitorClient, EvidenceQueue) {
        const loadStrings = async function() {
            const stringkeys = [
                {key: 'facenotfoundoncam', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_image', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_screenshot', component: 'quizaccess_proctoring'},
                {key: 'enable_web_camera_before_submitting', component: 'quizaccess_proctoring'},
                {key: 'webcam', component: 'quizaccess_proctoring'},
                {key: 'videonotavailable', component: 'quizaccess_proctoring'},
                {key: 'desktopcaptureprompt', component: 'quizaccess_proctoring'},
                {key: 'desktopcapturepromptnomarker', component: 'quizaccess_proctoring'},
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
                {key: 'multimonitor:blurmessage', component: 'quizaccess_proctoring'},
                {key: 'attemptwarning:multiplemonitors', component: 'quizaccess_proctoring'},
                {key: 'attemptwarning:quiznotinview', component: 'quizaccess_proctoring'},
                {key: 'attemptwarning:screensharestopped', component: 'quizaccess_proctoring'},
                {key: 'attemptwarning:title', component: 'quizaccess_proctoring'},
                {key: 'attemptwarning:wrongscreen', component: 'quizaccess_proctoring'},
                {key: 'screenmarkerchecking', component: 'quizaccess_proctoring'},
                {key: 'attemptwarning:sessionlost', component: 'quizaccess_proctoring'},
                {key: 'coverage:connection', component: 'quizaccess_proctoring'},
                {key: 'coverage:lost', component: 'quizaccess_proctoring'},
                {key: 'coverage:recovered', component: 'quizaccess_proctoring'},
                {key: 'coverage:device', component: 'quizaccess_proctoring'},
                {key: 'coverage:rejected', component: 'quizaccess_proctoring'},
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
                    desktopcapturepromptnomarker: strings[7],
                    desktopcapturetitle: strings[8],
                    entirescreenrequired: strings[9],
                    shareentirescreen: strings[10],
                    screenshareaccepted: strings[11],
                    screensharedenied: strings[12],
                    screensharenotsupported: strings[13],
                    screensharestopped: strings[14],
                    screenmarkerlabel: strings[15],
                    screenmarkerwrongmonitor: strings[16],
                    screenmonitorwindowopened: strings[17],
                    screenmonitorpopupblocked: strings[18],
                    faceblurmessage: strings[19],
                    multimonitorblurmessage: strings[20],
                    attemptwarningmultiplemonitors: strings[21],
                    attemptwarningquiznotinview: strings[22],
                    attemptwarningscreensharestopped: strings[23],
                    attemptwarningtitle: strings[24],
                    attemptwarningwrongscreen: strings[25],
                    screenmarkerchecking: strings[26],
                    attemptwarningsessionlost: strings[27],
                    coverageconnection: strings[28],
                    coveragelost: strings[29],
                    coveragerecovered: strings[30],
                    coveragedevice: strings[31],
                    coveragerejected: strings[32],
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
                        if (!alertDiv.classList.contains('proctoring-attempt-warning')) {
                            alertDiv.style.display = 'none';
                        }
                    });
                }
            } catch (error) {
                Notification.exception(error);
            }
        };

        let firstcalldelay = 3000; // 3 seconds after the page load.
        let takepicturedelay = 30000; // 30 seconds.

        const getUserCameraConstraints = function() {
            return {
                video: {
                    facingMode: 'user',
                    width: {ideal: 960},
                    height: {ideal: 1280},
                    aspectRatio: {ideal: 0.75},
                },
                audio: false,
            };
        };

        const requestUserCamera = function() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                return Promise.reject(new Error('getUserMedia unavailable'));
            }

            return navigator.mediaDevices.getUserMedia(getUserCameraConstraints())
                .catch(() => navigator.mediaDevices.getUserMedia({video: true, audio: false}));
        };

        /**
         * Acquire the webcam for the Pre-Check modal and bind it to the modal video element.
         *
         * The resulting MediaStream is tracked in the module-scoped precheckStream handle so it can
         * be deterministically released later (Req 6.2). Acquisition is idempotent: if a live stream
         * is already bound to the given video element, the existing stream is returned.
         *
         * @param {HTMLVideoElement} video The Pre-Check modal <video> element.
         * @returns {Promise<MediaStream>} Resolves with the bound MediaStream.
         */
        const acquirePrecheckCamera = function(video) {
            if (!video) {
                return Promise.reject(new Error('precheck video element unavailable'));
            }
            if (precheckStream && video.srcObject === precheckStream) {
                return Promise.resolve(precheckStream);
            }
            return requestUserCamera().then(function(stream) {
                precheckStream = stream;
                video.srcObject = stream;
                video.play();
                isCameraAllowed = true;
                return stream;
            });
        };

        /**
         * Tear down the Pre-Check camera: stop every track, detach it from the video element, and
         * clear the tracked stream/allowed flags (Req 6.3). Mirrors the stopIdDocumentStream()
         * teardown pattern used in startAttempt.js. Safe to call repeatedly.
         *
         * @param {HTMLVideoElement} [video] The Pre-Check modal <video> element.
         */
        const teardownPrecheckCamera = function(video) {
            if (precheckStream) {
                precheckStream.getTracks().forEach((track) => track.stop());
                precheckStream = null;
            }
            const target = video || document.getElementById('video');
            if (target) {
                target.srcObject = null;
            }
            isCameraAllowed = false;
        };

        // Crop the face in the box out of the image, as a data URL ('' when nothing was extracted).
        const extractFaceFromBox = async(imageRef, box) => {
            const regionsToExtract = [
                // eslint-disable-next-line no-undef
                new faceapi.Rect(box.x, box.y, box.width, box.height)
            ];
            // eslint-disable-next-line no-undef
            const faceImages = await faceapi.extractFaces(imageRef, regionsToExtract);
            return faceImages.length ? faceImages[faceImages.length - 1].toDataURL() : '';
        };

        // A detection call that never settles must not stop every later check: each one gives up
        // after this long, so the "checking" flags always clear (CPIT-470).
        const DETECTION_TIMEOUT_MS = 5000;
        const withTimeout = (promise, ms) => new Promise((resolve, reject) => {
            const timer = window.setTimeout(() => reject(new Error('detection timed out')), ms);
            Promise.resolve(promise).then((value) => {
                window.clearTimeout(timer);
                resolve(value);
            }, (error) => {
                window.clearTimeout(timer);
                reject(error);
            });
        });

        // Returns the face crop as a data URL, or '' when no face was found. It never writes to the
        // page itself: a call that timed out may still finish later, and must not leave its crop for
        // the next capture (CPIT-470).
        const detectface = async(input, minScore) => {
            // The configured sensitivity (faceblurminscore), not face-api's built-in 0.5 (CPIT-469).
            // eslint-disable-next-line no-undef
            const options = new faceapi.SsdMobilenetv1Options({minConfidence: minScore});
            // eslint-disable-next-line no-undef
            const output = await faceapi.detectAllFaces(input, options);
            return output.length ? extractFaceFromBox(input, output[0].box) : '';
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

        /**
         * Whether a web service failure means the login session behind this tab has changed.
         *
         * That happens mid-exam more than it sounds like it should: the session expires, or the
         * student signs out or switches accounts in another tab - and with guest auto-login on,
         * the tab quietly becomes the guest user, who holds no proctoring capability. Every
         * periodic upload then fails the same way, and the raw exception surfaces as a
         * "you do not have permissions (Proctoring send webcam photo)" modal every 30 seconds,
         * which tells the student nothing they can act on.
         *
         * @param {Object} error The rejection from Ajax.call.
         * @return {Boolean} True when this is an authentication failure, not a service fault.
         */
        const isAuthFailure = function(error) {
            const code = (error && (error.errorcode || (error.exception && error.exception.errorcode))) || '';
            return ['nopermissions', 'requireloginerror', 'servicerequireslogin',
                'invalidsesskey', 'sessionerror', 'sessionexpired', 'loggedout'].indexOf(code) !== -1;
        };

        let sessionLostNotified = false;

        /**
         * Tell the student once, in place, that their login session is gone - then stay quiet.
         *
         * One persistent banner beats a modal per failed upload: the failure repeats every capture
         * interval, and each modal steals focus from the attempt the student is trying to save.
         *
         * @param {String} message Localized explanation with the recovery step.
         */
        const showSessionLostBanner = function(message) {
            if (sessionLostNotified || !message) {
                return;
            }
            sessionLostNotified = true;

            let dock = document.getElementById('proctoring-attempt-warning-dock');
            if (!dock) {
                dock = document.createElement('div');
                dock.id = 'proctoring-attempt-warning-dock';
                dock.className = 'proctoring-attempt-warning-dock';
                document.body.appendChild(dock);
            }
            const banner = document.createElement('div');
            banner.id = 'proctoring-session-lost-banner';
            banner.className = 'alert alert-danger proctoring-attempt-warning';
            banner.setAttribute('role', 'alert');
            banner.textContent = message;
            dock.appendChild(banner);
        };

        /**
         * Failure handler for the periodic proctoring uploads.
         *
         * @param {Object} strings Localized strings.
         * @param {Object} error The rejection from Ajax.call.
         */
        const handleUploadFailure = function(strings, error) {
            if (isAuthFailure(error)) {
                showSessionLostBanner(strings.attemptwarningsessionlost);
                return;
            }
            Notification.exception(error);
        };

        /** Keep capture timestamps on the server clock despite a misconfigured device clock. */
        const createCaptureClock = function(props) {
            const serverMs = Number(props.servertime) * 1000;
            if (!Number.isFinite(serverMs) || serverMs <= 0) {
                return Date.now;
            }
            const monotonic = typeof performance !== 'undefined' && performance.now ?
                () => performance.now() : Date.now;
            const started = monotonic();
            return () => serverMs + Math.max(0, monotonic() - started);
        };

        /** One upload queue and an accessible, neutral coverage status for the attempt page. */
        const createUploadController = function(strings, captureClock) {
            let queue;
            let banner = null;
            let inactive = false;
            let cameraMissing = false;
            let screenMissing = false;
            const render = function(state) {
                let text = '';
                if (state.auth) {
                    text = strings.attemptwarningsessionlost;
                } else if (state.offline || state.retrying) {
                    text = strings.coverageconnection;
                } else if (state.problem === 'rejected') {
                    text = strings.coveragerejected;
                } else if (cameraMissing || screenMissing) {
                    text = strings.coveragedevice;
                } else if (state.dropped) {
                    text = strings.coveragelost;
                } else if (state.recovered) {
                    text = strings.coveragerecovered;
                }
                if (!banner && text) {
                    banner = document.createElement('div');
                    banner.id = 'proctoring-coverage-status';
                    banner.className = 'alert alert-warning proctoring-attempt-warning';
                    banner.setAttribute('role', 'status');
                    banner.setAttribute('aria-live', 'polite');
                    let dock = document.getElementById('proctoring-attempt-warning-dock');
                    if (!dock) {
                        dock = document.createElement('div');
                        dock.id = 'proctoring-attempt-warning-dock';
                        dock.className = 'proctoring-attempt-warning-dock';
                        document.body.appendChild(dock);
                    }
                    dock.appendChild(banner);
                }
                if (banner) {
                    banner.textContent = text || '';
                    banner.style.display = text ? '' : 'none';
                }
            };
            const start = function() {
                inactive = false;
                queue = EvidenceQueue.create({
                    now: captureClock,
                    online: () => navigator.onLine !== false,
                    onState: render,
                    send: request => new Promise((resolve, reject) => {
                        // Handle session loss in place rather than core/ajax redirecting an active exam.
                        Ajax.call([request], true, true, true, 15000)[0].done(resolve).fail(reject);
                    })
                });
                render(queue.snapshot());
            };
            start();
            const connectionChanged = function() {
                if (!inactive) {
                    queue.flush();
                }
            };
            window.addEventListener('online', connectionChanged);
            window.addEventListener('offline', connectionChanged);
            return {
                submit: function(request, capturedat) {
                    return !inactive && queue.enqueue(request, capturedat);
                },
                device: function(device, missing) {
                    if (device === 'camera') {
                        cameraMissing = missing;
                    } else {
                        screenMissing = missing;
                    }
                    if (!inactive) {
                        render(queue.snapshot());
                    }
                },
                suspend: function() {
                    inactive = true;
                    queue.dispose();
                },
                resume: start
            };
        };

        /**
         * Collapse Boost's course index drawer, if the theme is showing one.
         *
         * Drawer state lives in a button's aria-expanded plus classes on <body>, and the
         * drawer is rendered by the theme after our module runs on some pages, so the close
         * is attempted now and once more after the drawer turns up.
         */
        const closeCourseIndexDrawer = function() {
            const close = function() {
                const toggle = document.querySelector('[data-toggler="drawers"][data-target="theme_boost-drawers-courseindex"]');
                const drawer = document.getElementById('theme_boost-drawers-courseindex');
                if (!drawer) {
                    return false;
                }
                if (toggle && toggle.getAttribute('aria-expanded') === 'true') {
                    // Click the theme's own toggle rather than hiding the drawer directly, so
                    // the theme keeps its classes, focus handling and body padding consistent.
                    toggle.click();
                    return true;
                }
                return drawer.classList.contains('show') === false;
            };

            if (close()) {
                return;
            }

            // The drawer was not in the DOM yet. Watch briefly, then give up rather than
            // leaving an observer running for the whole attempt.
            const observer = new MutationObserver(function() {
                if (close()) {
                    observer.disconnect();
                }
            });
            observer.observe(document.body, {childList: true, subtree: true});
            window.setTimeout(function() {
                observer.disconnect();
            }, 5000);
        };

        const initSuspiciousActivityMonitoring = function(props, strings, uploads, captureClock) {
            let monitoringActive = true;
            const intervals = [];
            const monitorInterval = function(callback, delay) {
                const entry = {callback: callback, delay: delay};
                entry.id = monitoringActive ? window.setInterval(callback, delay) : null;
                intervals.push(entry);
                return entry.id;
            };
            let lastLogged = {};
            let hiddenStarted = 0;
            const throttleMs = 5000;
            const aiPattern = /(gemini|chatgpt|openai|copilot|claude|perplexity|bard|ask\s+gemini|ask\s+ai)/i;
            const monitorActivity = parseInt(props.monitorbrowseractivity, 10) === 1;
            const blockClipboard = parseInt(props.blockclipboard, 10) === 1;
            const captureDesktop = parseInt(props.captureviolationdesktop, 10) === 1;
            const coverageScreens = captureDesktop && parseInt(props.coveragescreens, 10) === 1;
            const desktopPointerEnvironment = !(/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i)
                .test(navigator.userAgent || '') &&
                !(window.matchMedia &&
                    window.matchMedia('(pointer: coarse) and (max-width: 1024px)').matches);
            const monitorMouseActivity = parseInt(props.monitormouseactivity || 0, 10) === 1 &&
                desktopPointerEnvironment;
            const detectPhone = parseInt(props.detectphone || 0, 10) === 1 && !!props.phonedetectliburl;
            // Multiple faces (CPIT-468): needs the face model that setup() loaded.
            const detectMultipleFaces = parseInt(props.detectmultiplefaces || 0, 10) === 1 &&
                parseInt(props.facemodelready || 0, 10) === 1;
            // A face only counts when its box is at least this share of the frame height, so a
            // photo or poster across the room is ignored.
            const multiFaceMinSize = Math.min(0.5, Math.max(0.03, parseFloat(props.multiplefacesminsize) || 0.10));
            const multiFaceMinScore = 0.6;
            // Like phone detection: a second face must stay in view across consecutive checks
            // (about 8 seconds), so a walk-past is not flagged; then a cooldown applies.
            const multiFaceCheckIntervalMs = 4000;
            const multiFaceRequiredFrames = 3;
            const multiFaceCooldownMs = 90000;
            const phoneMinScore = Math.min(0.95, Math.max(0.20, parseFloat(props.detectphoneminscore) || 0.60));
            // Defensive cadence: a phone must stay visible across consecutive checks before one
            // event (with the webcam frame attached) is logged, then a cooldown applies.
            const phoneCheckIntervalMs = 4000;
            const phoneRequiredFrames = 3;
            const phoneCooldownMs = 90000;
            const screenMarkerRequired = parseInt(
                props.screenmarkerrequired === undefined ? 1 : props.screenmarkerrequired,
                10
            ) === 1;
            const multiMonitorMode = ['log', 'warn', 'block'].includes(props.multimonitormode) ?
                props.multimonitormode : 'off';
            const blurWhenMultipleMonitors = parseInt(props.blurquizwithmultiplemonitors || 0, 10) === 1;
            const monitorDetectionEnabled = multiMonitorMode !== 'off' || blurWhenMultipleMonitors;
            const clipboardEvents = [
                'clipboard_copy',
                'clipboard_cut',
                'clipboard_paste'
            ];
            const multiMonitorEvents = [
                'multiple_monitors_detected',
                'monitor_detection_unavailable'
            ];
            const mouseEvents = [
                'mouse_left_window',
                'mouse_returned_window',
                'contextmenu'
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
                'screen_capture',
                'tab_hidden',
                'focus_lost',
                'clipboard_copy',
                'clipboard_cut',
                'clipboard_paste',
                'contextmenu',
                'shortcut',
                'possible_ai_tool',
                'page_exit',
                'screen_marker_missing',
                'multiple_monitors_detected'
            ];
            let screenStream = null;
            let screenShareGeneration = 0;
            let screenVideo = null;
            let screenCanvas = null;
            let screenReady = false;
            // Why screenReady is false, for missing-capture reasons (CPIT-471): 'wrong_screen' keeps
            // the share live (only the marker check failed), 'share_stopped' and 'helper_closed' do not.
            let screenUnavailableReason = '';
            let markerLastSeen = 0;
            let markerMissingLoggedAt = 0;
            let markerFaulted = false;
            let markerCheckTimer = null;
            let screenGateTimer = null;
            let screenMonitorClient = null;
            let latestDesktopFrame = '';
            let latestDesktopTime = 0;
            let multiMonitorLastState = '';
            let focusLostSince = 0;
            let suppressFocusLossUntil = 0;
            let phoneModel = null;
            let phoneCanvas = null;
            let phoneConsecutive = 0;
            let phoneLastLogged = 0;
            let phoneEvidenceFrame = '';
            let multiFaceConsecutive = 0;
            let multiFaceLastPositive = 0;
            // Each detector reports that it really ran once per page, after its first successful check
            // of a webcam frame: a loaded model with no camera, or failing inference, has not run.
            let multiFaceStartReported = false;
            let phoneStartReported = false;
            let multiFaceLastLogged = 0;
            let multiFaceChecking = false;
            let webcamEvidenceFrame = '';
            // Repeated captures while away (CPIT-471): one absence shares a key with its leave events.
            const awayCaptureIntervalMs = Math.min(120, Math.max(5, parseInt(props.awaycaptureinterval, 10) || 15)) * 1000;
            const awayCaptureMax = Math.min(30, Math.max(0, parseInt(props.awaycapturemax === undefined ? 10 : props.awaycapturemax,
                10) || 0));
            let awayKey = '';
            let awayStartedMs = 0;
            let awayCaptureCount = 0;
            let awayCaptureTimer = null;
            let awayEvidenceFrame = '';
            // Events whose attached frame must show the screen at the time, not a cached frame.
            const freshFrameEvents = ['possible_ai_tool', 'screen_marker_missing'];
            const freshFrameMaxAgeMs = 2000;
            const activeAttemptWarnings = {};
            const attemptWarningTimers = {};
            const markerToken = Math.random().toString(36).slice(2, 8).toUpperCase();

            const ensureMultiMonitorBlurNotice = function() {
                let notice = document.getElementById('proctoring-multimonitor-blur-notice');
                if (notice) {
                    return notice;
                }

                notice = document.createElement('div');
                notice.id = 'proctoring-multimonitor-blur-notice';
                notice.className = 'proctoring-multimonitor-blur-notice';
                notice.setAttribute('role', 'alert');
                notice.style.display = 'none';
                notice.textContent = strings.multimonitorblurmessage || strings.attemptwarningmultiplemonitors;
                document.body.appendChild(notice);

                return notice;
            };

            const setQuizBlurredForMultipleMonitors = function(blurred) {
                if (!blurWhenMultipleMonitors) {
                    return;
                }

                document.body.classList.toggle('proctoring-multimonitor-blur-active', blurred);
                const notice = blurred ?
                    ensureMultiMonitorBlurNotice() :
                    document.getElementById('proctoring-multimonitor-blur-notice');
                if (notice) {
                    notice.style.display = blurred ? 'block' : 'none';
                }
            };

            const ensureAttemptWarning = function() {
                let warning = document.getElementById('proctoring-attempt-warning');
                if (warning) {
                    return warning;
                }

                warning = document.createElement('div');
                warning.id = 'proctoring-attempt-warning';
                warning.className = 'alert alert-warning proctoring-attempt-warning';
                warning.setAttribute('role', 'alert');
                warning.style.display = 'none';

                // Docked to the body rather than inserted at the top of the content region.
                // Students are almost never scrolled to the top of a quiz page, and the previous
                // position: sticky inside #region-main could not help: sticky and fixed both
                // resolve against the nearest transformed ancestor, and LMS themes routinely put a
                // transform on a page wrapper, which pins the banner to the top of the document
                // instead of the top of the viewport. The body has no such ancestor.
                let dock = document.getElementById('proctoring-attempt-warning-dock');
                if (!dock) {
                    dock = document.createElement('div');
                    dock.id = 'proctoring-attempt-warning-dock';
                    dock.className = 'proctoring-attempt-warning-dock';
                    document.body.appendChild(dock);
                }
                dock.appendChild(warning);

                return warning;
            };

            const renderAttemptWarnings = function() {
                const warning = ensureAttemptWarning();
                const warningItems = Object.values(activeAttemptWarnings);

                if (!warningItems.length) {
                    warning.style.display = 'none';
                    warning.innerHTML = '';
                    return;
                }

                const highestType = warningItems.some((item) => item.type === 'danger') ? 'danger' : 'warning';
                warning.className = 'alert alert-' + highestType + ' proctoring-attempt-warning';
                warning.innerHTML = '<strong>' + strings.attemptwarningtitle + '</strong>' +
                    '<ul class="proctoring-attempt-warning-list mb-0">' +
                    warningItems.map((item) => '<li>' + item.message + '</li>').join('') +
                    '</ul>';
                warning.style.display = 'block';
            };

            const setAttemptWarning = function(key, message, type, timeoutMs) {
                if (!message) {
                    return;
                }

                if (attemptWarningTimers[key]) {
                    window.clearTimeout(attemptWarningTimers[key]);
                    attemptWarningTimers[key] = null;
                }

                activeAttemptWarnings[key] = {
                    message: message,
                    type: type || 'warning'
                };
                renderAttemptWarnings();

                if (timeoutMs) {
                    attemptWarningTimers[key] = window.setTimeout(function() {
                        delete activeAttemptWarnings[key];
                        attemptWarningTimers[key] = null;
                        renderAttemptWarnings();
                    }, timeoutMs);
                }
            };

            const clearAttemptWarning = function(key) {
                if (attemptWarningTimers[key]) {
                    window.clearTimeout(attemptWarningTimers[key]);
                    attemptWarningTimers[key] = null;
                }
                delete activeAttemptWarnings[key];
                renderAttemptWarnings();
            };

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

                const markerHeight = markerElement.offsetHeight || 96;

                // Reserve the marker's footprint in the status panel's screen slot so the docked
                // webcam sits below it. The marker itself stays fixed and outside the panel:
                // collapsing the quiz navigation must not hide it from the shared screen.
                const screenSlot = getDesktopPanelSlot('screen');
                if (screenSlot) {
                    let spacer = screenSlot.querySelector('.proctoring-screen-marker-spacer');
                    if (!spacer) {
                        spacer = document.createElement('div');
                        spacer.className = 'proctoring-screen-marker-spacer';
                        spacer.setAttribute('aria-hidden', 'true');
                        screenSlot.appendChild(spacer);
                    }
                    spacer.style.height = markerHeight + 'px';
                    const slotRect = spacer.getBoundingClientRect();
                    // Only follow the slot while it is fully in view; the marker must stay on screen.
                    if (slotRect.width >= 80 && slotRect.top >= 8 &&
                            slotRect.top + markerHeight <= window.innerHeight - 16) {
                        markerElement.classList.add('is-panel-aligned');
                        markerElement.style.top = slotRect.top + 'px';
                        markerElement.style.left = slotRect.left + 'px';
                        markerElement.style.right = 'auto';
                        markerElement.style.width = slotRect.width + 'px';
                        return;
                    }
                }

                const markerWidth = Math.min(220, Math.max(180, rect.width));
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
                monitorInterval(function() {
                    positionScreenMarker(markerElement);
                }, 1500);
            };

            const initScreenMarker = function() {
                if (!captureDesktop || !screenMarkerRequired ||
                        document.getElementById('proctoring-screen-verification-marker')) {
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

            const captureDesktopFrame = function(eventType, evidenceOnly) {
                // Evidence of what the student is doing does not depend on the marker check passing,
                // only on the share still being live (CPIT-471).
                const available = evidenceOnly ? screenEvidenceAvailable() : screenReady;
                if (!captureDesktop || !desktopCaptureEvents.includes(eventType) || !available) {
                    return '';
                }

                if (screenMonitorClient) {
                    return screenMonitorClient.getLatestScreenshot() || latestDesktopFrame;
                }

                if (!screenVideo) {
                    return '';
                }
                const screenTracks = screenStream && screenStream.getVideoTracks ? screenStream.getVideoTracks() : [];
                if (!screenTracks.length || screenTracks.every(track => track.readyState === 'ended' || track.muted)) {
                    return '';
                }

                return drawDesktopImage(screenVideo, screenVideo.videoWidth || 0, screenVideo.videoHeight || 0);
            };

            const drawDesktopImage = function(source, sourceWidth, sourceHeight) {
                if (!source || !sourceWidth || !sourceHeight) {
                    return '';
                }

                if (!screenCanvas) {
                    screenCanvas = document.createElement('canvas');
                }

                const targetWidth = Math.min(1280, sourceWidth);
                const targetHeight = Math.round(sourceHeight * (targetWidth / sourceWidth));
                screenCanvas.width = targetWidth;
                screenCanvas.height = targetHeight;
                screenCanvas.getContext('2d').drawImage(source, 0, 0, targetWidth, targetHeight);

                return screenCanvas.toDataURL('image/jpeg', 0.75);
            };

            // The browser reports leaving the quiz as the switch starts, before the other app is
            // drawn, so a frame grabbed then only ever shows the quiz. Away events wait for a frame
            // taken after the switch; whatever happens, the frame from the event itself is kept.
            const awayCaptureEvents = ['focus_lost', 'tab_hidden'];
            const awayCaptureDelayMs = 2000;
            const awayCaptureTimeoutMs = 3000;
            const awayCaptureMinLeadMs = 1000;
            const pendingAwayCaptures = new Set();

            const grabSharedScreenFrame = async function(eventMs, notBeforeMs) {
                if (!monitoringActive || !screenEvidenceAvailable()) {
                    return '';
                }
                if (screenMonitorClient) {
                    // Ask the helper for a new frame and accept only one taken after the switch
                    // (or after the request, when the student came straight back), or, when given,
                    // one no older than notBeforeMs.
                    const notBefore = notBeforeMs !== undefined ? notBeforeMs :
                        Math.min(captureClock(), eventMs + awayCaptureMinLeadMs);
                    screenMonitorClient.getLatestScreenshot();
                    const started = Date.now();
                    while (Date.now() - started < awayCaptureTimeoutMs) {
                        if (latestDesktopFrame && latestDesktopTime >= notBefore) {
                            return latestDesktopFrame;
                        }
                        await new Promise(resolve => window.setTimeout(resolve, 250));
                        if (!monitoringActive) {
                            return '';
                        }
                    }
                    return '';
                }
                // A hidden tab may stop painting its <video>; read the share track directly when possible.
                const track = screenStream && screenStream.getVideoTracks ? screenStream.getVideoTracks()[0] : null;
                if (track && track.readyState !== 'ended' && typeof window.ImageCapture === 'function') {
                    try {
                        const bitmap = await new window.ImageCapture(track).grabFrame();
                        const frame = drawDesktopImage(bitmap, bitmap.width, bitmap.height);
                        if (bitmap.close) {
                            bitmap.close();
                        }
                        if (frame) {
                            return frame;
                        }
                    } catch (error) {
                        // Fall back to the page's video element below.
                    }
                }
                return captureDesktopFrame('focus_lost', true);
            };

            const captureAwayFrame = function(eventMs) {
                return new Promise(resolve => {
                    const pending = {settled: false, timer: null};
                    const finish = frame => {
                        if (pending.settled) {
                            return;
                        }
                        pending.settled = true;
                        window.clearTimeout(pending.timer);
                        pendingAwayCaptures.delete(pending);
                        resolve(frame || '');
                    };
                    // Returning to the quiz, or leaving the page, settles the capture early.
                    pending.grabNow = () => {
                        window.clearTimeout(pending.timer);
                        grabSharedScreenFrame(eventMs).then(finish, () => finish(''));
                    };
                    pending.abandon = () => finish('');
                    pendingAwayCaptures.add(pending);
                    pending.timer = window.setTimeout(pending.grabNow, awayCaptureDelayMs);
                });
            };

            const settleAwayCaptures = function(useFrames) {
                Array.from(pendingAwayCaptures).forEach(pending => {
                    if (useFrames) {
                        pending.grabNow();
                    } else {
                        pending.abandon();
                    }
                });
            };

            // Whether frames of the shared screen can still be had. A failed marker check (another app
            // covering the quiz for a while) does not end the share, and that is exactly when frames of
            // what the student is doing are wanted.
            const screenEvidenceAvailable = function() {
                if (screenReady) {
                    return true;
                }
                if (screenUnavailableReason !== 'wrong_screen') {
                    return false;
                }
                if (screenMonitorClient) {
                    return true;
                }
                const track = screenStream && screenStream.getVideoTracks ? screenStream.getVideoTracks()[0] : null;
                return !!track && track.readyState !== 'ended';
            };

            // Why no desktop frame could be attached, for the report (CPIT-471).
            const captureMissingReason = function() {
                if (!screenEvidenceAvailable()) {
                    return screenUnavailableReason && screenUnavailableReason !== 'wrong_screen' ? screenUnavailableReason :
                        (screenMonitorClient ? 'helper_closed' : 'share_stopped');
                }
                return 'no_fresh_frame';
            };

            // While the student is away, capture the shared screen every so often, up to a limit,
            // so a long absence shows what happened rather than one frame. Each capture carries the
            // absence's key, which its leave events carry too.
            const stopAwayCaptures = function() {
                if (awayCaptureTimer) {
                    window.clearInterval(awayCaptureTimer);
                    awayCaptureTimer = null;
                }
                awayKey = '';
                awayStartedMs = 0;
                awayCaptureCount = 0;
            };
            const captureWhileAway = async function() {
                if (!monitoringActive || !awayKey || awayCaptureCount >= awayCaptureMax) {
                    if (awayCaptureTimer && awayCaptureCount >= awayCaptureMax) {
                        window.clearInterval(awayCaptureTimer);
                        awayCaptureTimer = null;
                    }
                    return;
                }
                const key = awayKey;
                awayCaptureCount++;
                const sequence = awayCaptureCount;
                const frame = screenEvidenceAvailable() ?
                    await grabSharedScreenFrame(captureClock(), captureClock() - freshFrameMaxAgeMs).catch(() => '') : '';
                if (!monitoringActive || key !== awayKey) {
                    return;
                }
                const capture = {
                    awaykey: key,
                    sequence: sequence,
                    awayseconds: Math.round((Date.now() - awayStartedMs) / 1000)
                };
                if (!frame) {
                    capture.capturemissing = captureMissingReason();
                }
                awayEvidenceFrame = frame || '';
                logEvent('away_capture', capture);
                awayEvidenceFrame = '';
            };
            const startAwayCaptures = function() {
                if (awayKey) {
                    return awayKey;
                }
                awayKey = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
                awayStartedMs = Date.now();
                awayCaptureCount = 0;
                if (captureDesktop && awayCaptureMax > 0) {
                    awayCaptureTimer = window.setInterval(captureWhileAway, awayCaptureIntervalMs);
                }
                return awayKey;
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

            /**
             * Size the marker search window, and the evidence it demands, from how large the
             * marker actually lands in the captured frame.
             *
             * The window used to be a fixed 6x4 tiles (96x64px of a 1280px-wide frame), but the
             * marker's colour row is 186 CSS px wide: on a 1728px-wide laptop screen that is
             * ~138px of the analysed frame, half again wider than the window. Detection
             * therefore depended on a window that clipped the outer two swatches and still
             * scraped past a flat 18-sample floor -- it worked, with almost no margin, and the
             * margin shrank further on smaller or differently scaled displays.
             *
             * Sizing the window to the row means whole swatches land inside it, so the sample
             * floor can scale with the area a real swatch covers. That is deliberately a
             * stricter test than the flat 18: a wider window would otherwise make it easier for
             * unrelated colourful desktop content to satisfy all three colours by coincidence.
             */
            const markerSearchGeometry = function(frameWidth, tileSize) {
                // Keep the historical window as the fallback for environments that cannot
                // report a screen width to scale against.
                const fallback = {tilesX: 6, tilesY: 4, minSamples: 18};
                const screenWidth = window.screen ? window.screen.width : 0;
                if (!screenWidth || !frameWidth || !tileSize) {
                    return fallback;
                }

                // Captured pixels per CSS pixel of the shared screen. Matches styles.css:
                // three 58x24 swatches separated by two 6px gaps.
                const scale = frameWidth / screenWidth;
                const rowWidth = 186 * scale;
                const swatchWidth = 58 * scale;
                const swatchHeight = 24 * scale;
                if (!(rowWidth > 0) || !(swatchHeight > 0)) {
                    return fallback;
                }

                // One tile of slack on each axis so the window still holds the whole row when
                // the marker straddles a tile boundary.
                return {
                    tilesX: Math.min(24, Math.max(6, Math.ceil(rowWidth / tileSize) + 1)),
                    tilesY: Math.min(12, Math.max(4, Math.ceil(swatchHeight / tileSize) + 1)),
                    // countMarkerTiles samples every second pixel on both axes, so this is the
                    // share of one whole swatch that must be visible and on-hue.
                    minSamples: Math.max(18, Math.round(
                        Math.floor(swatchWidth / 2) * Math.floor(swatchHeight / 2) * 0.35
                    )),
                };
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
                if (!screenMarkerRequired || !captureDesktop || !screenVideo) {
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
                const search = markerSearchGeometry(imageData.width, tileSize);

                for (let tileY = 0; tileY < maxTileY; tileY++) {
                    for (let tileX = 0; tileX < maxTileX; tileX++) {
                        const totals = {magenta: 0, cyan: 0, yellow: 0};

                        for (let yOffset = 0; yOffset < search.tilesY; yOffset++) {
                            for (let xOffset = 0; xOffset < search.tilesX; xOffset++) {
                                const tile = tiles[tileKey(tileX + xOffset, tileY + yOffset)];
                                if (!tile) {
                                    continue;
                                }
                                totals.magenta += tile.magenta;
                                totals.cyan += tile.cyan;
                                totals.yellow += tile.yellow;
                            }
                        }

                        if (totals.magenta >= search.minSamples && totals.cyan >= search.minSamples &&
                                totals.yellow >= search.minSamples) {
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

            /**
             * Report which surface a screen share captured.
             *
             * Firefox reports neither displaySurface nor any equivalent in the track settings, so an
             * entire-screen share there would otherwise always look like a window. When the browser is
             * silent, a share at least as large as the physical screen is taken to be the entire screen
             * (a multi-monitor desktop is larger still); anything smaller is a window. Firefox applies page
             * zoom to both screen.width (down) and devicePixelRatio (up), so their product is the physical
             * size at any zoom. A window sized exactly to the screen with no panel at all is
             * indistinguishable by size, and is accepted.
             *
             * @param {MediaStreamTrack} track The shared video track.
             * @returns {string} 'monitor', 'window', 'browser', or '' when it cannot be told.
             */
            const sharedDisplaySurface = function(track) {
                const settings = track && track.getSettings ? track.getSettings() : {};
                if (settings.displaySurface) {
                    return settings.displaySurface;
                }
                const view = typeof window === 'undefined' ? {} : window;
                const screenSize = view.screen || {};
                const ratio = view.devicePixelRatio || 1;
                if (!settings.width || !settings.height || !screenSize.width || !screenSize.height) {
                    return '';
                }
                // screen.width is in CSS pixels, rounded, so the physical size it implies can be off by
                // about a pixel per unit of devicePixelRatio. Allow only that much: a maximised window
                // is short by the panel or taskbar, often only 20-40 pixels, and must not pass.
                const slack = Math.ceil(ratio) + 1;
                const coversScreen = settings.width >= Math.round(screenSize.width * ratio) - slack &&
                    settings.height >= Math.round(screenSize.height * ratio) - slack;
                return coversScreen ? 'monitor' : 'window';
            };

            const stopScreenStream = function() {
                screenShareGeneration++;
                if (markerCheckTimer) {
                    window.clearInterval(markerCheckTimer);
                    markerCheckTimer = null;
                }
                if (screenStream) {
                    screenStream.getTracks().forEach((track) => track.stop());
                    screenStream = null;
                }
                if (screenVideo) {
                    screenVideo.srcObject = null;
                }
                if (screenCanvas) {
                    screenCanvas.width = 0;
                    screenCanvas.height = 0;
                }
                screenReady = false;
                screenUnavailableReason = 'share_stopped';
            };

            // The marker has to be *on* the shared screen, not visible in the very frame we
            // happen to sample. Anything the desktop puts in front of the quiz window for a
            // moment hides it through no fault of the student: the share picker, the browser's
            // own "you are sharing your screen" bubble, a notification, the Dock, another app
            // taking focus as the share is granted. So accept the share as soon as the marker
            // appears, and only fault it once the marker has been gone for the whole grace
            // period -- the same tolerance screenmonitor.php already applies.
            const markerGraceMs = 30000;
            const markerWatchIntervalMs = 2000;

            const acceptSharedScreen = function() {
                markerLastSeen = Date.now();
                markerMissingLoggedAt = 0;
                markerFaulted = false;
                if (screenReady) {
                    return;
                }

                screenReady = true;
                screenUnavailableReason = '';
                setScreenShareStatus(strings.screenshareaccepted, 'success');
                clearAttemptWarning('wrongscreen');
                clearAttemptWarning('screenshare');
                hideScreenShareGate();
            };

            const faultSharedScreen = function(reason) {
                // Keep the stream alive: this is a valid entire-screen share that is currently
                // showing the wrong thing, so the student can fix it by bringing the quiz
                // forward and the watcher below will accept it again without a re-prompt.
                if (!markerFaulted) {
                    markerFaulted = true;
                    screenReady = false;
                    screenUnavailableReason = 'wrong_screen';
                    setScreenShareStatus(strings.screenmarkerwrongmonitor, 'danger');
                    setAttemptWarning('wrongscreen', strings.attemptwarningwrongscreen, 'danger');
                    showScreenShareGate();
                }

                if (markerMissingLoggedAt && Date.now() - markerMissingLoggedAt < markerGraceMs) {
                    return;
                }

                markerMissingLoggedAt = Date.now();
                logEvent('screen_marker_missing', {
                    reason: reason,
                    note: 'The shared monitor did not contain the visible Moodle quiz screen marker.'
                });
            };

            const startMarkerChecks = function() {
                if (!captureDesktop || !screenMarkerRequired) {
                    return;
                }

                if (markerCheckTimer) {
                    window.clearInterval(markerCheckTimer);
                }

                markerCheckTimer = window.setInterval(function() {
                    if (!screenStream) {
                        return;
                    }

                    if (sharedScreenContainsMarker()) {
                        acceptSharedScreen();
                        return;
                    }

                    if (Date.now() - markerLastSeen > markerGraceMs) {
                        faultSharedScreen(screenReady ?
                            'periodic_marker_check_failed' : 'initial_marker_check_failed');
                    }
                }, markerWatchIntervalMs);
            };

            const requestScreenShare = async function(event) {
                if (event) {
                    event.preventDefault();
                }
                if (!monitoringActive) {
                    return;
                }

                // The helper window (or the browser's share picker) is about to take
                // focus at our own request; that must not count against the student.
                suppressFocusLossUntil = Date.now() + 15000;

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
                const generation = screenShareGeneration;

                try {
                    const granted = await navigator.mediaDevices.getDisplayMedia({
                        video: {
                            displaySurface: 'monitor'
                        },
                        audio: false
                    });
                    if (!monitoringActive || generation !== screenShareGeneration) {
                        granted.getTracks().forEach(track => track.stop());
                        return;
                    }
                    screenStream = granted;
                } catch (error) {
                    if (monitoringActive && generation === screenShareGeneration) {
                        setScreenShareStatus(strings.screensharedenied, 'danger');
                    }
                    return;
                }

                const videoTrack = screenStream.getVideoTracks()[0];
                if (!videoTrack || sharedDisplaySurface(videoTrack) !== 'monitor') {
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
                    if (!monitoringActive || generation !== screenShareGeneration) {
                        return;
                    }
                    stopScreenStream();
                    setScreenShareStatus(strings.screensharedenied, 'danger');
                    return;
                }

                const hasFrame = await waitForScreenFrame();
                if (!monitoringActive || generation !== screenShareGeneration) {
                    return;
                }
                if (!hasFrame) {
                    stopScreenStream();
                    setScreenShareStatus(strings.screensharedenied, 'danger');
                    return;
                }

                videoTrack.addEventListener('ended', function() {
                    if (!monitoringActive || generation !== screenShareGeneration) {
                        return;
                    }
                    stopScreenStream();
                    setScreenShareStatus(strings.screensharestopped, 'danger');
                    clearAttemptWarning('wrongscreen');
                    setAttemptWarning('screenshare', strings.attemptwarningscreensharestopped, 'danger');
                    showScreenShareGate();
                    logEvent('screen_share_stopped', {
                        reason: 'screen_share_ended'
                    });
                });

                // Open the grace period now. If the quiz window happens to be behind the share
                // picker or another app at this instant, the watcher accepts the share the
                // moment the marker becomes visible instead of failing the student outright.
                markerLastSeen = Date.now();
                markerMissingLoggedAt = 0;
                markerFaulted = false;

                if (!screenMarkerRequired || sharedScreenContainsMarker()) {
                    acceptSharedScreen();
                } else {
                    setScreenShareStatus(strings.screenmarkerchecking, 'info');
                }

                startMarkerChecks();
            };

            const loadExternalScript = function(src) {
                return new Promise(function(resolve, reject) {
                    const script = document.createElement('script');
                    script.src = src;
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            };

            const capturePhoneEvidenceFrame = function(video) {
                if (!phoneCanvas) {
                    phoneCanvas = document.createElement('canvas');
                }
                const targetWidth = Math.min(640, video.videoWidth);
                const targetHeight = Math.round(video.videoHeight * (targetWidth / video.videoWidth));
                phoneCanvas.width = targetWidth;
                phoneCanvas.height = targetHeight;
                phoneCanvas.getContext('2d').drawImage(video, 0, 0, targetWidth, targetHeight);

                return phoneCanvas.toDataURL('image/jpeg', 0.8);
            };

            const checkPhoneFrame = async function() {
                const video = document.getElementById('video');
                if (!monitoringActive || !phoneModel || !video || !video.videoWidth || !video.videoHeight ||
                        document.visibilityState === 'hidden') {
                    return;
                }

                let predictions = [];
                try {
                    predictions = await withTimeout(phoneModel.detect(video), DETECTION_TIMEOUT_MS) || [];
                } catch (error) {
                    return;
                }
                if (!monitoringActive) {
                    return;
                }
                if (!phoneStartReported) {
                    phoneStartReported = true;
                    logEvent('phone_detection_started', {});
                }

                const hit = predictions.find(function(prediction) {
                    return prediction.class === 'cell phone' && prediction.score >= phoneMinScore;
                });
                if (!hit) {
                    phoneConsecutive = 0;
                    return;
                }

                phoneConsecutive++;
                if (phoneConsecutive < phoneRequiredFrames || Date.now() - phoneLastLogged < phoneCooldownMs) {
                    return;
                }

                phoneLastLogged = Date.now();
                phoneConsecutive = 0;
                phoneEvidenceFrame = capturePhoneEvidenceFrame(video);
                logEvent('phone_detected', {
                    confidence: Math.round(hit.score * 100) / 100,
                    frames: phoneRequiredFrames,
                    note: 'A phone-like object stayed visible in the webcam across consecutive checks.'
                });
                phoneEvidenceFrame = '';
            };

            /**
             * Count the faces in the webcam large and confident enough to be a person at the desk.
             *
             * @param {HTMLVideoElement} video Webcam video.
             * @returns {Promise<number>} Number of qualifying faces.
             */
            const countWebcamFaces = async function(video) {
                // eslint-disable-next-line no-undef
                const options = new faceapi.SsdMobilenetv1Options({minConfidence: multiFaceMinScore});
                // eslint-disable-next-line no-undef
                const detections = await faceapi.detectAllFaces(video, options);
                const minHeight = video.videoHeight * multiFaceMinSize;
                return detections.filter(function(detection) {
                    return detection.box && detection.box.height >= minHeight;
                }).length;
            };

            const checkMultipleFaces = async function() {
                if (multiFaceChecking) {
                    return;
                }
                const video = document.getElementById('video');
                if (!monitoringActive || !video || !video.videoWidth || !video.videoHeight ||
                        document.visibilityState === 'hidden') {
                    // Nothing was observed, so the run of positive checks is broken: two separate
                    // walk-pasts either side of a gap must not add up to one sustained presence.
                    multiFaceConsecutive = 0;
                    return;
                }

                multiFaceChecking = true;
                let faces = 0;
                try {
                    faces = await withTimeout(countWebcamFaces(video), DETECTION_TIMEOUT_MS);
                } catch (error) {
                    multiFaceConsecutive = 0;
                    return;
                } finally {
                    multiFaceChecking = false;
                }
                if (!monitoringActive) {
                    multiFaceConsecutive = 0;
                    return;
                }
                if (!multiFaceStartReported) {
                    multiFaceStartReported = true;
                    logEvent('multiple_faces_detection_started', {});
                }
                if (faces < 2) {
                    multiFaceConsecutive = 0;
                    return;
                }

                // A slow or throttled check also breaks the run: positives only count as consecutive
                // when they are no more than two check intervals apart.
                const now = Date.now();
                if (now - multiFaceLastPositive > multiFaceCheckIntervalMs * 2) {
                    multiFaceConsecutive = 0;
                }
                multiFaceLastPositive = now;
                multiFaceConsecutive++;
                if (multiFaceConsecutive < multiFaceRequiredFrames || Date.now() - multiFaceLastLogged < multiFaceCooldownMs) {
                    return;
                }

                multiFaceLastLogged = Date.now();
                multiFaceConsecutive = 0;
                // The webcam frame is the evidence a reviewer judges; it is never decided automatically.
                webcamEvidenceFrame = capturePhoneEvidenceFrame(video);
                logEvent('multiple_faces_detected', {
                    faces: faces,
                    frames: multiFaceRequiredFrames,
                    note: 'More than one face stayed visible in the webcam across consecutive checks.'
                });
                webcamEvidenceFrame = '';
            };

            const initPhoneDetection = async function() {
                if (!detectPhone) {
                    return;
                }

                try {
                    if (!window.tf) {
                        await loadExternalScript(props.phonedetectliburl + '/tf.min.js');
                    }
                    if (!window.cocoSsd) {
                        await loadExternalScript(props.phonedetectliburl + '/coco-ssd.min.js');
                    }
                    phoneModel = await window.cocoSsd.load({
                        modelUrl: props.phonedetectliburl + '/model/model.json'
                    });
                } catch (error) {
                    // Phone detection is best-effort: never interrupt the attempt when the
                    // libraries or model are unavailable.
                    window.console.debug('quizaccess_proctoring: phone detection unavailable', error);
                    return;
                }

                monitorInterval(checkPhoneFrame, phoneCheckIntervalMs);
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
                            `<p>${screenMarkerRequired ?
                                strings.desktopcaptureprompt : strings.desktopcapturepromptnomarker}</p>` +
                            '<div id="proctoring-screen-share-status" class="proctoring-screen-share-status"></div>' +
                            '<button id="proctoring-screen-share-button" class="btn btn-primary">' +
                                strings.shareentirescreen +
                            '</button>' +
                        '</div>' +
                    '</div>'
                );

                $('#proctoring-screen-share-button').on('click', requestScreenShare);

                if (props.screenmonitorurl && !screenMonitorClient) {
                    // The helper window runs in the background, where browsers throttle its timers,
                    // so a status read just after a quiz page loads is often out of date even though
                    // the share is fine. Only a fresh "stopped" from the helper ends the share at once;
                    // anything else has to persist through the grace period before it counts.
                    const monitorUnavailableGraceMs = 10000;
                    const monitorStoppedFreshMs = 5000;
                    let monitorUnavailableSince = 0;
                    screenMonitorClient = ScreenMonitorClient.create(props, {
                        onReady: function() {
                            monitorUnavailableSince = 0;
                            screenReady = true;
                            screenUnavailableReason = '';
                            setScreenShareStatus(strings.screenshareaccepted, 'success');
                            clearAttemptWarning('wrongscreen');
                            clearAttemptWarning('screenshare');
                            hideScreenShareGate();
                        },
                        onUnavailable: function(status) {
                            if (screenReady) {
                                const now = Date.now();
                                const statusAge = status && status.ts ? Math.max(0, now - status.ts) : null;
                                const stoppedByHelper = !!(status && status.stopped === true &&
                                    statusAge !== null && statusAge <= monitorStoppedFreshMs);
                                if (!stoppedByHelper) {
                                    if (!monitorUnavailableSince) {
                                        monitorUnavailableSince = now;
                                    }
                                    if (now - monitorUnavailableSince < monitorUnavailableGraceMs) {
                                        return;
                                    }
                                }
                                monitorUnavailableSince = 0;
                                screenReady = false;
                                // A fresh "stopped" from the helper means the share ended; silence means
                                // the helper window itself is gone or not reporting.
                                screenUnavailableReason = status && status.stopped === true ? 'share_stopped' : 'helper_closed';
                                logEvent('screen_share_stopped', {
                                    reason: 'persistent_monitor_unavailable',
                                    statusage: statusAge === null ? null : Math.round(statusAge / 1000),
                                    helperready: !!(status && status.ready === true),
                                    helperstopped: !!(status && status.stopped === true)
                                });
                                setScreenShareStatus(strings.screensharestopped, 'danger');
                                clearAttemptWarning('wrongscreen');
                                setAttemptWarning('screenshare', strings.attemptwarningscreensharestopped, 'danger');
                                showScreenShareGate();
                            } else {
                                // Already not ready because the marker check failed, but the share was
                                // still live. Once the helper says it stopped, or stays silent past the
                                // grace period, frames are no longer to be had (CPIT-471).
                                if (screenUnavailableReason === 'wrong_screen') {
                                    const now = Date.now();
                                    const statusAge = status && status.ts ? Math.max(0, now - status.ts) : null;
                                    const stoppedByHelper = !!(status && status.stopped === true &&
                                        statusAge !== null && statusAge <= monitorStoppedFreshMs);
                                    if (!monitorUnavailableSince) {
                                        monitorUnavailableSince = now;
                                    }
                                    if (stoppedByHelper || now - monitorUnavailableSince >= monitorUnavailableGraceMs) {
                                        monitorUnavailableSince = 0;
                                        screenUnavailableReason = stoppedByHelper ? 'share_stopped' : 'helper_closed';
                                    }
                                }
                                scheduleScreenShareGate(2500);
                            }
                        },
                        onWrongScreen: function() {
                            monitorUnavailableSince = 0;
                            screenReady = false;
                            screenUnavailableReason = 'wrong_screen';
                            logEvent('screen_marker_missing', {
                                reason: 'persistent_monitor_marker_missing',
                                note: 'The persistent screen monitor did not see the Moodle quiz screen marker.'
                            });
                            setScreenShareStatus(strings.screenmarkerwrongmonitor, 'danger');
                            setAttemptWarning('wrongscreen', strings.attemptwarningwrongscreen, 'danger');
                            showScreenShareGate();
                        },
                        onScreenshot: function(message) {
                            latestDesktopFrame = message.image || '';
                            // Both windows share the device clock. Convert frame age once, then use
                            // the server-relative monotonic clock for freshness and evidence time.
                            const timestamp = Number(message.ts) || 0;
                            latestDesktopTime = timestamp ?
                                captureClock() - Math.max(0, Date.now() - timestamp) : 0;
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
                        screenUnavailableReason = '';
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
                if (!monitoringActive) {
                    return;
                }
                if (eventType === 'screen_capture' && !coverageScreens) {
                    return;
                }
                if (!monitorActivity && !(blockClipboard && clipboardEvents.includes(eventType)) &&
                        !(coverageScreens && eventType === 'screen_capture') &&
                        !(captureDesktop && screenShareEvents.includes(eventType)) &&
                        !(captureDesktop && eventType === 'away_capture') &&
                        !(monitorDetectionEnabled && multiMonitorEvents.includes(eventType)) &&
                        !(monitorMouseActivity && mouseEvents.includes(eventType)) &&
                        !(detectPhone && ['phone_detected', 'phone_detection_started'].includes(eventType)) &&
                        !(detectMultipleFaces &&
                            ['multiple_faces_detected', 'multiple_faces_detection_started'].includes(eventType))) {
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
                    screenshot: eventType === 'phone_detected' ? phoneEvidenceFrame
                        : (eventType === 'multiple_faces_detected' ? webcamEvidenceFrame
                            : (eventType === 'away_capture' ? awayEvidenceFrame : captureDesktopFrame(eventType)))
                };
                let capturedat = Math.floor(captureClock() / 1000);
                if (eventType === 'screen_capture') {
                    // A helper window frame can be stale after suspension; never label it as a fresh capture.
                    if (screenMonitorClient) {
                        args.screenshot = captureClock() - latestDesktopTime <= 15000 ? latestDesktopFrame : '';
                        capturedat = Math.floor(latestDesktopTime / 1000);
                    }
                    const missing = !screenReady || !args.screenshot;
                    uploads.device('screen', missing);
                    if (missing) {
                        return;
                    }
                }
                const request = {
                    methodname: 'quizaccess_proctoring_log_event',
                    args: args
                };
                const withMissingReason = function(extra) {
                    // A desktop capture was expected but none is attached: say why (CPIT-471).
                    const missing = captureDesktop && eventType !== 'away_capture' && desktopCaptureEvents.includes(eventType) &&
                        !args.screenshot ? captureMissingReason() : '';
                    args.eventdetail = JSON.stringify(Object.assign({}, detail || {}, extra || {},
                        missing ? {capturemissing: missing} : {}));
                };
                if (awayCaptureEvents.includes(eventType) && captureDesktop && screenEvidenceAvailable()) {
                    // Keep the event's own time; only the attached frame comes from after the switch.
                    // Tried even when the event itself had no frame, which used to leave it with none.
                    const eventFrame = args.screenshot;
                    captureAwayFrame(captureClock()).then(function(awayFrame) {
                        args.screenshot = awayFrame || eventFrame;
                        withMissingReason(args.screenshot ? {screenshottiming: awayFrame ? 'after_leaving' : 'at_event'} : {});
                        uploads.submit(request, capturedat);
                    });
                    return;
                }
                if (freshFrameEvents.includes(eventType) && captureDesktop && screenReady && screenMonitorClient) {
                    // The helper's cached frame can be seconds old: ask for one taken at the event.
                    const eventMs = captureClock();
                    grabSharedScreenFrame(eventMs, eventMs - freshFrameMaxAgeMs).then(function(frame) {
                        args.screenshot = frame || '';
                        withMissingReason(frame ? {screenshottiming: 'fresh'} : {});
                        uploads.submit(request, capturedat);
                    }, function() {
                        args.screenshot = '';
                        withMissingReason({});
                        uploads.submit(request, capturedat);
                    });
                    return;
                }
                withMissingReason({});
                uploads.submit(request, capturedat);
            };

            const getPointerBoundary = function(event) {
                const x = typeof event.clientX === 'number' ? event.clientX : null;
                const y = typeof event.clientY === 'number' ? event.clientY : null;

                if (x !== null && x <= 0) {
                    return 'left';
                }
                if (x !== null && x >= window.innerWidth - 1) {
                    return 'right';
                }
                if (y !== null && y <= 0) {
                    return 'top';
                }
                if (y !== null && y >= window.innerHeight - 1) {
                    return 'bottom';
                }

                return 'unknown';
            };

            const getPointerEventDetail = function(event, reason) {
                return {
                    reason: reason,
                    pointertype: event.pointerType || 'mouse',
                    boundary: getPointerBoundary(event),
                    clientx: typeof event.clientX === 'number' ? Math.round(event.clientX) : null,
                    clienty: typeof event.clientY === 'number' ? Math.round(event.clientY) : null,
                    viewportwidth: window.innerWidth || 0,
                    viewportheight: window.innerHeight || 0
                };
            };

            const initMouseActivityMonitoring = function() {
                if (!monitorMouseActivity) {
                    return;
                }

                let pointerOutsideWindow = false;

                const isMousePointer = function(event) {
                    return !event.pointerType || event.pointerType === 'mouse';
                };

                const logMouseLeft = function(event, reason) {
                    if (pointerOutsideWindow || !isMousePointer(event)) {
                        return;
                    }
                    pointerOutsideWindow = true;
                    logEvent('mouse_left_window', getPointerEventDetail(event, reason));
                };

                const logMouseReturned = function(event, reason) {
                    if (!pointerOutsideWindow || !isMousePointer(event)) {
                        return;
                    }
                    pointerOutsideWindow = false;
                    logEvent('mouse_returned_window', getPointerEventDetail(event, reason));
                };

                const maybeLeftWindow = function(event, reason) {
                    if (!event.relatedTarget && !event.toElement) {
                        logMouseLeft(event, reason);
                    }
                };

                document.addEventListener('pointerout', function(event) {
                    maybeLeftWindow(event, 'pointerout');
                }, true);
                document.addEventListener('mouseout', function(event) {
                    maybeLeftWindow(event, 'mouseout');
                }, true);
                document.addEventListener('pointerover', function(event) {
                    logMouseReturned(event, 'pointerover');
                }, true);
                document.addEventListener('mouseover', function(event) {
                    logMouseReturned(event, 'mouseover');
                }, true);
                document.documentElement.addEventListener('mouseleave', function(event) {
                    logMouseLeft(event, 'document_mouseleave');
                }, true);
                document.documentElement.addEventListener('mouseenter', function(event) {
                    logMouseReturned(event, 'document_mouseenter');
                }, true);
            };

            const detectMonitorSetup = async function(allowPermissionPrompt) {
                if (window.screen && typeof window.screen.isExtended === 'boolean') {
                    return {
                        supported: true,
                        multiple: !!window.screen.isExtended,
                        count: window.screen.isExtended ? 2 : 1,
                        source: 'screen.isExtended',
                    };
                }

                if (allowPermissionPrompt && typeof window.getScreenDetails === 'function') {
                    try {
                        const details = await window.getScreenDetails();
                        const count = details && details.screens ? details.screens.length : 0;
                        return {
                            supported: count > 0,
                            multiple: count > 1,
                            count: count,
                            source: 'getScreenDetails',
                        };
                    } catch (error) {
                        return {
                            supported: false,
                            multiple: false,
                            count: 0,
                            source: 'getScreenDetails',
                            error: error && error.name ? error.name : 'unknown',
                        };
                    }
                }

                return {
                    supported: false,
                    multiple: false,
                    count: 0,
                    source: 'unavailable',
                };
            };

            const checkMultiMonitorSetup = async function() {
                if (!monitorDetectionEnabled) {
                    return;
                }

                const status = await detectMonitorSetup(false);
                const state = !status.supported ? 'unavailable' : (status.multiple ? 'multiple' : 'single');
                if (state === multiMonitorLastState) {
                    return;
                }
                multiMonitorLastState = state;

                if (!status.supported) {
                    logEvent('monitor_detection_unavailable', status);
                    clearAttemptWarning('multiplemonitors');
                    setQuizBlurredForMultipleMonitors(false);
                } else if (status.multiple) {
                    logEvent('multiple_monitors_detected', status);
                    if (multiMonitorMode === 'warn' || multiMonitorMode === 'block') {
                        setAttemptWarning('multiplemonitors', strings.attemptwarningmultiplemonitors, 'warning');
                    }
                    setQuizBlurredForMultipleMonitors(true);
                } else {
                    clearAttemptWarning('multiplemonitors');
                    setQuizBlurredForMultipleMonitors(false);
                }
            };

            initScreenShareGate();
            if (coverageScreens) {
                monitorInterval(function() {
                    logEvent('screen_capture', {reason: 'scheduled_coverage_capture'});
                }, Math.max(5000, parseInt(props.camshotdelay, 10) || 30000));
            }
            initPhoneDetection();
            if (detectMultipleFaces) {
                monitorInterval(checkMultipleFaces, multiFaceCheckIntervalMs);
            }
            checkMultiMonitorSetup();
            if (monitorDetectionEnabled) {
                monitorInterval(checkMultiMonitorSetup, 60000);
                window.addEventListener('focus', checkMultiMonitorSetup, true);
            }

            if (monitorActivity) {
                document.addEventListener('visibilitychange', function() {
                    if (document.visibilityState === 'hidden') {
                        hiddenStarted = Date.now();
                        logEvent('tab_hidden', {
                            awaykey: startAwayCaptures(),
                            reason: 'document_hidden',
                            note: 'Quiz tab was hidden. This can indicate tab switching or opening another browser surface.'
                        });
                    } else if (document.visibilityState === 'visible') {
                        if (typeof document.hasFocus !== 'function' || document.hasFocus()) {
                            stopAwayCaptures();
                        }
                        logEvent('tab_visible', {
                            reason: 'document_visible',
                            hiddenms: hiddenStarted ? Date.now() - hiddenStarted : 0
                        });
                        hiddenStarted = 0;
                    }
                }, true);

                window.addEventListener('blur', function(event) {
                    if (Date.now() < suppressFocusLossUntil) {
                        return;
                    }
                    // The listener captures blur from inputs inside the quiz too; only the window losing
                    // focus means the student left (CPIT-471).
                    if (event && event.target && event.target !== window &&
                            typeof document.hasFocus === 'function' && document.hasFocus()) {
                        return;
                    }
                    focusLostSince = Date.now();
                    logEvent('focus_lost', {
                        awaykey: startAwayCaptures(),
                        reason: 'window_blur'
                    });
                }, true);

                window.addEventListener('focus', function() {
                    // Back already: the frame at the moment of return is the closest to where they went.
                    settleAwayCaptures(true);
                    stopAwayCaptures();
                    if (focusLostSince) {
                        setAttemptWarning('quiznotinview', strings.attemptwarningquiznotinview, 'warning', 12000);
                    }
                    logEvent('focus_returned', {
                        reason: 'window_focus'
                    });
                    focusLostSince = 0;
                }, true);
            }

            initMouseActivityMonitoring();

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

            if (monitorActivity || blockClipboard || monitorMouseActivity) {
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

                // Browser-native AI side panels (Gemini in Chrome, Copilot in Edge)
                // live outside the page DOM, so clicks inside them are invisible to
                // the label check above. Opening one has a detectable geometry
                // signature instead: the viewport narrows sharply while the window
                // keeps its size and the zoom level stays the same.
                const sidePanelMinShrink = 220;
                let sidePanelBaseline = {
                    inner: window.innerWidth || 0,
                    outer: window.outerWidth || 0,
                    dpr: window.devicePixelRatio || 1
                };
                let sidePanelResizeTimer = null;
                let sidePanelLastLogged = 0;

                const evaluateSidePanel = function() {
                    const inner = window.innerWidth || 0;
                    const outer = window.outerWidth || 0;
                    const dpr = window.devicePixelRatio || 1;
                    const zoomChanged = Math.abs(dpr - sidePanelBaseline.dpr) > 0.001;
                    const innerShrink = sidePanelBaseline.inner - inner;
                    const outerDelta = Math.abs(outer - sidePanelBaseline.outer);
                    const cooldownOver = Date.now() - sidePanelLastLogged > 90000;

                    if (!zoomChanged && cooldownOver && innerShrink >= sidePanelMinShrink && outerDelta <= 40) {
                        sidePanelLastLogged = Date.now();
                        logEvent('possible_ai_tool', {
                            reason: 'browser_side_panel_opened',
                            innerwidth: inner,
                            previousinnerwidth: sidePanelBaseline.inner,
                            outerwidth: outer
                        });
                    }

                    sidePanelBaseline = {inner: inner, outer: outer, dpr: dpr};
                };

                window.addEventListener('resize', function() {
                    if (sidePanelResizeTimer) {
                        window.clearTimeout(sidePanelResizeTimer);
                    }
                    sidePanelResizeTimer = window.setTimeout(evaluateSidePanel, 500);
                }, true);

                // A panel opened before the attempt page loaded never fires a resize
                // event; a large window-vs-viewport width gap betrays it instead. Only
                // checked at integer zoom levels, where the two are directly comparable.
                if (Math.abs((window.devicePixelRatio || 1) - Math.round(window.devicePixelRatio || 1)) < 0.01 &&
                        (window.outerWidth || 0) > 0 &&
                        (window.outerWidth - window.innerWidth) >= 300) {
                    logEvent('possible_ai_tool', {
                        reason: 'browser_side_panel_present',
                        innerwidth: window.innerWidth || 0,
                        outerwidth: window.outerWidth || 0
                    });
                }

                window.addEventListener('pagehide', function() {
                    logEvent('page_exit', {
                        reason: 'pagehide'
                    });
                }, true);
            }
            // Leaving the page must not drop an away event still waiting for its frame: submit it
            // now, before the upload queue is suspended, with the frame from the event itself.
            window.addEventListener('pagehide', function() {
                settleAwayCaptures(false);
            }, true);

            return {
                suspend: function() {
                    monitoringActive = false;
                    intervals.forEach(entry => window.clearInterval(entry.id));
                    if (screenGateTimer) {
                        window.clearTimeout(screenGateTimer);
                    }
                    Object.values(attemptWarningTimers).forEach(timer => window.clearTimeout(timer));
                    stopScreenStream();
                    latestDesktopFrame = '';
                    latestDesktopTime = 0;
                    phoneEvidenceFrame = '';
                    webcamEvidenceFrame = '';
                    awayEvidenceFrame = '';
                    stopAwayCaptures();
                    multiFaceConsecutive = 0;
                    if (phoneCanvas) {
                        phoneCanvas.width = 0;
                        phoneCanvas.height = 0;
                    }
                    if (screenMonitorClient && screenMonitorClient.stop) {
                        screenMonitorClient.stop();
                    }
                },
                resume: function() {
                    monitoringActive = true;
                    intervals.forEach(entry => { entry.id = window.setInterval(entry.callback, entry.delay); });
                    if (screenMonitorClient) {
                        screenMonitorClient.start();
                    } else if (captureDesktop) {
                        showScreenShareGate();
                    }
                }
            };
        };

        return {
            async setup(props, modelurl) {
                // Anchor before strings/models load; retries preserve each frame's original time.
                const captureClock = createCaptureClock(props);
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
                takepicturedelay = Math.max(5000, parseInt(props.camshotdelay, 10) || 30000);
                // The per-capture face crop and "face not found" notice belong to face matching and
                // the no-face blur, not to multiple-face detection, which only borrows the model.
                const captureFaceCheck = faceModelReady && parseInt(props.facemodelformultiplefacesonly || 0, 10) !== 1;
                let captureFaceMisses = 0;
                // Quiz core renders a lone tertiary-nav "Back" link during attempts;
                // on a proctored attempt it only walks students out of the exam
                // mid-attempt (and fires focus-loss violations on the way).
                const backnav = document.querySelector('.tertiary-navigation');
                if (backnav) {
                    backnav.style.display = 'none';
                }
                // Close the course index drawer for the attempt. It lists every activity in the
                // course, so on a proctored attempt it is a row of links out of the exam - and
                // leaving it open also squeezes the question area on smaller screens. Only the
                // opening state is touched: a student who wants it can still open it, and the
                // preference is left alone so it reopens on the next page outside the exam.
                closeCourseIndexDrawer();
                // Skip for summary page.
                if (document.getElementById("page-mod-quiz-summary") !== null &&
                    document.getElementById("page-mod-quiz-summary").innerHTML.length) {
                    return false;
                }
                if (document.getElementById("page-mod-quiz-review") !== null &&
                    document.getElementById("page-mod-quiz-review").innerHTML.length) {
                    return false;
                }

                const uploads = createUploadController(strings, captureClock);
                let monitoring = null;
                let pageActive = true;
                let pageGeneration = 0;
                let captureBusy = false;
                let cameraStream = null;
                let captureTimer = null;
                let firstCaptureTimer = null;
                if (parseInt(props.monitorbrowseractivity, 10) === 1 ||
                        parseInt(props.blockclipboard, 10) === 1 ||
                        parseInt(props.monitormouseactivity || 0, 10) === 1 ||
                        parseInt(props.captureviolationdesktop, 10) === 1 ||
                        parseInt(props.blurquizwithmultiplemonitors || 0, 10) === 1 ||
                        parseInt(props.detectphone || 0, 10) === 1 ||
                        (parseInt(props.detectmultiplefaces || 0, 10) === 1 && faceModelReady) ||
                        ['log', 'warn', 'block'].includes(props.multimonitormode)) {
                    props.facemodelready = faceModelReady ? 1 : 0;
                    monitoring = initSuspiciousActivityMonitoring(props, strings, uploads, captureClock);
                }

                const width = Math.max(240, parseInt(props.image_width, 10) || 480);
                let height = 0; // This will be computed based on the input stream.
                let streaming = false;
                let data = null;

                const webcamBox = $(`<div class="proctoring-fixed-webcam-box d-flex">`
                    + '<video id="video" autoplay muted playsinline webkit-playsinline>'
                    + `${strings.videonotavailable}</video>`
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
                const faceBlurMinScore = Math.min(0.95, Math.max(0.10, parseFloat(props.faceblurminscore || 0.30)));
                const faceBlurMisses = Math.min(20, Math.max(1, parseInt(props.faceblurmisses || 4, 10)));
                const faceBlurHits = Math.min(10, Math.max(1, parseInt(props.faceblurhits || 1, 10)));
                const faceBlurInitialGraceMs = Math.min(
                    60000,
                    Math.max(0, parseInt(props.faceblurinitialgrace || 10, 10) * 1000)
                );
                let faceBlurTimer = null;
                let faceBlurChecking = false;
                let facePresentCount = 0;
                let faceMissingCount = 0;
                let facePauseStartedAt = 0;
                let facePauseCanvas = null;

                // Every pause is logged, with how long it lasted, so a reviewer sees each one rather
                // than only the capped "no face" points (CPIT-470). The start carries the webcam frame.
                // The upload queue is disposed as the page goes away, dropping anything still waiting
                // in it, so an event sent while leaving goes out as a keepalive request instead, which
                // the browser completes after the page has gone.
                const sendWhileLeaving = (request) => {
                    const cfg = window.M && window.M.cfg;
                    if (!window.fetch || !cfg || !cfg.sesskey || !cfg.wwwroot) {
                        return false;
                    }
                    try {
                        window.fetch(cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + encodeURIComponent(cfg.sesskey) +
                            '&info=' + encodeURIComponent(request.methodname), {
                            method: 'POST',
                            keepalive: true,
                            credentials: 'same-origin',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify([{index: 0, methodname: request.methodname, args: request.args}])
                        }).catch(() => undefined);
                        return true;
                    } catch (error) {
                        return false;
                    }
                };
                const logFacePause = (eventType, detail, screenshot, leaving) => {
                    const capturedat = Math.floor(captureClock() / 1000);
                    const request = {
                        methodname: 'quizaccess_proctoring_log_event',
                        args: {
                            courseid: parseInt(props.courseid, 10) || 0,
                            quizid: parseInt(props.quizid, 10) || 0,
                            attemptid: parseInt(props.status, 10) || 0,
                            reportid: parseInt(props.id, 10) || 0,
                            eventtype: eventType,
                            eventdetail: JSON.stringify(detail || {}),
                            pagevisibility: document.visibilityState || '',
                            currenturl: window.location.href,
                            screenshot: screenshot || ''
                        }
                    };
                    if (leaving) {
                        request.args.capturedat = capturedat;
                        request.args.requestid = 'pause-' + capturedat + '-' + Math.random().toString(36).slice(2, 10);
                        // Also queued under the same request id: if the page stays (a cancelled leave)
                        // and the keepalive request failed, the queue still delivers it, and the server
                        // ingests one request id only once.
                        sendWhileLeaving(request);
                    }
                    uploads.submit(request, capturedat);
                };
                const captureFacePauseFrame = () => {
                    if (!video || !video.videoWidth || !video.videoHeight) {
                        return '';
                    }
                    facePauseCanvas = facePauseCanvas || document.createElement('canvas');
                    const frameWidth = Math.min(640, video.videoWidth);
                    facePauseCanvas.width = frameWidth;
                    facePauseCanvas.height = Math.round(video.videoHeight * (frameWidth / video.videoWidth));
                    facePauseCanvas.getContext('2d').drawImage(video, 0, 0, facePauseCanvas.width, facePauseCanvas.height);
                    return facePauseCanvas.toDataURL('image/jpeg', 0.8);
                };
                // Ending a pause because the page is starting to go is a guess: the student can cancel
                // at an "unsaved changes" prompt, or a later handler can stop the submit. So the end is
                // sent at once, and the pause carries straight on as a continuation timed from that
                // moment. The continuation is only logged (a start marked continued, then its end) if
                // the page stays; if the page really goes, its few milliseconds are simply dropped. Its
                // time adds to the same pause in the report; it is not another pause.
                let facePauseContinuation = false;
                const endFacePause = (reason, leaving) => {
                    if (!facePauseStartedAt) {
                        return;
                    }
                    const seconds = Math.max(0, Math.round((Date.now() - facePauseStartedAt) / 1000));
                    if (facePauseContinuation) {
                        logFacePause('face_missing_start', {reason: 'still_paused', continued: true}, '', leaving);
                    }
                    logFacePause('face_missing_end', {durationseconds: seconds, reason: reason}, '', leaving);
                    facePauseStartedAt = 0;
                    facePauseContinuation = false;
                };
                const endFacePauseForLeaving = () => {
                    // A submit is followed straight away by beforeunload; the continuation that the
                    // first one began has nothing in it yet.
                    if (!facePauseStartedAt || (facePauseContinuation && Date.now() - facePauseStartedAt < 2000)) {
                        return;
                    }
                    endFacePause('page_left', true);
                    facePauseStartedAt = Date.now();
                    facePauseContinuation = true;
                };
                const endFacePauseOnPagehide = () => {
                    if (facePauseContinuation && Date.now() - facePauseStartedAt < 2000) {
                        // Ended a moment ago as the page began to go, and it did go.
                        facePauseStartedAt = 0;
                        facePauseContinuation = false;
                        return;
                    }
                    // Either nothing ended the pause early, or the student spent a while at a leave
                    // prompt before going: that time is logged too.
                    endFacePause('page_left', true);
                };

                const setQuizBlurredForFace = (blurred) => {
                    const wasBlurred = document.body.classList.contains('proctoring-face-blur-active');
                    document.body.classList.toggle('proctoring-face-blur-active', blurred);
                    const notice = document.getElementById('proctoring-face-blur-notice');
                    if (notice) {
                        notice.style.display = blurred ? 'block' : 'none';
                    }
                    if (!pageActive) {
                        return;
                    }
                    if (blurred && !wasBlurred) {
                        facePauseStartedAt = Date.now();
                        logFacePause('face_missing_start', {reason: 'no_face_in_view'}, captureFacePauseFrame());
                    } else if (!blurred && wasBlurred) {
                        endFacePause('face_back_in_view');
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

                    // The grace period runs once per attempt, from its first page, not again on every
                    // page: short questions would otherwise keep the check inside it (CPIT-470).
                    let graceStartedAt = Date.now();
                    const graceKey = 'quizaccess_proctoring_face_grace_' + (parseInt(props.status, 10) || 0);
                    try {
                        const stored = parseInt(window.sessionStorage.getItem(graceKey) || '', 10);
                        if (stored > 0 && stored <= graceStartedAt) {
                            graceStartedAt = stored;
                        } else {
                            window.sessionStorage.setItem(graceKey, String(graceStartedAt));
                        }
                    } catch (error) {
                        // Storage unavailable: the grace period then runs on each page, as before.
                    }
                    const graceEndsAt = graceStartedAt + faceBlurInitialGraceMs;
                    setQuizBlurredForFace(false);

                    const checkFaceVisibility = async() => {
                        if (faceBlurChecking) {
                            return;
                        }

                        const graceActive = Date.now() < graceEndsAt;
                        if (!video.videoWidth || !video.videoHeight) {
                            if (!graceActive) {
                                faceMissingCount++;
                                facePresentCount = 0;
                                if (faceMissingCount >= faceBlurMisses) {
                                    setQuizBlurredForFace(true);
                                }
                            }
                            return;
                        }

                        faceBlurChecking = true;
                        try {
                            // eslint-disable-next-line no-undef
                            const detections = await withTimeout(faceapi.detectAllFaces(
                                video,
                                // eslint-disable-next-line no-undef
                                new faceapi.SsdMobilenetv1Options({minConfidence: faceBlurMinScore})
                            ), DETECTION_TIMEOUT_MS);
                            const faceVisible = detections.some((detection) => detection.score >= faceBlurMinScore);
                            if (faceVisible) {
                                facePresentCount++;
                                faceMissingCount = 0;
                                if (facePresentCount >= faceBlurHits) {
                                    setQuizBlurredForFace(false);
                                }
                            } else if (!graceActive) {
                                faceMissingCount++;
                                facePresentCount = 0;
                                if (faceMissingCount >= faceBlurMisses) {
                                    setQuizBlurredForFace(true);
                                }
                            }
                        } catch (error) {
                            // A check that failed or timed out saw nothing either way: it is neither a
                            // face nor a miss. Counting it as a miss would pause the quiz on a slow device
                            // that never completes a check, with no way for the student to resume.
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
                    if (!pageActive || captureBusy) {
                        return;
                    }
                    const tracks = video && video.srcObject && video.srcObject.getVideoTracks
                        ? video.srcObject.getVideoTracks() : [];
                    if (!tracks.length || tracks.every(track => track.readyState === 'ended' || track.muted) ||
                            !video.videoWidth || !video.videoHeight || video.paused || video.ended) {
                        uploads.device('camera', true);
                        return;
                    }
                    captureBusy = true;
                    const capturedat = Math.floor(captureClock() / 1000);
                    const generation = pageGeneration;
                    try {
                        const context = canvas.getContext('2d');
                        if (width && height) {
                            canvas.width = width;
                            canvas.height = height;
                            context.drawImage(video, 0, 0, width, height);
                            data = canvas.toDataURL('image/png');
                            photo.setAttribute('src', data);
                            props.webcampicture = data;

                            let croppedImage = document.getElementById('cropimg');
                            if (croppedImage) {
                                croppedImage.removeAttribute('src');
                            }
                            let faceChecked = captureFaceCheck;
                            if (captureFaceCheck) {
                                try {
                                    const crop = await withTimeout(detectface(photo, faceBlurMinScore), DETECTION_TIMEOUT_MS);
                                    if (crop && croppedImage) {
                                        croppedImage.src = crop;
                                    }
                                } catch (error) {
                                    // A check that failed or timed out found nothing either way.
                                    faceChecked = false;
                                    if (croppedImage) {
                                        croppedImage.removeAttribute('src');
                                    }
                                }
                            }
                            if (!pageActive || generation !== pageGeneration) {
                                if (croppedImage) {
                                    croppedImage.removeAttribute('src');
                                }
                                return;
                            }
                            let faceFound;
                            let faceImage;
                            if (!faceChecked) {
                                // Not checked: without the face model, or when the check failed,
                                // nothing looked for a face, so this capture is neither a face nor
                                // a miss (CPIT-469, CPIT-470).
                                faceFound = 2;
                                faceImage = "";
                            } else if (croppedImage && croppedImage.getAttribute('src')) {
                                captureFaceMisses = 0;
                                removeNotifications();
                                faceFound = 1;
                                faceImage = croppedImage.src;
                            } else {
                                // One small frame is easily missed in low light or with a turned
                                // head, so only warn the student after two misses in a row.
                                captureFaceMisses++;
                                if (captureFaceMisses >= 2) {
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

                            if (pageActive) {
                                uploads.device('camera', false);
                                uploads.submit(request, capturedat);
                            }
                        } else {
                            uploads.device('camera', true);
                            clearphoto();
                        }
                    } catch (error) {
                        if (pageActive && generation === pageGeneration) {
                            uploads.device('camera', true);
                        }
                    } finally {
                        captureBusy = false;
                    }
                };

                const startCamera = function() {
                    const generation = pageGeneration;
                    requestUserCamera()
                    // eslint-disable-next-line promise/always-return
                    .then(async function(stream) {
                        if (!pageActive || generation !== pageGeneration) {
                            stream.getTracks().forEach(track => track.stop());
                            return;
                        }
                        cameraStream = stream;
                        video.srcObject = stream;
                        await video.play();
                        if (!pageActive || generation !== pageGeneration) {
                            return;
                        }
                        isCameraAllowed = true;
                        initFaceVisibilityBlur();
                        const reportCamera = missing => {
                            if (pageActive && generation === pageGeneration) {
                                uploads.device('camera', missing);
                            }
                        };
                        stream.getVideoTracks().forEach(track => {
                            track.addEventListener('ended', () => reportCamera(true));
                            track.addEventListener('mute', () => reportCamera(true));
                            track.addEventListener('unmute', () => reportCamera(false));
                        });
                    })
                    .catch(function() {
                        if (!pageActive || generation !== pageGeneration) {
                            return;
                        }
                        if (cameraStream) {
                            cameraStream.getTracks().forEach(track => track.stop());
                            cameraStream = null;
                        }
                        uploads.device('camera', true);
                        hideButtons();
                    });
                };
                startCamera();

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
                    firstCaptureTimer = window.setTimeout(takepicture, firstcalldelay);
                    captureTimer = window.setInterval(takepicture, takepicturedelay);
                } else {
                    hideButtons();
                }

                // A pause still running when the page is left ends as the page starts to go: on a form
                // submit, or before unload (which also covers the quiz timer's automatic submission).
                // Both come before the server finishes the attempt; an end logged afterwards would
                // be timestamped after the attempt finished and refused. pagehide is the last resort.
                document.addEventListener('submit', endFacePauseForLeaving, true);
                window.addEventListener('beforeunload', endFacePauseForLeaving, true);
                window.addEventListener('pagehide', function() {
                    // A pause still running when the page is left ends here, before uploads stop.
                    endFacePauseOnPagehide();
                    pageActive = false;
                    pageGeneration++;
                    uploads.suspend();
                    if (monitoring) {
                        monitoring.suspend();
                    }
                    window.clearTimeout(firstCaptureTimer);
                    window.clearInterval(captureTimer);
                    if (faceBlurTimer) {
                        window.clearInterval(faceBlurTimer);
                        faceBlurTimer = null;
                    }
                    if (cameraStream) {
                        cameraStream.getTracks().forEach(track => track.stop());
                        cameraStream = null;
                    }
                    if (video) {
                        video.srcObject = null;
                    }
                    streaming = false;
                    if (canvas) {
                        canvas.width = 0;
                        canvas.height = 0;
                    }
                    // Release the last displayed/base64 captures too, not only the recovery queue.
                    data = null;
                    delete props.webcampicture;
                    if (photo) {
                        photo.removeAttribute('src');
                    }
                    const crop = document.getElementById('cropimg');
                    if (crop) {
                        crop.removeAttribute('src');
                    }
                });
                window.addEventListener('pageshow', function(event) {
                    if (!event.persisted || pageActive) {
                        return;
                    }
                    pageActive = true;
                    uploads.resume();
                    if (monitoring) {
                        monitoring.resume();
                    }
                    startCamera();
                    firstCaptureTimer = window.setTimeout(takepicture, firstcalldelay);
                    captureTimer = window.setInterval(takepicture, takepicturedelay);
                });

                return true;
            },
            async init(props) {
                let height = 0; // This will be computed based on the input stream.
                let streaming = false;
                let video = null;
                let canvas = null;
                let photo = null;
                let data = null;
                const width = Math.max(240, parseInt(props.image_width, 10) || 480);

                /**
                 * Startup
                 */
                async function startup() {
                    video = document.getElementById('video');
                    canvas = document.getElementById('canvas');
                    photo = document.getElementById('photo');

                    if (video) {
                        // Camera acquisition is deferred until the Pre-Check modal is opened, so the
                        // camera is never activated on activity page load (Req 6.1). Acquisition and
                        // teardown are scoped to the modal lifecycle (Req 6.2, 6.3).
                        bindPrecheckModalCamera(video);

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
                        }).fail(function(error) {
                            handleUploadFailure(strings, error);
                        });

                    } else {
                        clearphoto();
                    }
                }

                /**
                 * Bind the Pre-Check camera lifecycle to the modal that hosts the webcam.
                 *
                 * The camera is only acquired once the Pre-Check modal becomes visible (Req 6.2) and
                 * is released whenever the modal is hidden/cancelled, the student aborts/exits, or the
                 * page is navigated away (Req 6.3). This keeps the camera off on activity page load
                 * (Req 6.1). Mirrors the stopIdDocumentStream() teardown approach in startAttempt.js.
                 *
                 * @param {HTMLVideoElement} modalvideo The Pre-Check modal <video> element.
                 */
                function bindPrecheckModalCamera(modalvideo) {
                    let acquiring = false;
                    let acquireFailed = false;

                    // The modal is considered open when its video element is laid out/visible.
                    const isModalOpen = function() {
                        return modalvideo.offsetParent !== null || modalvideo.getClientRects().length > 0;
                    };

                    const openCamera = function() {
                        if (precheckStream || acquiring || acquireFailed) {
                            return;
                        }
                        acquiring = true;
                        acquirePrecheckCamera(modalvideo)
                            // eslint-disable-next-line promise/always-return
                            .then(function() {
                                acquiring = false;
                                Notification.addNotification({
                                    message: props.cameraallow,
                                    type: 'success' // Success notification type.
                                });
                            })
                            .catch(function() {
                                acquiring = false;
                                acquireFailed = true;
                                Notification.addNotification({
                                    message: props.allowcamerawarning,
                                    type: 'warning'
                                });
                                hideButtons();
                            });
                    };

                    const closeCamera = function() {
                        if (precheckStream) {
                            teardownPrecheckCamera(modalvideo);
                        }
                        // Allow a fresh acquisition attempt the next time the modal is opened.
                        acquireFailed = false;
                    };

                    const syncCameraWithModal = function() {
                        if (isModalOpen()) {
                            openCamera();
                        } else {
                            closeCamera();
                        }
                    };

                    // React to the modal being inserted/removed or shown/hidden in the DOM.
                    if (typeof MutationObserver === 'function') {
                        const observer = new MutationObserver(syncCameraWithModal);
                        observer.observe(document.body, {childList: true, subtree: true});
                    }
                    // Backstop poll for themes that toggle visibility without DOM mutations.
                    window.setInterval(syncCameraWithModal, 750);
                    // Evaluate the initial state without forcing acquisition on page load.
                    syncCameraWithModal();

                    // Release the device when the student explicitly cancels/aborts the Pre-Check modal.
                    document.addEventListener('click', function(ev) {
                        const target = ev.target;
                        if (!target || typeof target.closest !== 'function') {
                            return;
                        }
                        if (target.closest('.mod_quiz_preflight_popup .closebutton, ' +
                                '.mod_quiz_preflight_popup [name="cancel"], ' +
                                '.moodle-dialogue .closebutton')) {
                            teardownPrecheckCamera(modalvideo);
                        }
                    }, true);

                    // Release the device on navigation away from the activity (Req 6.3).
                    window.addEventListener('beforeunload', function() {
                        teardownPrecheckCamera(modalvideo);
                    });
                    window.addEventListener('pagehide', function() {
                        teardownPrecheckCamera(modalvideo);
                    });
                }

                await startup();

                return data;
            }
        };
    });
