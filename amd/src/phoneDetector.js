// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

/**
 * Phone detection (TensorFlow.js 4 and COCO-SSD), run in a hidden same-origin iframe (CPIT-484).
 *
 * The attempt page also runs face-api.js, which bundles TensorFlow.js core 1.7 and keeps its
 * engine in the page-wide `window._tfengine`. TensorFlow.js 4 adopts whatever engine it finds
 * there, and both versions look it up again on every operation, so on one page either COCO-SSD
 * failed to load ("n is not a function", reported as "the detector never started") or, with the
 * engine swapped, face detection stopped. An iframe is a window of its own: the libraries load
 * there with their own engine and no RequireJS, and each check draws the webcam frame onto a
 * canvas in that window, so neither library can see the other.
 *
 * @module quizaccess_proctoring/phoneDetector
 */
define([], function() {
    /** @var {string[]} The UMD bundles to run, in order, from the library directory. */
    const LIBRARIES = ['tf.min.js', 'coco-ssd.min.js'];

    /**
     * Create the hidden iframe and wait for its empty document.
     *
     * `srcdoc` rather than no `src`: an iframe's first about:blank document can be replaced when
     * it finishes loading, taking any scripts already run in it along.
     *
     * @returns {Promise<Window>} The iframe's window.
     */
    const createRealm = function() {
        return new Promise(function(resolve, reject) {
            const frame = document.createElement('iframe');
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute('tabindex', '-1');
            frame.style.display = 'none';
            frame.addEventListener('load', function() {
                if (frame.contentWindow && frame.contentWindow.document) {
                    resolve(frame.contentWindow);
                } else {
                    reject(new Error('The phone detection frame did not load'));
                }
            }, {once: true});
            frame.srcdoc = '<!DOCTYPE html><html><head></head><body></body></html>';
            document.body.appendChild(frame);
        });
    };

    /**
     * Fetch a script and run it inline in the iframe's window.
     *
     * An inline script runs during appendChild, and an error it throws goes to that window's
     * error listeners rather than to the caller, so it is caught there.
     *
     * @param {Window} realm The iframe's window.
     * @param {string} src Script URL (same origin).
     * @returns {Promise<void>} Resolves once the script has run.
     */
    const runScript = async function(realm, src) {
        const response = await window.fetch(src, {credentials: 'same-origin'});
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ' loading ' + src);
        }
        const code = await response.text();

        const script = realm.document.createElement('script');
        script.text = code + '\n//# sourceURL=' + src;
        let failure = null;
        const onError = function(event) {
            if (!failure) {
                failure = event.error || new Error(event.message || ('Error running ' + src));
            }
        };
        realm.addEventListener('error', onError);
        try {
            realm.document.head.appendChild(script);
        } finally {
            realm.removeEventListener('error', onError);
        }
        if (failure) {
            throw failure;
        }
    };

    /**
     * Load the libraries and the model.
     *
     * @param {string} baseUrl The library directory: tf.min.js, coco-ssd.min.js and model/model.json.
     * @returns {Promise<{detect: function((HTMLVideoElement|HTMLCanvasElement)): Promise<Array>}>} The
     *     detector: detect() resolves to COCO-SSD's predictions for the source's current frame.
     */
    const load = async function(baseUrl) {
        const realm = await createRealm();
        for (const library of LIBRARIES) {
            await runScript(realm, baseUrl + '/' + library);
        }
        if (!realm.cocoSsd) {
            throw new Error('COCO-SSD did not load');
        }
        const model = await realm.cocoSsd.load({modelUrl: baseUrl + '/model/model.json'});
        // A canvas of the iframe's own window: TensorFlow.js checks its input against that window's
        // element types, so a canvas or video of the quiz page would not be recognised.
        const canvas = realm.document.createElement('canvas');

        return {
            detect: function(source) {
                const width = source.videoWidth || source.width;
                const height = source.videoHeight || source.height;
                if (!width || !height) {
                    return Promise.resolve([]);
                }
                canvas.width = width;
                canvas.height = height;
                canvas.getContext('2d').drawImage(source, 0, 0, width, height);
                return model.detect(canvas);
            },
        };
    };

    return {load: load};
});
