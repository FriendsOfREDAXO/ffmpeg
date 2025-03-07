(function ($) {
    var doProgress;

    function SetProgressStart() {
        doProgress = setInterval(showProgress, 2000);
    }

    function scrolllog() {
        $('#log pre').scrollTop($('#log pre')[0].scrollHeight);
    }

    function showProgress() {
        console.log("Checking progress...");
        $.ajax({
            type: 'get',
            url: 'index.php?page=ffmpeg/main&ffmpeg_video=1&progress=1',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);

                $('#log pre').html("Request failed: " + textStatus);
                $('#start').removeClass('disabled');
                clearInterval(doProgress);
            })
            .done(function (data) {
                console.log("Progress response:", data);
                $('#log pre').html(data.log);
                scrolllog();

                if (data.log && data.log.indexOf("Overwrite ?") >= 0) {
                    $('#start').removeClass('disabled');
                    clearInterval(doProgress);
                } else if (data.progress === "error") {
                    $('#prog').html('Fehler');
                    $('#prog').css('width', '100%');
                    $('#prog').addClass('progress-bar-danger');
                    $('#start').removeClass('disabled');
                    clearInterval(doProgress);
                } else if (data.progress === "done") {
                    $('#prog').html('100%');
                    $('#prog').css('width', '100%');
                    $('#start').removeClass('disabled');
                    clearInterval(doProgress);
                    showFinalDone();
                } else if (typeof data.progress === 'number') {
                    $('#prog').css('width', data.progress + '%');
                    $('#prog').html(data.progress + '%');
                } else {
                    // Fallback für unbekannten Statuswert
                    $('#prog').html('Verarbeitung...');
                    $('#prog').css('width', '50%');
                }
            });
    }

    function showFinalDone() {
        console.log("Finalizing...");
        $.ajax({
            type: 'get',
            url: 'index.php?page=ffmpeg/main&ffmpeg_video=1&done=1',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);
                $('#log pre').html("Request failed: " + textStatus);
            })
            .done(function (data) {
                console.log("Done response:", data);
                $('#log pre').html(data.log);
                
                // Show success message
                if (typeof rex !== 'undefined' && rex.infoPool) {
                    rex.infoPool.add({
                        type: 'success',
                        title: 'FFmpeg',
                        message: 'Vorgang erfolgreich abgeschlossen!'
                    });
                } else {
                    alert('Vorgang erfolgreich abgeschlossen!');
                }
                
                // Reload page to show updated video list
                setTimeout(function() {
                    window.location.reload();
                }, 3000);
            });
    }

    $(document).ready(function () {
        console.log("FFMPEG Script loaded"); // Debug-Ausgabe

        // Toggle between operation types
        $('.operation-select').on('click', function() {
            console.log("Operation selected:", $(this).data('operation')); // Debug-Ausgabe
            
            $('.operation-select').removeClass('active');
            $(this).addClass('active');
            
            const operationType = $(this).data('operation');
            $('.operation-options').hide();
            $('#' + operationType + '-options').show();
            $('input[name=operation_type]').val(operationType);
        });

        // Video selection - Direct handler
        $(document).on('change', '.video-select', function() {
            console.log("Video selected:", $(this).val()); // Debug-Ausgabe
            
            if ($('input[name=video]:checked').length > 0) {
                $('#start').removeClass('disabled');
            } else {
                $('#start').addClass('disabled');
            }
        });

        // Delete optimized video
        $(document).on('click', '.delete-optimized', function(e) {
            e.preventDefault();
            if (!confirm('Sind Sie sicher, dass Sie diese Datei löschen möchten?')) {
                return;
            }
            
            const filename = $(this).data('filename');
            $.ajax({
                type: 'get',
                url: 'index.php?page=ffmpeg/main&ffmpeg_video=1&delete_optimized=1&filename=' + filename,
                dataType: 'json',
            })
                .done(function(data) {
                    if (data.success) {
                        if (typeof rex !== 'undefined' && rex.infoPool) {
                            rex.infoPool.add({
                                type: 'success',
                                title: 'FFmpeg',
                                message: 'Datei erfolgreich gelöscht!'
                            });
                        } else {
                            alert('Datei erfolgreich gelöscht!');
                        }
                        
                        window.location.reload();
                    } else {
                        if (typeof rex !== 'undefined' && rex.infoPool) {
                            rex.infoPool.add({
                                type: 'error',
                                title: 'FFmpeg',
                                message: 'Fehler beim Löschen: ' + data.message
                            });
                        } else {
                            alert('Fehler beim Löschen: ' + data.message);
                        }
                    }
                })
                .fail(function() {
                    if (typeof rex !== 'undefined' && rex.infoPool) {
                        rex.infoPool.add({
                            type: 'error',
                            title: 'FFmpeg',
                            message: 'Fehler beim Löschen der Datei'
                        });
                    } else {
                        alert('Fehler beim Löschen der Datei');
                    }
                });
        });

        // Time range slider for trimmed videos
        if ($('#trim-range').length) {
            const $trimRange = $('#trim-range');
            const $startTime = $('#trim_start_time');
            const $endTime = $('#trim_end_time');
            const $duration = $('#video_duration');
            
            $trimRange.slider({
                range: true,
                min: 0,
                max: parseInt($duration.val() || 100),
                values: [0, parseInt($duration.val() || 100)],
                slide: function(event, ui) {
                    $startTime.val(formatTime(ui.values[0]));
                    $endTime.val(formatTime(ui.values[1]));
                }
            });
            
            // Initialize times
            $startTime.val(formatTime($trimRange.slider("values", 0)));
            $endTime.val(formatTime($trimRange.slider("values", 1)));
            
            // Update slider when time inputs change
            $startTime.on('change', function() {
                const timeInSeconds = parseTimeToSeconds($(this).val());
                const sliderValues = $trimRange.slider("values");
                $trimRange.slider("values", 0, timeInSeconds);
            });
            
            $endTime.on('change', function() {
                const timeInSeconds = parseTimeToSeconds($(this).val());
                const sliderValues = $trimRange.slider("values");
                $trimRange.slider("values", 1, timeInSeconds);
            });
        }
        
        // Video preview
        $(document).on('click', '.video-preview-link', function(e) {
            e.preventDefault();
            const videoUrl = $(this).data('video');
            const $modal = $('#video-preview-modal');
            
            $modal.find('video source').attr('src', videoUrl);
            $modal.find('video')[0].load();
            $modal.modal('show');
        });

        // Start operation
        $(document).on('click', '#start', function (e) {
            e.preventDefault();
            console.log("Start button clicked"); // Debug-Ausgabe
            
            const $button = $(this);
            
            if ($button.hasClass('disabled')) {
                console.log("Button is disabled"); // Debug-Ausgabe
                return false;
            }

            let video = $('input[name=video]:checked').val();
            let operationType = $('input[name=operation_type]').val();

            console.log("Selected video:", video); // Debug-Ausgabe
            console.log("Operation type:", operationType); // Debug-Ausgabe

            if (video === undefined) {
                if (typeof rex !== 'undefined' && rex.infoPool) {
                    rex.infoPool.add({
                        type: 'error',
                        title: 'FFmpeg',
                        message: 'Bitte wählen Sie eine Video-Datei aus!'
                    });
                } else {
                    alert('Bitte wählen Sie eine Video-Datei aus!');
                }
                return false;
            }

            let url = 'index.php?page=ffmpeg/main&ffmpeg_video=1&start=1&video=' + video + '&operation=' + operationType;
            
            // Add operation-specific parameters
            if (operationType === 'trim') {
                const startTime = $('#trim_start_time').val();
                const endTime = $('#trim_end_time').val();
                url += '&start_time=' + startTime + '&end_time=' + endTime;
            } else if (operationType === 'poster') {
                const timestamp = $('#poster_timestamp').val();
                url += '&timestamp=' + timestamp;
            }

            console.log("Request URL:", url); // Debug-Ausgabe
            
            $button.addClass('disabled');
            
            // Reset progress bar
            $('#prog').removeClass('progress-bar-danger');
            $('#prog').css('width', '0%');
            $('#prog').html('0%');
            
            // Clear log
            $('#log pre').html('');

            $.ajax({
                type: 'get',
                url: url
            })
                .fail(function (jqXHR, textStatus) {
                    console.log("Request failed: " + textStatus);
                    $('.log').show();
                    $('.progress-section').show();
                    $('#log pre').html("Request failed: " + textStatus);
                    
                    $button.removeClass('disabled');
                    
                    if (typeof rex !== 'undefined' && rex.infoPool) {
                        rex.infoPool.add({
                            type: 'error',
                            title: 'FFmpeg',
                            message: 'Fehler beim Starten des Vorgangs: ' + textStatus
                        });
                    } else {
                        alert('Fehler beim Starten des Vorgangs: ' + textStatus);
                    }
                })
                .done(function (msg) {
                    console.log("Request succeeded"); // Debug-Ausgabe
                    $('.log').show();
                    $('.progress-section').show();
                    $('.progress').show();
                    
                    // Sofortiges Feedback
                    $('#log pre').html('Vorgang gestartet...');
                    $('#prog').css('width', '5%');
                    $('#prog').html('5%');
                    
                    SetProgressStart();
                });

            return false;
        });
    });
    
    // Helper function to format seconds to HH:MM:SS
    function formatTime(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        return [h, m, s].map(v => v < 10 ? '0' + v : v).join(':');
    }
    
    // Helper function to parse HH:MM:SS to seconds
    function parseTimeToSeconds(timeStr) {
        const parts = timeStr.split(':').map(part => parseInt(part, 10));
        return parts[0] * 3600 + parts[1] * 60 + parts[2];
    }
})(jQuery);
