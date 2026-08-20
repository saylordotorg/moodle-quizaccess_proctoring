// @SuppressWarnings("javascript:S4144");
let isCameraAllowed = false;
// Module-scoped handle to the MediaStream acquired for the Pre-Check modal (Req 6.2).
let precheckStream = null;

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

        const initSuspiciousActivityMonitoring = function(props, strings) {
            let lastLogged = {};
            let hiddenStarted = 0;
            const throttleMs = 5000;
            const aiPattern = /(gemini|chatgpt|openai|copilot|claude|perplexity|bard|ask\s+gemini|ask\s+ai)/i;
            const monitorActivity = parseInt(props.monitorbrowseractivity, 10) === 1;
            const blockClipboard = parseInt(props.blockclipboard, 10) === 1;
            const captureDesktop = parseInt(props.captureviolationdesktop, 10) === 1;
            const desktopPointerEnvironment = !(/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i)
                .test(navigator.userAgent || '') &&
                !(window.matchMedia &&
                    window.matchMedia('(pointer: coarse) and (max-width: 1024px)').matches);
            const monitorMouseActivity = parseInt(props.monitormouseactivity || 0, 10) === 1 &&
                desktopPointerEnvironment;
            const detectPhone = parseInt(props.detectphone || 0, 10) === 1 && !!props.phonedetectliburl;
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
                'tab_hidden',
                'focus_lost',
                'clipboard_copy',
                'clipboard_cut',
                'clipboard_paste',
                'contextmenu',
                'mouse_left_window',
                'mouse_returned_window',
                'shortcut',
                'possible_ai_tool',
                'page_exit',
                'screen_marker_missing',
                'multiple_monitors_detected'
            ];
            let screenStream = null;
            let screenVideo = null;
            let screenCanvas = null;
            let screenReady = false;
            let markerLastSeen = 0;
            let markerMissingLoggedAt = 0;
            let markerFaulted = false;
            let markerCheckTimer = null;
            let screenGateTimer = null;
            let screenMonitorClient = null;
            let latestDesktopFrame = '';
            let multiMonitorLastState = '';
            let focusLostSince = 0;
            let suppressFocusLossUntil = 0;
            let phoneModel = null;
            let phoneCanvas = null;
            let phoneConsecutive = 0;
            let phoneLastLogged = 0;
            let phoneEvidenceFrame = '';
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
                const dock = document.createElement('div');
                dock.id = 'proctoring-attempt-warning-dock';
                dock.className = 'proctoring-attempt-warning-dock';
                dock.appendChild(warning);
                document.body.appendChild(dock);

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

                if (!await waitForScreenFrame()) {
                    stopScreenStream();
                    setScreenShareStatus(strings.screensharedenied, 'danger');
                    return;
                }

                videoTrack.addEventListener('ended', function() {
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
                if (!phoneModel || !video || !video.videoWidth || !video.videoHeight ||
                        document.visibilityState === 'hidden') {
                    return;
                }

                let predictions = [];
                try {
                    predictions = await phoneModel.detect(video) || [];
                } catch (error) {
                    return;
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

                window.setInterval(checkPhoneFrame, phoneCheckIntervalMs);
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
                    screenMonitorClient = ScreenMonitorClient.create(props, {
                        onReady: function() {
                            screenReady = true;
                            setScreenShareStatus(strings.screenshareaccepted, 'success');
                            clearAttemptWarning('wrongscreen');
                            clearAttemptWarning('screenshare');
                            hideScreenShareGate();
                        },
                        onUnavailable: function() {
                            if (screenReady) {
                                screenReady = false;
                                logEvent('screen_share_stopped', {
                                    reason: 'persistent_monitor_unavailable'
                                });
                                setScreenShareStatus(strings.screensharestopped, 'danger');
                                clearAttemptWarning('wrongscreen');
                                setAttemptWarning('screenshare', strings.attemptwarningscreensharestopped, 'danger');
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
                            setAttemptWarning('wrongscreen', strings.attemptwarningwrongscreen, 'danger');
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
                        !(captureDesktop && screenShareEvents.includes(eventType)) &&
                        !(monitorDetectionEnabled && multiMonitorEvents.includes(eventType)) &&
                        !(monitorMouseActivity && mouseEvents.includes(eventType)) &&
                        !(detectPhone && eventType === 'phone_detected')) {
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
                    screenshot: eventType === 'phone_detected' ? phoneEvidenceFrame : captureDesktopFrame(eventType)
                };

                Ajax.call([{
                    methodname: 'quizaccess_proctoring_log_event',
                    args: args
                }])[0].fail(function() {
                    // Do not interrupt the quiz attempt if activity logging is unavailable.
                });
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
            initPhoneDetection();
            checkMultiMonitorSetup();
            if (monitorDetectionEnabled) {
                window.setInterval(checkMultiMonitorSetup, 60000);
                window.addEventListener('focus', checkMultiMonitorSetup, true);
            }

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
                    if (Date.now() < suppressFocusLossUntil) {
                        return;
                    }
                    focusLostSince = Date.now();
                    logEvent('focus_lost', {
                        reason: 'window_blur'
                    });
                }, true);

                window.addEventListener('focus', function() {
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

                if (parseInt(props.monitorbrowseractivity, 10) === 1 ||
                        parseInt(props.blockclipboard, 10) === 1 ||
                        parseInt(props.monitormouseactivity || 0, 10) === 1 ||
                        parseInt(props.captureviolationdesktop, 10) === 1 ||
                        parseInt(props.blurquizwithmultiplemonitors || 0, 10) === 1 ||
                        parseInt(props.detectphone || 0, 10) === 1 ||
                        ['log', 'warn', 'block'].includes(props.multimonitormode)) {
                    initSuspiciousActivityMonitoring(props, strings);
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

                    const graceEndsAt = Date.now() + faceBlurInitialGraceMs;
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
                            const detections = await faceapi.detectAllFaces(video);
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
                            if (!graceActive) {
                                faceMissingCount++;
                                facePresentCount = 0;
                                if (faceMissingCount >= faceBlurMisses) {
                                    setQuizBlurredForFace(true);
                                }
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

                requestUserCamera()
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
                        }).fail(Notification.exception);

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
