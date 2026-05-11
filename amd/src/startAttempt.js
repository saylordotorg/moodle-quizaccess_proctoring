define(['jquery', 'core/ajax', 'core/notification', 'core/str'],
    function($, Ajax, Notification, Str) {
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
                const faceRequired = parseInt(props.faceidcheck, 10) === 1;
                const screenRequired = parseInt(props.requireentirescreen, 10) === 1;
                let faceReady = !faceRequired;
                let screenReady = !screenRequired;
                let screenStream = null;

                const setScreenConfirmed = function(confirmed) {
                    const input = document.getElementById('id_entirescreenconfirmed');
                    if (input) {
                        input.value = confirmed ? 1 : 0;
                    }
                };

                const updatePreflightGate = function() {
                    if (faceReady && screenReady) {
                        $("#form_activate").css("visibility", "visible");
                        submitButton.show();
                    } else {
                        $("#form_activate").css("visibility", "hidden");
                        submitButton.hide();
                    }
                };

                if (faceRequired || screenRequired) {
                    updatePreflightGate();
                }

                $('#fcvalidate').append('<img id="validate-cropimg" style="display: none;" src="" alt=""/>');
                $("#screensharevalidate").click(async function(event) {
                    event.preventDefault();

                    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                        $("#screen_share_result").html(`<span style="color: red">${strings.screensharenotsupported}</span>`);
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    if (screenStream) {
                        screenStream.getTracks().forEach((track) => track.stop());
                        screenStream = null;
                    }

                    try {
                        screenStream = await navigator.mediaDevices.getDisplayMedia({
                            video: {
                                displaySurface: 'monitor'
                            },
                            audio: false
                        });
                    } catch (error) {
                        $("#screen_share_result").html(`<span style="color: red">${strings.screensharedenied}</span>`);
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    const videoTrack = screenStream.getVideoTracks()[0];
                    const settings = videoTrack && videoTrack.getSettings ? videoTrack.getSettings() : {};
                    if (!videoTrack || settings.displaySurface !== 'monitor') {
                        if (screenStream) {
                            screenStream.getTracks().forEach((track) => track.stop());
                            screenStream = null;
                        }
                        $("#screen_share_result").html(`<span style="color: red">${strings.entirescreenrequired}</span>`);
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                        return;
                    }

                    videoTrack.addEventListener('ended', function() {
                        $("#screen_share_result").html(`<span style="color: red">${strings.screensharestopped}</span>`);
                        screenReady = false;
                        setScreenConfirmed(false);
                        updatePreflightGate();
                    });

                    $("#screen_share_result").html(`<span style="color: green">${strings.screenshareaccepted}</span>`);
                    screenReady = true;
                    setScreenConfirmed(true);
                    updatePreflightGate();
                });

                $("#fcvalidate").click(async function(event) {

                    event.preventDefault();
                    const photo = document.getElementById('photo');
                    const canvas = document.getElementById('canvas');
                    const video = document.getElementById('video');
                    const context = canvas.getContext('2d');
                    canvas.width = props.imagewidth;

                    canvas.height = canvas.width / (4 / 3);
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
                                updatePreflightGate();
                            } else if (status === 'registered') {
                                $("#video").css("border", "10px solid green");
                                $("#face_validation_result").html(`<span style="color: green">${strings.faceregistered}</span>`);
                                document.getElementById("fcvalidate").style.display = "none";
                                faceReady = true;
                                updatePreflightGate();
                            } else if (status === 'photonotuploaded') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.photonotuploaded}</span>`);
                            } else if (status === 'invalidApi') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.invalidapi}</span>`);
                            } else if (status === 'facenotfound') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.facenotfoundoncam}</span>`);
                            } else if (status === 'faceunclear') {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.facequalityfailed}</span>`);
                            } else {
                                $("#video").css("border", "10px solid red");
                                $("#face_validation_result").html(`<span style="color: red">${strings.facenotmatched}</span>`);
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
