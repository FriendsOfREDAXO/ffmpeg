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
                    
                case 'status':
                    // Rufe die interne Status-Methode auf und sende das Ergebnis
                    $statusData = $this->checkStatus();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($statusData);
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
    
    // Öffentliche statische Methode, um den Konvertierungsstatus zu überprüfen
    public static function getConversionStatus()
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        $active = false;
        $processInfo = [];
        
        if (!empty($conversionId)) {
            $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
            if (file_exists($log)) {
                $logContent = rex_file::get($log);
                
                // Überprüfen, ob der Prozess abgeschlossen ist
                if (strpos($logContent, 'was successfully added to rex_mediapool') === false &&
                    strpos($logContent, 'registration was not successful') === false) {
                    
                    // Überprüfen, ob der ffmpeg-Prozess noch läuft
                    // Einfaches Heuristik: Wurde die Datei in den letzten 30 Sekunden aktualisiert?
                    $lastModified = filemtime($log);
                    $currentTime = time();
                    
                    if ($currentTime - $lastModified < 30) {
                        $active = true;
                        
                        // Dateinamen aus Log extrahieren
                        preg_match('/Konvertierung für "(.*?)" gestartet/', $logContent, $matches);
                        $videoName = $matches[1] ?? '';
                        
                        $processInfo = [
                            'video' => $videoName,
                            'startTime' => '',
                            'log' => $logContent
                        ];
                        
                        // Startzeit extrahieren
                        preg_match('/gestartet um (.*?)\\n/', $logContent, $timeMatches);
                        if (!empty($timeMatches[1])) {
                            $processInfo['startTime'] = $timeMatches[1];
                        }
                    } else {
                        // Prozess ist nicht mehr aktiv, aber möglicherweise nicht ordnungsgemäß beendet
                        // Wir löschen die Session-Variable, um künftige Konvertierungen zu ermöglichen
                        rex_unset_session('ffmpeg_conversion_id');
                    }
                } else {
                    // Prozess ist abgeschlossen, Session-Variable löschen
                    rex_unset_session('ffmpeg_conversion_id');
                }
            } else {
                // Log existiert nicht, Session-Variable löschen
                rex_unset_session('ffmpeg_conversion_id');
            }
        }
        
        return [
            'active' => $active,
            'info' => $processInfo
        ];
    }
    
    // Private Methode für interne API-Aufrufe
    private function checkStatus()
    {
        return self::getConversionStatus();
    }
    
    protected function isProcessRunning($conversionId)
    {
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            return false;
        }
        
        $logContent = rex_file::get($log);
        
        // Wenn das Log in den letzten 30 Sekunden geändert wurde, betrachten wir den Prozess als aktiv
        $lastModified = filemtime($log);
        $currentTime = time();
        
        if ($currentTime - $lastModified < 30) {
            return true;
        }
        
        // Alternativ könnten wir auch prüfen, ob der Prozess in den letzten Zeilen aktiv war
        // Extrahiere die letzten 100 Zeichen des Logs
        $tailLog = substr($logContent, -100);
        
        // Überprüfen auf typische ffmpeg-Ausgaben während der Verarbeitung
        $activeKeywords = [
            'frame=', 'fps=', 'time=', 'bitrate=', 'speed='
        ];
        
        foreach ($activeKeywords as $keyword) {
            if (strpos($tailLog, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    protected function handleStart($video)
    {
        if (empty($video)) {
            throw new rex_api_exception('No video file selected');
        }
        
        // Überprüfen, ob das Ausgabe-Video bereits existiert
        $outputFilename = 'web_' . pathinfo($video, PATHINFO_FILENAME) . '.mp4';
        $mediaSql = rex_sql::factory();
        $existingMedia = $mediaSql->getArray(
            'SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?',
            [$outputFilename]
        );
        
        if (!empty($existingMedia)) {
            // Überschreiben bestätigen lassen oder abbrechen
            if (rex_request('confirm_overwrite', 'boolean', false) !== true) {
                return [
                    'status' => 'confirm_overwrite',
                    'message' => 'Ein Video mit dem Namen "' . $outputFilename . '" existiert bereits. Überschreiben?'
                ];
            }
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
            return ['progress' => 'error', 'log' => 'No active conversion found'];
        }

        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            return ['progress' => 'error', 'log' => 'Log file not found'];
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

        // Check if conversion is complete based on log content and process status
        if ($progress > 98 || 
            strpos($getContent, 'Qavg') !== false || 
            strpos($getContent, 'kb/s:') !== false) {
            
            // Überprüfen, ob der Prozess noch läuft
            if (!$this->isProcessRunning($conversionId)) {
                // Prozess ist beendet, jetzt den Medienpool-Import durchführen
                $this->handleDone();
                return ['progress' => 'done', 'log' => $getContent];
            }
        }
        
        // Prozess ist entweder noch aktiv oder nicht vollständig abgeschlossen
        return ['progress' => $progress, 'log' => $getContent];
    }

    protected function handleDone()
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        if (empty($conversionId)) {
            return ['status' => 'error', 'log' => 'No active conversion found'];
        }

        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            return ['status' => 'error', 'log' => 'Log file not found'];
        }

        // Prüfen, ob das Log bereits die Erfolgsmeldung enthält
        $logContent = rex_file::get($log);
        if (strpos($logContent, 'was successfully added to rex_mediapool') !== false) {
            // Bereinigen der Session
            rex_unset_session('ffmpeg_conversion_id');
            return ['status' => 'success', 'log' => $logContent];
        }

        // Import required functions if needed
        if (!function_exists('rex_mediapool_deleteMedia')) {
            require rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
        }

        $inputFile = rex_session('ffmpeg_input_video_file', 'string', null);
        $outputFile = rex_session('ffmpeg_output_video_file', 'string', null);

        if (!is_null($inputFile) && !is_null($outputFile) && file_exists($outputFile)) {
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
            
            // Bereinigen der Session
            rex_unset_session('ffmpeg_conversion_id');
            
            return ['status' => 'success', 'log' => rex_file::get($log)];
        } else {
            rex_file::put($log, 'Fehler: Ausgabe-Datei konnte nicht erstellt werden.' . PHP_EOL, FILE_APPEND);
            
            if (!is_null($inputFile) && rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                rex_file::put($log, sprintf("Source file %s deletion was not possible", $inputFile) . PHP_EOL, FILE_APPEND);
            }
            
            if (!is_null($outputFile)) {
                rex_file::put($log, sprintf("Destination file %s rex_mediapool registration was not successful", $outputFile) . PHP_EOL, FILE_APPEND);
            }
            
            rex_file::put($log, 'Please execute a mediapool sync by hand' . PHP_EOL, FILE_APPEND);
            
            // Konvertierung fehlgeschlagen
            rex_file::put($log, 'Konvertierung fehlgeschlagen um ' . date('d.m.Y H:i:s') . PHP_EOL, FILE_APPEND);
            
            // Bereinigen der Session
            rex_unset_session('ffmpeg_conversion_id');
            
            return ['status' => 'error', 'log' => rex_file::get($log)];
        }
    }
}
