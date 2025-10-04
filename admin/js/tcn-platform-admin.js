(function ($) {
    'use strict';

    $(function () {
        var $logTable = $('.tcn-platform-log-table');

        if (!$logTable.length) {
            return;
        }

        if (!$.fn.DataTable) {
            return;
        }

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
    });
})(jQuery);
