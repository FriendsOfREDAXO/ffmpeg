<?php

if (rex::isBackend() && rex::getUser()) {

    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }
    $log = rex_addon::get('ffmpeg')->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');

    // Immer sicherstellen, dass Datenpfad existiert
    if (!is_dir(rex_addon::get('ffmpeg')->getDataPath())) {
        mkdir(rex_addon::get('ffmpeg')->getDataPath(), 0755, true);
    }

    if (rex_be_controller::getCurrentPagePart(1) == 'ffmpeg') {
        rex_view::addJsFile(rex_url::addonAssets('ffmpeg', 'js/script.js'));
        rex_view::addCssFile(rex_url::addonAssets('ffmpeg', 'css/style.css'));
        
        // jQuery UI für Slider
        rex_view::addJsFile('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js');
        rex_view::addCssFile('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css');
    }

    if (rex_request::get('ffmpeg_video', 'boolean', false)) {

        if (rex_request::get('start', 'boolean', false)) {
            rex_file::put($log, "=== NEW FFMPEG PROCESS STARTED ===\n");
            rex_file::put($log, "Time: " . date('Y-m-d H:i:s') . "\n");
            rex_file::put($log, "PHP version: " . phpversion() . "\n");
            rex_file::put($log, "OS: " . PHP_OS . "\n");
            
            try {
                $input = rex_path::media(rex_request::get('video', 'string'));
                $operation = rex_request::get('operation', 'string', 'convert');
                
                // Debug-Log starten
                rex_file::put($log, "Starting FFmpeg operation: " . $operation . "\n", FILE_APPEND);
                rex_file::put($log, "Input file: " . $input . "\n", FILE_APPEND);
                
                // Check file read permissions
                if (file_exists($input)) {
                    if (is_readable($input)) {
                        rex_file::put($log, "Input file exists and is readable\n", FILE_APPEND);
                    } else {
                        rex_file::put($log, "ERROR: Input file exists but is not readable!\n", FILE_APPEND);
                    }
                    
                    // Try to get file size as additional check
                    $filesize = @filesize($input);
                    if ($filesize !== false) {
                        rex_file::put($log, "Input file size: " . $filesize . " bytes\n", FILE_APPEND);
                    } else {
                        rex_file::put($log, "WARNING: Cannot get file size!\n", FILE_APPEND);
                    }
                } else {
                    rex_file::put($log, "ERROR: Input file does not exist: " . $input . "\n", FILE_APPEND);
                }
                
                // Get original video metadata if available
                $originalMeta = [];
                if (file_exists($input)) {
                    $mediaObj = rex_media::get(rex_request::get('video', 'string'));
                    if ($mediaObj) {
                        // Get meta info for the original video
                        $sql = rex_sql::factory();
                        $metaFields = $sql->getArray('SELECT * FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE \'med_%\'');
                        
                        foreach ($metaFields as $field) {
                            $originalMeta[$field['name']] = $mediaObj->getValue($field['name']);
                        }
                    }
                }
                
                // Set up appropriate command and output based on operation type
                switch ($operation) {
                    case 'convert':
                        $output = rex_path::media('web_' . pathinfo($input, PATHINFO_FILENAME));
                        $command = trim(rex_addon::get('ffmpeg')->getConfig('command')) . " ";
                        
                        preg_match_all('/OUTPUT.(.*) /m', $command, $matches, PREG_SET_ORDER, 0);
                        if (count($matches) > 0) {
                            $file = (trim($matches[0][0]));
                            $outputFile = $output . '.' . pathinfo($file, PATHINFO_EXTENSION);
                            rex_set_session('ffmpeg_input_video_file', $input);
                            rex_set_session('ffmpeg_output_video_file', $outputFile);
                            rex_set_session('ffmpeg_operation', 'convert');
                            rex_set_session('ffmpeg_original_meta', $originalMeta);
                        } else {
                            // Fallback, wenn kein Match gefunden wurde
                            $outputFile = $output . '.mp4';
                            rex_set_session('ffmpeg_input_video_file', $input);
                            rex_set_session('ffmpeg_output_video_file', $outputFile);
                            rex_set_session('ffmpeg_operation', 'convert');
                            rex_set_session('ffmpeg_original_meta', $originalMeta);
                        }
                        
                        // Check output directory permissions
                        $outputDir = dirname($outputFile);
                        if (is_dir($outputDir)) {
                            if (is_writable($outputDir)) {
                                rex_file::put($log, "Output directory is writable\n", FILE_APPEND);
                            } else {
                                rex_file::put($log, "ERROR: Output directory is not writable: " . $outputDir . "\n", FILE_APPEND);
                            }
                        } else {
                            rex_file::put($log, "ERROR: Output directory doesn't exist: " . $outputDir . "\n", FILE_APPEND);
                        }
                        
                        $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);
                        break;
                        
                    case 'trim':
                        $startTime = rex_request::get('start_time', 'string', '00:00:00');
                        $endTime = rex_request::get('end_time', 'string', '00:00:10');
                        
                        // Create unique filename with time info
                        $timeInfo = str_replace(':', '', $startTime) . '-' . str_replace(':', '', $endTime);
                        $output = rex_path::media('trim_' . pathinfo($input, PATHINFO_FILENAME) . '_' . $timeInfo);
                        $outputFile = $output . '.mp4';
                        
                        // Build ffmpeg command for trim
                        $command = 'ffmpeg -y -i ' . escapeshellarg($input) . ' -ss ' . $startTime . ' -to ' . $endTime . ' -c:v libx264 -c:a aac ' . escapeshellarg($outputFile);
                        
                        rex_set_session('ffmpeg_input_video_file', $input);
                        rex_set_session('ffmpeg_output_video_file', $outputFile);
                        rex_set_session('ffmpeg_operation', 'trim');
                        rex_set_session('ffmpeg_original_meta', $originalMeta);
                        break;
                        
                    case 'poster':
                        $timestamp = rex_request::get('timestamp', 'string', '00:00:05');
                        
                        // Create unique filename with timestamp
                        $timeInfo = str_replace(':', '', $timestamp);
                        $output = rex_path::media('poster_' . pathinfo($input, PATHINFO_FILENAME) . '_' . $timeInfo);
                        $outputFile = $output . '.jpg';
                        
                        // Build ffmpeg command for poster extraction
                        $command = 'ffmpeg -y -i ' . escapeshellarg($input) . ' -ss ' . $timestamp . ' -frames:v 1 ' . escapeshellarg($outputFile);
                        
                        rex_set_session('ffmpeg_input_video_file', $input);
                        rex_set_session('ffmpeg_output_video_file', $outputFile);
                        rex_set_session('ffmpeg_operation', 'poster');
                        rex_set_session('ffmpeg_original_meta', $originalMeta);
                        break;
                    
                    default:
                        // Fallback to convert if unknown operation
                        $output = rex_path::media('web_' . pathinfo($input, PATHINFO_FILENAME));
                        $command = trim(rex_addon::get('ffmpeg')->getConfig('command')) . " ";
                        
                        preg_match_all('/OUTPUT.(.*) /m', $command, $matches, PREG_SET_ORDER, 0);
                        if (count($matches) > 0) {
                            $file = (trim($matches[0][0]));
                            $outputFile = $output . '.' . pathinfo($file, PATHINFO_EXTENSION);
                            rex_set_session('ffmpeg_input_video_file', $input);
                            rex_set_session('ffmpeg_output_video_file', $outputFile);
                            rex_set_session('ffmpeg_operation', 'convert');
                            rex_set_session('ffmpeg_original_meta', $originalMeta);
                        } else {
                            // Fallback, wenn kein Match gefunden wurde
                            $outputFile = $output . '.mp4';
                            rex_set_session('ffmpeg_input_video_file', $input);
                            rex_set_session('ffmpeg_output_video_file', $outputFile);
                            rex_set_session('ffmpeg_operation', 'convert');
                            rex_set_session('ffmpeg_original_meta', $originalMeta);
                        }
                        
                        $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);
                        break;
                }
                
                // Test if ffmpeg is available
                $testCommand = 'ffmpeg -version';
                $testOutput = [];
                $testReturnVar = 0;
                
                exec($testCommand, $testOutput, $testReturnVar);
                
                if ($testReturnVar !== 0) {
                    rex_file::put($log, "ERROR: ffmpeg command not found. Make sure ffmpeg is installed and in your PATH\n", FILE_APPEND);
                } else {
                    rex_file::put($log, "ffmpeg found: " . implode("\n", array_slice($testOutput, 0, 1)) . "\n", FILE_APPEND);
                }
                
                // Debug-Log
                rex_file::put($log, "Output file: " . $outputFile . "\n", FILE_APPEND);
                rex_file::put($log, "Command to execute: " . $command . "\n\n", FILE_APPEND);
                
                // Execute command based on OS
                if (str_starts_with(PHP_OS, 'WIN')) {
                    $winCmd = "start /B " . $command . " 1>> " . escapeshellarg($log) . " 2>&1";
                    rex_file::put($log, "Windows command: " . $winCmd . "\n\n", FILE_APPEND);
                    
                    // For Windows, try to use exec instead of popen if available
                    if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
                        rex_file::put($log, "Using exec for Windows\n", FILE_APPEND);
                        exec($winCmd, $execOutput, $execReturnVar);
                        rex_file::put($log, "exec return code: " . $execReturnVar . "\n", FILE_APPEND);
                    } else {
                        rex_file::put($log, "Using popen for Windows\n", FILE_APPEND);
                        pclose(popen($winCmd, "r"));
                    }
                } else {
                    $unixCmd = $command . " 1>> " . escapeshellarg($log) . " 2>&1 >/dev/null &";
                    rex_file::put($log, "Unix command: " . $unixCmd . "\n\n", FILE_APPEND);
                    
                    if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
                        rex_file::put($log, "Using exec for Unix\n", FILE_APPEND);
                        exec($unixCmd, $execOutput, $execReturnVar);
                        rex_file::put($log, "exec return code: " . $execReturnVar . "\n", FILE_APPEND);
                    } else {
                        rex_file::put($log, "Using shell_exec for Unix\n", FILE_APPEND);
                        shell_exec($unixCmd);
                    }
                }
                
                rex_file::put($log, "Process started\n\n", FILE_APPEND);
            } catch (Exception $e) {
                rex_file::put($log, "EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
                rex_file::put($log, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
            }

            exit();
        }
        
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');

        if (rex_request::get('progress', 'boolean', false)) {
            $getContent = '';
            
            if (file_exists($log)) {
                $getContent = rex_file::get($log);
            } else {
                exit(json_encode([
                    'progress' => 0, 
                    'log' => 'Logdatei existiert nicht: ' . $log
                ]));
            }
            
            $operation = rex_session('ffmpeg_operation', 'string', 'convert');
            $outputFile = rex_session('ffmpeg_output_video_file', 'string', '');
            
            // Debug: Logfile content
            if (empty($getContent)) {
                exit(json_encode([
                    'progress' => 0, 
                    'log' => 'Prozess wurde gestartet. Bitte warten...'
                ]));
            }
            
            // Check if output file already exists, which indicates success
            if (!empty($outputFile) && file_exists($outputFile)) {
                exit(json_encode([
                    'progress' => 'done',
                    'log' => $getContent . "\n\nOutput file exists - process complete!"
                ]));
            }
            
            // Check for error in ffmpeg output
            if (strpos($getContent, 'Error') !== false || strpos($getContent, 'error:') !== false) {
                exit(json_encode([
                    'progress' => 'error',
                    'log' => $getContent
                ]));
            }
            
            // Different progress tracking for different operations
            if ($operation == 'poster') {
                // Poster generation is typically fast
                // Check for specific success markers in the ffmpeg output
                if (strpos($getContent, 'frame=    1') !== false || 
                    strpos($getContent, 'video:') !== false ||
                    strpos($getContent, 'muxing overhead') !== false) {
                    $results = 'done';
                } else {
                    $results = 50; // Show 50% as default for poster
                }
            } else {
                // Video processing (convert or trim)
                preg_match("/Duration: (.*?), start:/ms", $getContent, $matches);
                if (!empty($rawDuration = $matches[1] ?? '')) {
                    $ar = array_reverse(explode(":", $rawDuration));
                    $duration = floatval($ar[0] ?? 0);
                    if (!empty($ar[1])) $duration += intval($ar[1]) * 60;
                    if (!empty($ar[2])) $duration += intval($ar[2]) * 60 * 60;
                    
                    preg_match_all("/time=(.*?) bitrate/", $getContent, $matches);
                    $rawTime = array_pop($matches);
                    if (is_array($rawTime)) {
                        $rawTime = array_pop($rawTime);
                    }
                    
                    if ($rawTime) {
                        $ar = array_reverse(explode(":", $rawTime));
                        $time = floatval($ar[0] ?? 0);
                        if (!empty($ar[1])) $time += intval($ar[1]) * 60;
                        if (!empty($ar[2])) $time += intval($ar[2]) * 60 * 60;
                        
                        //progress percentage
                        $progress = round(($time / $duration) * 100);
                        
                        if ($progress > 98) {
                            $results = 'done';
                        } else {
                            $results = $progress;
                        }
                    } else {
                        $results = 5; // Minimal progress to show process has started
                    }
                } else {
                    // Can't determine progress, check for completion markers
                    if (strpos($getContent, 'video:') !== false || 
                        strpos($getContent, 'Qavg') !== false || 
                        strpos($getContent, 'kb/s:') !== false ||
                        strpos($getContent, 'muxing overhead') !== false) {
                        $results = 'done';
                    } else if (strpos($getContent, 'ffmpeg version') !== false) {
                        // Prozess hat zumindest begonnen
                        $results = 5;
                    } else {
                        $results = 0;
                    }
                }
            }
            
            if (strpos($getContent, 'Overwrite ?') >= 0) {
                $results = 'error';
            }

            exit(json_encode(['progress' => $results, 'log' => $getContent]));
        }
        
        // Funktion zum manuellen Import in den Medienpool
        function ffmpeg_add_to_mediapool($file, $categoryId = 0) {
            if (!file_exists($file)) {
                return ['success' => false, 'message' => 'Datei existiert nicht: ' . $file];
            }
            
            // Bei Pfadangaben nur den Dateinamen extrahieren
            $filename = basename($file);
            $targetFile = rex_path::media($filename);
            
            // Datei kopieren, falls sie noch nicht im Medienpool ist
            if ($file !== $targetFile) {
                if (!copy($file, $targetFile)) {
                    return ['success' => false, 'message' => 'Kopieren fehlgeschlagen: ' . $file . ' -> ' . $targetFile];
                }
            }
            
            // Prüfen, ob Datei bereits in der Datenbank existiert
            $sql = rex_sql::factory();
            $sql->setQuery('SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?', [$filename]);
            
            if ($sql->getRows() > 0) {
                // Datei existiert bereits, aktualisieren
                $mediaId = $sql->getValue('id');
                
                // Medienpool-Cache aktualisieren
                rex_media_cache::delete($filename);
                
                return ['success' => true, 'message' => 'Datei existiert bereits in der Datenbank (ID: ' . $mediaId . ')', 'id' => $mediaId];
            }
            
            // Datei in die Datenbank eintragen
            try {
                $fileInfo = pathinfo($filename);
                $fileType = rex_file::mimeType($targetFile);
                $fileSize = filesize($targetFile);
                
                // Datei-Metadaten ermitteln (für Videos)
                $fileWidth = 0;
                $fileHeight = 0;
                
                if (strpos($fileType, 'video/') === 0) {
                    // Optional FFProbe nutzen, um Metadaten zu extrahieren
                    // Hier vereinfacht, kann später erweitert werden
                }
                
                $sql = rex_sql::factory();
                $sql->setTable(rex::getTable('media'));
                $sql->setValue('filename', $filename);
                $sql->setValue('originalname', $filename);
                $sql->setValue('filetype', $fileType);
                $sql->setValue('filesize', $fileSize);
                
                if ($fileWidth > 0 && $fileHeight > 0) {
                    $sql->setValue('width', $fileWidth);
                    $sql->setValue('height', $fileHeight);
                }
                
                $sql->setValue('category_id', $categoryId);
                $sql->setValue('updateuser', rex::getUser()->getLogin());
                $sql->setValue('updatedate', date('Y-m-d H:i:s'));
                $sql->setValue('createuser', rex::getUser()->getLogin());
                $sql->setValue('createdate', date('Y-m-d H:i:s'));
                
                $sql->insert();
                $mediaId = $sql->getLastId();
                
                // Media Manager Cache aktualisieren
                rex_media_manager::deleteCacheByFilename($filename);
                
                // Medienpool-Cache aktualisieren
                rex_media_cache::delete($filename);
                
                // Event auslösen (für AddOns, die auf neue Medien reagieren)
                rex_extension::registerPoint(new rex_extension_point('MEDIA_ADDED', '', [
                    'id' => $mediaId,
                    'filename' => $filename,
                    'category_id' => $categoryId
                ]));
                
                return ['success' => true, 'message' => 'Datei erfolgreich in die Datenbank eingetragen (ID: ' . $mediaId . ')', 'id' => $mediaId];
            } catch (Exception $e) {
                return ['success' => false, 'message' => 'Fehler beim Eintragen in die Datenbank: ' . $e->getMessage()];
            }
        }

        if (rex_request::get('done', 'boolean', false)) {
            if (!function_exists('rex_mediapool_deleteMedia')) {
                require_once rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
            }

            $inputFile = rex_session('ffmpeg_input_video_file', 'string', null);
            $outputFile = rex_session('ffmpeg_output_video_file', 'string', null);
            $operation = rex_session('ffmpeg_operation', 'string', 'convert');
            $originalMeta = rex_session('ffmpeg_original_meta', 'array', []);
            
            // Debug-Ausgabe für Fehlersuche
            rex_file::put($log, "=== FINALIZATION ===\n", FILE_APPEND);
            rex_file::put($log, "Input file: $inputFile\n", FILE_APPEND);
            rex_file::put($log, "Output file: $outputFile\n", FILE_APPEND);
            rex_file::put($log, "Operation: $operation\n", FILE_APPEND);

            if (!is_null($inputFile) && !is_null($outputFile)) {
                $importSuccess = false;
                
                // 1. Überprüfen, ob die Ausgabedatei existiert
                if (file_exists($outputFile)) {
                    rex_file::put($log, "Output file exists: " . $outputFile . PHP_EOL, FILE_APPEND);
                    
                    // 2. Datei in den Medienpool importieren
                    $importResult = ffmpeg_add_to_mediapool($outputFile, 0);
                    rex_file::put($log, "Import result: " . print_r($importResult, true) . PHP_EOL, FILE_APPEND);
                    
                    if ($importResult['success']) {
                        $importSuccess = true;
                        
                        // 3. Copy metadata from original to the new file if metadata inheritance is enabled
                        if (!empty($originalMeta) && rex_addon::get('ffmpeg')->getConfig('inherit_meta') == 1) {
                            try {
                                $mediaId = $importResult['id'];
                                rex_file::put($log, "Copying metadata to new file (ID: $mediaId)" . PHP_EOL, FILE_APPEND);
                                
                                $updateData = [];
                                foreach ($originalMeta as $key => $value) {
                                    // Copy relevant metadata (skip technical attributes that would be different)
                                    if (!in_array($key, ['med_width', 'med_height', 'med_size', 'med_duration'])) {
                                        $updateData[$key] = $value;
                                    }
                                }
                                
                                if (!empty($updateData)) {
                                    $sql = rex_sql::factory();
                                    $sql->setTable(rex::getTable('media'));
                                    $sql->setWhere(['id' => $mediaId]);
                                    $sql->setValues($updateData);
                                    $sql->update();
                                    rex_file::put($log, "Metadata copied successfully" . PHP_EOL, FILE_APPEND);
                                }
                            } catch (Exception $e) {
                                rex_file::put($log, "Error copying metadata: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                            }
                        }
                    }
                } else {
                    rex_file::put($log, "ERROR: Output file does not exist: " . $outputFile . PHP_EOL, FILE_APPEND);
                    
                    // Zum Test: Datei noch mal suchen
                    rex_file::put($log, "Searching for files in media directory..." . PHP_EOL, FILE_APPEND);
                    $basename = basename($outputFile);
                    if (file_exists(rex_path::media($basename))) {
                        rex_file::put($log, "Found file in media directory: " . $basename . PHP_EOL, FILE_APPEND);
                        
                        // Versuchen, diese zu importieren
                        $importResult = ffmpeg_add_to_mediapool(rex_path::media($basename), 0);
                        rex_file::put($log, "Import result for found file: " . print_r($importResult, true) . PHP_EOL, FILE_APPEND);
                        
                        if ($importResult['success']) {
                            $importSuccess = true;
                        }
                    } else {
                        rex_file::put($log, "No matching file found in media directory" . PHP_EOL, FILE_APPEND);
                    }
                }
                
                // 4. Original löschen, wenn konfiguriert und Operation=convert
                if ($importSuccess && $operation == 'convert' && rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                    try {
                        rex_mediapool_deleteMedia(pathinfo($inputFile, PATHINFO_BASENAME));
                        rex_unset_session('ffmpeg_input_video_file');
                        rex_file::put($log, sprintf("Source file %s deletion was successful", $inputFile) . PHP_EOL, FILE_APPEND);
                    } catch (Exception $e) {
                        rex_file::put($log, "Error deleting original: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                    }
                }
                
                rex_unset_session('ffmpeg_output_video_file');
                rex_unset_session('ffmpeg_operation');
                rex_unset_session('ffmpeg_original_meta');
                
                rex_file::put($log, "Process completed with " . ($importSuccess ? "success" : "failure") . PHP_EOL, FILE_APPEND);
            } else {
                rex_file::put($log, "ERROR: Missing input or output file information" . PHP_EOL, FILE_APPEND);
                if ($operation == 'convert' && rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                    rex_file::put($log, sprintf("Source file %s deletion was not possible", $inputFile) . PHP_EOL, FILE_APPEND);
                }
                rex_file::put($log, sprintf("Destination file %s rex_mediapool registration was not successful", $outputFile) . PHP_EOL, FILE_APPEND);
                rex_file::put($log, 'Please execute a mediapool sync by hand' . PHP_EOL, FILE_APPEND);
            }

            exit(json_encode(['log' => rex_file::get($log)]));
        }
    }
}
