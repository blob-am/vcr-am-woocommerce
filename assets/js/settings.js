/**
 * Injects a "Test connection" button under the API key field on the
 * VCR settings tab and wires it to the `vcr_test_connection` AJAX
 * endpoint exposed by ConnectionTester.php.
 *
 * No jQuery dependency. WP admin already loads jQuery, but vanilla
 * keeps this file portable across future admin redesigns and trims
 * runtime overhead on the settings page.
 *
 * Server-side i18n strings are passed in via `wp_localize_script`
 * under the `vcrSettings` global.
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

        var status = document.createElement('span');
        status.style.marginLeft = '12px';
        status.style.fontWeight = '500';

        apiKeyInput.parentNode.appendChild(button);
        apiKeyInput.parentNode.appendChild(status);

        button.addEventListener('click', function () {
            var apiKey = apiKeyInput.value.trim();
            var baseUrlInput = document.querySelector('#vcr_base_url');
            var baseUrl = baseUrlInput ? baseUrlInput.value.trim() : '';

            // Clear any previous result before kicking off the request.
            status.style.color = '#646970';
            status.textContent = vcrSettings.i18n.testing;
            button.disabled = true;

            var formData = new FormData();
            formData.append('action', vcrSettings.action);
            formData.append('nonce', vcrSettings.nonce);
            formData.append('api_key', apiKey);
            formData.append('base_url', baseUrl);

            fetch(vcrSettings.ajaxUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return { success: false, data: { message: vcrSettings.i18n.networkError } };
                    });
                })
                .then(function (payload) {
                    if (payload && payload.success) {
                        status.style.color = '#00a32a';
                        status.textContent = (payload.data && payload.data.message) || '';
                    } else {
                        status.style.color = '#d63638';
                        status.textContent =
                            (payload && payload.data && payload.data.message) ||
                            vcrSettings.i18n.connectionFailed;
                    }
                })
                .catch(function () {
                    status.style.color = '#d63638';
                    status.textContent = vcrSettings.i18n.networkError;
                })
                .then(function () {
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
