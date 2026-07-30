(function ($) {
    var doProgress;
    var conversionActive = false;
    var lastStatus = '';
    var progressValue = 0;
    var importStarted = false;
    var completionTimeout = null;
    var currentVideoName = '';
    var converterInitialized = false;

    function SetProgressStart() {
        // Fortschrittsabfrage alle 2 Sekunden
        doProgress = setInterval(showProgress, 2000);
    }

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
        $('.video-item').removeClass('has-inline-status');

        var $item = getCurrentVideoItem();
        if ($item.length) {
            $item.addClass('has-inline-status');
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

    function markInlineCompleted() {
        var $item = getCurrentVideoItem();
        if (!$item.length) {
            return;
        }

        $item.find('.video-inline-donut').css('--progress', '100');
        $item.find('.video-inline-donut-value').text('100%');
        var convertedLabel = $item.data('convertedLabel') || 'Konvertiert';
        $item.find('.video-inline-status-text')
            .addClass('text-success')
            .html('<i class="fa fa-check"></i> ' + convertedLabel);
        $item.find('.video-inline-status-static').hide();

        // Nach Abschluss als konvertiert markieren und den Aktion-Button dezent halten.
        $item.removeClass('processing').addClass('already-converted');

        var reconvertLabel = $item.data('reconvertLabel') || 'Erneut konvertieren';
        var $actionGroup = $item.find('.video-actions-group-primary').first();
        if (!$actionGroup.length) {
            $actionGroup = $('<div class="video-actions-group video-actions-group-primary"></div>').prependTo($item.find('.video-actions').first());
        }

        var $button = $actionGroup.find('.ffmpeg-start-conversion').first();
        if (!$button.length) {
            $button = $('<button type="button" class="btn btn-xs ffmpeg-start-conversion" data-video="' + currentVideoName + '"></button>');
            $actionGroup.append($button);
        }

        $button
            .removeClass('btn-primary')
            .addClass('btn-default ffmpeg-start-conversion-secondary')
            .html('<i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> ' + reconvertLabel)
            .prop('disabled', false)
            .removeClass('disabled');
    }

    function updateGlobalProgress(percent, label) {
        var value = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
        $('#global-progress-donut').css('--progress', String(value));
        $('#global-progress-value').text(value + '%');

        if (label) {
            $('#progress-text').html(label);
        } else {
            $('#progress-text').html(value + '%');
        }
    }

    function showProgress() {
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_convert&func=progress&video=' + encodeURIComponent(currentVideoName),
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);
                $('#log pre').html("Request failed: " + textStatus);
                $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
                clearInterval(doProgress);
                conversionActive = false;
                updateUIForConversion(false);
            })
            .done(function (data) {
                if (data.error) {
                    $('#log pre').html("Error: " + data.error);
                    $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
                    clearInterval(doProgress);
                    conversionActive = false;
                    updateUIForConversion(false);
                    return;
                }

                // Log aktualisieren
                $('#log pre').html(data.log);
                scrolllog();
                updateInlineLog(data.log || '');
                
                // Prüfen, ob das Log auf einen Import-Abschluss hinweist
                var logText = data.log || '';
                var isImportComplete = logText.indexOf('was successfully added to rex_mediapool') !== -1;
                var isConversionDone = logText.indexOf('Konvertierung abgeschlossen') !== -1;
                
                // Fortschritt verarbeiten
                if (data.progress === 'error') {
                    // Fehler beim Fortschritt
                    stopConversion();
                } else if (data.progress === 'done' || isImportComplete || isConversionDone) {
                    // Konvertierung und Import abgeschlossen
                    showCompleted();
                } else if (data.status === 'importing') {
                    // Import läuft noch
                    updateGlobalProgress(99, 'Importiere...');
                    updateInlineProgress(99, 'Importiere...');
                    
                    // Wenn noch nicht gestartet, Import-Prozess starten
                    if (!importStarted) {
                        importStarted = true;
                        startImport();
                    }
                } else {
                    // Normaler Fortschritt
                    var newProgress = parseInt(data.progress);
                    
                    // Nur aktualisieren, wenn der neue Wert größer ist
                    // oder der aktuelle Wert 0 ist (neue Konvertierung)
                    if (newProgress > progressValue || progressValue === 0) {
                        progressValue = newProgress;
                        updateProgress(progressValue);
                        updateInlineProgress(progressValue, progressValue + '%');
                    }
                    
                    // Prüfen auf Abschlusszeichen im Log
                    if (progressValue > 95 && logContainsCompletionMarkers(logText)) {
                        // FFMPEG scheint fertig zu sein, aber der Importprozess wurde noch nicht gestartet
                        if (!importStarted) {
                            importStarted = true;
                            startImport();
                        }
                    }
                }
            });
    }
    
    // Prüft, ob das Log typische Hinweise auf einen abgeschlossenen FFMPEG-Prozess enthält
    function logContainsCompletionMarkers(logText) {
        var markers = [
            'video:', 
            'audio:', 
            'Konvertierung abgeschlossen',
            'muxing overhead',
            'fps=0.0',
            'bitrate=',
            'speed='
        ];
        
        for (var i = 0; i < markers.length; i++) {
            if (logText.indexOf(markers[i]) !== -1) {
                return true;
            }
        }
        
        return false;
    }
    
    // Fortschrittsanzeige aktualisieren
    function updateProgress(percent) {
        updateGlobalProgress(percent);
    }
    
    // Konvertierung beenden (bei Fehler oder Abbruch)
    function stopConversion() {
        $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
        clearInterval(doProgress);
        
        if (completionTimeout) {
            clearTimeout(completionTimeout);
            completionTimeout = null;
        }
        
        conversionActive = false;
        updateUIForConversion(false);
    }
    
    // Abschluss anzeigen
    function showCompleted() {
        // UI auf 100% setzen
        updateGlobalProgress(100);
        $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
        
        // Erfolgsanimation anzeigen
        $('.spinner').hide();
        $('#progress-text').addClass('text-success').html('<i class="fa fa-check"></i> Fertig!');
        markInlineCompleted();
        
        // Timer stoppen
        clearInterval(doProgress);
        
        if (completionTimeout) {
            clearTimeout(completionTimeout);
        }

        // Kein Auto-Reload: Protokoll und Status sollen sichtbar bleiben,
        // damit man die Ausgabe nach Abschluss weiter prüfen kann.
        completionTimeout = null;

        conversionActive = false;
        updateUIForConversion(false);
    }

    function updateUIForConversion(active) {
        conversionActive = active;
        
        if (active) {
            // UI für aktive Konvertierung
            $('.progress-section').show();
            $('.ffmpeg-start-conversion').addClass('disabled').prop('disabled', true);
            $('.spinner').show();
            $('#progress-text').removeClass('text-success').html('0%');
            updateGlobalProgress(0);
            progressValue = 0;
            importStarted = false;
            activateInlineStatus(currentVideoName);
            updateInlineProgress(0, '0%');
        } else {
            // UI für inaktive Konvertierung
            $('.ffmpeg-start-conversion').removeClass('disabled').prop('disabled', false);
        }
    }

    // Import-Prozess starten
    function startImport() {
        console.log("Starting media import process...");
        
        // Fortschrittsanzeige auf 99%
        updateGlobalProgress(99, 'Importiere...');
        updateInlineProgress(99, 'Importiere...');
        
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_convert&func=done&video=' + encodeURIComponent(currentVideoName),
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.error("Import failed: " + textStatus);
                // Bei Fehler nochmal versuchen
                setTimeout(function() {
                    importStarted = false;
                    startImport();
                }, 2000);
            })
            .done(function (data) {
                if (data.error) {
                    console.error("Import error: " + data.error);
                    return;
                }
                
                // Log aktualisieren
                $('#log pre').html(data.log);
                scrolllog();
                updateInlineLog(data.log || '');
                
                // Prüfen, ob der Import erfolgreich war
                if (data.status === 'success' || data.log.indexOf('was successfully added to rex_mediapool') !== -1) {
                    showCompleted();
                } else {
                    // Bei unvollständigem Import erneut versuchen
                    setTimeout(function() {
                        startImport();
                    }, 2000);
                }
            });
    }

    // Konvertierungsstatus vom Server abrufen
    function checkStatus() {
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_convert&func=status',
            dataType: 'json',
        })
            .done(function (data) {
                if (data.active) {
                    // Es läuft eine Konvertierung
                    conversionActive = true;
                    lastStatus = data.status || '';
                    
                    // Log anzeigen
                    if (data.info && data.info.log) {
                        $('#log pre').html(data.info.log);
                        scrolllog();
                    }
                    
                    // UI aktualisieren
                    $('.progress-section').show();
                    $('.ffmpeg-start-conversion').addClass('disabled').prop('disabled', true);
                    
                    // Video-Name speichern
                    if (data.info && data.info.video) {
                        currentVideoName = data.info.video;
                        activateInlineStatus(currentVideoName);
                        
                        // Markiere das aktive Video in der Liste
                        $('.video-item[data-video-item="' + currentVideoName + '"]').addClass('processing');
                    }
                    
                    if (data.status === 'converting') {
                        // Fortschrittsanzeige starten
                        SetProgressStart();
                    } else if (data.status === 'importing') {
                        // Import läuft
                        updateGlobalProgress(99, 'Importiere...');
                        updateInlineProgress(99, 'Importiere...');
                        
                        if (!importStarted) {
                            importStarted = true;
                            startImport();
                        }
                    } else if (data.status === 'done') {
                        // Bereits abgeschlossen
                        showCompleted();
                    }
                }
            });
    }
    
    // Funktion zum Prüfen aller Videos
    function checkAllVideoStatus() {
        // Fortschrittsanzeige zeigen
        $('.progress-section').show();
        $('#progress-text').html('Prüfe Status...');
        
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_convert&func=check_all',
            dataType: 'json',
        })
        .done(function(data) {
            if (data.active) {
                // Es läuft eine Konvertierung
                conversionActive = true;
                updateUIForConversion(true);
                
                // Log anzeigen
                if (data.info && data.info.log) {
                    $('#log pre').html(data.info.log);
                    scrolllog();
                    updateInlineLog(data.info.log);
                }
                
                // Video-Name speichern
                if (data.info && data.info.video) {
                    currentVideoName = data.info.video;
                    activateInlineStatus(currentVideoName);
                    
                    // Markiere das aktive Video in der Liste
                    $('.video-item[data-video-item="' + currentVideoName + '"]').addClass('processing');
                }
                
                // Starte Fortschrittsanzeige
                SetProgressStart();
            } else {
                // Keine Konvertierung aktiv
                $('#progress-text').html('Keine aktive Konvertierung gefunden');
                setTimeout(function() {
                    window.location.reload(); // Liste aktualisieren
                }, 2000);
            }
        })
        .fail(function() {
            $('#progress-text').html('Fehler beim Prüfen des Status');
        });
    }

    function initFfmpegConverter() {
        if (converterInitialized) {
            return;
        }
        converterInitialized = true;

        // Bei Seitenladung den Status prüfen
        checkStatus();
        
        // Konvertierung direkt am Video starten
        $(document).on('click', '.ffmpeg-start-conversion', function () {
            var video = $(this).data('video');
            if (!video) {
                return false;
            }

            // Video-Namen speichern für spätere Statusabfragen
            currentVideoName = video;
            
            // Variablen zurücksetzen
            progressValue = 0;
            importStarted = false;
            lastStatus = '';
            
            // UI aktualisieren
            updateUIForConversion(true);
            
            // Konvertierung starten
            startConversion(video);
            
            return false;
        });

        // Status-Button
        $('#check_status').on('click', function() {
            checkAllVideoStatus();
            return false;
        });
        
        // Video-Vorschau im Modal öffnen
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

        // Inline-Protokoll ein-/ausklappen
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

    // Konvertierung starten
    function startConversion(video, confirmOverwrite) {
        let url = 'index.php?rex-api-call=ffmpeg_convert&func=start&video=' + encodeURIComponent(video);
        
        if (confirmOverwrite) {
            url += '&confirm_overwrite=1';
        }

        $.ajax({
            type: 'get',
            url: url,
            dataType: 'json'
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Start failed: " + textStatus);
                $('.progress-section').show();
                $('#log pre').html("Request failed: " + textStatus);
                updateUIForConversion(false);
            })
            .done(function (data) {
                if (data.error) {
                    $('.progress-section').show();
                    $('#log pre').html("Error: " + data.error);
                    updateUIForConversion(false);
                    return;
                }
                
                // Überschreiben bestätigen
                if (data.status === 'confirm_overwrite') {
                    updateUIForConversion(false);
                    
                    if (confirm(data.message)) {
                        startConversion(video, true);
                    }
                    return;
                }

                // Konvertierung gestartet
                $('.progress-section').show();
                updateGlobalProgress(0);
                $('#log pre').html("Konvertierung gestartet...");
                activateInlineStatus(video);
                updateInlineProgress(0, '0%');
                updateInlineLog('Konvertierung gestartet...');
                
                // Fortschrittsüberwachung starten
                SetProgressStart();
            });
    }
})(jQuery);
