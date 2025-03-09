<?php
class rex_api_ffmpeg_converter extends rex_api_function
{
    protected $published = true;

    public function execute()
    {
        try {
            // Backend User Check
            $user = rex_backend_login::createUser();
            if (!$user) {
                throw new rex_api_exception('Unauthorized access - no backend login');
            }

            $func = rex_request('func', 'string', '');
            $video = rex_request('video', 'string', '');

            switch ($func) {
                case 'start':
                    $result = $this->handleStart($video);
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                case 'progress':
                    $result = $this->handleProgress();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                case 'done':
                    $result = $this->handleDone();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                default:
                    throw new rex_api_exception('Invalid function');
            }
        } catch (Exception $e) {
            rex_logger::logException($e);
            rex_response::cleanOutputBuffers();
            rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
            rex_response::sendJson(['error' => $e->getMessage()]);
            exit;
        }
    }

    protected function handleStart($video)
    {
        if (empty($video)) {
            throw new rex_api_exception('No video file selected');
        }

        // Create unique ID for this conversion
        $conversionId = uniqid();
        rex_set_session('ffmpeg_conversion_id', $conversionId);

        // Create or clear log file
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        rex_file::put($log, '');

        // Setup input and output paths
        $input = rex_path::media($video);
        $output = rex_path::media('web_' . pathinfo($input, PATHINFO_FILENAME));

        // Get command from config
        $command = trim(rex_addon::get('ffmpeg')->getConfig('command')) . " ";

        // Extract output file extension from command
        preg_match_all('/OUTPUT.(.*) /m', $command, $matches, PREG_SET_ORDER, 0);
        if (count($matches) > 0) {
            $file = (trim($matches[0][0]));
            $outputFile = $output . '.' . pathinfo($file, PATHINFO_EXTENSION);
            rex_set_session('ffmpeg_input_video_file', $input);
            rex_set_session('ffmpeg_output_video_file', $outputFile);
        }

        // Replace placeholders in command
        $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);

        // Execute ffmpeg command in background
        if (str_starts_with(PHP_OS, 'WIN')) {
            pclose(popen("start /B " . $command . " 1> $log 2>&1", "r")); // windows
        } else {
            shell_exec($command . " 1> $log 2>&1 >/dev/null &"); //linux
        }

        return ['status' => 'started', 'conversion_id' => $conversionId];
    }

    protected function handleProgress()
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        if (empty($conversionId)) {
            throw new rex_api_exception('No active conversion found');
        }

        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        $getContent = rex_file::get($log);

        // Parse progress from ffmpeg output
        preg_match("/Duration: (.*?), start:/ms", $getContent, $matches);
        if (!empty($rawDuration = $matches[1] ?? '')) {
            $ar = array_reverse(explode(":", $rawDuration));
            $duration = floatval($ar[0]);
            if (!empty($ar[1])) $duration += intval($ar[1]) * 60;
            if (!empty($ar[2])) $duration += intval($ar[2]) * 60 * 60;
        } else {
            $duration = 0;
        }

        preg_match_all("/time=(.*?) bitrate/", $getContent, $matches);
        $rawTime = array_pop($matches);
        if (is_array($rawTime)) {
            $rawTime = array_pop($rawTime);
        }

        if (!empty($rawTime)) {
            $ar = array_reverse(explode(":", $rawTime));
            $time = floatval($ar[0]);
            if (!empty($ar[1])) $time += intval($ar[1]) * 60;
            if (!empty($ar[2])) $time += intval($ar[2]) * 60 * 60;

            // Calculate progress percentage
            if ($duration > 0) {
                $progress = round(($time / $duration) * 100);
            } else {
                $progress = 0;
            }
        } else {
            $progress = 0;
        }

        // Check if conversion is complete
        if ($progress > 98) {
            $results = 'done';
        } elseif (strpos($getContent, 'Qavg') !== false) {
            $results = 'done';
        } elseif (strpos($getContent, 'kb/s:') !== false) {
            $results = 'done';
        } else {
            $results = $progress;
        }

        return ['progress' => $results, 'log' => $getContent];
    }

    protected function handleDone()
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        if (empty($conversionId)) {
            throw new rex_api_exception('No active conversion found');
        }

        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');

        // Import required functions if needed
        if (!function_exists('rex_mediapool_deleteMedia')) {
            require rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
        }

        $inputFile = rex_session('ffmpeg_input_video_file', 'string', null);
        $outputFile = rex_session('ffmpeg_output_video_file', 'string', null);

        if (!is_null($inputFile) && !is_null($outputFile)) {
            // Delete source file if configured
            if (rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                rex_mediapool_deleteMedia(pathinfo($inputFile, PATHINFO_BASENAME));
                rex_unset_session('ffmpeg_input_video_file');
                rex_file::put($log, sprintf("Source file %s deletion was successful", $inputFile) . PHP_EOL, FILE_APPEND);
            }
            
            // Add converted file to media pool
            rex_mediapool_syncFile(pathinfo($outputFile, PATHINFO_BASENAME), 0, '');
            rex_unset_session('ffmpeg_output_video_file');
            rex_file::put($log, sprintf("Destination file %s was successfully added to rex_mediapool", $outputFile) . PHP_EOL, FILE_APPEND);
        } else {
            if (rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                rex_file::put($log, sprintf("Source file %s deletion was not possible", $inputFile) . PHP_EOL, FILE_APPEND);
            }
            rex_file::put($log, sprintf("Destination file %s rex_mediapool registration was not successful", $outputFile) . PHP_EOL, FILE_APPEND);
            rex_file::put($log, 'Please execute a mediapool sync by hand' . PHP_EOL, FILE_APPEND);
        }

        // Clean up session
        rex_unset_session('ffmpeg_conversion_id');

        return ['log' => rex_file::get($log)];
    }
}
