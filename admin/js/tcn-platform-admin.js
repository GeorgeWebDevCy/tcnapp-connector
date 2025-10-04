(function ($) {
    'use strict';

    $(function () {
        var $logTable = $('.tcn-platform-log-table');

        if ($logTable.length && $.fn.DataTable) {
            var localized = (window.tcnPlatformAdmin && window.tcnPlatformAdmin.logTable) ? window.tcnPlatformAdmin.logTable : {};
            var options = {
                pageLength: 25,
                responsive: true,
                order: [[0, 'desc']],
                autoWidth: false,
                language: localized,
                dom: '<"tcn-platform-log-controls"lf>rt<"tcn-platform-log-footer"ip>',
                columnDefs: [
                    { targets: 0, className: 'tcn-platform-log-col-time', width: '14%', responsivePriority: 1 },
                    { targets: 1, className: 'tcn-platform-log-col-source', width: '12%', responsivePriority: 4 },
                    { targets: 2, className: 'tcn-platform-log-col-message', width: '26%', responsivePriority: 2 },
                    { targets: 3, className: 'tcn-platform-log-col-details', responsivePriority: 3 }
                ]
            };

            if (localized.lengthMenuAll) {
                options.lengthMenu = [
                    [10, 25, 50, 100, -1],
                    [10, 25, 50, 100, localized.lengthMenuAll]
                ];
            }

            if (localized.searchPlaceholder) {
                options.language = $.extend(true, {}, localized, {
                    searchPlaceholder: localized.searchPlaceholder
                });
            }

            $logTable.DataTable(options);
        }

        var $apiTester = $('.tcn-platform-api-tester');
        if ($apiTester.length) {
            var $form = $apiTester.find('.tcn-platform-api-form');

            $apiTester.on('click', '.tcn-platform-api-example-fill', function (event) {
                event.preventDefault();

                if (!$form.length) {
                    return;
                }

                var $example = $(this).closest('.tcn-platform-api-example');
                if (!$example.length) {
                    return;
                }

                var method = ($example.attr('data-method') || 'GET').toString().toUpperCase();
                var url = $example.attr('data-url') || '';
                var headers = $example.attr('data-headers') || '';
                var body = $example.attr('data-body') || '';

                $form.find('#request_method').val(method);
                $form.find('#request_url').val(url);
                $form.find('#request_headers').val(headers);
                $form.find('#request_body').val(body);
            });

            $apiTester.on('click', '.tcn-platform-api-reset', function (event) {
                event.preventDefault();

                if (!$form.length) {
                    return;
                }

                $form.find('#request_method').val('GET');
                $form.find('#request_url').val('');
                $form.find('#request_headers').val('');
                $form.find('#request_body').val('');
            });
        }
    });
})(jQuery);
