(function ($) {
    var doProgress;

    function SetProgressStart() {
        doProgress = setInterval(
            showProgress, 2000);
    }

    function scrolllog() {
        $('#log pre').scrollTop($('#log pre')[0].scrollHeight);
    }

    function showProgress() {
        $.ajax({
            type: 'get',
            url: './index.php?ffmpeg_video=1&progress=1',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);

                $('#log pre').html("Request failed: " + textStatus);
                $('#start').removeClass('disabled');
                clearInterval(doProgress);
            })
            .done(function (data) {
                $('#log pre').html(data.log);

                scrolllog();

                if (data.log.indexOf("Overwrite ?") >= 0) {
                    $('#start').removeClass('disabled');
                    clearInterval(doProgress);
                } else {
                    if (data.progress === "done") {
                        $('#prog').html('100%');
                        $('#prog').css('width', '100%');
                        $('#start').removeClass('disabled');
                        clearInterval(doProgress);
                        showFinalDone();
                    } else {
                        $('#prog').css('width', data.progress + '%');
                        $('#prog').html(data.progress + '%');
                    }
                }
            });
    }

    function showFinalDone() {
        $.ajax({
            type: 'get',
            url: './index.php?ffmpeg_video=1&done=1',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);
                $('#log pre').html("Request failed: " + textStatus);
            })
            .done(function (data) {
                $('#log pre').html(data.log);
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
        $('input[name=video]').on('change', function() {
            console.log("Video selected:", $(this).val()); // Debug-Ausgabe
            
            if ($('input[name=video]:checked').length > 0) {
                $('#start').removeClass('disabled');
            } else {
                $('#start').addClass('disabled');
            }
        });

        // Handle tabs
        $('.nav-tabs a').on('click', function (e) {
            e.preventDefault();
            $(this).tab('show');
        });

        // Delete optimized video
        $('.delete-optimized').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Sind Sie sicher, dass Sie dieses optimierte Video löschen möchten?')) {
                return;
            }
            
            const filename = $(this).data('filename');
            $.ajax({
                type: 'get',
                url: './index.php?ffmpeg_video=1&delete_optimized=1&filename=' + filename,
                dataType: 'json',
            })
                .done(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Fehler beim Löschen: ' + data.message);
                    }
                })
                .fail(function() {
                    alert('Fehler beim Löschen des Videos');
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
        $('.video-preview-link').on('click', function(e) {
            e.preventDefault();
            const videoUrl = $(this).data('video');
            const $modal = $('#video-preview-modal');
            
            $modal.find('video source').attr('src', videoUrl);
            $modal.find('video')[0].load();
            $modal.modal('show');
        });

        // Start operation - Direkter Event-Handler zum Button
        $('#start').on('click', function (e) {
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
                alert('Bitte wählen Sie eine Video-Datei aus!');
                return false;
            }

            let url = './index.php?ffmpeg_video=1&start=1&video=' + video + '&operation=' + operationType;
            
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

            $.ajax({
                type: 'get',
                url: url
            })
                .fail(function (jqXHR, textStatus) {
                    console.log("Request failed: " + textStatus);
                    $('.log').show();
                    $('.progress-section').show();
                    $('#log pre').html("Request failed: " + textStatus);
                })
                .done(function (msg) {
                    console.log("Request succeeded"); // Debug-Ausgabe
                    $('.log').show();
                    $('.progress-section').show();
                    $('.progress').show();
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
