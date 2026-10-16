(function ($) {
    $('table.posts #the-list, table.pages #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        'start': fixPlaceholder,
        'stop': restoreCellWidths,
        'update': function (e, ui) {
            $.post( scporder_vars.ajax_url, {
                action: 'update-menu-order',
                order: $('#the-list').sortable('serialize'),
                nonce: scporder_vars.nonce
            });
        }
    });
    $('table.tags #the-list').sortable({
        'items': 'tr',
        'axis': 'y',
        'helper': fixHelper,
        'start': fixPlaceholder,
        'stop': restoreCellWidths,
        'update': function (e, ui) {
            $.post( scporder_vars.ajax_url, {
                action: 'update-menu-order-tags',
                order: $('#the-list').sortable('serialize'),
                nonce: scporder_vars.nonce
            });
        }
    });
    function fixHelper(e, row) {
        var cells = row.children();
        var helper = row.clone();

        row.data('scporder-cell-widths', cells.map(function () {
            return this.style.width;
        }).get());

        // Freeze only the dragged copy, including both th and td cells.
        // Leave the table's own column widths under WordPress's control.
        helper.children().each(function (index) {
            $(this).width(cells.eq(index).width());
        });

        return helper;
    }

    function fixPlaceholder(e, ui) {
        // Hidden columns must not create extra columns in the placeholder.
        // This runs after ACF's sortstart handler replaces its cells.
        ui.placeholder.empty().append($('<td>').attr({
            colspan: ui.helper.children(':visible').length
        }).css('padding', 0));
    }

    function restoreCellWidths(e, ui) {
        var widths = ui.item.data('scporder-cell-widths');

        // ACF sets widths on the original row during sortstart.
        ui.item.children().each(function (index) {
            this.style.width = widths[index];
        });
        ui.item.removeData('scporder-cell-widths');
    }

})(jQuery)
