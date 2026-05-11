// @SuppressWarnings("javascript:S4144");
let isCameraAllowed = false;

define(['jquery', 'core/ajax', 'core/notification', 'core/str'],
    function($, Ajax, Notification, Str) {
        const loadStrings = async function() {
            const stringkeys = [
                {key: 'facenotfoundoncam', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_image', component: 'quizaccess_proctoring'},
                {key: 'wrong_during_taking_screenshot', component: 'quizaccess_proctoring'},
                {key: 'enable_web_camera_before_submitting', component: 'quizaccess_proctoring'},
                {key: 'webcam', component: 'quizaccess_proctoring'},
                {key: 'videonotavailable', component: 'quizaccess_proctoring'},
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

        const initSuspiciousActivityMonitoring = function(props) {
            let lastLogged = {};
            let hiddenStarted = 0;
            const throttleMs = 5000;
            const aiPattern = /(gemini|chatgpt|openai|copilot|claude|perplexity|bard|ask\s+gemini|ask\s+ai)/i;

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

            const logEvent = function(eventType, detail) {
                const now = Date.now();
                const detailText = JSON.stringify(detail || {});
                const throttleKey = eventType + ':' + detailText.substring(0, 120);

                if (lastLogged[throttleKey] && now - lastLogged[throttleKey] < throttleMs) {
                    return;
                }
                lastLogged[throttleKey] = now;

                Ajax.call([{
                    methodname: 'quizaccess_proctoring_log_event',
                    args: {
                        courseid: parseInt(props.courseid, 10) || 0,
                        quizid: parseInt(props.quizid, 10) || 0,
                        attemptid: parseInt(props.status, 10) || 0,
                        reportid: parseInt(props.id, 10) || 0,
                        eventtype: eventType,
                        eventdetail: detailText,
                        pagevisibility: document.visibilityState || '',
                        currenturl: window.location.href
                    }
                }])[0].fail(function() {
                    // Do not interrupt the quiz attempt if activity logging is unavailable.
                });
            };

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

            document.addEventListener('copy', function() {
                logEvent('clipboard_copy', {
                    selectionlength: getSelectedTextLength()
                });
            }, true);

            document.addEventListener('cut', function() {
                logEvent('clipboard_cut', {
                    selectionlength: getSelectedTextLength()
                });
            }, true);

            document.addEventListener('paste', function(event) {
                let pastedLength = 0;
                try {
                    const clipboard = event.clipboardData || window.clipboardData;
                    pastedLength = clipboard ? clipboard.getData('text').length : 0;
                } catch (error) {
                    pastedLength = 0;
                }

                logEvent('clipboard_paste', {
                    pastedlength: pastedLength
                });
            }, true);

            document.addEventListener('contextmenu', function() {
                logEvent('contextmenu', {
                    reason: 'right_click'
                });
            }, true);

            document.addEventListener('keydown', function(event) {
                const key = (event.key || '').toLowerCase();
                const shortcut = getShortcutName(event);
                const ctrlOrMeta = event.ctrlKey || event.metaKey;
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
        };

        return {
            async setup(props, modelurl) {
                const strings = await loadStrings();
                if (modelurl !== null) {
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
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

                if (parseInt(props.monitorbrowseractivity, 10) === 1) {
                    initSuspiciousActivityMonitoring(props);
                }

                const width = props.image_width;
                let height = 0; // This will be computed based on the input stream.
                let streaming = false;
                let data = null;

                $('body').append(`<div class="proctoring-fixed-webcam-box d-flex">`
                    + `<video id="video">${strings.videonotavailable}</video>`
                    + '<img id="cropimg" src="" alt=""/><canvas id="canvas" style="display:none;"></canvas>'
                    + '<div class="output" style="display:none;">'
                    + '<img id="photo" alt="The picture will appear in this box."/></div></div>');

                const video = document.getElementById('video');
                const canvas = document.getElementById('canvas');
                const photo = document.getElementById('photo');

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
                makeElementDraggable(video);

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
                        if (modelurl !== null) {
                            await detectface(photo, croppedImage);
                        }
                        let faceFound;
                        let faceImage;
                        if (croppedImage.src) {
                            if (modelurl !== null) {
                                removeNotifications();
                            }
                            faceFound = 1;
                            faceImage = croppedImage.src;
                        } else {
                            if (modelurl !== null) {
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
