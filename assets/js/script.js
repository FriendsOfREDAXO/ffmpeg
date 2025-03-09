(function ($) {
    var doProgress;
    var conversionActive = false;
    var lastStatus = '';
    var progressValue = 0;
    var importStarted = false;
    var completionTimeout = null;

    function SetProgressStart() {
        // Fortschrittsabfrage alle 2 Sekunden
        doProgress = setInterval(showProgress, 2000);
    }

    function scrolllog() {
        $('#log pre').scrollTop($('#log pre')[0].scrollHeight);
    }

    function showProgress() {
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=progress',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);
                $('#log pre').html("Request failed: " + textStatus);
                $('#start').removeClass('disabled').prop('disabled', false);
                clearInterval(doProgress);
                conversionActive = false;
                updateUIForConversion(false);
            })
            .done(function (data) {
                if (data.error) {
                    $('#log pre').html("Error: " + data.error);
                    $('#start').removeClass('disabled').prop('disabled', false);
                    clearInterval(doProgress);
                    conversionActive = false;
                    updateUIForConversion(false);
                    return;
                }

                // Log aktualisieren
                $('#log pre').html(data.log);
                scrolllog();
                
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
                    $('#prog').css('width', '99%');
                    $('#progress-text').html('Importiere...');
                    
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
        $('#prog').css('width', percent + '%');
        $('#progress-text').html(percent + '%');
    }
    
    // Konvertierung beenden (bei Fehler oder Abbruch)
    function stopConversion() {
        $('#start').removeClass('disabled').prop('disabled', false);
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
        $('#prog').css('width', '100%');
        $('#progress-text').html('100%');
        $('#start').removeClass('disabled').prop('disabled', false);
        
        // Erfolgsanimation anzeigen
        $('.spinner').hide();
        $('#progress-text').addClass('text-success').html('<i class="fa fa-check"></i> Fertig!');
        
        // Timer stoppen
        clearInterval(doProgress);
        
        if (completionTimeout) {
            clearTimeout(completionTimeout);
        }
        
        // Nach 3 Sekunden Seite neu laden
        completionTimeout = setTimeout(function() {
            window.location.reload();
        }, 3000);
        
        conversionActive = false;
        updateUIForConversion(false);
    }

    function updateUIForConversion(active) {
        conversionActive = active;
        
        if (active) {
            // UI für aktive Konvertierung
            $('.progress-section').show();
            $('input[name=video]').prop('disabled', true);
            $('#start').addClass('disabled').prop('disabled', true);
            $('.spinner').show();
            $('#progress-text').removeClass('text-success').html('0%');
            progressValue = 0;
            importStarted = false;
        } else {
            // UI für inaktive Konvertierung
            $('input[name=video]').prop('disabled', false);
            $('#start').removeClass('disabled').prop('disabled', false);
        }
    }

    // Import-Prozess starten
    function startImport() {
        console.log("Starting media import process...");
        
        // Fortschrittsanzeige auf 99%
        $('#prog').css('width', '99%');
        $('#progress-text').html('Importiere...');
        
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=done',
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
            url: 'index.php?rex-api-call=ffmpeg_converter&func=status',
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
                    $('input[name=video]').prop('disabled', true);
                    $('#start').addClass('disabled').prop('disabled', true);
                    
                    if (data.status === 'converting') {
                        // Fortschrittsanzeige starten
                        SetProgressStart();
                    } else if (data.status === 'importing') {
                        // Import läuft
                        $('#prog').css('width', '99%');
                        $('#progress-text').html('Importiere...');
                        
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

    $(document).ready(function () {
        // Bei Seitenladung den Status prüfen
        checkStatus();
        
        // Start-Button
        $('#start').on('click', function () {
            let video = $('input[name=video]:checked').val();

            if (video === undefined) {
                alert($('.rex-addon-output').data('i18n-select-video') || 'Bitte wählen Sie ein Video zur Konvertierung aus!');
                return false;
            }

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
            checkStatus();
            return false;
        });
        
        // Video-Auswahl
        $('input[name=video]').change(function() {
            $('.video-item').removeClass('active-video');
            $(this).closest('.video-item').addClass('active-video');
        });
    });
    
    // Konvertierung starten
    function startConversion(video, confirmOverwrite) {
        let url = 'index.php?rex-api-call=ffmpeg_converter&func=start&video=' + encodeURIComponent(video);
        
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
                $('#prog').css('width', '0%');
                $('#progress-text').html('0%');
                $('#log pre').html("Konvertierung gestartet...");
                
                // Fortschrittsüberwachung starten
                SetProgressStart();
            });
    }
})(jQuery);
