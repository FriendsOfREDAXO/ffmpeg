(function ($) {
    var selectionPlaybackActive = false;
    var selectionPlaybackMode = 'once';
    var pingPongDirection = 'forward';
    var pingPongTimer = null;
    var internalPauseForPingPong = false;

    function updatePlaybackUiState() {
        var context = document.getElementById('ffmpeg-trimmer-context');
        var modeIndicator = document.getElementById('trimmer-mode-indicator');
        var playSelectionButton = document.querySelector('.trimmer-video-control[data-action="play-selection"]');
        var pingPongButton = document.querySelector('.trimmer-video-control[data-action="play-selection-pingpong"]');
        var loopLabel = document.querySelector('.trimmer-loop-toggle-label');
        var loopToggle = document.querySelector('.trimmer-loop-toggle');

        var labelReady = 'Bereit';
        var labelOnce = 'Einmal';
        var labelLoop = 'Schleife';
        var labelPingPong = 'PingPong';

        if (context) {
            labelReady = context.getAttribute('data-mode-label-ready') || labelReady;
            labelOnce = context.getAttribute('data-mode-label-once') || labelOnce;
            labelLoop = context.getAttribute('data-mode-label-loop') || labelLoop;
            labelPingPong = context.getAttribute('data-mode-label-pingpong') || labelPingPong;
        }

        if (playSelectionButton) {
            playSelectionButton.classList.remove('is-active', 'mode-once', 'mode-loop');
            playSelectionButton.setAttribute('aria-pressed', 'false');
        }
        if (pingPongButton) {
            pingPongButton.classList.remove('is-active', 'mode-pingpong');
            pingPongButton.setAttribute('aria-pressed', 'false');
        }

        if (loopLabel && loopToggle) {
            loopLabel.classList.toggle('is-active', loopToggle.checked);
        }

        var indicatorText = labelReady;
        if (selectionPlaybackActive) {
            if (selectionPlaybackMode === 'pingpong') {
                indicatorText = labelPingPong;
                if (pingPongButton) {
                    pingPongButton.classList.add('is-active', 'mode-pingpong');
                    pingPongButton.setAttribute('aria-pressed', 'true');
                }
            } else if (selectionPlaybackMode === 'loop') {
                indicatorText = labelLoop;
                if (playSelectionButton) {
                    playSelectionButton.classList.add('is-active', 'mode-loop');
                    playSelectionButton.setAttribute('aria-pressed', 'true');
                }
            } else {
                indicatorText = labelOnce;
                if (playSelectionButton) {
                    playSelectionButton.classList.add('is-active', 'mode-once');
                    playSelectionButton.setAttribute('aria-pressed', 'true');
                }
            }
        }

        if (modeIndicator) {
            modeIndicator.textContent = indicatorText;
        }
    }

    function syncAudioRemovalControl() {
        var loopCheckbox = document.querySelector('input[name="create_loop"]');
        var removeAudioCheckbox = document.querySelector('input[name="remove_audio"]');

        if (!loopCheckbox || !removeAudioCheckbox) {
            return;
        }

        if (loopCheckbox.checked) {
            removeAudioCheckbox.checked = true;
            removeAudioCheckbox.disabled = true;
            var disabledLabel = removeAudioCheckbox.closest('label');
            if (disabledLabel) {
                disabledLabel.classList.add('is-disabled');
            }
        } else {
            removeAudioCheckbox.disabled = false;
            var enabledLabel = removeAudioCheckbox.closest('label');
            if (enabledLabel) {
                enabledLabel.classList.remove('is-disabled');
            }
        }
    }

    function stopPingPongTimer() {
        if (pingPongTimer !== null) {
            window.clearInterval(pingPongTimer);
            pingPongTimer = null;
        }
    }

    function stopSelectionPlayback() {
        selectionPlaybackActive = false;
        selectionPlaybackMode = 'once';
        pingPongDirection = 'forward';
        internalPauseForPingPong = false;
        stopPingPongTimer();
        updatePlaybackUiState();
    }

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

    function startPingPongReverse(video, start) {
        stopPingPongTimer();
        pingPongDirection = 'backward';
        internalPauseForPingPong = true;
        video.pause();
        window.setTimeout(function () {
            internalPauseForPingPong = false;
        }, 0);

        pingPongTimer = window.setInterval(function () {
            if (!selectionPlaybackActive || selectionPlaybackMode !== 'pingpong') {
                stopPingPongTimer();
                return;
            }

            var step = 0.04;
            var next = Math.max(start, toNumber(video.currentTime) - step);
            video.currentTime = next;
            updateVideoHud();

            if (next <= start + 0.001) {
                stopPingPongTimer();
                pingPongDirection = 'forward';
                video.currentTime = start;
                if (selectionPlaybackActive) {
                    video.play();
                }
            }
        }, 33);
    }

    function playSelection(mode) {
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
        selectionPlaybackMode = mode === 'pingpong' ? 'pingpong' : (mode === 'loop' ? 'loop' : 'once');
        pingPongDirection = 'forward';
        stopPingPongTimer();
        video.loop = false;
        video.currentTime = start;
        video.play();
        updatePlaybackUiState();
    }

    function restartSelectionPlayback() {
        var video = document.getElementById('trimmer-video');
        if (!video || !selectionPlaybackActive || selectionPlaybackMode !== 'loop') {
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

        // Wichtig: Kein pause(), sonst setzt der pause-Handler selectionPlaybackActive auf false.
        // Dadurch würde der Bereichstest nach einem Durchlauf stoppen.
        video.currentTime = start;
        if (video.paused && selectionPlaybackActive) {
            video.play();
        }
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
                    stopSelectionPlayback();
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
                var loopEnabled = $('.trimmer-loop-toggle').is(':checked');
                var normalMode = loopEnabled ? 'loop' : 'once';
                if (selectionPlaybackActive && selectionPlaybackMode === normalMode) {
                    stopSelectionPlayback();
                    video.pause();
                    return;
                }

                playSelection(normalMode);
                return;
            }

            if (action === 'play-selection-pingpong') {
                if (selectionPlaybackActive && selectionPlaybackMode === 'pingpong') {
                    stopSelectionPlayback();
                    video.pause();
                    return;
                }

                playSelection('pingpong');
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
            updatePlaybackUiState();
        });

        $(document).on('change', 'input[name="create_loop"]', function () {
            syncAudioRemovalControl();
        });

        $(document).on('change', 'input[name="remove_audio"]', function () {
            syncAudioRemovalControl();
        });

        video.addEventListener('timeupdate', function () {
            updateVideoHud();

            if (!selectionPlaybackActive) {
                return;
            }

            if (selectionPlaybackMode === 'pingpong') {
                var pingPongStart = toNumber($('#start_time').val());
                var pingPongEnd = toNumber($('#end_time').val());
                if (pingPongEnd <= pingPongStart) {
                    return;
                }

                if (pingPongDirection === 'forward' && video.currentTime >= pingPongEnd) {
                    startPingPongReverse(video, pingPongStart);
                }

                return;
            }

            var end = toNumber($('#end_time').val());
            if (video.currentTime >= end && selectionPlaybackMode === 'loop') {
                restartSelectionPlayback();
                return;
            }

            if (video.currentTime >= end && selectionPlaybackMode === 'once') {
                stopSelectionPlayback();
                video.pause();
            }
        });

        video.addEventListener('pause', function () {
            if (internalPauseForPingPong) {
                return;
            }
            stopSelectionPlayback();
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
                    stopSelectionPlayback();
                }
            }
        });

        applyVideoMetadata();
        updateVideoHud();
        updatePlaybackUiState();
        syncAudioRemovalControl();
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
