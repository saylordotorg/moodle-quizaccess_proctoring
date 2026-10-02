// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Optional local device tests before a timed attempt.
 *
 * @module     quizaccess_proctoring/deviceReadiness
 * @copyright  2026 Saylor Academy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax'], function(Ajax) {
    /**
     * Report which surface a screen share captured.
     *
     * Firefox reports neither displaySurface nor any equivalent in the track settings, so an
     * entire-screen share there would otherwise always look like a window. When the browser is
     * silent, a share at least as large as the physical screen is taken to be the entire screen
     * (a multi-monitor desktop is larger still); anything smaller is a window. A maximised window
     * still loses the panel or taskbar, which is well outside the 2% allowed for rounding.
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
        const coversScreen = settings.width >= Math.floor(screenSize.width * ratio * 0.98) &&
            settings.height >= Math.floor(screenSize.height * ratio * 0.98);
        return coversScreen ? 'monitor' : 'window';
    };

    const stopTracks = function(stream) {
        if (stream) {
            stream.getTracks().forEach(function(track) {
                track.stop();
            });
        }
    };

    /**
     * Own test streams independently of the quiz's actual preflight/monitoring streams.
     * The onStream callback attaches and returns a video preview for camera/screen tests.
     *
     * @param {Object} options Media devices and lifecycle callbacks.
     * @returns {Object} Device test controller.
     */
    const createDeviceTests = function(options) {
        let generation = 0;
        let stream = null;
        let disposed = false;
        let autoStop = null;
        let cancelPending = null;
        const stop = function() {
            generation++;
            clearTimeout(autoStop);
            autoStop = null;
            if (cancelPending) {
                cancelPending(true);
                cancelPending = null;
            }
            stopTracks(stream);
            stream = null;
            options.onStream(null, '');
        };
        const waitForPreview = function(preview, kind, token) {
            return new Promise(function(resolve) {
                let finished = false;
                let frameTimer = null;
                let timeout = null;
                const finish = function(status) {
                    if (finished) {
                        return;
                    }
                    finished = true;
                    clearTimeout(frameTimer);
                    clearTimeout(timeout);
                    if (cancelPending === cancel) {
                        cancelPending = null;
                    }
                    resolve(status);
                };
                const cancel = function(reportStopped) {
                    if (reportStopped) {
                        options.onStatus(kind, 'stopped');
                    }
                    finish('stopped');
                };
                const checkFrame = function() {
                    if (finished) {
                        return;
                    }
                    if (disposed || token !== generation) {
                        finish('stopped');
                    } else if (preview.readyState >= 2 && preview.videoWidth > 0 && preview.videoHeight > 0) {
                        finish('passed');
                    } else {
                        frameTimer = setTimeout(checkFrame, 100);
                    }
                };
                cancelPending = cancel;
                // Bound both a hanging play() promise and the first decoded frame.
                timeout = setTimeout(function() {
                    finish('noframes');
                }, options.previewTimeout || 5000);
                try {
                    Promise.resolve(preview.play()).then(checkFrame, function() {
                        finish('previewfailed');
                    });
                } catch (error) {
                    finish('previewfailed');
                }
            });
        };
        return {
            stop: stop,
            dispose: function() {
                disposed = true;
                stop();
            },
            run: async function(kind) {
                if (disposed) {
                    return;
                }
                stop();
                const token = generation;
                const devices = options.mediaDevices;
                if (options.secure === false) {
                    options.onStatus(kind, 'secure');
                    return;
                }
                if (!devices || (kind === 'screen' ? !devices.getDisplayMedia : !devices.getUserMedia)) {
                    options.onStatus(kind, 'unsupported');
                    return;
                }
                options.onStatus(kind, 'checking');
                let timeout = null;
                let cancel = null;
                try {
                    // Call getDisplayMedia before the first await: browsers require the user's click.
                    const request = kind === 'screen'
                        ? devices.getDisplayMedia({video: {displaySurface: 'monitor'}, audio: false})
                        : devices.getUserMedia({video: kind === 'camera', audio: kind === 'microphone'});
                    const guarded = Promise.resolve(request).then(function(value) {
                        if (disposed || token !== generation) {
                            stopTracks(value);
                            return null;
                        }
                        return value;
                    });
                    const acquired = await Promise.race([
                        guarded,
                        new Promise(function(resolve) {
                            cancel = function(reportStopped) {
                                clearTimeout(timeout);
                                if (reportStopped) {
                                    options.onStatus(kind, 'stopped');
                                }
                                resolve(null);
                            };
                            cancelPending = cancel;
                            timeout = setTimeout(function() {
                                if (token === generation) {
                                    generation++;
                                    options.onStatus(kind, 'timeout');
                                }
                                cancel(false);
                            }, options.permissionTimeout || 30000);
                        }),
                    ]);
                    clearTimeout(timeout);
                    if (cancelPending === cancel) {
                        cancelPending = null;
                    }
                    if (!acquired || disposed || token !== generation) {
                        stopTracks(acquired);
                        return;
                    }
                    const track = kind === 'microphone' ? acquired.getAudioTracks()[0] : acquired.getVideoTracks()[0];
                    if (!track || track.readyState === 'ended') {
                        stopTracks(acquired);
                        options.onStatus(kind, 'unavailable');
                        return;
                    }
                    if (kind === 'screen') {
                        const surface = sharedDisplaySurface(track);
                        if (surface !== 'monitor') {
                            stopTracks(acquired);
                            options.onStatus(kind, surface ? 'wrongscreen' : 'screenunknown');
                            return;
                        }
                    }
                    stream = acquired;
                    const preview = options.onStream(stream, kind);
                    track.addEventListener('ended', function() {
                        if (token === generation) {
                            stop();
                            options.onStatus(kind, 'stopped');
                        }
                    }, {once: true});
                    if (kind !== 'microphone') {
                        const previewStatus = await waitForPreview(preview, kind, token);
                        if (disposed || token !== generation) {
                            return;
                        }
                        if (previewStatus !== 'passed') {
                            stop();
                            options.onStatus(kind, previewStatus);
                            return;
                        }
                    }
                    options.onStatus(kind, 'passed');
                    autoStop = setTimeout(stop, options.previewDuration || 8000);
                } catch (error) {
                    clearTimeout(timeout);
                    if (disposed || token !== generation) {
                        return;
                    }
                    stop();
                    const name = error && error.name;
                    options.onStatus(kind, name === 'NotAllowedError' || name === 'SecurityError' ? 'permission' :
                        name === 'NotFoundError' ? 'missing' :
                            name === 'NotReadableError' ? 'inuse' : 'unavailable');
                } finally {
                    clearTimeout(timeout);
                    if (cancelPending === cancel) {
                        cancelPending = null;
                    }
                }
            },
        };
    };

    /**
     * Attach local preview tests and authenticated connectivity probes.
     *
     * @param {Object} props Quiz module and optional legacy localized messages.
     * @returns {Object|null} Cleanup controller for navigation.
     */
    const init = function(props) {
        const root = document.getElementById('proctoring-readiness');
        if (!root) {
            return null;
        }
        // Preloaded by strings_for_js: device tests must work without another network request.
        const messages = Object.assign({}, props.strings || {});
        const preloaded = typeof M !== 'undefined' && M.str && M.str.quizaccess_proctoring;
        if (preloaded) {
            const prefix = 'readiness:';
            Object.keys(preloaded).forEach(function(key) {
                if (key.indexOf(prefix) === 0) {
                    messages[key.slice(prefix.length)] = preloaded[key];
                }
            });
        }
        const video = root.querySelector('[data-readiness-preview]');
        const meter = root.querySelector('[data-readiness-meter]');
        let audioContext = null;
        let meterTimer = null;
        let disposed = false;
        let suspended = false;
        let networkGeneration = 0;
        const pendingProbes = new Set();
        const cleanupPreview = function() {
            clearInterval(meterTimer);
            meterTimer = null;
            if (audioContext) {
                audioContext.close().catch(function() {});
                audioContext = null;
            }
            video.pause();
            video.srcObject = null;
            video.hidden = true;
            meter.value = 0;
            meter.hidden = true;
        };
        const setStatus = function(kind, state, detail) {
            const node = root.querySelector('[data-readiness-status="' + kind + '"]');
            if (!node || disposed || suspended) {
                return;
            }
            node.textContent = (messages[state] || messages.unavailable) + (detail ? ' ' + detail : '');
            node.className = 'mt-2 ' + (state === 'passed' || state === 'reachable' ? 'text-success' :
                state === 'checking' || state === 'notrequired' || state === 'stopped' ? 'text-muted' : 'text-warning');
        };
        const controller = createDeviceTests({
            mediaDevices: navigator.mediaDevices,
            secure: window.isSecureContext,
            onStatus: setStatus,
            onStream: function(stream, kind) {
                cleanupPreview();
                if (!stream) {
                    return null;
                }
                if (kind !== 'microphone') {
                    video.hidden = false;
                    video.srcObject = stream;
                    return video;
                }
                const Context = window.AudioContext || window.webkitAudioContext;
                if (!Context) {
                    return null;
                }
                try {
                    audioContext = new Context();
                    const source = audioContext.createMediaStreamSource(stream);
                    const analyser = audioContext.createAnalyser();
                    analyser.fftSize = 256;
                    source.connect(analyser);
                    const samples = new Uint8Array(analyser.fftSize);
                    meter.hidden = false;
                    audioContext.resume().catch(function() {});
                    meterTimer = setInterval(function() {
                        analyser.getByteTimeDomainData(samples);
                        const peak = samples.reduce(function(value, sample) {
                            return Math.max(value, Math.abs(sample - 128));
                        }, 0);
                        meter.value = peak / 128;
                    }, 100);
                } catch (error) {
                    // Permission still succeeded; a level meter is an optional visual aid.
                    cleanupPreview();
                }
                return null;
            },
        });
        root.querySelectorAll('[data-readiness-device]').forEach(function(button) {
            button.addEventListener('click', function() {
                if (!disposed && !suspended) {
                    controller.run(button.getAttribute('data-readiness-device'));
                }
            });
        });
        const networkButton = root.querySelector('[data-readiness-network]');
        root.querySelector('[data-readiness-stop]').addEventListener('click', function() {
            controller.stop();
        });
        networkButton.addEventListener('click', async function() {
            if (disposed || suspended || networkButton.disabled) {
                return;
            }
            const token = ++networkGeneration;
            networkButton.disabled = true;
            setStatus('network', 'checking');
            setStatus('face', 'checking');
            setStatus('identity', 'checking');
            const rpc = function(providers, payload) {
                const timeoutMs = providers ? 15000 : 10000;
                const request = Ajax.call([{
                    methodname: 'quizaccess_proctoring_readiness',
                    args: {cmid: props.cmid, providers: providers, payload: payload},
                }], true, true, false, timeoutMs)[0];
                let timeout;
                let cancel;
                return Promise.race([
                    Promise.resolve(request),
                    new Promise(function(resolve, reject) {
                        cancel = function() {
                            reject(new Error('Connection check cancelled'));
                        };
                        pendingProbes.add(cancel);
                        timeout = setTimeout(function() {
                            reject(new Error('Connection check timed out'));
                        }, timeoutMs);
                    }),
                ]).finally(function() {
                    clearTimeout(timeout);
                    pendingProbes.delete(cancel);
                });
            };
            try {
                const started = performance.now();
                const response = await rpc(false, 'x'.repeat(32768));
                if (disposed || token !== networkGeneration) {
                    return;
                }
                const elapsed = Math.round(performance.now() - started);
                if (response.receivedbytes !== 32768) {
                    throw new Error('Incomplete connectivity test');
                }
                setStatus('network', elapsed > 3000 ? 'slow' : 'passed', messages.roundtrip.replace('{ms}', elapsed));
                const result = await rpc(true, '');
                if (disposed || token !== networkGeneration) {
                    return;
                }
                result.providers.forEach(function(provider) {
                    if (provider.name === 'face' || provider.name === 'identity') {
                        setStatus(provider.name, provider.status);
                    }
                });
            } catch (error) {
                if (!disposed && token === networkGeneration) {
                    setStatus('network', 'networkerror');
                    setStatus('face', 'notchecked');
                    setStatus('identity', 'notchecked');
                }
            } finally {
                if (!disposed && token === networkGeneration) {
                    networkButton.disabled = false;
                }
            }
        });
        const suspend = function() {
            if (disposed || suspended) {
                return;
            }
            networkGeneration++;
            controller.stop();
            pendingProbes.forEach(function(cancel) {
                cancel();
            });
            if (networkButton.disabled) {
                setStatus('network', 'notchecked');
                setStatus('face', 'notchecked');
                setStatus('identity', 'notchecked');
            }
            networkButton.disabled = false;
            suspended = true;
        };
        const resume = function(event) {
            if (event.persisted && !disposed) {
                suspended = false;
            }
        };
        const dispose = function() {
            suspend();
            disposed = true;
            controller.dispose();
            window.removeEventListener('pagehide', suspend);
            window.removeEventListener('pageshow', resume);
        };
        window.addEventListener('pagehide', suspend);
        window.addEventListener('pageshow', resume);
        return {dispose: dispose};
    };
    return {init: init, createDeviceTests: createDeviceTests};
});
