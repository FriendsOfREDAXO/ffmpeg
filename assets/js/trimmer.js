(function ($) {
    var selectionPlaybackActive = false;

    function toNumber(value) {
        var parsed = parseFloat(value);
        if (Number.isNaN(parsed)) {
            return 0;
        }
        return parsed;
    }

    function formatTime(seconds) {
        var safeSeconds = Math.max(0, toNumber(seconds));
        return safeSeconds.toFixed(1) + 's';
    }

    function updateDurationHint() {
        var context = document.getElementById('ffmpeg-trimmer-context');
        if (!context) {
            return;
        }

        var template = context.getAttribute('data-preview-template') || 'Das geschnittene Video wird {0} Sekunden lang sein';
        var hint = document.getElementById('trimmer-duration-hint');
        if (!hint) {
            return;
        }

        var start = toNumber($('#start_time').val());
        var end = toNumber($('#end_time').val());
        var duration = Math.max(0, end - start);

        hint.textContent = template.replace('{0}', duration.toFixed(1));
    }

    function setCurrentTime(target) {
        var video = document.getElementById('trimmer-video');
        if (!video) {
            return;
        }

        var currentTime = video.currentTime.toFixed(1);
        if (target === 'start') {
            $('#start_time').val(currentTime);
        } else if (target === 'end') {
            $('#end_time').val(currentTime);
        }

        updateDurationHint();
        updateVideoHud();
    }

    function seekBy(seconds) {
        var video = document.getElementById('trimmer-video');
        if (!video) {
            return;
        }

        var duration = Number.isFinite(video.duration) ? video.duration : 0;
        var next = Math.max(0, Math.min(duration, video.currentTime + seconds));
        video.currentTime = next;
        updateVideoHud();
    }

    function updateVideoHud() {
        var video = document.getElementById('trimmer-video');
        if (!video) {
            return;
        }

        var currentNode = document.getElementById('trimmer-chip-current');
        var startNode = document.getElementById('trimmer-chip-start');
        var endNode = document.getElementById('trimmer-chip-end');
        var scrubber = document.getElementById('trimmer-scrubber');

        var start = toNumber($('#start_time').val());
        var end = toNumber($('#end_time').val());
        var current = toNumber(video.currentTime);
        var duration = Number.isFinite(video.duration) ? video.duration : 0;

        if (currentNode) {
            currentNode.textContent = formatTime(current);
        }
        if (startNode) {
            startNode.textContent = formatTime(start);
        }
        if (endNode) {
            endNode.textContent = formatTime(end);
        }

        if (scrubber) {
            scrubber.max = String(duration);
            scrubber.value = String(current);
        }
    }

    function playSelection() {
        var video = document.getElementById('trimmer-video');
        if (!video) {
            return;
        }

        var start = toNumber($('#start_time').val());
        var end = toNumber($('#end_time').val());

        if (end <= start) {
            return;
        }

        selectionPlaybackActive = true;
        video.loop = false;
        video.currentTime = start;
        video.play();
    }

    function restartSelectionPlayback() {
        var video = document.getElementById('trimmer-video');
        if (!video || !selectionPlaybackActive) {
            return;
        }

        var start = toNumber($('#start_time').val());
        var end = toNumber($('#end_time').val());
        if (end <= start) {
            return;
        }

        if (video.currentTime < end) {
            return;
        }

        video.pause();
        video.currentTime = start;
        window.setTimeout(function () {
            if (selectionPlaybackActive && video.paused) {
                video.play();
            }
        }, 50);
    }

    function setupEditor() {
        var video = document.getElementById('trimmer-video');
        if (!video) {
            return;
        }

        if (video.dataset.ffmpegReady === '1') {
            applyVideoMetadata();
            updateVideoHud();
            return;
        }
        video.dataset.ffmpegReady = '1';

        function applyVideoMetadata() {
            if (!Number.isFinite(video.duration) || video.duration <= 0) {
                return;
            }

            if (!$('#end_time').val()) {
                $('#end_time').val(video.duration.toFixed(1));
            }

            var scrubber = document.getElementById('trimmer-scrubber');
            if (scrubber) {
                scrubber.max = String(video.duration);
                if (!scrubber.value || scrubber.value === '0') {
                    scrubber.value = String(video.currentTime || 0);
                }
            }

            updateDurationHint();
            updateVideoHud();
        }

        $(document).on('click', '.trimmer-set-current', function () {
            var target = $(this).data('target');
            setCurrentTime(String(target));
        });

        $('#start_time, #end_time').on('input change', function () {
            updateDurationHint();
            updateVideoHud();
        });

        $(document).on('click', '.trimmer-video-control', function () {
            var action = String($(this).data('action') || '');
            if (action === 'seek') {
                seekBy(toNumber($(this).data('seconds')));
                return;
            }

            if (action === 'toggle-play') {
                if (video.paused) {
                    video.play();
                } else {
                    video.pause();
                    selectionPlaybackActive = false;
                }
                return;
            }

            if (action === 'mark-start') {
                setCurrentTime('start');
                return;
            }

            if (action === 'mark-end') {
                setCurrentTime('end');
                return;
            }

            if (action === 'play-selection') {
                playSelection();
            }
        });

        $('#trimmer-scrubber').on('input change', function () {
            video.currentTime = toNumber($(this).val());
            updateVideoHud();
        });

        $(document).on('change', '.trimmer-loop-toggle', function () {
            var targetId = $(this).data('target');
            var targetVideo = document.getElementById(targetId);
            if (targetVideo) {
                targetVideo.loop = $(this).is(':checked');
            }
        });

        video.addEventListener('timeupdate', function () {
            updateVideoHud();

            if (!selectionPlaybackActive) {
                return;
            }

            var end = toNumber($('#end_time').val());
            if (video.currentTime >= end) {
                restartSelectionPlayback();
            }
        });

        video.addEventListener('pause', function () {
            selectionPlaybackActive = false;
        });

        // Prefill and sync metadata-dependent controls.
        video.addEventListener('loadedmetadata', applyVideoMetadata);
        video.addEventListener('durationchange', applyVideoMetadata);
        video.addEventListener('loadeddata', applyVideoMetadata);

        document.addEventListener('keydown', function (event) {
            var activeTag = (document.activeElement && document.activeElement.tagName) ? document.activeElement.tagName.toLowerCase() : '';
            if (activeTag === 'input' || activeTag === 'textarea') {
                return;
            }

            if (event.ctrlKey && event.key.toLowerCase() === 's') {
                event.preventDefault();
                setCurrentTime('start');
                return;
            }

            if (event.ctrlKey && event.key.toLowerCase() === 'e') {
                event.preventDefault();
                setCurrentTime('end');
                return;
            }

            if (event.key === ' ') {
                event.preventDefault();
                if (video.paused) {
                    video.play();
                } else {
                    video.pause();
                    selectionPlaybackActive = false;
                }
            }
        });

        applyVideoMetadata();
        updateVideoHud();
    }

    function setupListPreview() {
        var context = document.getElementById('ffmpeg-trimmer-context');
        if (!context) {
            return;
        }

        var mediaBase = context.getAttribute('data-media-base') || '';

        $(document).on('click', '.video-preview-btn', function () {
            var filename = $(this).data('filename');
            if (!filename) {
                return;
            }

            var modal = $('#videoPreviewModal');
            if (!modal.length) {
                return;
            }

            var video = modal.find('#modalVideo')[0];
            var source = modal.find('#modalVideoSource')[0];
            var loopToggle = modal.find('.video-preview-loop-toggle');

            video.pause();
            source.src = mediaBase + encodeURIComponent(String(filename));
            modal.find('.video-filename-display').text(String(filename));
            video.loop = loopToggle.is(':checked');

            video.load();
            modal.modal('show');
        });

        $('#videoPreviewModal').on('change', '.video-preview-loop-toggle', function () {
            var targetId = $(this).data('previewTarget');
            var video = document.getElementById(targetId);
            if (video) {
                video.loop = $(this).is(':checked');
            }
        });

        $('#videoPreviewModal').on('hidden.bs.modal', function () {
            var video = $(this).find('#modalVideo')[0];
            video.pause();
        });
    }

    function initFfmpegTrimmer() {
        setupEditor();
        setupListPreview();
    }

    $(document).on('rex:ready', function () {
        initFfmpegTrimmer();
    });

    $(document).ready(function () {
        initFfmpegTrimmer();
    });
})(jQuery);
