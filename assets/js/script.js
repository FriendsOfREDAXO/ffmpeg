(function ($) {
    var doProgress;
    var conversionActive = false;
    var lastStatus = '';
    var waitingForImport = false;

    function SetProgressStart() {
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

                $('#log pre').html(data.log);
                scrolllog();
                
                // Statuswechsel überwachen
                if (data.status && lastStatus !== data.status) {
                    console.log("Status changed from " + lastStatus + " to " + data.status);
                    lastStatus = data.status;
                    
                    if (data.status === 'importing' && !waitingForImport) {
                        waitingForImport = true;
                        console.log("Starting import process...");
                        importConvertedVideo();
                    }
                }

                if (data.progress === 'error') {
                    $('#start').removeClass('disabled').prop('disabled', false);
                    clearInterval(doProgress);
                    conversionActive = false;
                    updateUIForConversion(false);
                } else if (data.progress === 'done') {
                    $('#prog').css('width', '100%');
                    $('#progress-text').html('100%');
                    $('#start').removeClass('disabled').prop('disabled', false);
                    
                    // Zeige Erfolgsanimation
                    $('.spinner').hide();
                    $('#progress-text').addClass('text-success').html('<i class="fa fa-check"></i> Fertig!');
                    
                    clearInterval(doProgress);
                    conversionActive = false;
                    updateUIForConversion(false);
                    
                    // Nach 3 Sekunden Seite neu laden, um die optimierte Video-Liste zu aktualisieren
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                } else if (data.status === 'importing') {
                    // Import läuft
                    $('#prog').css('width', '99%');
                    $('#progress-text').html('Importiere...');
                    
                    // Stelle sicher, dass der Import gestartet wird, falls noch nicht geschehen
                    if (!waitingForImport) {
                        waitingForImport = true;
                        importConvertedVideo();
                    }
                } else {
                    // Normaler Fortschritt während der Konvertierung
                    var progress = parseInt(data.progress);
                    $('#prog').css('width', progress + '%');
                    $('#progress-text').html(progress + '%');
                }
            });
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
        } else {
            // UI für inaktive Konvertierung, aber Fortschrittsanzeige bleibt sichtbar
            $('input[name=video]').prop('disabled', false);
            $('#start').removeClass('disabled').prop('disabled', false);
        }
    }

    function importConvertedVideo() {
        console.log("Executing import process...");
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=done',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.error("Import process failed: " + textStatus);
                waitingForImport = false;
            })
            .done(function (data) {
                if (data.error) {
                    console.error("Import error: " + data.error);
                    waitingForImport = false;
                    return;
                }
                
                console.log("Import process executed with status: " + data.status);
                $('#log pre').html(data.log);
                scrolllog();
                
                // Wenn der Import erfolgreich war, setzen wir das Flag zurück
                if (data.status === 'success') {
                    waitingForImport = false;
                    
                    // UI aktualisieren
                    $('#prog').css('width', '100%');
                    $('#progress-text').addClass('text-success').html('<i class="fa fa-check"></i> Fertig!');
                    $('.spinner').hide();
                    
                    // Nach 3 Sekunden Seite neu laden
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                } else {
                    // Bei Fehler oder unvollständigem Import, versuchen wir es erneut
                    setTimeout(function() {
                        importConvertedVideo();
                    }, 2000);
                }
            });
    }

    $(document).ready(function () {
        // Prüfe Server-Status bei Seitenladung
        checkServerConversionStatus();
        
        $('#start').on('click', function () {
            let video = $('input[name=video]:checked').val();

            if (video === undefined) {
                alert($('.rex-addon-output').data('i18n-select-video') || 'Bitte wählen Sie ein Video zur Konvertierung aus!');
                return false;
            }

            startConversion(video);
            return false;
        });

        // Status-Button
        $('#check_status').on('click', function() {
            checkServerConversionStatus();
            return false;
        });
        
        // Video-Auswahl etwas schöner machen
        $('input[name=video]').change(function() {
            $('.video-item').removeClass('active-video');
            $(this).closest('.video-item').addClass('active-video');
        });
    });
    
    function startConversion(video, confirmOverwrite) {
        // Reset status variables
        lastStatus = '';
        waitingForImport = false;
        
        updateUIForConversion(true);
        
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
                console.log("Request failed: " + textStatus);
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
                
                if (data.status === 'confirm_overwrite') {
                    // Frage Benutzer, ob überschrieben werden soll
                    updateUIForConversion(false);
                    
                    if (confirm(data.message)) {
                        startConversion(video, true);
                    }
                    return;
                }

                $('.progress-section').show();
                $('#prog').css('width', '0%');
                $('#progress-text').html('0%');
                $('#log pre').html("Konvertierung gestartet...");
                
                // Starte die Fortschrittsüberwachung
                SetProgressStart();
            });
    }
    
    function checkServerConversionStatus() {
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=status',
            dataType: 'json'
        })
            .done(function (data) {
                if (data.active) {
                    conversionActive = true;
                    updateUIForConversion(true);
                    
                    // Status für die Überwachung speichern
                    lastStatus = data.status || '';
                    
                    // Zeige Infos zur laufenden Konvertierung
                    if (data.info && data.info.log) {
                        $('#log pre').html(data.info.log);
                        scrolllog();
                    }
                    
                    // Je nach Status die UI aktualisieren
                    if (data.status === 'importing') {
                        $('#prog').css('width', '99%');
                        $('#progress-text').html('Importiere...');
                        
                        if (!waitingForImport) {
                            waitingForImport = true;
                            importConvertedVideo();
                        }
                    } else if (data.status === 'converting') {
                        // Fortschrittsanzeige starten
                        SetProgressStart();
                    }
                }
            });
    }
})(jQuery);
