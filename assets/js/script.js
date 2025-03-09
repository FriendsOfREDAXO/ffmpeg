(function ($) {
    var doProgress;
    var conversionActive = false;

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
            url: 'index.php?rex-api-call=ffmpeg_converter&func=progress',
            dataType: 'json',
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);

                $('#log pre').html("Request failed: " + textStatus);
                $('#start').removeClass('disabled');
                clearInterval(doProgress);
                conversionActive = false;
            })
            .done(function (data) {
                if (data.error) {
                    $('#log pre').html("Error: " + data.error);
                    $('#start').removeClass('disabled');
                    clearInterval(doProgress);
                    conversionActive = false;
                    return;
                }

                $('#log pre').html(data.log);
                scrolllog();

                if (data.log.indexOf("Overwrite ?") >= 0) {
                    $('#start').removeClass('disabled');
                    clearInterval(doProgress);
                    conversionActive = false;
                } else {
                    if (data.progress === "done") {
                        $('#prog').html('100%');
                        $('#prog').css('width', '100%');
                        $('#start').removeClass('disabled');
                        clearInterval(doProgress);
                        conversionActive = false;
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
        // Check if there's an active conversion on page load
        if (conversionActive) {
            $('.log').show();
            $('.progress-section').show();
            $('.progress').show();
            SetProgressStart();
        }

        $('#start').on('click', function () {
            let video = $('input[name=video]:checked').val();

            if (video === undefined) {
                alert('Please Select Video File for Start Operation!');
                return false;
            }

            $(this).addClass('disabled');
            conversionActive = true;

            $.ajax({
                type: 'get',
                url: 'index.php?rex-api-call=ffmpeg_converter&func=start&video=' + encodeURIComponent(video),
                dataType: 'json'
            })
                .fail(function (jqXHR, textStatus) {
                    console.log("Request failed: " + textStatus);
                    $('.log').show();
                    $('.progress-section').show();
                    $('#log pre').html("Request failed: " + textStatus);
                    $('#start').removeClass('disabled');
                    conversionActive = false;
                })
                .done(function (data) {
                    if (data.error) {
                        $('.log').show();
                        $('.progress-section').show();
                        $('#log pre').html("Error: " + data.error);
                        $('#start').removeClass('disabled');
                        conversionActive = false;
                        return;
                    }

                    $('.log').show();
                    $('.progress-section').show();
                    $('.progress').show();
                    $('#prog').css('width', '0%');
                    $('#prog').html('0%');
                    $('#log pre').html("Conversion started...");
                    SetProgressStart();
                });

            return false;
        });

        // Optional: Add a button to check status of ongoing conversions
        $('#check_status').on('click', function() {
            if (!conversionActive) {
                conversionActive = true;
                $('.log').show();
                $('.progress-section').show();
                $('.progress').show();
                SetProgressStart();
            }
            return false;
        });
    });
})(jQuery);
