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
            showStatus('Inserisci un URL YouTube.', 'error');
            return;
        }

        clearResults();
        showStatus('<span class="mvt-spinner"></span> Estrazione thumbnail in corso...', 'loading');

        $.post(mvtData.ajaxUrl, {
            action: 'mvt_get_thumbnails',
            nonce: mvtData.nonce,
            url: url
        }, function (response) {
            if (response.success) {
                showStatus('Trovate ' + response.data.thumbnails.length + ' thumbnail. Seleziona quella da importare.', 'success');
                renderThumbnails(response.data.thumbnails);
            } else {
                showStatus(response.data, 'error');
            }
        }).fail(function () {
            showStatus('Errore di comunicazione con il server.', 'error');
        });
    });

    function renderThumbnails(thumbnails) {
        var $container = $('#mvt-results');
        $container.empty();

        thumbnails.forEach(function (thumb) {
            var card = $('<div class="mvt-thumb-card"></div>');
            card.append('<img src="' + thumb.url + '" alt="' + thumb.label + '" />');
            card.append('<div class="mvt-thumb-label">' + thumb.label + '</div>');
            card.append('<div class="mvt-thumb-dims">' + thumb.w + '×' + thumb.h + '</div>');

            var btn = $('<button class="button button-primary">Aggiungi ai Media</button>');
            btn.on('click', function () {
                importThumbnail(thumb, card, btn);
            });

            card.append(btn);
            $container.append(card);
        });
    }

    function importThumbnail(thumb, card, btn) {
        btn.prop('disabled', true).html('<span class="mvt-spinner"></span> Importazione...');

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
                card.append('<div class="mvt-imported-msg">✓ Aggiunto ai Media!</div>');
                card.append('<a href="' + response.data.edit_url + '" class="button" target="_blank">Modifica</a> ');
                card.append('<a href="' + response.data.media_url + '" class="button" target="_blank">Vedi in Media</a>');
            } else {
                btn.prop('disabled', false).text('Riprova');
                showStatus(response.data, 'error');
            }
        }).fail(function () {
            btn.prop('disabled', false).text('Riprova');
            showStatus('Errore di comunicazione con il server.', 'error');
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
