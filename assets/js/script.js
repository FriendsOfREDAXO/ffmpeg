(function ($) {
    var doProgress;

    function SetProgressStart() {
        doProgress = setInterval(
            showProgress, 2000);
    }

    function scrolllog() {
        $('#log pre').scrollTop($('#log pre')[0].scrollHeight)
    }

    function showProgress() {
        $.ajax({
            type: 'get',
            url: './index.php?ffmpeg_video=1&progress=1',
            dataType: 'json', // data type
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
            dataType: 'json', // data type
        })
            .fail(function (jqXHR, textStatus) {
                console.log("Request failed: " + textStatus);

                $('#log pre').html("Request failed: " + textStatus);

            })
            .done(function (data) {
                $('#log pre').html(data.log);
            });
    }

    $(document).ready(function () {

        $('#start').on('click', function () {

            let video = $('input[name=video]:checked').val();

            if (video === undefined) {
                alert('Please Select Video File for Start Operation!');
                return false;
            }

            $(this).addClass('disabled');

            $.ajax({
                type: 'get',
                url: './index.php?ffmpeg_video=1&start=1&video=' + video
            })
                .fail(function (jqXHR, textStatus) {
                    console.log("Request failed: " + textStatus);

                    $('.log').show()
                    $('.progress-section').show();

                    $('#log pre').html("Request failed: " + textStatus);

                })
                .done(function (msg) {
                    $('.log').show();
                    $('.progress-section').show();
                    $('.progress').show();
                    SetProgressStart();
                })

            return false;
        })

    });
})(jQuery);