/**
 * Turns the "Classifier code for new catalog items" select into a search
 * over the codes the tax service accepts, fed by the
 * `vcr_search_classifier` AJAX endpoint (ClassifierSearchEndpoint.php).
 *
 * selectWoo is WooCommerce's own select2 fork and already styled on this
 * screen; jQuery comes with it. Settings arrive under `vcrClassifierSelect`
 * via `wp_localize_script`.
 */
(function ($) {
    'use strict';

    function init() {
        var $select = $('select.vcr-classifier-select');
        if (!$select.length || typeof $select.selectWoo !== 'function') {
            return;
        }

        var settings = window.vcrClassifierSelect;

        $select.selectWoo({
            width: '100%',
            allowClear: true,
            placeholder: settings.i18n.placeholder,
            minimumInputLength: settings.minimumInputLength,
            ajax: {
                url: settings.ajaxUrl,
                dataType: 'json',
                delay: 300,
                timeout: settings.timeoutMs,
                data: function (params) {
                    return {
                        action: settings.action,
                        nonce: settings.nonce,
                        term: params.term || '',
                    };
                },
                processResults: function (payload) {
                    if (payload && payload.success && payload.data) {
                        return { results: payload.data.results };
                    }
                    return { results: [] };
                },
            },
            language: {
                inputTooShort: function () {
                    return settings.i18n.inputTooShort;
                },
                searching: function () {
                    return settings.i18n.searching;
                },
                noResults: function () {
                    return settings.i18n.noResults;
                },
                errorLoading: function () {
                    return settings.i18n.failed;
                },
            },
        });
    }

    $(init);
})(jQuery);
