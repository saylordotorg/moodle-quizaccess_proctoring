<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Persistent desktop share monitor for quizaccess_proctoring.
 *
 * @package    quizaccess_proctoring
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$key = required_param('key', PARAM_ALPHANUMEXT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
$context = context_module::instance($cmid, MUST_EXIST);
require_login($course, true, $cm);

$url = new moodle_url('/mod/quiz/accessrule/proctoring/screenmonitor.php', [
    'cmid' => $cmid,
    'key' => $key,
]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('screenmonitor:title', 'quizaccess_proctoring'));
$PAGE->set_heading(get_string('screenmonitor:title', 'quizaccess_proctoring'));
$PAGE->requires->css('/mod/quiz/accessrule/proctoring/styles.css');

// Resolved from the site setting rather than a request parameter: a student must not be
// able to switch the screen check off by editing the helper window's URL. Kept in step
// with should_require_screen_marker() in rule.php, which is not autoloadable from here.
$markerrequired = (int)get_config('quizaccess_proctoring', 'requirescreenmarker') === 1;

$config = [
    'channel' => 'quizaccess_proctoring_screen_' . $key,
    'statuskey' => 'quizaccess_proctoring_screen_status_' . $key,
    'markerrequired' => $markerrequired,
    'strings' => [
        'share' => get_string('screenmonitor:share', 'quizaccess_proctoring'),
        'ready' => get_string('screenmonitor:ready', 'quizaccess_proctoring'),
        'stopped' => get_string('screenmonitor:stopped', 'quizaccess_proctoring'),
        'unsupported' => get_string('screenmonitor:unsupported', 'quizaccess_proctoring'),
        'wrongmonitor' => get_string('screenmonitor:wrongmonitor', 'quizaccess_proctoring'),
        'permissiondenied' => get_string('screenmonitor:permissiondenied', 'quizaccess_proctoring'),
        'captureunavailable' => get_string('screenmonitor:captureunavailable', 'quizaccess_proctoring'),
        'windowinactive' => get_string('screenmonitor:windowinactive', 'quizaccess_proctoring'),
        'requestfailed' => get_string('screenmonitor:requestfailed', 'quizaccess_proctoring'),
        'playbackfailed' => get_string('screenmonitor:playbackfailed', 'quizaccess_proctoring'),
        'noframes' => get_string('screenmonitor:noframes', 'quizaccess_proctoring'),
        'entirescreenrequired' => get_string('entirescreenrequired', 'quizaccess_proctoring'),
    ],
];

echo $OUTPUT->header();

$titlestr = s(get_string('screenmonitor:title', 'quizaccess_proctoring'));
$instructionsstr = s(get_string('screenmonitor:instructions', 'quizaccess_proctoring'));
$sharestr = s(get_string('screenmonitor:share', 'quizaccess_proctoring'));

echo <<<HTML
<div class="proctoring-screen-monitor">
    <div class="proctoring-screen-monitor-titlebar">
        <h3>{$titlestr}</h3>
    </div>
    <p class="proctoring-screen-monitor-instructions">
        {$instructionsstr}
    </p>
    <div id="proctoring-screen-monitor-status" class="proctoring-screen-monitor-status"></div>
    <button id="proctoring-screen-monitor-share" class="btn btn-primary">
        {$sharestr}
    </button>
</div>
HTML;

$js = <<<JS
(function(config) {
    const markerGraceMs = 30000;
    const statusIntervalMs = 2000;
    const markerMissingNotifyMs = 15000;
    const playbackTimeoutMs = 5000;
    let channel = null;
    let stream = null;
    let video = null;
    let canvas = null;
    let ready = false;
    let stopped = true;
    let markerVisible = false;
    let lastMarkerSeen = 0;
    let lastMarkerMissingMessage = 0;
    let displaySurface = '';
    let statusTimer = null;
    let pageActive = true;
    let shareGeneration = 0;
    let cancelPlaybackWait = null;

    const statusNode = document.getElementById('proctoring-screen-monitor-status');
    const shareButton = document.getElementById('proctoring-screen-monitor-share');

    const setStatus = function(message, type) {
        if (!statusNode) {
            return;
        }
        statusNode.className = 'proctoring-screen-monitor-status text-' + type;
        statusNode.textContent = message;
    };

    const tileKey = function(x, y) {
        return x + ':' + y;
    };

    // Size the marker search window, and the evidence it demands, from how large the
    // marker lands in the captured frame. A fixed 6x4 tile window (96x64px of a 1280px
    // frame) is narrower than the marker's 186 CSS px colour row on a typical laptop
    // screen, so detection depended on clipping the outer two swatches to scrape past a
    // flat 18-sample floor. Sizing the window to the row lets whole swatches land inside
    // it, so the floor can scale with a real swatch's area -- stricter than the flat 18,
    // which matters because a wider window would otherwise make coincidental matches on
    // colourful desktop content easier. Kept in step with the AMD modules' copies.
    const markerSearchGeometry = function(frameWidth, tileSize) {
        const fallback = {tilesX: 6, tilesY: 4, minSamples: 18};
        const screenWidth = window.screen ? window.screen.width : 0;
        if (!screenWidth || !frameWidth || !tileSize) {
            return fallback;
        }

        // Captured pixels per CSS pixel of the shared screen. Matches styles.css: three
        // 58x24 swatches separated by two 6px gaps.
        const scale = frameWidth / screenWidth;
        const rowWidth = 186 * scale;
        const swatchWidth = 58 * scale;
        const swatchHeight = 24 * scale;
        if (!(rowWidth > 0) || !(swatchHeight > 0)) {
            return fallback;
        }

        return {
            tilesX: Math.min(24, Math.max(6, Math.ceil(rowWidth / tileSize) + 1)),
            tilesY: Math.min(12, Math.max(4, Math.ceil(swatchHeight / tileSize) + 1)),
            minSamples: Math.max(18, Math.round(
                Math.floor(swatchWidth / 2) * Math.floor(swatchHeight / 2) * 0.35
            ))
        };
    };

    const drawFrame = function(imageDataOnly) {
        const sourceWidth = video ? video.videoWidth || 0 : 0;
        const sourceHeight = video ? video.videoHeight || 0 : 0;
        if (!sourceWidth || !sourceHeight) {
            return null;
        }

        if (!canvas) {
            canvas = document.createElement('canvas');
        }

        const targetWidth = Math.min(1280, sourceWidth);
        const targetHeight = Math.round(sourceHeight * (targetWidth / sourceWidth));
        canvas.width = targetWidth;
        canvas.height = targetHeight;
        const context = canvas.getContext('2d');
        context.drawImage(video, 0, 0, targetWidth, targetHeight);

        return imageDataOnly ? context.getImageData(0, 0, targetWidth, targetHeight) : canvas.toDataURL('image/jpeg', 0.75);
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
        // Nothing is drawn over the quiz to look for, so an entire-screen share is all
        // that was asked for. Reporting it as present keeps the quiz page's readiness
        // check satisfied instead of holding its gate shut on a check nobody wants.
        if (!config.markerrequired) {
            return true;
        }

        const imageData = drawFrame(true);
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

    const markerIsCurrent = function() {
        return markerVisible || (lastMarkerSeen > 0 && Date.now() - lastMarkerSeen <= markerGraceMs);
    };

    const buildStatus = function() {
        return {
            type: 'status',
            ready: ready,
            marker: ready ? markerIsCurrent() : false,
            stopped: stopped,
            displaySurface: displaySurface,
            ts: Date.now()
        };
    };

    const publishStatus = function() {
        const status = buildStatus();
        try {
            window.localStorage.setItem(config.statuskey, JSON.stringify(status));
        } catch (error) {
            // Ignore storage failures; BroadcastChannel still carries status while pages are open.
        }

        if (channel) {
            channel.postMessage(status);
        }
    };

    const clearStatus = function() {
        ready = false;
        stopped = true;
        markerVisible = false;
        publishStatus();
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

    const stopStream = function() {
        shareGeneration++;
        if (cancelPlaybackWait) {
            cancelPlaybackWait();
        }
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (video) {
            video.srcObject = null;
        }
        if (canvas) {
            canvas.width = 0;
            canvas.height = 0;
        }
        clearStatus();
    };

    const checkMarker = function() {
        if (!ready) {
            publishStatus();
            return;
        }

        markerVisible = sharedScreenContainsMarker();
        if (markerVisible) {
            lastMarkerSeen = Date.now();
            setStatus(config.strings.ready, 'success');
        } else if (!markerIsCurrent()) {
            setStatus(config.strings.wrongmonitor, 'danger');
            if (channel && Date.now() - lastMarkerMissingMessage > markerMissingNotifyMs) {
                lastMarkerMissingMessage = Date.now();
                channel.postMessage({
                    type: 'marker_missing',
                    ts: Date.now()
                });
            }
        }
        publishStatus();
    };

    const waitForFrame = async function() {
        for (let attempts = 0; attempts < 20; attempts++) {
            if (video && video.videoWidth && video.videoHeight) {
                return true;
            }
            await new Promise((resolve) => window.setTimeout(resolve, 100));
        }

        return false;
    };

    const waitForPlayback = function() {
        return new Promise((resolve) => {
            let timer = null;
            let settled = false;
            const finish = function(result) {
                if (settled) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                if (cancelPlaybackWait === cancel) {
                    cancelPlaybackWait = null;
                }
                resolve(result);
            };
            const cancel = () => finish('cancelled');
            cancelPlaybackWait = cancel;
            timer = window.setTimeout(() => finish('timeout'), playbackTimeoutMs);
            try {
                // Keep both handlers attached so a late result cannot affect a later share or reject unhandled.
                Promise.resolve(video.play()).then(() => finish('playing'), () => finish('failed'));
            } catch (error) {
                finish('failed');
            }
        });
    };

    const requestFailureMessage = function(error) {
        // Browser errors can contain sensitive details. Only known names select a translated message.
        switch (error && error.name) {
            case 'NotAllowedError':
            case 'SecurityError':
                return config.strings.permissiondenied;
            case 'NotReadableError':
                return config.strings.captureunavailable;
            case 'InvalidStateError':
                return config.strings.windowinactive;
            default:
                return config.strings.requestfailed;
        }
    };

    const startShare = async function(event) {
        if (event) {
            event.preventDefault();
        }
        if (!pageActive) {
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            setStatus(config.strings.unsupported, 'danger');
            clearStatus();
            return;
        }

        stopStream();
        const generation = shareGeneration;

        try {
            const granted = await navigator.mediaDevices.getDisplayMedia({
                video: {
                    displaySurface: 'monitor'
                },
                audio: false
            });
            if (!pageActive || generation !== shareGeneration) {
                granted.getTracks().forEach(track => track.stop());
                return;
            }
            stream = granted;
        } catch (error) {
            if (pageActive && generation === shareGeneration) {
                setStatus(requestFailureMessage(error), 'danger');
                clearStatus();
            }
            return;
        }

        const videoTrack = stream.getVideoTracks()[0];
        displaySurface = sharedDisplaySurface(videoTrack);
        if (!videoTrack || displaySurface !== 'monitor') {
            stopStream();
            setStatus(config.strings.entirescreenrequired, 'danger');
            return;
        }

        if (!video) {
            video = document.createElement('video');
            video.muted = true;
            video.playsInline = true;
        }
        video.srcObject = stream;

        const playback = await waitForPlayback();
        if (!pageActive || generation !== shareGeneration || playback === 'cancelled') {
            return;
        }
        if (playback !== 'playing') {
            stopStream();
            setStatus(playback === 'timeout' ? config.strings.noframes : config.strings.playbackfailed, 'danger');
            return;
        }

        const hasFrame = await waitForFrame();
        if (!pageActive || generation !== shareGeneration) {
            return;
        }
        if (!hasFrame) {
            stopStream();
            setStatus(config.strings.noframes, 'danger');
            return;
        }

        ready = true;
        stopped = false;
        checkMarker();

        videoTrack.addEventListener('ended', function() {
            if (!pageActive || generation !== shareGeneration) {
                return;
            }
            setStatus(config.strings.stopped, 'danger');
            clearStatus();
        });
    };

    if (window.BroadcastChannel) {
        channel = new BroadcastChannel(config.channel);
        channel.onmessage = function(event) {
            if (!pageActive) {
                return;
            }
            const message = event.data || {};
            if (message.type === 'status_request') {
                // Run a fresh marker check instead of replying from cache: browsers
                // throttle this hidden window's timers (to as little as once a
                // minute), so the interval-driven marker state can be long stale by
                // the time the quiz page asks. Message handlers are not throttled,
                // and checkMarker() publishes the status itself.
                checkMarker();
            } else if (message.type === 'screenshot_request' && ready && channel) {
                const tracks = stream ? stream.getVideoTracks() : [];
                if (!tracks.length || tracks.every(track => track.readyState === 'ended' || track.muted)) {
                    clearStatus();
                    return;
                }
                channel.postMessage({
                    type: 'screenshot',
                    image: drawFrame(false) || '',
                    marker: markerIsCurrent(),
                    ts: Date.now()
                });
            }
        };
    }

    if (shareButton) {
        shareButton.addEventListener('click', startShare);
    }

    window.addEventListener('pagehide', function() {
        pageActive = false;
        stopStream();
        window.clearInterval(statusTimer);
        statusTimer = null;
    });
    window.addEventListener('pageshow', function(event) {
        if (event.persisted && !statusTimer) {
            pageActive = true;
            statusTimer = window.setInterval(checkMarker, statusIntervalMs);
            setStatus(config.strings.stopped, 'danger');
            publishStatus();
        }
    });
    statusTimer = window.setInterval(checkMarker, statusIntervalMs);
    publishStatus();
})(%s);
JS;

echo html_writer::script(sprintf($js, json_encode($config)));
echo $OUTPUT->footer();
