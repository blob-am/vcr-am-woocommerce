/**
 * The Copy button on the problem report screen.
 *
 * Strings come from `wp_localize_script` under the `vcrReport` global.
 */
(function () {
    'use strict';

    function init() {
        var button = document.getElementById('vcr-report-copy');
        var text = document.getElementById('vcr-report-text');
        var status = document.getElementById('vcr-report-status');

        if (!button || !text || !status) {
            return;
        }

        button.addEventListener('click', function () {
            // Selected either way: if the clipboard is unavailable (plain
            // http, an old browser), Ctrl+C is one keystroke away.
            text.focus();
            text.select();

            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                status.textContent = vcrReport.copyFailed;
                return;
            }

            navigator.clipboard.writeText(text.value).then(
                function () {
                    status.textContent = vcrReport.copied;
                },
                function () {
                    status.textContent = vcrReport.copyFailed;
                }
            );
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
