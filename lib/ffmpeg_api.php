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
                    // Prüfen, ob bereits eine Konvertierung läuft
                    if ($this->isConversionActive()) {
                        throw new rex_api_exception('Eine Konvertierung läuft bereits. Bitte warten Sie, bis diese abgeschlossen ist.');
                    }
                    
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
                    
                case 'status':
                    $result = ['active' => $this->isConversionActive()];
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
    
    protected function isConversionActive()
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        if (empty($conversionId)) {
            return false;
        }
        
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            return false;
        }
        
        $logContent = rex_file::get($log);
        
        // Wenn das Log existiert, aber keine "Fertig"-Marke enthält, läuft die Konvertierung noch
        return strpos($logContent, 'was successfully added to rex_mediapool') === false &&
               strpos($logContent, 'registration was not successful') === false;
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
        $output = rex_path::media('web_' . pathinfo($video, PATHINFO_FILENAME));

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

        // Schreibe Startinformationen ins Log
        rex_file::put($log, 'Konvertierung für "' . $video . '" gestartet um ' . date('d.m.Y H:i:s') . "\n", FILE_APPEND);
        rex_file::put($log, 'Kommando: ' . $command . "\n\n", FILE_APPEND);

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
        if (!file_exists($log)) {
            throw new rex_api_exception('Log file not found');
        }
        
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
            // Direkt den Done-Handler aufrufen, um das Video in den Medienpool zu importieren
            $this->handleDone(true);
        } elseif (strpos($getContent, 'Qavg') !== false) {
            $results = 'done';
            $this->handleDone(true);
        } elseif (strpos($getContent, 'kb/s:') !== false) {
            $results = 'done';
            $this->handleDone(true);
        } else {
            $results = $progress;
        }

        return ['progress' => $results, 'log' => $getContent];
    }

    protected function handleDone($silentMode = false)
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        if (empty($conversionId)) {
            if ($silentMode) {
                return ['log' => 'No active conversion found'];
            }
            throw new rex_api_exception('No active conversion found');
        }

        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            throw new rex_api_exception('Log file not found');
        }

        // Import required functions if needed
        if (!function_exists('rex_mediapool_deleteMedia')) {
            require rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
        }

        $inputFile = rex_session('ffmpeg_input_video_file', 'string', null);
        $outputFile = rex_session('ffmpeg_output_video_file', 'string', null);

        if (!is_null($inputFile) && !is_null($outputFile)) {
            // Holen der Dateigröße vor dem Löschen
            $originalSize = 0;
            if (file_exists($inputFile)) {
                $originalSize = filesize($inputFile);
            }
            
            // Holen der Dateigröße des konvertierten Videos
            $convertedSize = 0;
            if (file_exists($outputFile)) {
                $convertedSize = filesize($outputFile);
            }
            
            // Berechnen der Einsparung
            $savings = 0;
            if ($originalSize > 0 && $convertedSize > 0) {
                $savings = round(100 - (($convertedSize / $originalSize) * 100));
                rex_file::put($log, sprintf("Dateigröße reduziert um %d%% (von %s auf %s)", 
                    $savings,
                    rex_formatter::bytes($originalSize),
                    rex_formatter::bytes($convertedSize)
                ) . PHP_EOL, FILE_APPEND);
            }
            
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
            
            // Konvertierung abgeschlossen
            rex_file::put($log, 'Konvertierung abgeschlossen um ' . date('d.m.Y H:i:s') . PHP_EOL, FILE_APPEND);
        } else {
            if (rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                rex_file::put($log, sprintf("Source file %s deletion was not possible", $inputFile) . PHP_EOL, FILE_APPEND);
            }
            rex_file::put($log, sprintf("Destination file %s rex_mediapool registration was not successful", $outputFile) . PHP_EOL, FILE_APPEND);
            rex_file::put($log, 'Please execute a mediapool sync by hand' . PHP_EOL, FILE_APPEND);
        }

        // Clean up session
        rex_unset_session('ffmpeg_conversion_id');

        $logContent = rex_file::get($log);
        
        // Wenn im Silent-Modus, Rückgabe ohne Header-Änderungen
        if ($silentMode) {
            return ['log' => $logContent];
        }
        
        return ['log' => $logContent];
    }
}
