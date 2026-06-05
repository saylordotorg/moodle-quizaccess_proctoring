define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'quizaccess_proctoring/screenMonitorClient'],
    function($, Ajax, Notification, Str, ScreenMonitorClient) {
        const loadStrings = async function() {
            const stringkeys = [
                {key: 'facematched', component: 'quizaccess_proctoring'},
                {key: 'photonotuploaded', component: 'quizaccess_proctoring'},
                {key: 'invalidapi', component: 'quizaccess_proctoring'},
                {key: 'facenotmatched', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_image', component: 'quizaccess_proctoring'},
                {key: 'faceregistered', component: 'quizaccess_proctoring'},
                {key: 'facenotfoundoncam', component: 'quizaccess_proctoring'},
                {key: 'facequalityfailed', component: 'quizaccess_proctoring'},
                {key: 'screenshareaccepted', component: 'quizaccess_proctoring'},
                {key: 'entirescreenrequired', component: 'quizaccess_proctoring'},
                {key: 'screensharedenied', component: 'quizaccess_proctoring'},
                {key: 'screensharenotsupported', component: 'quizaccess_proctoring'},
                {key: 'screensharestopped', component: 'quizaccess_proctoring'},
                {key: 'screenmarkerlabel', component: 'quizaccess_proctoring'},
                {key: 'screenmarkerwrongmonitor', component: 'quizaccess_proctoring'},
                {key: 'screenmonitor:windowopened', component: 'quizaccess_proctoring'},
                {key: 'screenmonitor:popupblocked', component: 'quizaccess_proctoring'},
                {key: 'preflight:complete', component: 'quizaccess_proctoring'},
                {key: 'preflight:actionneeded', component: 'quizaccess_proctoring'},
                {key: 'modal:pending', component: 'quizaccess_proctoring'},
                {key: 'preflight:submitlocked', component: 'quizaccess_proctoring'},
                {key: 'multimonitor:detected', component: 'quizaccess_proctoring'},
                {key: 'multimonitor:warning', component: 'quizaccess_proctoring'},
                {key: 'multimonitor:single', component: 'quizaccess_proctoring'},
                {key: 'multimonitor:unavailable', component: 'quizaccess_proctoring'},
                {key: 'multimonitor:blockmessage', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationdocumentmissing', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationdocumentbackmissing', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationfailed', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationpassed', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationprovidererror', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationretry', component: 'quizaccess_proctoring'},
                {key: 'videonotavailable', component: 'quizaccess_proctoring'},
            ];
            try {
                const strings = await Str.get_strings(stringkeys);
                return {
                    facematched: strings[0],
                    photonotuploaded: strings[1],
                    invalidapi: strings[2],
                    facenotmatched: strings[3],
                    wrongduringtakingimage: strings[4],
                    faceregistered: strings[5],
                    facenotfoundoncam: strings[6],
                    facequalityfailed: strings[7],
                    screenshareaccepted: strings[8],
                    entirescreenrequired: strings[9],
                    screensharedenied: strings[10],
                    screensharenotsupported: strings[11],
                    screensharestopped: strings[12],
                    screenmarkerlabel: strings[13],
                    screenmarkerwrongmonitor: strings[14],
                    screenmonitorwindowopened: strings[15],
                    screenmonitorpopupblocked: strings[16],
                    preflightcomplete: strings[17],
                    preflightactionneeded: strings[18],
                    preflightpending: strings[19],
                    preflightsubmitlocked: strings[20],
                    multimonitordetected: strings[21],
                    multimonitorwarning: strings[22],
                    multimonitorsingle: strings[23],
                    multimonitorunavailable: strings[24],
                    multimonitorblockmessage: strings[25],
                    idverificationdocumentmissing: strings[26],
                    idverificationdocumentbackmissing: strings[27],
                    idverificationfailed: strings[28],
                    idverificationpassed: strings[29],
                    idverificationprovidererror: strings[30],
                    idverificationretry: strings[31],
                    videonotavailable: strings[32],
                };
            } catch (error) {
                Notification.exception(error);
                return {}; // Return an empty object in case of an error.
            }
        };

        // Function to draw image from the box data.
        const extractFaceFromBox = async(imageRef, box) => {
            const regionsToExtract = [
                // eslint-disable-next-line no-undef
                new faceapi.Rect(box.x, box.y, box.width, box.height)
            ];
            // eslint-disable-next-line no-undef
            let faceImages = await faceapi.extractFaces(imageRef, regionsToExtract);

            if (faceImages.length !== 0) {
                return faceImages[0].toDataURL('image/png');
            }

            return '';
        };

        const getImageQuality = function(canvas, box) {
            const context = canvas.getContext('2d');
            const x = Math.min(canvas.width - 1, Math.max(0, Math.floor(box.x)));
            const y = Math.min(canvas.height - 1, Math.max(0, Math.floor(box.y)));
            const width = Math.max(1, Math.min(canvas.width - x, Math.floor(box.width)));
            const height = Math.max(1, Math.min(canvas.height - y, Math.floor(box.height)));
            const imageData = context.getImageData(x, y, width, height).data;
            const step = Math.max(1, Math.floor(Math.sqrt((width * height) / 2500)));
            let count = 0;
            let sum = 0;
            let sumsq = 0;
            let edgedelta = 0;
            let edgecount = 0;
            let previousRow = [];

            for (let yy = 0; yy < height; yy += step) {
                const row = [];
                let column = 0;
                for (let xx = 0; xx < width; xx += step) {
                    const index = ((yy * width) + xx) * 4;
                    const luminance = (0.2126 * imageData[index]) +
                        (0.7152 * imageData[index + 1]) +
                        (0.0722 * imageData[index + 2]);
                    row[column] = luminance;
                    sum += luminance;
                    sumsq += luminance * luminance;
                    count++;

                    if (column > 0) {
                        edgedelta += Math.abs(luminance - row[column - 1]);
                        edgecount++;
                    }
                    if (previousRow[column] !== undefined) {
                        edgedelta += Math.abs(luminance - previousRow[column]);
                        edgecount++;
                    }
                    column++;
                }
                previousRow = row;
            }

            const brightness = count > 0 ? sum / count : 0;
            const variance = count > 0 ? Math.max(0, (sumsq / count) - (brightness * brightness)) : 0;
            return {
                brightness: brightness,
                contrast: Math.sqrt(variance),
                sharpness: edgecount > 0 ? edgedelta / edgecount : 0,
            };
        };

        const getMinFaceRatio = function(canvas, box) {
            return Math.min(box.width, box.height) / Math.min(canvas.width, canvas.height);
        };

        const getFaceAreaRatio = function(canvas, box) {
            return (box.width * box.height) / (canvas.width * canvas.height);
        };

        const isCandidateFace = function(canvas, detection) {
            return detection.score >= 0.45 && getMinFaceRatio(canvas, detection.box) >= 0.08;
        };

        const isLikelyPersonFace = function(canvas, detection) {
            return detection.score >= 0.65 && getMinFaceRatio(canvas, detection.box) >= 0.16;
        };

        const hasClearFace = function(canvas, detection) {
            const box = detection.box;
            const minFaceRatio = getMinFaceRatio(canvas, box);
            const centerOffsetX = Math.abs((box.x + (box.width / 2)) - (canvas.width / 2)) / canvas.width;
            const centerOffsetY = Math.abs((box.y + (box.height / 2)) - (canvas.height / 2)) / canvas.height;
            const quality = getImageQuality(canvas, box);

            return detection.score >= 0.45 &&
                minFaceRatio >= 0.08 &&
                centerOffsetX <= 0.45 &&
                centerOffsetY <= 0.45 &&
                box.x >= -5 &&
                box.y >= -5 &&
                (box.x + box.width) <= (canvas.width + 5) &&
                (box.y + box.height) <= (canvas.height + 5) &&
                quality.brightness >= 25 &&
                quality.brightness <= 235 &&
                quality.contrast >= 5 &&
                quality.sharpness >= 1;
        };

        const detectface = async(input, canvas, croppedImage) => {
            // eslint-disable-next-line no-undef
            const output = await faceapi.detectAllFaces(input);
            const candidates = output.filter((detection) => isCandidateFace(canvas, detection))
                .sort((first, second) => getFaceAreaRatio(canvas, second.box) - getFaceAreaRatio(canvas, first.box));
            const likelypeople = candidates.filter((detection) => isLikelyPersonFace(canvas, detection));
            const detection = likelypeople[0] || candidates[0];

            if (!detection || likelypeople.length > 1 || !hasClearFace(canvas, detection)) {
                return {
                    faceFound: 0,
                    faceImage: '',
                    qualityPassed: false,
                };
            }

            const faceImage = await extractFaceFromBox(input, detection.box);
            if (faceImage) {
                croppedImage.setAttribute('src', faceImage);
                return {
                    faceFound: 1,
                    faceImage: faceImage,
                    qualityPassed: true,
                };
            }

            return {
                faceFound: 0,
                faceImage: '',
                qualityPassed: false,
            };
        };
        return {
            setup: async function(props, modelurl) {
                const strings = await loadStrings(); // Load localized strings.

                if (modelurl !== null) {
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
                }

                const submitButton = $("#id_submitbutton");
                const actionBar = $("#form_activate");
                const faceRequired = parseInt(props.faceidcheck, 10) === 1;
                const screenRequired = parseInt(props.requireentirescreen, 10) === 1;
                const screenMarkerRequired = parseInt(
                    props.screenmarkerrequired === undefined ? 1 : props.screenmarkerrequired,
                    10
                ) === 1;
                const privacyRequired = parseInt(props.privacyrequired || 0, 10) === 1;
                const honorRequired = parseInt(props.honorrequired || 0, 10) === 1;
                const captchaRequired = parseInt(props.captcharequired || 0, 10) === 1;
                const identityRequired = parseInt(props.idverificationrequired || 0, 10) === 1;
                const idBackRequired = parseInt(props.idverificationrequireback || 0, 10) === 1;
                const multiMonitorMode = ['log', 'warn', 'block'].includes(props.multimonitormode) ?
                    props.multimonitormode : 'off';
                const multiMonitorBlocks = multiMonitorMode === 'block';
                const submitButtonDefaultLabel = submitButton.is('input') ? submitButton.val() : submitButton.text();
                let faceReady = !faceRequired;
                let screenReady = !screenRequired;
                let privacyReady = !privacyRequired;
                let honorReady = !honorRequired;
                let captchaReady = !captchaRequired;
                let identityReady = !identityRequired || parseInt(props.idverificationpassed || 0, 10) === 1;
                let multiMonitorReady = !multiMonitorBlocks;
                let screenStream = null;
                let screenVideo = null;
                let screenCanvas = null;
                let idLiveStream = null;
                let idDocumentStream = null;
                let idDocumentAutoCaptureTimer = null;
                let idDocumentAutoCaptureScore = 0;
                let idDocumentAutoCaptureRunning = false;
                let activeIdDocumentSide = 'front';
                const capturedIdImages = {
                    front: '',
                    back: '',
                };
                let screenMonitorClient = null;
                const idDocumentAutoCaptureRequiredScore = 8;
                const idDocumentAutoCaptureInterval = 400;
                const markerToken = Math.random().toString(36).slice(2, 8).toUpperCase();

                const escapeHtml = function(text) {
                    return String(text || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                };

                const setRequirementStatus = function(key, state, message) {
                    const item = document.getElementById('proctoring-check-' + key);
                    const status = document.getElementById('proctoring-check-' + key + '-status');
                    if (!item || !status) {
                        return;
                    }

                    item.classList.remove('is-pending', 'is-complete', 'is-action');
                    status.classList.remove('is-pending', 'is-complete', 'is-action');
                    item.classList.add('is-' + state);
                    status.classList.add('is-' + state);
                    status.textContent = message || (
                        state === 'complete'
                            ? strings.preflightcomplete
                            : state === 'action'
                                ? strings.preflightactionneeded
                                : strings.preflightpending
                    );
                };

                const getCameraCaptureSize = function(video) {
                    const configuredWidth = Math.max(240, parseInt(props.imagewidth, 10) || 480);
                    const sourceWidth = video && video.videoWidth ? video.videoWidth : 0;
                    const sourceHeight = video && video.videoHeight ? video.videoHeight : 0;

                    if (!sourceWidth || !sourceHeight) {
                        return {
                            width: configuredWidth,
                            height: Math.round(configuredWidth / (4 / 3)),
                        };
                    }

                    return {
                        width: configuredWidth,
                        height: Math.max(1, Math.round(configuredWidth * (sourceHeight / sourceWidth))),
                    };
                };

                const logPreflightEvent = function(eventType, detail) {
                    Ajax.call([{
                        methodname: 'quizaccess_proctoring_log_event',
                        args: {
                            courseid: parseInt(props.courseid, 10) || 0,
                            quizid: parseInt(props.cmid, 10) || 0,
                            attemptid: parseInt(props.attemptid, 10) || 0,
                            reportid: 0,
                            eventtype: eventType,
                            eventdetail: JSON.stringify(detail || {}),
                            pagevisibility: document.visibilityState || '',
                            currenturl: window.location.href,
                            screenshot: '',
                        }
                    }])[0].fail(function() {});
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

                    if (typeof window.getScreenDetails === 'function') {
                        if (!allowPermissionPrompt) {
                            return {
                                supported: false,
                                multiple: false,
                                count: 0,
                                source: 'getScreenDetails',
                                promptrequired: true,
                            };
                        }
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

                const setMultiMonitorConfirmed = function(confirmed) {
                    const input = document.getElementById('id_multimonitorconfirmed');
                    if (input) {
                        input.value = confirmed ? 1 : 0;
                    }
                };

                const setMultiMonitorResult = function(message, success) {
                    const result = document.getElementById('multi_monitor_result');
                    if (result) {
                        result.innerHTML = `<span style="color: ${success ? 'green' : 'red'}">${escapeHtml(message)}</span>`;
                    }
                };

                const checkMultiMonitorSetup = async function(allowPermissionPrompt = false) {
                    if (multiMonitorMode === 'off') {
                        multiMonitorReady = true;
                        setMultiMonitorConfirmed(true);
                        return;
                    }

                    if (multiMonitorBlocks) {
                        setRequirementStatus('multimonitor', 'pending');
                    }

                    const status = await detectMonitorSetup(allowPermissionPrompt);
                    if (status.promptrequired && multiMonitorBlocks) {
                        multiMonitorReady = false;
                        setMultiMonitorConfirmed(false);
                        setMultiMonitorResult(strings.preflightactionneeded, false);
                        setRequirementStatus('multimonitor', 'action');
                        updatePreflightGate();
                        return;
                    }

                    if (!status.supported) {
                        logPreflightEvent('monitor_detection_unavailable', status);
                        multiMonitorReady = true;
                        setMultiMonitorConfirmed(true);
                        setMultiMonitorResult(strings.multimonitorunavailable, true);
                        if (multiMonitorBlocks) {
                            setRequirementStatus('multimonitor', 'complete', strings.multimonitorunavailable);
                        }
                        updatePreflightGate();
                        return;
                    }

                    if (status.multiple) {
                        logPreflightEvent('multiple_monitors_detected', status);
                        if (multiMonitorMode === 'warn') {
                            Notification.addNotification({
                                message: strings.multimonitorwarning,
                                type: 'warning'
                            });
                        }
                        multiMonitorReady = !multiMonitorBlocks;
                        setMultiMonitorConfirmed(!multiMonitorBlocks);
                        setMultiMonitorResult(strings.multimonitordetected, false);
                        if (multiMonitorBlocks) {
                            setRequirementStatus('multimonitor', 'action', strings.multimonitorblockmessage);
                        }
                    } else {
                        multiMonitorReady = true;
                        setMultiMonitorConfirmed(true);
                        setMultiMonitorResult(strings.multimonitorsingle, true);
                        if (multiMonitorBlocks) {
                            setRequirementStatus('multimonitor', 'complete', strings.multimonitorsingle);
                        }
                    }

                    updatePreflightGate();
                };

                const syncHonorRequirement = function() {
                    if (!honorRequired) {
                        return;
                    }

                    const checkbox = document.querySelector('input[name="proctoring"]');
                    honorReady = !!(checkbox && checkbox.checked);
                    setRequirementStatus('honor', honorReady ? 'complete' : 'pending');
                };

                const syncPrivacyRequirement = function() {
                    if (!privacyRequired) {
                        return;
                    }

                    const checkbox = document.querySelector('input[name="proctoringprivacy"]');
                    privacyReady = !!(checkbox && checkbox.checked);
                    setRequirementStatus('privacy', privacyReady ? 'complete' : 'pending');
                };

                const getCaptchaToken = function() {
                    const turnstile = document.querySelector('input[name="cf-turnstile-response"]');
                    const recaptcha = document.querySelector('textarea[name="g-recaptcha-response"], input[name="g-recaptcha-response"]');

                    if (turnstile && turnstile.value) {
                        return turnstile.value;
                    }
                    if (recaptcha && recaptcha.value) {
                        return recaptcha.value;
                    }

                    return '';
                };

                const syncCaptchaRequirement = function() {
                    if (!captchaRequired) {
                        return;
                    }

                    captchaReady = getCaptchaToken().length > 0;
                    setRequirementStatus('captcha', captchaReady ? 'complete' : 'pending');
                };

                const loadTurnstileApi = function() {
                    if (window.turnstile && typeof window.turnstile.render === 'function') {
                        return Promise.resolve();
                    }

                    if (window.quizaccessProctoringTurnstileLoad) {
                        return window.quizaccessProctoringTurnstileLoad;
                    }

                    window.quizaccessProctoringTurnstileLoad = new Promise(function(resolve, reject) {
                        const existingScript = document.querySelector('script[src*="challenges.cloudflare.com/turnstile"]');
                        if (existingScript) {
                            existingScript.addEventListener('load', resolve, {once: true});
                            existingScript.addEventListener('error', reject, {once: true});
                            window.setTimeout(function() {
                                if (window.turnstile && typeof window.turnstile.render === 'function') {
                                    resolve();
                                }
                            }, 0);
                            window.setTimeout(function() {
                                if (!(window.turnstile && typeof window.turnstile.render === 'function')) {
                                    reject(new Error('Turnstile failed to load.'));
                                }
                            }, 5000);
                            return;
                        }

                        const script = document.createElement('script');
                        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                        script.async = true;
                        script.defer = true;
                        script.addEventListener('load', resolve, {once: true});
                        script.addEventListener('error', reject, {once: true});
                        document.head.appendChild(script);
                    });

                    return window.quizaccessProctoringTurnstileLoad;
                };

                const renderTurnstileWidgets = async function() {
                    const widgets = document.querySelectorAll('.cf-turnstile');
                    if (!captchaRequired || widgets.length === 0) {
                        return;
                    }

                    try {
                        await loadTurnstileApi();
                        widgets.forEach(function(widget) {
                            if (widget.getAttribute('data-proctoring-rendered') === '1' || widget.querySelector('iframe')) {
                                widget.setAttribute('data-proctoring-rendered', '1');
                                return;
                            }

                            const sitekey = widget.getAttribute('data-sitekey');
                            if (!sitekey || !window.turnstile || typeof window.turnstile.render !== 'function') {
                                setRequirementStatus('captcha', 'action');
                                return;
                            }

                            window.turnstile.render(widget, {
                                sitekey: sitekey,
                                theme: widget.getAttribute('data-theme') || 'auto',
                                size: widget.getAttribute('data-size') || 'normal',
                                callback: function() {
                                    syncCaptchaRequirement();
                                    updatePreflightGate();
                                },
                                'expired-callback': function() {
                                    syncCaptchaRequirement();
                                    updatePreflightGate();
                                },
                                'error-callback': function() {
                                    captchaReady = false;
                                    setRequirementStatus('captcha', 'action');
                                    updatePreflightGate();
                                }
                            });
                            widget.setAttribute('data-proctoring-rendered', '1');
                        });
                    } catch (error) {
                        captchaReady = false;
                        setRequirementStatus('captcha', 'action');
                        updatePreflightGate();
                    }
                };

                const setScreenConfirmed = function(confirmed) {
                    const input = document.getElementById('id_entirescreenconfirmed');
                    if (input) {
                        input.value = confirmed ? 1 : 0;
                    }
                };

                const setIdVerificationConfirmed = function(confirmed) {
                    const input = document.getElementById('id_idverificationconfirmed');
                    if (input) {
                        input.value = confirmed ? 1 : 0;
                    }
                };

                const setIdVerificationResult = function(message, success) {
                    const result = document.getElementById('id_verification_result');
                    if (result) {
                        result.innerHTML = `<span style="color: ${success ? 'green' : 'red'}">${escapeHtml(message)}</span>`;
                    }
                };

                const readFileAsDataUrl = function(file) {
                    return new Promise(function(resolve, reject) {
                        const reader = new FileReader();
                        reader.onload = function() {
                            resolve(reader.result);
                        };
                        reader.onerror = reject;
                        reader.readAsDataURL(file);
                    });
                };

                const getIdDocumentSide = function(side) {
                    return side === 'back' ? 'back' : 'front';
                };

                const getIdDocumentSuffix = function(side) {
                    return getIdDocumentSide(side) === 'back' ? '-back' : '';
                };

                const getIdDocumentButtonSuffix = function(side) {
                    return getIdDocumentSide(side) === 'back' ? 'back' : '';
                };

                const getIdDocumentElement = function(side, part) {
                    return document.getElementById('proctoring-id-document' + getIdDocumentSuffix(side) + '-' + part);
                };

                const getIdDocumentInput = function(side) {
                    return document.getElementById('proctoring-id-document' + getIdDocumentSuffix(side));
                };

                const getIdDocumentButton = function(side, action) {
                    return document.getElementById('idverificationdocument' + getIdDocumentButtonSuffix(side) + action);
                };

                const getIdDocumentMissingMessage = function(side) {
                    return getIdDocumentSide(side) === 'back'
                        ? (strings.idverificationdocumentbackmissing || strings.idverificationdocumentmissing)
                        : strings.idverificationdocumentmissing;
                };

                const setIdDocumentCaptureState = function(state, side = activeIdDocumentSide) {
                    const captureSide = getIdDocumentSide(side);
                    const preview = getIdDocumentElement(captureSide, 'preview');
                    const video = getIdDocumentElement(captureSide, 'video');
                    const image = getIdDocumentElement(captureSide, 'preview-image');
                    const guide = preview ? preview.querySelector('.proctoring-id-document-guide') : null;
                    const startButton = getIdDocumentButton(captureSide, 'camera');
                    const captureButton = getIdDocumentButton(captureSide, 'capture');
                    const retakeButton = getIdDocumentButton(captureSide, 'retake');
                    const cameraMode = state === 'camera';
                    const capturedMode = state === 'captured';

                    if (preview) {
                        preview.style.display = cameraMode || capturedMode ? 'block' : 'none';
                    }
                    if (video) {
                        video.style.display = cameraMode ? 'block' : 'none';
                    }
                    if (guide) {
                        guide.style.display = cameraMode ? 'block' : 'none';
                    }
                    if (image) {
                        image.style.display = capturedMode ? 'block' : 'none';
                    }
                    if (startButton) {
                        startButton.style.display = cameraMode || capturedMode ? 'none' : 'inline-flex';
                    }
                    if (captureButton) {
                        captureButton.style.display = cameraMode ? 'inline-flex' : 'none';
                    }
                    if (retakeButton) {
                        retakeButton.style.display = capturedMode ? 'inline-flex' : 'none';
                    }
                };

                const stopIdDocumentAutoCapture = function() {
                    if (idDocumentAutoCaptureTimer) {
                        window.clearInterval(idDocumentAutoCaptureTimer);
                        idDocumentAutoCaptureTimer = null;
                    }
                    idDocumentAutoCaptureScore = 0;
                    idDocumentAutoCaptureRunning = false;
                };

                const waitForVideoFrame = async function(video) {
                    for (let attempts = 0; attempts < 20; attempts++) {
                        if (video && video.videoWidth && video.videoHeight) {
                            return true;
                        }
                        await new Promise((resolve) => window.setTimeout(resolve, 100));
                    }

                    return false;
                };

                const stopIdDocumentStream = function() {
                    stopIdDocumentAutoCapture();
                    if (idDocumentStream) {
                        idDocumentStream.getTracks().forEach((track) => track.stop());
                        idDocumentStream = null;
                    }
                    document.querySelectorAll('.proctoring-id-document-video').forEach(function(video) {
                        video.srcObject = null;
                    });
                };

                const stopIdLiveStream = function() {
                    if (idLiveStream) {
                        idLiveStream.getTracks().forEach((track) => track.stop());
                        idLiveStream = null;
                    }
                };

                const getContainedVideoRect = function(video) {
                    const bounds = video.getBoundingClientRect();
                    const sourceWidth = video.videoWidth || 0;
                    const sourceHeight = video.videoHeight || 0;
                    if (!bounds.width || !bounds.height || !sourceWidth || !sourceHeight) {
                        return null;
                    }

                    const sourceRatio = sourceWidth / sourceHeight;
                    const boundsRatio = bounds.width / bounds.height;
                    if (sourceRatio > boundsRatio) {
                        const height = bounds.width / sourceRatio;
                        return {
                            left: bounds.left,
                            top: bounds.top + ((bounds.height - height) / 2),
                            width: bounds.width,
                            height: height,
                        };
                    }

                    const width = bounds.height * sourceRatio;
                    return {
                        left: bounds.left + ((bounds.width - width) / 2),
                        top: bounds.top,
                        width: width,
                        height: bounds.height,
                    };
                };

                const getIdDocumentGuideSourceRect = function(video, paddingRatio) {
                    const guide = video && video.parentNode
                        ? video.parentNode.querySelector('.proctoring-id-document-guide')
                        : null;
                    const videoRect = getContainedVideoRect(video);
                    if (!guide || !videoRect) {
                        return null;
                    }

                    const guideRect = guide.getBoundingClientRect();
                    if (!guideRect.width || !guideRect.height) {
                        return null;
                    }

                    const padding = Math.min(guideRect.width, guideRect.height) * (paddingRatio || 0);
                    const visibleLeft = Math.max(videoRect.left, guideRect.left - padding);
                    const visibleTop = Math.max(videoRect.top, guideRect.top - padding);
                    const visibleRight = Math.min(videoRect.left + videoRect.width, guideRect.right + padding);
                    const visibleBottom = Math.min(videoRect.top + videoRect.height, guideRect.bottom + padding);
                    if (visibleRight <= visibleLeft || visibleBottom <= visibleTop) {
                        return null;
                    }

                    const x = ((visibleLeft - videoRect.left) / videoRect.width) * video.videoWidth;
                    const y = ((visibleTop - videoRect.top) / videoRect.height) * video.videoHeight;
                    const width = ((visibleRight - visibleLeft) / videoRect.width) * video.videoWidth;
                    const height = ((visibleBottom - visibleTop) / videoRect.height) * video.videoHeight;

                    return {
                        x: Math.max(0, Math.floor(x)),
                        y: Math.max(0, Math.floor(y)),
                        width: Math.max(1, Math.min(video.videoWidth - Math.floor(x), Math.floor(width))),
                        height: Math.max(1, Math.min(video.videoHeight - Math.floor(y), Math.floor(height))),
                    };
                };

                const documentRegionLooksAligned = function(video, side = activeIdDocumentSide) {
                    const canvas = getIdDocumentElement(side, 'canvas');
                    const rect = getIdDocumentGuideSourceRect(video, 0);
                    if (!canvas || !rect) {
                        return false;
                    }

                    const sampleWidth = 180;
                    const sampleHeight = Math.max(80, Math.round(sampleWidth * (rect.height / rect.width)));
                    canvas.width = sampleWidth;
                    canvas.height = sampleHeight;

                    const context = canvas.getContext('2d');
                    context.drawImage(video, rect.x, rect.y, rect.width, rect.height, 0, 0, sampleWidth, sampleHeight);
                    const imageData = context.getImageData(0, 0, sampleWidth, sampleHeight).data;
                    const step = 3;
                    let count = 0;
                    let bright = 0;
                    let sum = 0;
                    let sumsq = 0;
                    let edges = 0;
                    let edgeComparisons = 0;
                    let previousRow = [];

                    for (let yy = 0; yy < sampleHeight; yy += step) {
                        const row = [];
                        let column = 0;
                        for (let xx = 0; xx < sampleWidth; xx += step) {
                            const index = ((yy * sampleWidth) + xx) * 4;
                            const luminance = (0.2126 * imageData[index]) +
                                (0.7152 * imageData[index + 1]) +
                                (0.0722 * imageData[index + 2]);
                            row[column] = luminance;
                            sum += luminance;
                            sumsq += luminance * luminance;
                            count++;
                            if (luminance > 70) {
                                bright++;
                            }
                            if (column > 0) {
                                if (Math.abs(luminance - row[column - 1]) > 28) {
                                    edges++;
                                }
                                edgeComparisons++;
                            }
                            if (previousRow[column] !== undefined) {
                                if (Math.abs(luminance - previousRow[column]) > 28) {
                                    edges++;
                                }
                                edgeComparisons++;
                            }
                            column++;
                        }
                        previousRow = row;
                    }

                    if (!count || !edgeComparisons) {
                        return false;
                    }

                    const brightness = sum / count;
                    const variance = Math.max(0, (sumsq / count) - (brightness * brightness));
                    const contrast = Math.sqrt(variance);
                    const brightRatio = bright / count;
                    const edgeDensity = edges / edgeComparisons;

                    return brightness >= 75 &&
                        brightness <= 235 &&
                        contrast >= 12 &&
                        brightRatio >= 0.55 &&
                        edgeDensity >= 0.035 &&
                        edgeDensity <= 0.45;
                };

                const drawIdDocumentCapture = function(video, canvas) {
                    const context = canvas.getContext('2d');
                    const rect = getIdDocumentGuideSourceRect(video, 0.035);
                    if (!rect) {
                        const fallbackSize = getCameraCaptureSize(video);
                        canvas.width = fallbackSize.width;
                        canvas.height = fallbackSize.height;
                        context.drawImage(video, 0, 0, canvas.width, canvas.height);
                        return;
                    }

                    const targetWidth = Math.min(1280, Math.max(640, Math.round(rect.width)));
                    const targetHeight = Math.max(1, Math.round(targetWidth * (rect.height / rect.width)));
                    canvas.width = targetWidth;
                    canvas.height = targetHeight;
                    context.drawImage(video, rect.x, rect.y, rect.width, rect.height, 0, 0, targetWidth, targetHeight);
                };

                const startIdDocumentAutoCapture = function(side = activeIdDocumentSide) {
                    const captureSide = getIdDocumentSide(side);
                    stopIdDocumentAutoCapture();
                    idDocumentAutoCaptureTimer = window.setInterval(async function() {
                        const video = getIdDocumentElement(captureSide, 'video');
                        if (idDocumentAutoCaptureRunning || capturedIdImages[captureSide] || !idDocumentStream ||
                                !video || video.srcObject !== idDocumentStream || !video.videoWidth) {
                            return;
                        }

                        idDocumentAutoCaptureRunning = true;
                        try {
                            idDocumentAutoCaptureScore = documentRegionLooksAligned(video, captureSide)
                                ? idDocumentAutoCaptureScore + 1
                                : 0;
                            if (idDocumentAutoCaptureScore >= idDocumentAutoCaptureRequiredScore) {
                                await captureIdDocumentImage(captureSide);
                            }
                        } finally {
                            idDocumentAutoCaptureRunning = false;
                        }
                    }, idDocumentAutoCaptureInterval);
                };

                const startIdDocumentCamera = async function(side = 'front') {
                    const captureSide = getIdDocumentSide(side);
                    const video = getIdDocumentElement(captureSide, 'video');
                    if (!video) {
                        return false;
                    }

                    const previousSide = activeIdDocumentSide;
                    activeIdDocumentSide = captureSide;
                    if (previousSide !== captureSide && !capturedIdImages[previousSide]) {
                        setIdDocumentCaptureState('hidden', previousSide);
                    }
                    if (idDocumentStream && video.srcObject === idDocumentStream && video.videoWidth) {
                        setIdDocumentCaptureState('camera', captureSide);
                        startIdDocumentAutoCapture(captureSide);
                        return true;
                    }

                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        return false;
                    }

                    stopIdLiveStream();
                    stopIdDocumentStream();
                    idDocumentStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: {ideal: 'environment'},
                            width: {ideal: 1280},
                            height: {ideal: 720}
                        },
                        audio: false
                    }).catch(() => navigator.mediaDevices.getUserMedia({video: true, audio: false}));

                    video.srcObject = idDocumentStream;
                    video.muted = true;
                    video.playsInline = true;
                    await video.play();

                    if (!await waitForVideoFrame(video)) {
                        stopIdDocumentStream();
                        setIdDocumentCaptureState('hidden', captureSide);
                        return false;
                    }

                    setIdDocumentCaptureState('camera', captureSide);
                    startIdDocumentAutoCapture(captureSide);
                    return true;
                };

                const captureIdDocumentImage = async function(side = activeIdDocumentSide) {
                    const captureSide = getIdDocumentSide(side);
                    const video = getIdDocumentElement(captureSide, 'video');
                    const canvas = getIdDocumentElement(captureSide, 'canvas');
                    const preview = getIdDocumentElement(captureSide, 'preview-image');
                    if (!video || !canvas || !preview) {
                        return false;
                    }
                    if (!idDocumentStream || video.srcObject !== idDocumentStream || !video.videoWidth) {
                        if (!await startIdDocumentCamera(captureSide)) {
                            return false;
                        }
                    }

                    drawIdDocumentCapture(video, canvas);
                    capturedIdImages[captureSide] = canvas.toDataURL('image/png');
                    preview.setAttribute('src', capturedIdImages[captureSide]);
                    stopIdDocumentAutoCapture();
                    setIdDocumentCaptureState('captured', captureSide);
                    stopIdDocumentStream();

                    const idInput = getIdDocumentInput(captureSide);
                    if (idInput) {
                        idInput.value = '';
                    }

                    return true;
                };

                const startIdVerificationCamera = async function() {
                    const video = document.getElementById('proctoring-id-live-video');
                    if (!video) {
                        return false;
                    }

                    if (idLiveStream && video.srcObject === idLiveStream && video.videoWidth) {
                        return true;
                    }

                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        return false;
                    }

                    stopIdDocumentStream();
                    if (!capturedIdImages.front) {
                        setIdDocumentCaptureState('hidden', 'front');
                    }
                    if (idBackRequired && !capturedIdImages.back) {
                        setIdDocumentCaptureState('hidden', 'back');
                    }
                    stopIdLiveStream();
                    idLiveStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: 'user',
                            width: {ideal: 960},
                            height: {ideal: 1280}
                        },
                        audio: false
                    }).catch(() => navigator.mediaDevices.getUserMedia({video: true, audio: false}));

                    video.srcObject = idLiveStream;
                    video.muted = true;
                    video.playsInline = true;
                    await video.play();

                    return waitForVideoFrame(video);
                };

                const setSubmitButtonLabel = function(label) {
                    if (submitButton.is('input')) {
                        submitButton.val(label);
                    } else {
                        submitButton.text(label);
                    }
                };

                const getCurrentPreflightStep = function() {
                    if (privacyRequired && !privacyReady) {
                        return 'privacy';
                    }
                    if (honorRequired && !honorReady) {
                        return 'honor';
                    }
                    if (captchaRequired && !captchaReady) {
                        return 'captcha';
                    }
                    if (identityRequired && !identityReady) {
                        return 'identity';
                    }
                    if (faceRequired && !faceReady) {
                        return 'face';
                    }
                    if (screenRequired && !screenReady) {
                        return 'screen';
                    }
                    if (multiMonitorBlocks && !multiMonitorReady) {
                        return 'multimonitor';
                    }

                    return 'ready';
                };

                const updateGuidedPreflight = function(ready) {
                    const currentStep = getCurrentPreflightStep();
                    document.querySelectorAll('.proctoring-preflight-step').forEach(function(step) {
                        const stepName = step.getAttribute('data-preflight-step');
                        step.classList.toggle('is-active', !ready && stepName === currentStep);
                        step.classList.toggle('is-complete', ready || (
                            stepName === 'privacy' && privacyReady ||
                            stepName === 'honor' && honorReady ||
                            stepName === 'captcha' && captchaReady ||
                            stepName === 'identity' && identityReady ||
                            stepName === 'face' && faceReady ||
                            stepName === 'screen' && screenReady ||
                            stepName === 'multimonitor' && multiMonitorReady
                        ));
                    });

                    document.querySelectorAll('.proctoring-preflight-item').forEach(function(item) {
                        item.classList.toggle('is-current', !ready && item.id === 'proctoring-check-' + currentStep);
                    });

                    const readyNode = document.getElementById('proctoring-preflight-ready');
                    if (readyNode) {
                        readyNode.style.display = ready ? 'block' : 'none';
                    }

                    if (!ready && currentStep === 'captcha') {
                        renderTurnstileWidgets();
                    }
                };

                const updatePreflightGate = function() {
                    const ready = privacyReady && honorReady && captchaReady && identityReady &&
                        faceReady && screenReady && multiMonitorReady;
                    actionBar.addClass('proctoring-preflight-actionbar');
                    actionBar.css("visibility", "visible");
                    submitButton.show();
                    submitButton.prop('disabled', !ready);
                    submitButton.attr('aria-disabled', ready ? 'false' : 'true');
                    submitButton.toggleClass('disabled', !ready);
                    setSubmitButtonLabel(ready ? submitButtonDefaultLabel : (strings.preflightsubmitlocked || 'Complete precheck first'));
                    updateGuidedPreflight(ready);
                };

                const setScreenResult = function(message, success) {
                    $("#screen_share_result").html(
                        `<span style="color: ${success ? 'green' : 'red'}">${escapeHtml(message)}</span>`
                    );
                };

                syncPrivacyRequirement();
                syncHonorRequirement();
                syncCaptchaRequirement();
                if (identityRequired) {
                    setRequirementStatus('identity', identityReady ? 'complete' : 'pending');
                    setIdVerificationConfirmed(identityReady);
                }
                if (faceRequired) {
                    setRequirementStatus('face', 'pending');
                }
                if (screenRequired) {
                    setRequirementStatus('screen', 'pending');
                }
                if (multiMonitorBlocks) {
                    setRequirementStatus('multimonitor', 'pending');
                }

                if (privacyRequired || honorRequired || captchaRequired || identityRequired ||
                        faceRequired || screenRequired || multiMonitorBlocks) {
                    updatePreflightGate();
                }

                const privacyCheckbox = document.querySelector('input[name="proctoringprivacy"]');
                if (privacyCheckbox) {
                    privacyCheckbox.addEventListener('change', function() {
                        syncPrivacyRequirement();
                        updatePreflightGate();
                    });
                }

                const honorCheckbox = document.querySelector('input[name="proctoring"]');
                if (honorCheckbox) {
                    honorCheckbox.addEventListener('change', function() {
                        syncHonorRequirement();
                        updatePreflightGate();
                    });
                }

                const bindIdDocumentInput = function(side) {
                    const captureSide = getIdDocumentSide(side);
                    const idDocumentInput = getIdDocumentInput(captureSide);
                    if (!idDocumentInput) {
                        return;
                    }
                    idDocumentInput.addEventListener('change', function() {
                        if (idDocumentInput.files && idDocumentInput.files[0]) {
                            capturedIdImages[captureSide] = '';
                            stopIdDocumentStream();
                            setIdDocumentCaptureState('hidden', captureSide);
                        }
                    });
                };
                bindIdDocumentInput('front');
                bindIdDocumentInput('back');

                if (captchaRequired) {
                    window.setInterval(function() {
                        const previousReady = captchaReady;
                        syncCaptchaRequirement();
                        if (previousReady !== captchaReady) {
                            updatePreflightGate();
                        }
                    }, 500);
                }

                if (multiMonitorMode !== 'off') {
                    checkMultiMonitorSetup(false);
                }

                $("#multimonitorvalidate").click(async function(event) {
                    event.preventDefault();
                    await checkMultiMonitorSetup(true);
                });

                const bindIdDocumentCaptureButtons = function(side) {
                    const captureSide = getIdDocumentSide(side);

                    $(getIdDocumentButton(captureSide, 'camera')).click(async function(event) {
                        event.preventDefault();
                        capturedIdImages[captureSide] = '';
                        try {
                            if (!await startIdDocumentCamera(captureSide)) {
                                setIdVerificationResult(strings.videonotavailable, false);
                            }
                        } catch (error) {
                            setIdDocumentCaptureState('hidden', captureSide);
                            setIdVerificationResult(strings.videonotavailable, false);
                        }
                    });

                    $(getIdDocumentButton(captureSide, 'capture')).click(async function(event) {
                        event.preventDefault();
                        try {
                            if (!await captureIdDocumentImage(captureSide)) {
                                setIdVerificationResult(getIdDocumentMissingMessage(captureSide), false);
                            }
                        } catch (error) {
                            setIdVerificationResult(getIdDocumentMissingMessage(captureSide), false);
                        }
                    });

                    $(getIdDocumentButton(captureSide, 'retake')).click(async function(event) {
                        event.preventDefault();
                        capturedIdImages[captureSide] = '';
                        const preview = getIdDocumentElement(captureSide, 'preview-image');
                        if (preview) {
                            preview.removeAttribute('src');
                        }
                        try {
                            if (!await startIdDocumentCamera(captureSide)) {
                                setIdDocumentCaptureState('hidden', captureSide);
                                setIdVerificationResult(strings.videonotavailable, false);
                            }
                        } catch (error) {
                            setIdDocumentCaptureState('hidden', captureSide);
                            setIdVerificationResult(strings.videonotavailable, false);
                        }
                    });
                };
                bindIdDocumentCaptureButtons('front');
                bindIdDocumentCaptureButtons('back');

                $("#idverificationcamera").click(async function(event) {
                    event.preventDefault();
                    setRequirementStatus('identity', 'pending');
                    try {
                        if (!await startIdVerificationCamera()) {
                            setIdVerificationResult(strings.videonotavailable, false);
                            setRequirementStatus('identity', 'action');
                        }
                    } catch (error) {
                        setIdVerificationResult(strings.videonotavailable, false);
                        setRequirementStatus('identity', 'action');
                    }
                });

                $("#idverificationvalidate").click(async function(event) {
                    event.preventDefault();
                    if (!identityRequired) {
                        return;
                    }

                    identityReady = false;
                    setIdVerificationConfirmed(false);
                    setRequirementStatus('identity', 'pending');
                    updatePreflightGate();

                    const spinner = document.getElementById('idverification_spinner');
                    if (spinner) {
                        spinner.style.display = 'block';
                    }

                    const failIdentity = function(message) {
                        if (spinner) {
                            spinner.style.display = 'none';
                        }
                        setIdVerificationResult(message, false);
                        setRequirementStatus('identity', 'action');
                        updatePreflightGate();
                    };

                    const idInput = getIdDocumentInput('front');
                    const idBackInput = getIdDocumentInput('back');
                    if (!capturedIdImages.front && (!idInput || !idInput.files || !idInput.files[0])) {
                        failIdentity(strings.idverificationdocumentmissing);
                        return;
                    }
                    if (idBackRequired && !capturedIdImages.back &&
                            (!idBackInput || !idBackInput.files || !idBackInput.files[0])) {
                        failIdentity(getIdDocumentMissingMessage('back'));
                        return;
                    }

                    let cameraReady = false;
                    try {
                        cameraReady = await startIdVerificationCamera();
                    } catch (error) {
                        cameraReady = false;
                    }

                    if (!cameraReady) {
                        failIdentity(strings.videonotavailable);
                        return;
                    }

                    const video = document.getElementById('proctoring-id-live-video');
                    const canvas = document.getElementById('proctoring-id-live-canvas');
                    const crop = document.getElementById('proctoring-id-live-crop');
                    const context = canvas.getContext('2d');
                    const captureSize = getCameraCaptureSize(video);
                    canvas.width = captureSize.width;
                    canvas.height = captureSize.height;
                    context.drawImage(video, 0, 0, canvas.width, canvas.height);
                    const liveImage = canvas.toDataURL('image/png');

                    let liveFaceFound = 0;
                    if (modelurl !== null) {
                        const detection = await detectface(canvas, canvas, crop);
                        if (!detection.qualityPassed) {
                            failIdentity(strings.facequalityfailed);
                            return;
                        }
                        liveFaceFound = detection.faceFound;
                    }

                    let idImage = capturedIdImages.front;
                    if (!idImage) {
                        try {
                            idImage = await readFileAsDataUrl(idInput.files[0]);
                        } catch (error) {
                            failIdentity(strings.idverificationdocumentmissing);
                            return;
                        }
                    }

                    let idBackImage = capturedIdImages.back;
                    if (idBackRequired && !idBackImage) {
                        try {
                            idBackImage = await readFileAsDataUrl(idBackInput.files[0]);
                        } catch (error) {
                            failIdentity(getIdDocumentMissingMessage('back'));
                            return;
                        }
                    }

                    Ajax.call([{
                        methodname: 'quizaccess_proctoring_verify_id',
                        args: {
                            courseid: parseInt(props.courseid, 10) || 0,
                            cmid: parseInt(props.cmid, 10) || 0,
                            attemptid: parseInt(props.attemptid, 10) || 0,
                            idimage: idImage,
                            liveimage: liveImage,
                            livefacefound: liveFaceFound,
                            idbackimage: idBackImage || ''
                        }
                    }])[0].done(function(res) {
                        if (spinner) {
                            spinner.style.display = 'none';
                        }

                        if (res.status === 'pass') {
                            identityReady = true;
                            setIdVerificationConfirmed(true);
                            setIdVerificationResult(strings.idverificationpassed, true);
                            setRequirementStatus('identity', 'complete');
                            stopIdLiveStream();
                        } else if (res.status === 'retry') {
                            failIdentity(res.message || strings.idverificationretry);
                            return;
                        } else if (res.status === 'error') {
                            failIdentity(strings.idverificationprovidererror);
                            return;
                        } else {
                            failIdentity(res.message || strings.idverificationfailed);
                            return;
                        }

                        updatePreflightGate();
                    }).fail(function(error) {
                        if (spinner) {
                            spinner.style.display = 'none';
                        }
                        identityReady = false;
                        setIdVerificationConfirmed(false);
                        setRequirementStatus('identity', 'action');
                        updatePreflightGate();
                        Notification.exception(error);
                    });
                });

                const initScreenMarker = function() {
                    if (!screenRequired || !screenMarkerRequired ||
                            document.getElementById('proctoring-screen-verification-marker')) {
                        return;
                    }

                    $('body').append(
                        '<div id="proctoring-screen-verification-marker" ' +
                            'class="proctoring-screen-verification-marker" aria-hidden="true" ' +
                            'style="position:fixed;top:8px;right:8px;z-index:10002;width:220px;padding:8px;' +
                            'border:3px solid #fff;background:#111;color:#fff;pointer-events:none;">' +
                            `<div class="proctoring-screen-marker-label">${escapeHtml(strings.screenmarkerlabel)}</div>` +
                            '<div class="proctoring-screen-marker-colors">' +
                                '<span class="proctoring-screen-marker-swatch proctoring-screen-marker-magenta" ' +
                                    'style="display:block;width:58px;height:24px;background:#ff00cc;"></span>' +
                                '<span class="proctoring-screen-marker-swatch proctoring-screen-marker-cyan" ' +
                                    'style="display:block;width:58px;height:24px;background:#00ffcc;"></span>' +
                                '<span class="proctoring-screen-marker-swatch proctoring-screen-marker-yellow" ' +
                                    'style="display:block;width:58px;height:24px;background:#ffe600;"></span>' +
                            '</div>' +
                            `<div class="proctoring-screen-marker-token">${markerToken}</div>` +
                        '</div>'
                    );
                };

                const stopScreenStream = function() {
                    if (screenStream) {
                        screenStream.getTracks().forEach((track) => track.stop());
                        screenStream = null;
                    }
                    if (screenVideo) {
                        screenVideo.srcObject = null;
                    }
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
                    if (!screenMarkerRequired) {
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

                initScreenMarker();

                if (screenRequired && props.screenmonitorurl) {
                    screenMonitorClient = ScreenMonitorClient.create(props, {
                        onReady: function() {
                            screenReady = true;
                            setScreenConfirmed(true);
                            setScreenResult(strings.screenshareaccepted, true);
                            setRequirementStatus('screen', 'complete');
                            updatePreflightGate();
                        },
                        onUnavailable: function() {
                            if (screenReady) {
                                setScreenResult(strings.screensharestopped, false);
                            }
                            screenReady = false;
                            setScreenConfirmed(false);
                            setRequirementStatus('screen', 'action');
                            updatePreflightGate();
                        },
                        onWrongScreen: function() {
                            screenReady = false;
                            setScreenConfirmed(false);
                            setScreenResult(strings.screenmarkerwrongmonitor, false);
                            setRequirementStatus('screen', 'action');
                            updatePreflightGate();
                        },
                        onOpenBlocked: function() {
                            setScreenResult(strings.screenmonitorpopupblocked, false);
                            setRequirementStatus('screen', 'action');
                        },
                        onOpened: function() {
                            setScreenResult(strings.screenmonitorwindowopened, true);
                            setRequirementStatus('screen', 'pending');
                        }
                    });
                    screenMonitorClient.start();
                }

                $('#fcvalidate').append('<img id="validate-cropimg" style="display: none;" src="" alt=""/>');
                $("#screensharevalidate").click(async function(event) {
                    event.preventDefault();
                    setRequirementStatus('screen', 'pending');

                    if (screenMonitorClient) {
                        screenMonitorClient.open();
                        return;
                    }

                    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                        setScreenResult(strings.screensharenotsupported, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
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
                        setScreenResult(strings.screensharedenied, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    const videoTrack = screenStream.getVideoTracks()[0];
                    const settings = videoTrack && videoTrack.getSettings ? videoTrack.getSettings() : {};
                    if (!videoTrack || settings.displaySurface !== 'monitor') {
                        stopScreenStream();
                        setScreenResult(strings.entirescreenrequired, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
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
                        setScreenResult(strings.screensharedenied, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    if (!await waitForScreenFrame() || !sharedScreenContainsMarker()) {
                        stopScreenStream();
                        setScreenResult(strings.screenmarkerwrongmonitor, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    videoTrack.addEventListener('ended', function() {
                        setScreenResult(strings.screensharestopped, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                    });

                    setScreenResult(strings.screenshareaccepted, true);
                    setRequirementStatus('screen', 'complete');
                    screenReady = true;
                    setScreenConfirmed(true);
                    updatePreflightGate();
                });

                $("#fcvalidate").click(async function(event) {

                    event.preventDefault();
                    setRequirementStatus('face', 'pending');
                    const photo = document.getElementById('photo');
                    const canvas = document.getElementById('canvas');
                    const video = document.getElementById('video');
                    const context = canvas.getContext('2d');
                    const captureSize = getCameraCaptureSize(video);

                    canvas.width = captureSize.width;
                    canvas.height = captureSize.height;
                    context.drawImage(video, 0, 0, canvas.width, canvas.height);
                    var data = canvas.toDataURL('image/png');
                    photo.setAttribute('src', data);

                    const courseid = document.getElementById('courseidval').value;
                    const cmid = document.getElementById('cmidval').value;
                    const profileimage = document.getElementById('profileimage').value;

                    // Getting the face image from screenshot.
                    let croppedImage = document.getElementById('validate-cropimg');
                    croppedImage.removeAttribute('src');
                    let faceFound = 0;
                    let faceImage = "";
                    if (modelurl !== null) {
                        const detection = await detectface(canvas, canvas, croppedImage);
                        if (!detection.qualityPassed) {
                            $("#video").css("border", "10px solid red");
                            $("#face_validation_result").html(`<span style="color: red">${strings.facequalityfailed}</span>`);
                            setRequirementStatus('face', 'action');
                            return;
                        }
                        faceFound = detection.faceFound;
                        faceImage = detection.faceImage;
                    }
                    const wsfunction = 'quizaccess_proctoring_validate_face';
                    const params = {
                        'courseid': courseid,
                        'cmid': cmid,
                        'profileimage': profileimage,
                        'webcampicture': data,
                        'parenttype': 'camshot_image',
                        'faceimage': faceImage,
                        'facefound': faceFound,
                    };

                    const request = {
                        methodname: wsfunction,
                        args: params
                    };
                    document.getElementById('loading_spinner').style.display = 'block';
                    Ajax.call([request])[0].done(function(res) {
                        if (res.warnings.length < 1) {
                            document.getElementById('loading_spinner').style.display = 'none';
                            var status = res.status;
                            if (status === 'success') {
                                $("#video").css("border", "10px solid green");
                                $("#face_validation_result").html(`<span style="color: green">${strings.facematched}</span>`);
                                document.getElementById("fcvalidate").style.display = "none";
                                faceReady = true;
                                setRequirementStatus('face', 'complete');
                                updatePreflightGate();
                            } else if (status === 'registered') {
                                $("#video").css("border", "10px solid green");
                                $("#face_validation_result").html(`<span style="color: green">${strings.faceregistered}</span>`);
                                document.getElementById("fcvalidate").style.display = "none";
                                faceReady = true;
                                setRequirementStatus('face', 'complete');
                                updatePreflightGate();
                            } else if (status === 'photonotuploaded') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.photonotuploaded}</span>`);
                                setRequirementStatus('face', 'action');
                            } else if (status === 'invalidApi') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.invalidapi}</span>`);
                                setRequirementStatus('face', 'action');
                            } else if (status === 'facenotfound') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.facenotfoundoncam}</span>`);
                                setRequirementStatus('face', 'action');
                            } else if (status === 'faceunclear') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.facequalityfailed}</span>`);
                                setRequirementStatus('face', 'action');
                            } else {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.facenotmatched}</span>`);
                                setRequirementStatus('face', 'action');
                            }
                        } else {
                            document.getElementById('loading_spinner').style.display = 'none';
                            if (video) {
                                Notification.addNotification({
                                    message: strings.wrongduringtakingimage,
                                    type: 'error'
                                });
                            }
                        }
                    }).fail(Notification.exception);

                });

                return true;
            }
        };
    });
