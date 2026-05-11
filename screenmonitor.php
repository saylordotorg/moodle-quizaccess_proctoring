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

list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
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

$config = [
    'channel' => 'quizaccess_proctoring_screen_' . $key,
    'statuskey' => 'quizaccess_proctoring_screen_status_' . $key,
    'strings' => [
        'share' => get_string('screenmonitor:share', 'quizaccess_proctoring'),
        'ready' => get_string('screenmonitor:ready', 'quizaccess_proctoring'),
        'stopped' => get_string('screenmonitor:stopped', 'quizaccess_proctoring'),
        'unsupported' => get_string('screenmonitor:unsupported', 'quizaccess_proctoring'),
        'wrongmonitor' => get_string('screenmonitor:wrongmonitor', 'quizaccess_proctoring'),
        'denied' => get_string('screensharedenied', 'quizaccess_proctoring'),
        'entirescreenrequired' => get_string('entirescreenrequired', 'quizaccess_proctoring'),
    ],
];

echo $OUTPUT->header();
?>
<div class="proctoring-screen-monitor">
    <h3><?php echo s(get_string('screenmonitor:title', 'quizaccess_proctoring')); ?></h3>
    <p><?php echo s(get_string('screenmonitor:instructions', 'quizaccess_proctoring')); ?></p>
    <div id="proctoring-screen-monitor-status" class="proctoring-screen-monitor-status"></div>
    <button id="proctoring-screen-monitor-share" class="btn btn-primary">
        <?php echo s(get_string('screenmonitor:share', 'quizaccess_proctoring')); ?>
    </button>
</div>
<?php
$js = <<<JS
(function(config) {
    const markerGraceMs = 8000;
    const statusIntervalMs = 2000;
    const markerMissingNotifyMs = 10000;
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
        const imageData = drawFrame(true);
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

    const stopStream = function() {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (video) {
            video.srcObject = null;
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

    const startShare = async function(event) {
        if (event) {
            event.preventDefault();
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            setStatus(config.strings.unsupported, 'danger');
            clearStatus();
            return;
        }

        stopStream();

        try {
            stream = await navigator.mediaDevices.getDisplayMedia({
                video: {
                    displaySurface: 'monitor'
                },
                audio: false
            });
        } catch (error) {
            setStatus(config.strings.denied, 'danger');
            clearStatus();
            return;
        }

        const videoTrack = stream.getVideoTracks()[0];
        const settings = videoTrack && videoTrack.getSettings ? videoTrack.getSettings() : {};
        displaySurface = settings.displaySurface || '';
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

        try {
            await video.play();
        } catch (error) {
            stopStream();
            setStatus(config.strings.denied, 'danger');
            return;
        }

        if (!await waitForFrame()) {
            stopStream();
            setStatus(config.strings.denied, 'danger');
            return;
        }

        ready = true;
        stopped = false;
        checkMarker();

        videoTrack.addEventListener('ended', function() {
            setStatus(config.strings.stopped, 'danger');
            clearStatus();
        });
    };

    if (window.BroadcastChannel) {
        channel = new BroadcastChannel(config.channel);
        channel.onmessage = function(event) {
            const message = event.data || {};
            if (message.type === 'status_request') {
                publishStatus();
            } else if (message.type === 'screenshot_request' && ready && channel) {
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

    window.addEventListener('beforeunload', clearStatus);
    window.setInterval(checkMarker, statusIntervalMs);
    publishStatus();
})(%s);
JS;

echo html_writer::script(sprintf($js, json_encode($config)));
echo $OUTPUT->footer();
?>
