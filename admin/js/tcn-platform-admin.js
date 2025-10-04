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
            dom: '<"tcn-platform-log-controls"lf>rt<"tcn-platform-log-footer"ip>'
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
