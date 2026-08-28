(function ($) {
    // Redesign: ein einziger Polling-Endpunkt (func=job_status) statt der
    // bisherigen vier (progress/done/status/check_all), die alle denselben
    // Zustand ueber Session + Text-Suche im ffmpeg-Log rekonstruiert haben
    // ("was successfully added to rex_mediapool", "Konvertierung
    // abgeschlossen" o.ae.). Der Server liefert jetzt einen echten Status
    // (running/finalizing/done/error), der Abschluss wird dort per
    // Exit-Code-Datei erkannt, nicht per Text-Heuristik -- siehe
    // FriendsOfRedaxo\FFmpeg\Api\Converter::handleJobStatus().
    var doPoll = null;
    var currentJobId = '';
    var currentVideoName = '';
    var converterInitialized = false;

    function scrolllog() {
        $('#log pre').scrollTop($('#log pre')[0].scrollHeight);
    }

    function getCurrentVideoItem() {
        return $('.video-item[data-video-item="' + currentVideoName + '"]').first();
    }

    function activateInlineStatus(videoName) {
        if (!videoName) {
            return;
        }

        currentVideoName = videoName;
        $('.video-inline-status').hide();
        $('.video-item').removeClass('has-inline-status processing');

        var $item = getCurrentVideoItem();
        if ($item.length) {
            $item.addClass('has-inline-status processing');
            $item.find('.video-inline-status').show();
            $item.find('.video-inline-main').css('display', 'flex');
            $item.find('.video-inline-status-static').hide();
            $item.find('.video-inline-status-text').text($item.find('.video-inline-status').data('runningLabel') || 'Läuft');
        }
    }

    function updateInlineProgress(percent, label) {
        var $item = getCurrentVideoItem();
        if (!$item.length) {
            return;
        }

        var valueText = Math.max(0, Math.min(100, parseInt(percent, 10) || 0)) + '%';
        $item.find('.video-inline-donut').css('--progress', String(percent));
        $item.find('.video-inline-donut-value').text(valueText);
        $item.find('.video-inline-status-text').removeClass('text-success').text(label || valueText);
    }

    function updateInlineLog(logText) {
        var $item = getCurrentVideoItem();
        if (!$item.length) {
            return;
        }

        $item.find('.video-inline-log pre').text(logText || '');
    }

    // Zwei unabhaengige Modi pro Zeile moeglich (Optimieren + Webversion,
    // siehe mediapool.ffmpeg.main.php) -- nur den Button/das Badge des
    // GERADE abgeschlossenen Modus aktualisieren, den jeweils anderen
    // unangetastet lassen. Kompressionsrate wird bewusst NICHT clientseitig
    // nachgerechnet (dafuer bräuchte es die Original-/Ziel-Dateigroesse, die
    // hier nicht vorliegen) -- ein generisches "Fertig" reicht, die exakte
    // Prozentzahl zeigt sich nach einem Neuladen der Seite/Liste.
    function markInlineCompleted(mode) {
        var $item = getCurrentVideoItem();
        if (!$item.length) {
            return;
        }

        $item.find('.video-inline-donut').css('--progress', '100');
        $item.find('.video-inline-donut-value').text('100%');
        var convertedLabel = $item.data('convertedLabel') || 'Fertig';
        $item.find('.video-inline-status-text')
            .addClass('text-success')
            .html('<i class="fa fa-check"></i> ' + convertedLabel);
        $item.find('.video-inline-status-static').hide();

        $item.removeClass('processing');

        var $button = $item.find('.ffmpeg-start-conversion[data-mode="' + mode + '"]').first();
        if ($button.length) {
            var reconvertLabel = 'overwrite' === mode
                ? ($button.data('reoptimizeLabel') || 'Erneut optimieren')
                : ($button.data('recreateLabel') || 'Webversion erneut erstellen');
            $button
                .removeClass('btn-primary')
                .addClass('btn-default ffmpeg-start-conversion-secondary')
                .html('<i class="fa-solid ' + ('overwrite' === mode ? 'fa-arrows-rotate' : 'fa-globe') + '" aria-hidden="true"></i> ' + reconvertLabel);
        }
        $item.find('.ffmpeg-start-conversion').prop('disabled', false).removeClass('disabled');
    }

    function markInlineError(message) {
        var $item = getCurrentVideoItem();
        if ($item.length) {
            $item.find('.video-inline-status-text').removeClass('text-success').addClass('text-danger').html('<i class="fa fa-exclamation-triangle"></i> ' + (message || 'Fehler'));
        }
        $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
    }

    function updateGlobalProgress(percent, label) {
        var value = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
        $('#global-progress-donut').css('--progress', String(value));
        $('#global-progress-value').text(value + '%');
        $('#progress-text').html(label || (value + '%'));
    }

    function updateUIForConversion(active) {
        if (active) {
            $('.progress-section').show();
            $('.ffmpeg-start-conversion').addClass('disabled').prop('disabled', true);
            $('.spinner').show();
            $('#progress-text').removeClass('text-success').html('0%');
            updateGlobalProgress(0);
            activateInlineStatus(currentVideoName);
            updateInlineProgress(0, '0%');
        } else {
            $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
        }
    }

    function showCompleted(mode) {
        updateGlobalProgress(100);
        $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
        $('.spinner').hide();
        $('#progress-text').addClass('text-success').html('<i class="fa fa-check"></i> Fertig!');
        markInlineCompleted(mode || 'new_file');
        stopPolling();
    }

    function showError(message) {
        $('.spinner').hide();
        $('#progress-text').removeClass('text-success').html('<i class="fa fa-exclamation-triangle"></i> ' + (message || 'Fehler'));
        markInlineError(message);
        stopPolling();
    }

    function stopPolling() {
        if (doPoll) {
            clearInterval(doPoll);
            doPoll = null;
        }
    }

    // Ein Tick des Polling-Loops -- fragt den Job einmal ab und reagiert auf
    // dessen (echten) Status. "finalizing" ist ein Zwischenzustand (ffmpeg
    // fertig, Medienpool-Registrierung laeuft server-seitig gerade) und wird
    // wie "laeuft noch" behandelt, kein Extra-Request noetig wie vorher bei
    // startImport().
    function pollOnce(jobId) {
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_convert&func=job_status&job=' + encodeURIComponent(jobId),
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                showError('Anfrage fehlgeschlagen: ' + textStatus);
            })
            .done(function (data) {
                if (data.video) {
                    currentVideoName = data.video;
                }
                $('#log pre').html(data.log || '');
                scrolllog();
                updateInlineLog(data.log || '');

                if ('done' === data.status) {
                    showCompleted(data.mode);
                } else if ('error' === data.status) {
                    showError(data.message || 'Konvertierung fehlgeschlagen');
                } else {
                    var progress = 'finalizing' === data.status ? 99 : (parseInt(data.progress, 10) || 0);
                    var label = 'finalizing' === data.status ? 'Importiere...' : progress + '%';
                    updateGlobalProgress(progress, label);
                    updateInlineProgress(progress, label);
                }
            });
    }

    function startPolling(jobId) {
        currentJobId = jobId;
        stopPolling();
        pollOnce(jobId);
        doPoll = setInterval(function () {
            pollOnce(jobId);
        }, 1500);
    }

    // Bei Seitenladung pruefen, ob bereits (von diesem oder einem anderen
    // Backend-User, z.B. ueber MediaPlace's "Video optimieren"-Button)
    // ein Job laeuft -- server-seitig global, nicht mehr session-gebunden.
    function resumeActiveJobIfAny() {
        if (!window.ffmpegActiveJob || !window.ffmpegActiveJob.id) {
            return;
        }

        currentVideoName = window.ffmpegActiveJob.video || '';
        updateUIForConversion(true);
        startPolling(window.ffmpegActiveJob.id);
    }

    function startConversion(video, mode, confirmOverwrite) {
        var url = 'index.php?rex-api-call=ffmpeg_convert&func=start&video=' + encodeURIComponent(video) + '&mode=' + encodeURIComponent(mode || 'new_file');
        if (confirmOverwrite) {
            url += '&confirm_overwrite=1';
        }

        $.ajax({
            type: 'get',
            url: url,
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                $('.progress-section').show();
                $('#log pre').html('Request failed: ' + textStatus);
                updateUIForConversion(false);
            })
            .done(function (data) {
                if (data.error) {
                    $('.progress-section').show();
                    $('#log pre').html('Error: ' + data.error);
                    updateUIForConversion(false);
                    return;
                }

                if ('confirm_overwrite' === data.status) {
                    updateUIForConversion(false);
                    if (confirm(data.message)) {
                        startConversion(video, mode, true);
                    }
                    return;
                }

                if ('busy' === data.status) {
                    updateUIForConversion(false);
                    alert(data.message || 'Es läuft bereits eine Konvertierung.');
                    return;
                }

                $('.progress-section').show();
                updateGlobalProgress(0);
                $('#log pre').html('Konvertierung gestartet...');
                activateInlineStatus(video);
                updateInlineProgress(0, '0%');
                updateInlineLog('Konvertierung gestartet...');
                startPolling(data.job);
            });
    }

    function initFfmpegConverter() {
        if (converterInitialized) {
            return;
        }
        converterInitialized = true;

        resumeActiveJobIfAny();

        $(document).on('click', '.ffmpeg-start-conversion', function () {
            var video = $(this).data('video');
            if (!video) {
                return false;
            }
            var mode = $(this).data('mode') || 'new_file';

            currentVideoName = video;
            updateUIForConversion(true);
            startConversion(video, mode);

            return false;
        });

        $('#check_status').on('click', function () {
            window.location.reload();
            return false;
        });

        $(document).on('click', '.ffmpeg-preview-link', function (event) {
            event.preventDefault();

            var mediaUrl = $(this).data('mediaUrl');
            var previewLabel = $(this).data('previewLabel') || 'Vorschau';
            var videoTitle = $(this).data('videoTitle') || '';

            if (!mediaUrl) {
                return;
            }

            var title = previewLabel + (videoTitle ? ' - ' + videoTitle : '');
            $('#ffmpeg-video-preview-title').text(title);

            var videoElement = document.getElementById('ffmpeg-preview-video');
            if (videoElement) {
                videoElement.pause();
                videoElement.removeAttribute('src');
                videoElement.load();
                videoElement.setAttribute('src', mediaUrl);
                videoElement.loop = $('.ffmpeg-preview-loop-toggle').is(':checked');
                videoElement.load();
            }

            $('#ffmpeg-video-preview-modal').modal('show');
        });

        $(document).on('change', '.ffmpeg-preview-loop-toggle', function () {
            var targetId = $(this).data('previewTarget');
            var videoElement = document.getElementById(targetId);
            if (videoElement) {
                videoElement.loop = $(this).is(':checked');
            }
        });

        $(document).on('click', '.ffmpeg-toggle-inline-log', function (event) {
            event.preventDefault();
            var $button = $(this);
            var $item = $button.closest('.video-item');
            var $log = $item.find('.video-inline-log-row').first();
            if (!$log.length) {
                return;
            }
            var isVisible = $log.is(':visible');
            var showLabel = $button.data('showLabel') || 'Protokoll anzeigen';
            var hideLabel = $button.data('hideLabel') || 'Protokoll ausblenden';

            $log.toggle(!isVisible);
            $button.attr('aria-expanded', !isVisible ? 'true' : 'false');
            $button.text(!isVisible ? hideLabel : showLabel);
        });
    }

    $(document).on('rex:ready', function () {
        initFfmpegConverter();
    });

    $(document).ready(function () {
        initFfmpegConverter();
    });
})(jQuery);
