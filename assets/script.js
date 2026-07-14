(function ($) {
    'use strict';

    console.log('MVT script loaded');
    console.log('MVT mvtData:', typeof mvtData !== 'undefined' ? 'OK' : 'UNDEFINED');

    if (typeof mvtData === 'undefined') {
        console.error('MVT: mvtData is undefined - script localization failed');
        return;
    }

    function showStatus(msg, type) {
        var $status = $('#mvt-status');
        $status.removeClass('success error loading').addClass(type).html(msg).show();
    }

    function clearResults() {
        $('#mvt-results').empty();
        $('#mvt-status').hide();
    }

    $(document).on('click', '#mvt-fetch', function (e) {
        e.preventDefault();
        console.log('MVT fetch clicked');
        var url = $('#mvt-url').val().trim();

        if (!url) {
            showStatus('Enter a YouTube URL.', 'error');
            return;
        }

        clearResults();
        showStatus('<span class="mvt-spinner"></span> Fetching thumbnails...', 'loading');

        $.post(mvtData.ajaxUrl, {
            action: 'mvt_get_thumbnails',
            nonce: mvtData.nonce,
            url: url
        }, function (response) {
            if (response.success) {
                showStatus('Found ' + response.data.thumbnails.length + ' thumbnails. Select one to import.', 'success');
                renderThumbnails(response.data.thumbnails);
            } else {
                showStatus(response.data, 'error');
            }
        }).fail(function () {
            showStatus('Server communication error.', 'error');
        });
    });

    function renderThumbnails(thumbnails) {
        var $container = $('#mvt-results');
        $container.empty();

        thumbnails.forEach(function (thumb) {
            var card = $('<div class="mvt-thumb-card"></div>');
            card.append('<img src="' + thumb.url + '" alt="' + thumb.label + '" />');
            card.append('<div class="mvt-thumb-label">' + thumb.label + '</div>');
            card.append('<div class="mvt-thumb-dims">' + thumb.w + ' x ' + thumb.h + '</div>');

            var btn = $('<button class="button button-primary">Add to Media Library</button>');
            btn.on('click', function () {
                importThumbnail(thumb, card, btn);
            });

            card.append(btn);
            $container.append(card);
        });
    }

    function importThumbnail(thumb, card, btn) {
        btn.prop('disabled', true).html('<span class="mvt-spinner"></span> Importing...');

        $.post(mvtData.ajaxUrl, {
            action: 'mvt_import_thumbnail',
            nonce: mvtData.nonce,
            image_url: thumb.url,
            video_id: thumb.video_id,
            video_title: thumb.video_title || ''
        }, function (response) {
            if (response.success) {
                card.addClass('imported');
                btn.remove();
                card.append('<div class="mvt-imported-msg">Added to the Media Library!</div>');
                card.append('<a href="' + response.data.edit_url + '" class="button" target="_blank">Edit</a> ');
                card.append('<a href="' + response.data.media_url + '" class="button" target="_blank">View in Media Library</a>');
            } else {
                btn.prop('disabled', false).text('Retry');
                showStatus(response.data, 'error');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Retry');
            showStatus('Server communication error.', 'error');
        });
    }

    // Allow Enter key in the URL field
    $(document).on('keypress', '#mvt-url', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#mvt-fetch').trigger('click');
        }
    });

})(jQuery);
