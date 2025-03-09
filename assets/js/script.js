(function ($) {
    var doProgress;
    var conversionActive = false;

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

                if (data.log.indexOf("Overwrite ?") >= 0) {
                    $('#start').removeClass('disabled').prop('disabled', false);
                    clearInterval(doProgress);
                    conversionActive = false;
                    updateUIForConversion(false);
                } else {
                    if (data.progress === "done") {
                        $('#prog').css('width', '100%');
                        $('#progress-text').html('100%');
                        $('#start').removeClass('disabled').prop('disabled', false);
                        
                        // Zeige Erfolgsanimation
                        $('.spinner').hide();
                        $('#progress-text').addClass('text-success').html('<i class="fa fa-check"></i> Fertig!');
                        
                        clearInterval(doProgress);
                        
                        // Wichtig: führe showFinalDone() aus, bevor wir conversionActive auf false setzen
                        showFinalDone();
                        
                        conversionActive = false;
                        updateUIForConversion(false);
                        
                        // Nach 3 Sekunden Seite neu laden, um die optimierte Video-Liste zu aktualisieren
                        setTimeout(function() {
                            window.location.reload();
                        }, 3000);
                    } else {
                        var progress = parseInt(data.progress);
                        $('#prog').css('width', progress + '%');
                        $('#progress-text').html(progress + '%');
                    }
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
        $.ajax({
            type: 'get',
            url: 'index.php?rex-api-call=ffmpeg_converter&func=done',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);
                $('#log pre').html("Request failed: " + textStatus);
            })
            .done(function (data) {
                if (data.error) {
                    $('#log pre').html("Error: " + data.error);
                    return;
                }
                $('#log pre').html(data.log);
                scrolllog();
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

            updateUIForConversion(true);

            $.ajax({
                type: 'get',
                url: 'index.php?rex-api-call=ffmpeg_converter&func=start&video=' + encodeURIComponent(video),
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

                    $('.progress-section').show();
                    $('#prog').css('width', '0%');
                    $('#progress-text').html('0%');
                    $('#log pre').html("Konvertierung gestartet...");
                    SetProgressStart();
                });

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
                    SetProgressStart();
                }
            });
    }
})(jQuery);
