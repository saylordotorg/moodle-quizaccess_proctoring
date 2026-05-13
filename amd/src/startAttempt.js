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
                const honorRequired = parseInt(props.honorrequired || 0, 10) === 1;
                const captchaRequired = parseInt(props.captcharequired || 0, 10) === 1;
                const multiMonitorMode = ['log', 'warn', 'block'].includes(props.multimonitormode) ?
                    props.multimonitormode : 'off';
                const multiMonitorBlocks = multiMonitorMode === 'block';
                const submitButtonDefaultLabel = submitButton.is('input') ? submitButton.val() : submitButton.text();
                let faceReady = !faceRequired;
                let screenReady = !screenRequired;
                let honorReady = !honorRequired;
                let captchaReady = !captchaRequired;
                let multiMonitorReady = !multiMonitorBlocks;
                let screenStream = null;
                let screenVideo = null;
                let screenCanvas = null;
                let screenMonitorClient = null;
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

                const setSubmitButtonLabel = function(label) {
                    if (submitButton.is('input')) {
                        submitButton.val(label);
                    } else {
                        submitButton.text(label);
                    }
                };

                const getCurrentPreflightStep = function() {
                    if (honorRequired && !honorReady) {
                        return 'honor';
                    }
                    if (captchaRequired && !captchaReady) {
                        return 'captcha';
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
                            stepName === 'honor' && honorReady ||
                            stepName === 'captcha' && captchaReady ||
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
                    const ready = honorReady && captchaReady && faceReady && screenReady && multiMonitorReady;
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

                syncHonorRequirement();
                syncCaptchaRequirement();
                if (faceRequired) {
                    setRequirementStatus('face', 'pending');
                }
                if (screenRequired) {
                    setRequirementStatus('screen', 'pending');
                }
                if (multiMonitorBlocks) {
                    setRequirementStatus('multimonitor', 'pending');
                }

                if (honorRequired || captchaRequired || faceRequired || screenRequired || multiMonitorBlocks) {
                    updatePreflightGate();
                }

                const honorCheckbox = document.querySelector('input[name="proctoring"]');
                if (honorCheckbox) {
                    honorCheckbox.addEventListener('change', function() {
                        syncHonorRequirement();
                        updatePreflightGate();
                    });
                }

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

                const initScreenMarker = function() {
                    if (!screenRequired || document.getElementById('proctoring-screen-verification-marker')) {
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
