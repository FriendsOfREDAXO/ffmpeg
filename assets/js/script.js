(function ($) {
    var doProgress;
    var conversionActive = false;
    var completionCheck; // Timer für den automatischen Import nach Abschluss

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
                    
                    // Starte den Import-Prozess
                    showFinalDone();
                    
                    // Nach 3 Sekunden Seite neu laden, um die optimierte Video-Liste zu aktualisieren
                    setTimeout(function() {
                        window.location.reload();
                    }, 3000);
                } else {
                    var progress = parseInt(data.progress);
                    $('#prog').css('width', progress + '%');
                    $('#progress-text').html(progress + '%');
                    
                    // Wenn der Fortschritt nahe an 100% ist, starten wir einen zusätzlichen Timer, 
                    // der explizit auf die Fertigstellung prüft
                    if (progress >= 95 && !completionCheck) {
                        completionCheck = setInterval(checkCompletion, 2000);
                    }
                }
            });
    }
    
    // Separate Funktion, die explizit prüft, ob die Konvertierung abgeschlossen ist
    // und den Import auslöst, auch wenn der Progress-Callback dies verpasst hat
    function checkCompletion() {
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=progress',
            dataType: 'json',
        })
            .done(function (data) {
                if (data.progress === 'done' || 
                    (data.log && (
                        data.log.indexOf('Qavg') !== -1 || 
                        data.log.indexOf('kb/s:') !== -1 || 
                        data.log.indexOf('video:') !== -1
                    ))
                ) {
                    // Die Konvertierung ist abgeschlossen, aber der Callback hat es möglicherweise verpasst
                    clearInterval(completionCheck);
                    completionCheck = null;
                    
                    // Den Import explizit starten
                    showFinalDone();
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

    function showFinalDone() {
        console.log("Performing final import process...");
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=done',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.error("Import process failed: " + textStatus);
                $('#log pre').append("\nImport process failed: " + textStatus);
                scrolllog();
            })
            .done(function (data) {
                if (data.error) {
                    console.error("Import error: " + data.error);
                    $('#log pre').append("\nImport error: " + data.error);
                    scrolllog();
                    return;
                }
                
                console.log("Import process completed successfully");
                $('#log pre').html(data.log);
                scrolllog();
                
                // Nochmal verifizieren, dass der Import erfolgreich war
                if (data.log.indexOf("was successfully added to rex_mediapool") === -1) {
                    console.warn("Video may not have been imported correctly. Retrying...");
                    // Wenn das Video nicht importiert wurde, versuchen wir es erneut
                    setTimeout(showFinalDone, 1000);
                } else {
                    conversionActive = false;
                    updateUIForConversion(false);
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
                
                // Stelle sicher, dass der Completion-Check zurückgesetzt wird
                if (completionCheck) {
                    clearInterval(completionCheck);
                    completionCheck = null;
                }
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
                    
                    // Zeige Infos zur laufenden Konvertierung
                    if (data.info && data.info.video) {
                        $('#log pre').html(data.info.log || "Konvertierung für \"" + data.info.video + "\" läuft...");
                        scrolllog();
                    }
                    
                    SetProgressStart();
                    
                    // Bei aktivem Prozess auch den Completion-Check starten
                    if (!completionCheck) {
                        completionCheck = setInterval(checkCompletion, 2000);
                    }
                }
            });
    }
})(jQuery);
