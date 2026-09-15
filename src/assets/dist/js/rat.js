/* global Craft, Garnish, $ */

/**
 * Powers the "View more..." link in the Edit History sidebar panel.
 *
 * The rows come back from the server already rendered, so appended entries
 * stay identical to the ones rendered with the page — dates, translations and
 * avatar markup included.
 */
(function () {
    'use strict';

    if (typeof Craft === 'undefined' || typeof Garnish === 'undefined') {
        return;
    }

    var DEFAULT_PAGE_SIZE = 10;

    function loadMore($panel, $link) {
        if ($link.hasClass('rat-loading')) {
            return;
        }

        var $list = $panel.find('.rat-history-list');
        var elementId = $panel.attr('data-rat-element-id');
        var siteId = $panel.attr('data-rat-site-id');
        var pageSize = parseInt($panel.attr('data-rat-page-size'), 10) || DEFAULT_PAGE_SIZE;

        if (!elementId || !siteId || !$list.length) {
            return;
        }

        $link.addClass('rat-loading');

        Craft.sendActionRequest('POST', 'rat/edit-log/element-history', {
            data: {
                elementId: elementId,
                siteId: siteId,
                limit: pageSize,
                offset: $list.children('.rat-history-item').length,
            },
        })
            .then(function (response) {
                $($.parseHTML(response.data.html)).appendTo($list);

                // Avatars ship as empty placeholders that the CP's thumb
                // loader fills in; newly appended ones need a nudge.
                if (Craft.cp && Craft.cp.elementThumbLoader) {
                    Craft.cp.elementThumbLoader.load($list);
                }

                if (!response.data.hasMore) {
                    $panel.find('.rat-history-more').remove();
                }
            })
            .catch(function (e) {
                var message =
                    (e.response && e.response.data && e.response.data.message) ||
                    Craft.t('rat', 'Couldn’t load more edit history.');

                Craft.cp.displayError(message);
            })
            .then(function () {
                $link.removeClass('rat-loading');
            });
    }

    // Delegated, so it also covers sidebars that arrive later with a slideout.
    Garnish.$doc.on('click', '[data-rat-load-more]', function (ev) {
        ev.preventDefault();

        var $link = $(this);
        loadMore($link.closest('.rat-edit-history'), $link);
    });
})();
