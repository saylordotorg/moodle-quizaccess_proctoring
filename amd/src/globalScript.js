// This file is part of Moodle - https://moodle.org/
// Licensed under the GNU GPL v3 or later, https://www.gnu.org/copyleft/gpl.html.

/**
 * Load a third-party UMD library so it lands on window, even on a page running RequireJS.
 *
 * TensorFlow.js and COCO-SSD ship as UMD bundles: when a global AMD `define` exists they
 * register an anonymous module instead of setting window.tf / window.cocoSsd. Moodle pages
 * always have RequireJS, so a plain script tag left both globals unset, RequireJS reported
 * "Mismatched anonymous define() module", and phone detection never started (CPIT-484).
 *
 * The source is fetched first and then run as an inline script, which executes during
 * appendChild - so `define` and `require` are hidden only for that synchronous run and no
 * module RequireJS loads meanwhile can ever see them missing.
 *
 * @module quizaccess_proctoring/globalScript
 */
define([], function() {
    /**
     * Fetch and run a UMD script as a browser global.
     *
     * @param {string} src Script URL (same origin).
     * @returns {Promise<void>} Resolves once the script has run.
     */
    const load = async function(src) {
        const response = await window.fetch(src, {credentials: 'same-origin'});
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ' loading ' + src);
        }
        const code = await response.text();

        const script = document.createElement('script');
        script.text = code + '\n//# sourceURL=' + src;

        const amdDefine = window.define;
        const amdRequire = window.require;
        let failure = null;
        const onError = function(event) {
            if (!failure) {
                failure = event.error || new Error(event.message || ('Error running ' + src));
            }
        };
        window.define = undefined;
        window.require = undefined;
        window.addEventListener('error', onError);
        try {
            document.head.appendChild(script);
        } finally {
            window.removeEventListener('error', onError);
            window.define = amdDefine;
            window.require = amdRequire;
        }
        if (failure) {
            throw failure;
        }
    };

    return {load: load};
});
