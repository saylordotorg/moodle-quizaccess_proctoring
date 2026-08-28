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
                {key: 'modal:idverificationdocumentnotinwindow', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationdocumentinwindow', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationdocumentready', component: 'quizaccess_proctoring'},
                {key: 'modal:idverificationdocumentblurry', component: 'quizaccess_proctoring'},
                {
                    key: 'preflight:stepcounter',
                    component: 'quizaccess_proctoring',
                    param: {current: '__CURRENT__', total: '__TOTAL__'},
                },
                {
                    key: 'preflight:progresscount',
                    component: 'quizaccess_proctoring',
                    param: {done: '__DONE__', total: '__TOTAL__'},
                },
                {key: 'preflight:setupcomplete', component: 'quizaccess_proctoring'},
                {key: 'screenmarkerchecking', component: 'quizaccess_proctoring'},
                {key: 'devicenotice:handheld', component: 'quizaccess_proctoring'},
                {key: 'devicenotice:nocamera', component: 'quizaccess_proctoring'},
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
                    idverificationdocumentnotinwindow: strings[33],
                    idverificationdocumentinwindow: strings[34],
                    idverificationdocumentready: strings[35],
                    idverificationdocumentblurry: strings[36],
                    stepcounterpattern: strings[37],
                    progresscountpattern: strings[38],
                    setupcomplete: strings[39],
                    screenmarkerchecking: strings[40],
                    devicenoticehandheld: strings[41],
                    devicenoticenocamera: strings[42],
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

        const getCaptureSharpness = function(canvas) {
            // Measure on a fixed-width rendition so the metric is independent of capture resolution.
            const sampleWidth = Math.min(480, canvas.width);
            const sampleHeight = Math.max(1, Math.round(canvas.height * (sampleWidth / canvas.width)));
            const sample = document.createElement('canvas');
            sample.width = sampleWidth;
            sample.height = sampleHeight;
            const context = sample.getContext('2d');
            context.drawImage(canvas, 0, 0, sampleWidth, sampleHeight);
            const imageData = context.getImageData(0, 0, sampleWidth, sampleHeight).data;
            const luminances = new Float32Array(sampleWidth * sampleHeight);
            for (let index = 0; index < luminances.length; index++) {
                const offset = index * 4;
                luminances[index] = (0.2126 * imageData[offset]) +
                    (0.7152 * imageData[offset + 1]) +
                    (0.0722 * imageData[offset + 2]);
            }

            let edgedelta = 0;
            let edgecount = 0;
            for (let yy = 1; yy < sampleHeight; yy++) {
                for (let xx = 1; xx < sampleWidth; xx++) {
                    const index = (yy * sampleWidth) + xx;
                    edgedelta += Math.abs(luminances[index] - luminances[index - 1]) +
                        Math.abs(luminances[index] - luminances[index - sampleWidth]);
                    edgecount += 2;
                }
            }

            return edgecount > 0 ? edgedelta / edgecount : 0;
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
                const idDocumentCaptureReady = {
                    front: false,
                    back: false,
                };
                let screenMonitorClient = null;
                let stepperRefresh = null;
                const idDocumentAutoCaptureRequiredScore = 8;
                const idDocumentAutoCaptureInterval = 400;
                const idDocumentMinCaptureSharpness = 1.8;
                const markerToken = Math.random().toString(36).slice(2, 8).toUpperCase();

                const escapeHtml = function(text) {
                    return String(text || '')
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                };

                /**
                 * Set a checklist item's state and the short status word beside it.
                 *
                 * The status is a pill in a narrow rail: it holds "Pending", "Complete" or "Action
                 * needed", and nothing longer. A full sentence here wraps across the item and lands
                 * on top of the step's own label - which is what a monitor warning did to step 7.
                 * Explanations belong in the step body, where every step already puts them.
                 *
                 * @param {String} key Requirement key.
                 * @param {String} state One of pending, complete, action.
                 * @param {String} [message] Short override for the status word.
                 */
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
                    // The camshot width setting sizes the periodic in-quiz captures; identity and face
                    // verification captures need more detail, so never go below 640 pixels wide here.
                    const configuredWidth = Math.max(640, parseInt(props.imagewidth, 10) || 480);
                    const sourceWidth = video && video.videoWidth ? video.videoWidth : 0;
                    const sourceHeight = video && video.videoHeight ? video.videoHeight : 0;

                    if (!sourceWidth || !sourceHeight) {
                        return {
                            width: configuredWidth,
                            height: Math.round(configuredWidth / (4 / 3)),
                        };
                    }

                    const width = Math.min(configuredWidth, sourceWidth);
                    return {
                        width: width,
                        height: Math.max(1, Math.round(width * (sourceHeight / sourceWidth))),
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
                            setRequirementStatus('multimonitor', 'complete');
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
                            setRequirementStatus('multimonitor', 'action');
                        }
                    } else {
                        multiMonitorReady = true;
                        setMultiMonitorConfirmed(true);
                        setMultiMonitorResult(strings.multimonitorsingle, true);
                        if (multiMonitorBlocks) {
                            setRequirementStatus('multimonitor', 'complete');
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

                const setIdDocumentGuideProgress = function(side, score, inWindow = false) {
                    const captureSide = getIdDocumentSide(side);
                    const preview = getIdDocumentElement(side, 'preview');
                    const guide = preview ? preview.querySelector('.proctoring-id-document-guide') : null;
                    if (!guide) {
                        return;
                    }

                    const progress = Math.max(0, Math.min(1, score / idDocumentAutoCaptureRequiredScore));
                    const ready = inWindow && progress >= 1;
                    const status = !inWindow
                        ? (strings.idverificationdocumentnotinwindow || 'ID not in window')
                        : ready
                            ? (strings.idverificationdocumentready || 'ID in window - click Capture')
                            : (strings.idverificationdocumentinwindow || 'ID in window - hold still');
                    const statusNode = guide.querySelector('.proctoring-id-document-status');
                    const captureButton = getIdDocumentButton(captureSide, 'capture');
                    idDocumentCaptureReady[captureSide] = ready;
                    guide.style.setProperty('--proctoring-id-hold-progress', (progress * 100).toFixed(0) + '%');
                    guide.classList.toggle('is-detected', inWindow);
                    guide.classList.toggle('is-in-window', inWindow);
                    guide.classList.toggle('is-holding', inWindow && progress >= 0.5);
                    guide.classList.toggle('is-not-in-window', !inWindow);
                    if (statusNode) {
                        statusNode.textContent = status;
                    }
                    guide.setAttribute('aria-label', status);
                    if (captureButton) {
                        captureButton.disabled = !ready;
                        captureButton.setAttribute('aria-disabled', ready ? 'false' : 'true');
                        captureButton.classList.toggle('disabled', !ready);
                        captureButton.title = status;
                    }
                };

                const resetIdDocumentGuideProgress = function(side = null) {
                    const sides = side ? [getIdDocumentSide(side)] : ['front', 'back'];
                    sides.forEach(function(captureSide) {
                        setIdDocumentGuideProgress(captureSide, 0);
                    });
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
                        captureButton.disabled = !cameraMode || !idDocumentCaptureReady[captureSide];
                        captureButton.setAttribute(
                            'aria-disabled',
                            cameraMode && idDocumentCaptureReady[captureSide] ? 'false' : 'true'
                        );
                        captureButton.classList.toggle('disabled', !cameraMode || !idDocumentCaptureReady[captureSide]);
                    }
                    if (retakeButton) {
                        retakeButton.style.display = capturedMode ? 'inline-flex' : 'none';
                    }
                    if (!cameraMode) {
                        resetIdDocumentGuideProgress(captureSide);
                    }
                };

                const stopIdDocumentAutoCapture = function() {
                    if (idDocumentAutoCaptureTimer) {
                        window.clearInterval(idDocumentAutoCaptureTimer);
                        idDocumentAutoCaptureTimer = null;
                    }
                    idDocumentAutoCaptureScore = 0;
                    idDocumentAutoCaptureRunning = false;
                    resetIdDocumentGuideProgress();
                };

                /**
                 * Shape the preview box to the stream inside it.
                 *
                 * The box had a fixed 4:3 shape while the camera decides its own aspect ratio, and
                 * object-fit: contain then paints bars down the sides (or across the top) of every
                 * camera that disagrees - which is every 16:9 laptop camera. The crop maths already
                 * accounts for those bars, so this is cosmetic, but "why is my camera in a small
                 * box in the middle of a black rectangle" is a reasonable question to be spared.
                 *
                 * @param {HTMLVideoElement} video The preview video.
                 */
                const matchPreviewToStream = function(video) {
                    if (!video || !video.videoWidth || !video.videoHeight) {
                        return;
                    }
                    const box = video.closest('.proctoring-id-document-preview');
                    if (!box) {
                        return;
                    }
                    box.style.setProperty(
                        '--proctoring-cam-ratio',
                        String(video.videoWidth / video.videoHeight)
                    );
                };

                const waitForVideoFrame = async function(video) {
                    for (let attempts = 0; attempts < 20; attempts++) {
                        if (video && video.videoWidth && video.videoHeight) {
                            matchPreviewToStream(video);
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

                const getLuminanceAt = function(imageData, width, height, x, y) {
                    const safeX = Math.max(0, Math.min(width - 1, Math.round(x)));
                    const safeY = Math.max(0, Math.min(height - 1, Math.round(y)));
                    const index = ((safeY * width) + safeX) * 4;

                    return (0.2126 * imageData[index]) +
                        (0.7152 * imageData[index + 1]) +
                        (0.0722 * imageData[index + 2]);
                };

                const summarizeIdDocumentRegion = function(imageData, width, height, bounds, excludeBounds = null) {
                    const step = 3;
                    const left = Math.max(0, Math.floor(bounds.left));
                    const top = Math.max(0, Math.floor(bounds.top));
                    const right = Math.min(width, Math.ceil(bounds.right));
                    const bottom = Math.min(height, Math.ceil(bounds.bottom));
                    const exclude = excludeBounds ? {
                        left: Math.max(0, Math.floor(excludeBounds.left)),
                        top: Math.max(0, Math.floor(excludeBounds.top)),
                        right: Math.min(width, Math.ceil(excludeBounds.right)),
                        bottom: Math.min(height, Math.ceil(excludeBounds.bottom)),
                    } : null;
                    let count = 0;
                    let bright = 0;
                    let sum = 0;
                    let sumsq = 0;
                    let edges = 0;
                    let edgeComparisons = 0;
                    let previousRow = [];

                    for (let yy = top; yy < bottom; yy += step) {
                        const row = [];
                        let column = 0;
                        for (let xx = left; xx < right; xx += step) {
                            if (exclude && xx >= exclude.left && xx < exclude.right &&
                                    yy >= exclude.top && yy < exclude.bottom) {
                                continue;
                            }

                            const luminance = getLuminanceAt(imageData, width, height, xx, yy);
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
                        if (row.length > 0) {
                            previousRow = row;
                        }
                    }

                    if (!count || !edgeComparisons) {
                        return {
                            count: count,
                            brightness: 0,
                            contrast: 0,
                            brightRatio: 0,
                            edgeDensity: 0,
                        };
                    }

                    const brightness = sum / count;
                    const variance = Math.max(0, (sumsq / count) - (brightness * brightness));

                    return {
                        count: count,
                        brightness: brightness,
                        contrast: Math.sqrt(variance),
                        brightRatio: bright / count,
                        edgeDensity: edges / edgeComparisons,
                    };
                };

                const measureIdDocumentBoundaryContrast = function(imageData, width, height, innerBounds) {
                    const spacing = Math.max(4, Math.floor(Math.min(
                        innerBounds.right - innerBounds.left,
                        innerBounds.bottom - innerBounds.top
                    ) / 24));
                    const inset = 4;
                    const outset = 5;
                    const scan = Math.max(6, Math.floor(Math.min(
                        innerBounds.right - innerBounds.left,
                        innerBounds.bottom - innerBounds.top
                    ) / 18));
                    const strongThreshold = 16;
                    const sides = {
                        top: {count: 0, strong: 0, sum: 0},
                        bottom: {count: 0, strong: 0, sum: 0},
                        left: {count: 0, strong: 0, sum: 0},
                        right: {count: 0, strong: 0, sum: 0},
                    };

                    const getPairDifference = function(innerX, innerY, outerX, outerY) {
                        if (outerX < 0 || outerY < 0 || outerX >= width || outerY >= height) {
                            return null;
                        }
                        return Math.abs(
                            getLuminanceAt(imageData, width, height, innerX, innerY) -
                            getLuminanceAt(imageData, width, height, outerX, outerY)
                        );
                    };

                    const addSideSample = function(side, difference) {
                        if (difference === null) {
                            return;
                        }

                        sides[side].count++;
                        sides[side].sum += difference;
                        if (difference >= strongThreshold) {
                            sides[side].strong++;
                        }
                    };

                    const addBestSideSample = function(side, makePair) {
                        let best = null;
                        for (let offset = -scan; offset <= scan; offset += 2) {
                            const pair = makePair(offset);
                            const difference = getPairDifference(pair.innerX, pair.innerY, pair.outerX, pair.outerY);
                            if (difference !== null && (best === null || difference > best)) {
                                best = difference;
                            }
                        }
                        addSideSample(side, best);
                    };

                    for (let xx = innerBounds.left + spacing; xx < innerBounds.right - spacing; xx += spacing) {
                        addBestSideSample('top', function(offset) {
                            const edgeY = innerBounds.top + offset;
                            return {
                                innerX: xx,
                                innerY: edgeY + inset,
                                outerX: xx,
                                outerY: edgeY - outset,
                            };
                        });
                        addBestSideSample('bottom', function(offset) {
                            const edgeY = innerBounds.bottom + offset;
                            return {
                                innerX: xx,
                                innerY: edgeY - inset,
                                outerX: xx,
                                outerY: edgeY + outset,
                            };
                        });
                    }

                    for (let yy = innerBounds.top + spacing; yy < innerBounds.bottom - spacing; yy += spacing) {
                        addBestSideSample('left', function(offset) {
                            const edgeX = innerBounds.left + offset;
                            return {
                                innerX: edgeX + inset,
                                innerY: yy,
                                outerX: edgeX - outset,
                                outerY: yy,
                            };
                        });
                        addBestSideSample('right', function(offset) {
                            const edgeX = innerBounds.right + offset;
                            return {
                                innerX: edgeX - inset,
                                innerY: yy,
                                outerX: edgeX + outset,
                                outerY: yy,
                            };
                        });
                    }

                    const summary = {
                        average: 0,
                        count: 0,
                        coveredSides: 0,
                        horizontalSides: 0,
                        verticalSides: 0,
                    };
                    Object.keys(sides).forEach(function(side) {
                        const sideStats = sides[side];
                        if (!sideStats.count) {
                            return;
                        }

                        const average = sideStats.sum / sideStats.count;
                        const strongRatio = sideStats.strong / sideStats.count;
                        const present = average >= 12 && strongRatio >= 0.45;
                        summary.average += sideStats.sum;
                        summary.count += sideStats.count;
                        if (present) {
                            summary.coveredSides++;
                            if (side === 'top' || side === 'bottom') {
                                summary.horizontalSides++;
                            } else {
                                summary.verticalSides++;
                            }
                        }
                    });
                    summary.average = summary.count > 0 ? summary.average / summary.count : 0;

                    return summary;
                };

                const getIdDocumentRegionStatus = function(video, side = activeIdDocumentSide) {
                    const canvas = getIdDocumentElement(side, 'canvas');
                    const guideRect = getIdDocumentGuideSourceRect(video, 0);
                    const paddedRect = getIdDocumentGuideSourceRect(video, 0.18);
                    if (!canvas || !guideRect || !paddedRect) {
                        return {
                            detected: false,
                            aligned: false,
                        };
                    }

                    const sampleWidth = 220;
                    const sampleHeight = Math.max(100, Math.round(sampleWidth * (paddedRect.height / paddedRect.width)));
                    canvas.width = sampleWidth;
                    canvas.height = sampleHeight;

                    const context = canvas.getContext('2d');
                    context.drawImage(
                        video,
                        paddedRect.x,
                        paddedRect.y,
                        paddedRect.width,
                        paddedRect.height,
                        0,
                        0,
                        sampleWidth,
                        sampleHeight
                    );
                    const imageData = context.getImageData(0, 0, sampleWidth, sampleHeight).data;
                    const innerBounds = {
                        left: ((guideRect.x - paddedRect.x) / paddedRect.width) * sampleWidth,
                        top: ((guideRect.y - paddedRect.y) / paddedRect.height) * sampleHeight,
                        right: ((guideRect.x + guideRect.width - paddedRect.x) / paddedRect.width) * sampleWidth,
                        bottom: ((guideRect.y + guideRect.height - paddedRect.y) / paddedRect.height) * sampleHeight,
                    };
                    const fullBounds = {
                        left: 0,
                        top: 0,
                        right: sampleWidth,
                        bottom: sampleHeight,
                    };
                    const innerStats = summarizeIdDocumentRegion(imageData, sampleWidth, sampleHeight, innerBounds);
                    const ringStats = summarizeIdDocumentRegion(
                        imageData,
                        sampleWidth,
                        sampleHeight,
                        fullBounds,
                        innerBounds
                    );
                    const boundaryStats = measureIdDocumentBoundaryContrast(
                        imageData,
                        sampleWidth,
                        sampleHeight,
                        innerBounds
                    );
                    const documentBoundaryFound = ringStats.count > innerStats.count * 0.15 &&
                        boundaryStats.coveredSides >= 4 &&
                        boundaryStats.horizontalSides >= 2 &&
                        boundaryStats.verticalSides >= 2 &&
                        boundaryStats.average >= 13;
                    const detected = documentBoundaryFound &&
                        innerStats.brightness >= 60 &&
                        innerStats.brightness <= 245 &&
                        innerStats.contrast >= 8 &&
                        innerStats.brightRatio >= 0.4 &&
                        innerStats.edgeDensity >= 0.02 &&
                        innerStats.edgeDensity <= 0.55;
                    const aligned = detected &&
                        boundaryStats.average >= 15 &&
                        innerStats.brightness >= 75 &&
                        innerStats.brightness <= 235 &&
                        innerStats.contrast >= 12 &&
                        innerStats.brightRatio >= 0.55 &&
                        innerStats.edgeDensity >= 0.035 &&
                        innerStats.edgeDensity <= 0.45;

                    return {
                        detected: detected,
                        aligned: aligned,
                    };
                };

                const improveIdDocumentStreamResolution = async function(video) {
                    // Some cameras hand out a low-resolution mode despite the ideal
                    // constraints; ask the live track to renegotiate before capture.
                    const track = idDocumentStream ? idDocumentStream.getVideoTracks()[0] : null;
                    if (!track || !track.getSettings || !track.applyConstraints) {
                        return;
                    }
                    if ((track.getSettings().width || 0) >= 1920) {
                        return;
                    }
                    try {
                        await track.applyConstraints({width: {ideal: 3840}, height: {ideal: 2160}});
                        await waitForVideoFrame(video);
                    } catch (error) {
                        // Keep whatever resolution the camera agreed to.
                    }
                };

                const takeIdDocumentPhoto = async function() {
                    // A still photo uses the camera's photo pipeline, which on most
                    // devices is far sharper than a grabbed video frame.
                    const track = idDocumentStream ? idDocumentStream.getVideoTracks()[0] : null;
                    if (!track || typeof window.ImageCapture !== 'function' ||
                            typeof window.createImageBitmap !== 'function') {
                        return null;
                    }
                    try {
                        const capturer = new window.ImageCapture(track);
                        const blob = await Promise.race([
                            capturer.takePhoto(),
                            new Promise((resolve) => window.setTimeout(() => resolve(null), 3000)),
                        ]);
                        if (!blob) {
                            return null;
                        }
                        return await window.createImageBitmap(blob);
                    } catch (error) {
                        return null;
                    }
                };

                const trimLetterboxBars = function(canvas) {
                    // Virtual cameras and mismatched driver modes pad frames with black
                    // bars; crop uniform near-black margins so the ID fills the capture.
                    const width = canvas.width;
                    const height = canvas.height;
                    if (width < 80 || height < 80) {
                        return;
                    }
                    const context = canvas.getContext('2d');
                    const imageData = context.getImageData(0, 0, width, height).data;
                    const isDarkLine = function(fixed, isRow) {
                        const length = isRow ? width : height;
                        const step = Math.max(1, Math.floor(length / 48));
                        let bright = 0;
                        let count = 0;
                        for (let position = 0; position < length; position += step) {
                            const index = (isRow ? ((fixed * width) + position) : ((position * width) + fixed)) * 4;
                            const luminance = (0.2126 * imageData[index]) +
                                (0.7152 * imageData[index + 1]) +
                                (0.0722 * imageData[index + 2]);
                            count++;
                            if (luminance > 18) {
                                bright++;
                            }
                        }
                        return count > 0 && (bright / count) < 0.04;
                    };
                    const limitY = Math.floor(height / 3);
                    const limitX = Math.floor(width / 3);
                    let top = 0;
                    while (top < limitY && isDarkLine(top, true)) {
                        top++;
                    }
                    let bottom = 0;
                    while (bottom < limitY && isDarkLine(height - 1 - bottom, true)) {
                        bottom++;
                    }
                    let left = 0;
                    while (left < limitX && isDarkLine(left, false)) {
                        left++;
                    }
                    let right = 0;
                    while (right < limitX && isDarkLine(width - 1 - right, false)) {
                        right++;
                    }
                    if (top + bottom + left + right < 8) {
                        return;
                    }
                    const cropWidth = width - left - right;
                    const cropHeight = height - top - bottom;
                    if (cropWidth < 200 || cropHeight < 120) {
                        return;
                    }
                    const trimmed = context.getImageData(left, top, cropWidth, cropHeight);
                    canvas.width = cropWidth;
                    canvas.height = cropHeight;
                    canvas.getContext('2d').putImageData(trimmed, 0, 0);
                };

                const drawIdDocumentCapture = function(video, canvas, photo = null) {
                    const context = canvas.getContext('2d');
                    const rect = getIdDocumentGuideSourceRect(video, 0.035);
                    const source = photo || video;
                    const sourceWidth = photo ? photo.width : (video.videoWidth || 0);
                    const sourceHeight = photo ? photo.height : (video.videoHeight || 0);
                    if (!rect) {
                        const fallbackWidth = Math.min(1920, sourceWidth || 1280);
                        const fallbackHeight = sourceWidth
                            ? Math.max(1, Math.round(fallbackWidth * (sourceHeight / sourceWidth)))
                            : Math.round(fallbackWidth / (4 / 3));
                        canvas.width = fallbackWidth;
                        canvas.height = fallbackHeight;
                        context.drawImage(source, 0, 0, canvas.width, canvas.height);
                        return;
                    }

                    // The guide rect is measured in video-frame pixels; a still photo can
                    // be larger than the video stream, so rescale the crop to match it.
                    const scaleX = photo && video.videoWidth ? photo.width / video.videoWidth : 1;
                    const scaleY = photo && video.videoHeight ? photo.height / video.videoHeight : 1;
                    const cropX = rect.x * scaleX;
                    const cropY = rect.y * scaleY;
                    const cropWidth = Math.max(1, rect.width * scaleX);
                    const cropHeight = Math.max(1, rect.height * scaleY);
                    const targetWidth = Math.min(1920, Math.max(640, Math.round(cropWidth)));
                    const targetHeight = Math.max(1, Math.round(targetWidth * (cropHeight / cropWidth)));
                    canvas.width = targetWidth;
                    canvas.height = targetHeight;
                    context.drawImage(source, cropX, cropY, cropWidth, cropHeight, 0, 0, targetWidth, targetHeight);
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
                            const documentStatus = getIdDocumentRegionStatus(video, captureSide);
                            idDocumentAutoCaptureScore = documentStatus.aligned
                                ? Math.min(idDocumentAutoCaptureRequiredScore, idDocumentAutoCaptureScore + 1)
                                : 0;
                            setIdDocumentGuideProgress(
                                captureSide,
                                idDocumentAutoCaptureScore,
                                documentStatus.aligned
                            );
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
                            width: {ideal: 3840},
                            height: {ideal: 2160}
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
                    await improveIdDocumentStreamResolution(video);

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
                        return 'unavailable';
                    }
                    if (!idDocumentStream || video.srcObject !== idDocumentStream || !video.videoWidth) {
                        if (!await startIdDocumentCamera(captureSide)) {
                            return 'unavailable';
                        }
                        return 'notready';
                    }

                    const finalDocumentStatus = getIdDocumentRegionStatus(video, captureSide);
                    if (!idDocumentCaptureReady[captureSide] || !finalDocumentStatus.aligned) {
                        idDocumentAutoCaptureScore = 0;
                        setIdDocumentGuideProgress(captureSide, 0, false);
                        return 'notready';
                    }

                    const photo = await takeIdDocumentPhoto();
                    drawIdDocumentCapture(video, canvas, photo);
                    trimLetterboxBars(canvas);
                    if (getCaptureSharpness(canvas) < idDocumentMinCaptureSharpness) {
                        idDocumentAutoCaptureScore = 0;
                        setIdDocumentGuideProgress(captureSide, 0, false);
                        return 'blurry';
                    }
                    capturedIdImages[captureSide] = canvas.toDataURL('image/jpeg', 0.92);
                    preview.setAttribute('src', capturedIdImages[captureSide]);
                    stopIdDocumentAutoCapture();
                    setIdDocumentCaptureState('captured', captureSide);
                    stopIdDocumentStream();

                    const idInput = getIdDocumentInput(captureSide);
                    if (idInput) {
                        idInput.value = '';
                    }

                    return 'captured';
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
                    // Landscape, because the camera taking this shot is a built-in laptop camera
                    // and those are landscape. The old portrait ideal (960x1280) asked every one of
                    // them for a mode they do not have, and what came back was either a rotated
                    // frame or a landscape one padded to fit - which is what put bars down the
                    // sides of the preview. 1280x720 is well within any camera the face matcher
                    // needs, and the capture floors at 640 wide regardless.
                    idLiveStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: 'user',
                            width: {ideal: 1280},
                            height: {ideal: 720}
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

                    if (stepperRefresh) {
                        stepperRefresh(ready, currentStep);
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

                const setFaceValidationResult = function(message, success) {
                    $("#face_validation_result").html(
                        `<span style="color: ${success ? 'green' : 'red'}">${escapeHtml(message)}</span>`
                    );
                };

                const setFaceValidationSpinner = function(visible) {
                    const spinner = document.getElementById('loading_spinner');
                    if (spinner) {
                        spinner.style.display = visible ? 'block' : 'none';
                    }
                };

                const setFaceValidationComplete = function(message) {
                    const video = document.getElementById('video');
                    const validateButton = document.getElementById('fcvalidate');
                    if (video) {
                        $("#video").css("border", "10px solid green");
                    }
                    setFaceValidationResult(message, true);
                    if (validateButton) {
                        validateButton.style.display = "none";
                    }
                    faceReady = true;
                    setRequirementStatus('face', 'complete');
                    updatePreflightGate();
                };

                const setFaceValidationAction = function(message) {
                    const video = document.getElementById('video');
                    if (video) {
                        $("#video").css("border", "10px solid red");
                    }
                    setFaceValidationResult(message, false);
                    faceReady = false;
                    setRequirementStatus('face', 'action');
                    updatePreflightGate();
                };

                const submitFaceValidation = function(webcamPicture, faceImage, faceFound) {
                    const courseidInput = document.getElementById('courseidval');
                    const cmidInput = document.getElementById('cmidval');
                    const profileImageInput = document.getElementById('profileimage');
                    const request = {
                        methodname: 'quizaccess_proctoring_validate_face',
                        args: {
                            courseid: courseidInput ? courseidInput.value : (parseInt(props.courseid, 10) || 0),
                            cmid: cmidInput ? cmidInput.value : (parseInt(props.cmid, 10) || 0),
                            profileimage: profileImageInput ? profileImageInput.value : '',
                            webcampicture: webcamPicture,
                            parenttype: 'camshot_image',
                            faceimage: faceImage,
                            facefound: faceFound,
                        }
                    };

                    return new Promise(function(resolve, reject) {
                        Ajax.call([request])[0].done(resolve).fail(reject);
                    });
                };

                const applyFaceValidationResponse = function(res) {
                    setFaceValidationSpinner(false);
                    if (!res || (res.warnings && res.warnings.length >= 1)) {
                        Notification.addNotification({
                            message: strings.wrongduringtakingimage,
                            type: 'error'
                        });
                        setFaceValidationAction(strings.facequalityfailed);
                        return false;
                    }

                    const status = res.status;
                    if (status === 'success') {
                        setFaceValidationComplete(strings.facematched);
                        return true;
                    }
                    if (status === 'registered') {
                        setFaceValidationComplete(strings.faceregistered);
                        return true;
                    }
                    if (status === 'photonotuploaded') {
                        setFaceValidationAction(strings.photonotuploaded);
                        return false;
                    }
                    if (status === 'invalidApi') {
                        setFaceValidationAction(strings.invalidapi);
                        return false;
                    }
                    if (status === 'facenotfound') {
                        setFaceValidationAction(strings.facenotfoundoncam);
                        return false;
                    }
                    if (status === 'faceunclear') {
                        setFaceValidationAction(strings.facequalityfailed);
                        return false;
                    }

                    setFaceValidationAction(strings.facenotmatched);
                    return false;
                };

                const validateFacePreflightImage = async function(webcamPicture, faceImage, faceFound) {
                    if (!faceRequired) {
                        return true;
                    }

                    setRequirementStatus('face', 'pending');
                    setFaceValidationSpinner(true);
                    try {
                        const res = await submitFaceValidation(webcamPicture, faceImage, faceFound);
                        return applyFaceValidationResponse(res);
                    } catch (error) {
                        setFaceValidationSpinner(false);
                        setFaceValidationAction(strings.facequalityfailed);
                        Notification.exception(error);
                        return false;
                    }
                };

                // Say up front when the device cannot get through setup. Nothing in the precheck
                // mentioned that a proctored exam needs a laptop or desktop with a camera, so a
                // student on a phone found out at step 2 - by failing it, with no explanation of
                // why - and a student on a camera-less machine failed the same way. This is a
                // notice, not a gate: a touchscreen laptop is a perfectly good exam machine, and
                // guessing wrong must not be able to lock anybody out.
                (function() {
                    const wrapper = document.querySelector('.quiz-check-form');
                    const panel = wrapper ? wrapper.querySelector('.proctoring-preflight-panel') : null;
                    if (!wrapper || !panel || document.getElementById('proctoring-device-notice')) {
                        return;
                    }

                    const hasCamera = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
                    // Touch-only plus a phone-sized screen. Either signal alone is a false positive
                    // waiting to happen: laptops have touchscreens, and a small window is just a
                    // small window.
                    const touchOnly = window.matchMedia
                        && window.matchMedia('(any-pointer: coarse)').matches
                        && !window.matchMedia('(any-pointer: fine)').matches;
                    const smallScreen = Math.min(
                        window.screen ? window.screen.width : window.innerWidth,
                        window.screen ? window.screen.height : window.innerHeight
                    ) <= 820;
                    const looksHandheld = touchOnly && smallScreen;

                    if (hasCamera && !looksHandheld) {
                        return;
                    }

                    const notice = document.createElement('div');
                    notice.id = 'proctoring-device-notice';
                    notice.className = 'alert alert-warning proctoring-device-notice';
                    notice.setAttribute('role', 'alert');
                    notice.textContent = hasCamera
                        ? strings.devicenoticehandheld
                        : strings.devicenoticenocamera;
                    panel.parentNode.insertBefore(notice, panel);
                })();

                // Rebuild the precheck as a two-pane stepper: the requirements checklist
                // becomes a left rail with numbered dots, the active step fills the right
                // pane, and the quiz's submit buttons move into a pinned footer. Purely
                // presentational — the existing step machine keeps driving the state.
                const stepperNodes = (function() {
                    const wrapper = document.querySelector('.quiz-check-form');
                    const panel = wrapper ? wrapper.querySelector('.proctoring-preflight-panel') : null;
                    const stepsWrap = wrapper ? wrapper.querySelector('.proctoring-preflight-steps') : null;
                    if (!wrapper || !panel || !stepsWrap) {
                        return null;
                    }

                    const dialog = wrapper.closest('.modal-dialog');
                    if (dialog) {
                        dialog.classList.add('proctoring-stepper-dialog');
                    }
                    wrapper.classList.add('proctoring-stepper-active');

                    const layout = document.createElement('div');
                    layout.className = 'proctoring-stepper';
                    const rail = document.createElement('div');
                    rail.className = 'proctoring-stepper-rail';
                    const pane = document.createElement('div');
                    pane.className = 'proctoring-stepper-pane';
                    const kicker = document.createElement('div');
                    kicker.className = 'proctoring-stepper-kicker';
                    wrapper.insertBefore(layout, wrapper.firstChild);
                    rail.appendChild(panel);
                    pane.appendChild(kicker);
                    pane.appendChild(stepsWrap);
                    layout.appendChild(rail);
                    layout.appendChild(pane);

                    const footer = document.createElement('div');
                    footer.className = 'proctoring-stepper-footer';
                    const timelimit = document.getElementById('proctoring-stepper-timelimit');
                    if (timelimit) {
                        timelimit.style.display = '';
                        footer.appendChild(timelimit);
                        // The footer line makes quizaccess_timelimit's own "Time limit"
                        // header + paragraph in the same form redundant. Hiding the
                        // message row and the legend separately left the fieldset itself
                        // behind, which reads as a heading with nothing under it - so the
                        // whole fieldset goes, but only once it is established that no
                        // other rule's fields are living inside it.
                        const timelimitmessage = document.getElementById('fitem_id_honestycheckmessage');
                        if (timelimitmessage) {
                            timelimitmessage.style.display = 'none';
                        }
                        const timelimitheader = document.getElementById('id_honestycheckheader');
                        if (timelimitheader) {
                            const timelimitlegend = timelimitheader.querySelector('legend') ||
                                timelimitheader.querySelector('h3, .fheader');
                            if (timelimitlegend) {
                                timelimitlegend.style.display = 'none';
                            }
                            // A Moodle form header renders as a fieldset wrapping every
                            // element up to the next header, so it is only safe to hide
                            // when the time-limit message was its only occupant.
                            const remaining = Array.from(
                                timelimitheader.querySelectorAll('.fitem, .form-group')
                            ).filter(function(item) {
                                return item !== timelimitmessage &&
                                    !(timelimitmessage && timelimitmessage.contains(item)) &&
                                    item.style.display !== 'none';
                            });
                            if (!remaining.length) {
                                timelimitheader.style.display = 'none';
                            }
                        }
                    }
                    const spacer = document.createElement('div');
                    spacer.className = 'proctoring-stepper-spacer';
                    footer.appendChild(spacer);
                    const progress = document.createElement('div');
                    progress.className = 'proctoring-stepper-progress';
                    footer.appendChild(progress);
                    const submitNode = document.getElementById('id_submitbutton');
                    const actionRow = submitNode
                        ? (submitNode.closest('.fitem') || submitNode.closest('.form-group') || submitNode.parentNode)
                        : null;
                    if (actionRow && actionRow !== wrapper && !wrapper.contains(actionRow)) {
                        actionRow.classList.add('proctoring-stepper-actions');
                        footer.appendChild(actionRow);

                        // Cancel belongs beside the button it is the alternative to. Core groups it
                        // with the submit, but only themes that keep that grouping bring it along
                        // when the row moves - elsewhere it was left behind below the card, styled
                        // as a stray default button. Placing it explicitly works either way, and
                        // moving it is safe because the footer is still inside the form, so the
                        // button still submits. It goes after the submit so it reads as the quieter
                        // of the two.
                        const cancelNode = document.getElementById('id_cancel');
                        if (cancelNode && !actionRow.contains(cancelNode)) {
                            (submitNode.parentNode === actionRow ? actionRow : submitNode.parentNode)
                                .insertBefore(cancelNode, submitNode.nextSibling);
                        }
                    }
                    wrapper.appendChild(footer);

                    const items = Array.from(panel.querySelectorAll('.proctoring-preflight-item'));
                    items.forEach(function(item, index) {
                        const dot = document.createElement('span');
                        dot.className = 'proctoring-stepper-dot';
                        dot.textContent = String(index + 1);
                        item.insertBefore(dot, item.firstChild);
                    });

                    return {
                        wrapper: wrapper,
                        stepsWrap: stepsWrap,
                        kicker: kicker,
                        progress: progress,
                        items: items,
                    };
                })();

                const clearViewedStep = function() {
                    if (!stepperNodes) {
                        return;
                    }
                    stepperNodes.stepsWrap.classList.remove('proctoring-viewing');
                    stepperNodes.stepsWrap.querySelectorAll('.proctoring-preflight-step.is-viewed')
                        .forEach(function(node) {
                            node.classList.remove('is-viewed');
                        });
                    stepperNodes.items.forEach(function(item) {
                        item.classList.remove('is-viewing');
                    });
                };

                if (stepperNodes) {
                    stepperNodes.items.forEach(function(item) {
                        item.addEventListener('click', function() {
                            const key = item.id.replace('proctoring-check-', '');
                            const section = stepperNodes.stepsWrap.querySelector(
                                '.proctoring-preflight-step[data-preflight-step="' + key + '"]'
                            );
                            if (!section) {
                                return;
                            }
                            const reachable = item.classList.contains('is-complete') ||
                                key === getCurrentPreflightStep();
                            if (!reachable) {
                                return;
                            }
                            clearViewedStep();
                            if (!section.classList.contains('is-active')) {
                                section.classList.add('is-viewed');
                                item.classList.add('is-viewing');
                                stepperNodes.stepsWrap.classList.add('proctoring-viewing');
                            }
                        });
                    });

                    stepperRefresh = function(ready, currentStep) {
                        clearViewedStep();
                        const total = stepperNodes.items.length;
                        let doneCount = 0;
                        let currentIndex = -1;
                        stepperNodes.items.forEach(function(item, index) {
                            if (item.classList.contains('is-complete')) {
                                doneCount++;
                            }
                            if (item.id === 'proctoring-check-' + currentStep) {
                                currentIndex = index;
                            }
                        });
                        stepperNodes.kicker.textContent = (ready || currentIndex === -1)
                            ? (strings.setupcomplete || '')
                            : (strings.stepcounterpattern || '')
                                .replace('__CURRENT__', String(currentIndex + 1))
                                .replace('__TOTAL__', String(total));
                        stepperNodes.progress.textContent = (strings.progresscountpattern || '')
                            .replace('__DONE__', String(doneCount))
                            .replace('__TOTAL__', String(total));
                    };
                }

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
                            const captureResult = await captureIdDocumentImage(captureSide);
                            if (captureResult === 'blurry') {
                                setIdVerificationResult(
                                    strings.idverificationdocumentblurry || getIdDocumentMissingMessage(captureSide),
                                    false
                                );
                            } else if (captureResult === 'unavailable') {
                                setIdVerificationResult(strings.videonotavailable, false);
                            } else if (captureResult !== 'captured') {
                                setIdVerificationResult(
                                    strings.idverificationdocumentnotinwindow || getIdDocumentMissingMessage(captureSide),
                                    false
                                );
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

                // "I can't provide a photo ID" opens a triage prompt instead of sending
                // anything: the guidance for each answer is already rendered server-side, so
                // this only reveals the matching panel. The two escalation answers record a
                // request so staff can act on it in Moodle, and a no-ID request has to carry
                // a category and the student's own explanation before it can be submitted.
                const exemptTriage = document.getElementById('proctoring-idv-exempt-triage');
                const exemptResult = document.getElementById('id_exemption_result');
                const exemptCategory = document.getElementById('proctoring-idv-exempt-category');
                const exemptDetail = document.getElementById('proctoring-idv-exempt-detail');
                const exemptAlt = document.getElementById('proctoring-idv-exempt-alt');
                const exemptSubmit = document.getElementById('proctoring-idv-exempt-submit');

                const setExemptResult = function(message) {
                    if (exemptResult && message) {
                        exemptResult.textContent = message;
                        exemptResult.style.display = 'block';
                    }
                };

                const recordExemptDeclaration = function(reason, extra) {
                    const args = Object.assign({
                        courseid: parseInt(props.courseid, 10) || 0,
                        cmid: parseInt(props.cmid, 10) || 0,
                        reason: reason,
                    }, extra || {});
                    return Ajax.call([{
                        methodname: 'quizaccess_proctoring_request_id_exemption',
                        args: args
                    }])[0].done(function(res) {
                        setExemptResult(res.message);
                    }).fail(function() {
                        // Recording only helps staff find the student sooner. The student's
                        // actual next step is already on screen, so a failure here must not
                        // replace it with an error.
                    });
                };

                // The follow-up email draft is built here rather than server-side so it can
                // quote the answers the student just gave: the support ticket and the request
                // staff see in Moodle then say the same thing.
                const buildExemptMailLink = function(panel, category, detail, alternatives) {
                    const target = document.getElementById('proctoring-idv-exempt-maillink');
                    const contact = panel.getAttribute('data-contact');
                    if (!target || !contact) {
                        return;
                    }
                    const lines = [panel.getAttribute('data-header'), ''];
                    lines.push(panel.getAttribute('data-reasonlabel'));
                    lines.push(category + (detail ? ' — ' + detail : ''));
                    if (alternatives) {
                        lines.push('');
                        lines.push(panel.getAttribute('data-altlabel'));
                        lines.push(alternatives);
                    }
                    const href = 'mailto:' + contact +
                        '?subject=' + encodeURIComponent(panel.getAttribute('data-subject')) +
                        '&body=' + encodeURIComponent(lines.join('\r\n'));
                    const link = document.createElement('a');
                    link.setAttribute('href', href);
                    link.setAttribute('class', 'proctoring-idv-exempt-mail');
                    link.textContent = contact;
                    target.innerHTML = '';
                    target.appendChild(link);
                };

                const updateExemptSubmitState = function() {
                    if (!exemptSubmit) {
                        return;
                    }
                    const ready = !!(exemptCategory && exemptCategory.value) &&
                        !!(exemptDetail && exemptDetail.value.trim());
                    exemptSubmit.disabled = !ready;
                };
                if (exemptCategory) {
                    exemptCategory.addEventListener('change', updateExemptSubmitState);
                }
                if (exemptDetail) {
                    exemptDetail.addEventListener('input', updateExemptSubmitState);
                }

                if (exemptSubmit) {
                    exemptSubmit.addEventListener('click', function() {
                        const category = exemptCategory ? exemptCategory.value : '';
                        const detail = exemptDetail ? exemptDetail.value.trim() : '';
                        const alternatives = exemptAlt ? exemptAlt.value.trim() : '';
                        if (!category || !detail) {
                            updateExemptSubmitState();
                            return;
                        }
                        exemptSubmit.disabled = true;
                        recordExemptDeclaration('noid', {
                            category: category,
                            detail: detail,
                            alternatives: alternatives,
                        }).done(function(res) {
                            if (res.status === 'incomplete') {
                                exemptSubmit.disabled = false;
                                return;
                            }
                            const panel = document.getElementById('proctoring-idv-exempt-noid-sent');
                            const form = document.querySelector('.proctoring-idv-exempt-form');
                            if (form) {
                                form.style.display = 'none';
                            }
                            if (panel) {
                                const label = exemptCategory
                                    ? exemptCategory.options[exemptCategory.selectedIndex].textContent
                                    : '';
                                buildExemptMailLink(panel, label, detail, alternatives);
                                panel.style.display = 'block';
                            }
                        }).fail(function() {
                            exemptSubmit.disabled = false;
                        });
                    });
                }

                $("#idverificationexempt").click(function(event) {
                    event.preventDefault();
                    if (!exemptTriage) {
                        return;
                    }
                    const open = exemptTriage.style.display !== 'none';
                    exemptTriage.style.display = open ? 'none' : 'block';
                    event.currentTarget.setAttribute('aria-expanded', open ? 'false' : 'true');
                });

                $(".proctoring-idv-exempt-choice").click(function(event) {
                    event.preventDefault();
                    const reason = event.currentTarget.getAttribute('data-exempt-reason');
                    document.querySelectorAll('.proctoring-idv-exempt-answer').forEach(function(panel) {
                        panel.style.display = panel.getAttribute('data-exempt-answer') === reason ? 'block' : 'none';
                    });
                    document.querySelectorAll('.proctoring-idv-exempt-choice').forEach(function(choice) {
                        choice.classList.toggle('active', choice === event.currentTarget);
                    });
                    if (exemptResult) {
                        exemptResult.textContent = '';
                        exemptResult.style.display = 'none';
                    }
                    // Nothing is recorded by picking an answer. A no-ID request is filed by
                    // its own submit button once the form is filled in, and a capture problem
                    // is recorded further in, once the tips have not helped.
                    if (reason === 'noid') {
                        updateExemptSubmitState();
                    }
                });

                $("#proctoring-idv-exempt-stuck").click(function(event) {
                    event.preventDefault();
                    const escalation = document.getElementById('proctoring-idv-exempt-capture-escalation');
                    if (escalation) {
                        escalation.style.display = 'block';
                    }
                    event.currentTarget.disabled = true;
                    recordExemptDeclaration('capture');
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

                    const liveVideo = document.getElementById('proctoring-id-live-video');
                    const cameraWasLive = !!(idLiveStream && liveVideo &&
                        liveVideo.srcObject === idLiveStream && liveVideo.videoWidth);
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

                    // Verify ID starts the camera itself; when it was off, give
                    // auto-exposure and the student a moment before the snapshot.
                    if (!cameraWasLive) {
                        await new Promise((resolve) => window.setTimeout(resolve, 1500));
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
                    let liveFaceImage = "";
                    if (modelurl !== null) {
                        const detection = await detectface(canvas, canvas, crop);
                        if (!detection.qualityPassed) {
                            failIdentity(strings.facequalityfailed);
                            return;
                        }
                        liveFaceFound = detection.faceFound;
                        liveFaceImage = detection.faceImage;
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
                    }])[0].done(async function(res) {
                        if (spinner) {
                            spinner.style.display = 'none';
                        }

                        if (res.status === 'pass') {
                            identityReady = true;
                            setIdVerificationConfirmed(true);
                            setIdVerificationResult(strings.idverificationpassed, true);
                            setRequirementStatus('identity', 'complete');
                            if (faceRequired) {
                                await validateFacePreflightImage(liveImage, liveFaceImage, liveFaceFound);
                            }
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

                /**
                 * Size the marker search window, and the evidence it demands, from how large
                 * the marker actually lands in the captured frame.
                 *
                 * The window used to be a fixed 6x4 tiles (96x64px of a 1280px-wide frame),
                 * but the marker's colour row is 186 CSS px wide: on a 1728px-wide laptop
                 * screen that is ~138px of the analysed frame, half again wider than the
                 * window, so detection depended on clipping the outer two swatches and still
                 * scraping past a flat 18-sample floor. Sizing the window to the row means
                 * whole swatches land inside it, so the floor can scale with the area a real
                 * swatch covers -- a stricter test than the flat 18, which matters because a
                 * wider window would otherwise make it easier for unrelated colourful desktop
                 * content to satisfy all three colours by coincidence.
                 *
                 * Kept in step with the same helper in proctoring.js and screenmonitor.php.
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

                    // One tile of slack on each axis so the window still holds the whole row
                    // when the marker straddles a tile boundary.
                    return {
                        tilesX: Math.min(24, Math.max(6, Math.ceil(rowWidth / tileSize) + 1)),
                        tilesY: Math.min(12, Math.max(4, Math.ceil(swatchHeight / tileSize) + 1)),
                        // countMarkerTiles samples every second pixel on both axes, so this is
                        // the share of one whole swatch that must be visible and on-hue.
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

                /**
                 * Wait for the screen check marker to show up on the shared screen.
                 *
                 * At the instant a share is granted the marker is routinely covered by
                 * something the student did not choose to put there: the browser's share
                 * picker closing, its "you are sharing your screen" bubble, a notification,
                 * or another app that the desktop brings forward as the grant completes. A
                 * single sample taken right then fails an entirely correct share, so give the
                 * marker a few seconds to appear before rejecting the screen.
                 */
                const waitForSharedScreenMarker = async function() {
                    for (let attempts = 0; attempts < 20; attempts++) {
                        if (sharedScreenContainsMarker()) {
                            return true;
                        }
                        await new Promise((resolve) => window.setTimeout(resolve, 500));
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

                    if (!await waitForScreenFrame()) {
                        stopScreenStream();
                        setScreenResult(strings.screensharedenied, false);
                        setRequirementStatus('screen', 'action');
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    if (screenMarkerRequired) {
                        setScreenResult(strings.screenmarkerchecking, true);
                    }
                    if (!await waitForSharedScreenMarker()) {
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

                    // Getting the face image from screenshot.
                    let croppedImage = document.getElementById('validate-cropimg');
                    croppedImage.removeAttribute('src');
                    let faceFound = 0;
                    let faceImage = "";
                    if (modelurl !== null) {
                        const detection = await detectface(canvas, canvas, croppedImage);
                        if (!detection.qualityPassed) {
                            setFaceValidationAction(strings.facequalityfailed);
                            return;
                        }
                        faceFound = detection.faceFound;
                        faceImage = detection.faceImage;
                    }
                    await validateFacePreflightImage(data, faceImage, faceFound);

                });

                return true;
            }
        };
    });
