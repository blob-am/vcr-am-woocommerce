/**
 * Injects a "Test connection" button under the API key field on the
 * VCR settings tab and wires it to the `vcr_test_connection` AJAX
 * endpoint exposed by ConnectionTester.php.
 *
 * No jQuery dependency. WP admin already loads jQuery, but vanilla
 * keeps this file portable across future admin redesigns and trims
 * runtime overhead on the settings page.
 *
 * Server-side i18n strings, AJAX URL, nonce, and the per-request
 * timeout ceiling are passed in via `wp_localize_script` under the
 * `vcrSettings` global.
 */
(function () {
    'use strict';

    function init() {
        var apiKeyInput = document.querySelector('#vcr_api_key');
        if (!apiKeyInput) {
            return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.textContent = vcrSettings.i18n.testButton;
        button.style.marginLeft = '8px';

        // role + aria-live so screen readers announce status changes
        // without the admin having to refocus on the line. `polite`
        // (vs `assertive`) waits for the current speech to finish.
        var status = document.createElement('span');
        status.className = 'vcr-test-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.style.marginLeft = '12px';
        status.style.fontWeight = '500';

        apiKeyInput.parentNode.appendChild(button);
        apiKeyInput.parentNode.appendChild(status);

        function setStatus(message, color) {
            // WP admin palette: success #00a32a, error #d63638, neutral
            // #646970. Inline styles rather than custom classes keep the
            // asset to a single file; an admin who wants to override
            // colours can target `.vcr-test-status`.
            status.style.color = color;
            status.textContent = message;
        }

        button.addEventListener('click', function () {
            var apiKey = apiKeyInput.value.trim();
            var baseUrlInput = document.querySelector('#vcr_base_url');
            var baseUrl = baseUrlInput ? baseUrlInput.value.trim() : '';

            setStatus(vcrSettings.i18n.testing, '#646970');
            button.disabled = true;

            var formData = new FormData();
            formData.append('action', vcrSettings.action);
            formData.append('nonce', vcrSettings.nonce);
            formData.append('api_key', apiKey);
            formData.append('base_url', baseUrl);

            // AbortController gives us a deterministic browser-side ceiling
            // — without it, fetch waits for the underlying TCP timeout
            // (often minutes) on a black-holed endpoint. The timeout here
            // is set slightly *longer* than the server-side limit so the
            // server's actual error response wins the race; if the
            // browser aborted first the admin would always see "timed
            // out" instead of the real upstream error.
            var controller = new AbortController();
            var timeoutId = setTimeout(function () {
                controller.abort();
            }, vcrSettings.timeoutMs);

            fetch(vcrSettings.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { success: false, data: { message: vcrSettings.i18n.networkError } };
                    });
                })
                .then(function (payload) {
                    if (payload && payload.success) {
                        setStatus(
                            (payload.data && payload.data.message) || '',
                            '#00a32a',
                        );
                    } else {
                        setStatus(
                            (payload && payload.data && payload.data.message) ||
                                vcrSettings.i18n.connectionFailed,
                            '#d63638',
                        );
                    }
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        setStatus(vcrSettings.i18n.timedOut, '#d63638');
                    } else {
                        setStatus(vcrSettings.i18n.networkError, '#d63638');
                    }
                })
                .then(function () {
                    clearTimeout(timeoutId);
                    button.disabled = false;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
