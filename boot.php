<?php

if (rex::isBackend() && rex::getUser()) {

    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }
    $log = rex_addon::get('ffmpeg')->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');

    if (rex_be_controller::getCurrentPage() == 'ffmpeg/main') {
        rex_view::addJsFile(rex_url::addonAssets('ffmpeg', 'js/script.js'));
        rex_view::addCssFile(rex_url::addonAssets('ffmpeg', 'css/style.css'));
        
        // jQuery UI für Slider
        rex_view::addJsFile('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js');
        rex_view::addCssFile('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css');
    }

    if (rex_request::get('ffmpeg_video', 'boolean', false)) {

        if (rex_request::get('start', 'boolean', false)) {
            rex_file::put($log, ''); // Create or clear log file

            $input = rex_path::media(rex_request::get('video', 'string'));
            $operation = rex_request::get('operation', 'string', 'convert');
            
            // Debug-Log starten
            rex_file::put($log, "Starting FFmpeg operation: " . $operation . PHP_EOL);
            rex_file::put($log, "Input file: " . $input . PHP_EOL);
            
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
            } else {
                rex_file::put($log, "ERROR: Input file does not exist: " . $input . PHP_EOL);
            }
            
            // Set up appropriate command and output based on operation type
            switch ($operation) {
                case 'convert':
                    $output = rex_path::media('web_' . pathinfo($input, PATHINFO_FILENAME));
                    $command = trim(rex_addon::get('ffmpeg')->getConfig('command')) . " ";
                    
                    preg_match_all('/OUTPUT.(.*) /m', $command, $matches, PREG_SET_ORDER, 0);
                    if (count($matches) > 0) {
                        $file = (trim($matches[0][0]));
                        rex_set_session('ffmpeg_input_video_file', $input);
                        rex_set_session('ffmpeg_output_video_file', $output . '.' . pathinfo($file, PATHINFO_EXTENSION));
                        rex_set_session('ffmpeg_operation', 'convert');
                        rex_set_session('ffmpeg_original_meta', $originalMeta);
                    } else {
                        // Fallback, wenn kein Match gefunden wurde
                        rex_set_session('ffmpeg_input_video_file', $input);
                        rex_set_session('ffmpeg_output_video_file', $output . '.mp4');
                        rex_set_session('ffmpeg_operation', 'convert');
                        rex_set_session('ffmpeg_original_meta', $originalMeta);
                    }
                    
                    $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);
                    break;
                    
                case 'trim':
                    $startTime = rex_request::get('start_time', 'string', '00:00:00');
                    $endTime = rex_request::get('end_time', 'string', '00:00:10');
                    
                    // Create unique filename with time info
                    $timeInfo = str_replace(':', '', $startTime) . '-' . str_replace(':', '', $endTime);
                    $output = rex_path::media('trim_' . pathinfo($input, PATHINFO_FILENAME) . '_' . $timeInfo);
                    
                    // Build ffmpeg command for trim
                    $command = 'ffmpeg -y -i ' . $input . ' -ss ' . $startTime . ' -to ' . $endTime . ' -c:v libx264 -c:a aac ' . $output . '.mp4';
                    
                    rex_set_session('ffmpeg_input_video_file', $input);
                    rex_set_session('ffmpeg_output_video_file', $output . '.mp4');
                    rex_set_session('ffmpeg_operation', 'trim');
                    rex_set_session('ffmpeg_original_meta', $originalMeta);
                    break;
                    
                case 'poster':
                    $timestamp = rex_request::get('timestamp', 'string', '00:00:05');
                    
                    // Create unique filename with timestamp
                    $timeInfo = str_replace(':', '', $timestamp);
                    $output = rex_path::media('poster_' . pathinfo($input, PATHINFO_FILENAME) . '_' . $timeInfo);
                    
                    // Build ffmpeg command for poster extraction
                    $command = 'ffmpeg -y -i ' . $input . ' -ss ' . $timestamp . ' -frames:v 1 ' . $output . '.jpg';
                    
                    rex_set_session('ffmpeg_input_video_file', $input);
                    rex_set_session('ffmpeg_output_video_file', $output . '.jpg');
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
                        rex_set_session('ffmpeg_input_video_file', $input);
                        rex_set_session('ffmpeg_output_video_file', $output . '.' . pathinfo($file, PATHINFO_EXTENSION));
                        rex_set_session('ffmpeg_operation', 'convert');
                        rex_set_session('ffmpeg_original_meta', $originalMeta);
                    } else {
                        // Fallback, wenn kein Match gefunden wurde
                        rex_set_session('ffmpeg_input_video_file', $input);
                        rex_set_session('ffmpeg_output_video_file', $output . '.mp4');
                        rex_set_session('ffmpeg_operation', 'convert');
                        rex_set_session('ffmpeg_original_meta', $originalMeta);
                    }
                    
                    $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);
                    break;
            }
            
            // Debug-Log
            rex_file::put($log, "Output file: " . rex_session('ffmpeg_output_video_file', 'string', '') . PHP_EOL);
            rex_file::put($log, "Command: " . $command . PHP_EOL . PHP_EOL);
            
            // Execute command based on OS
            if (str_starts_with(PHP_OS, 'WIN')) {
                pclose(popen("start /B " . $command . " 1>> $log 2>&1", "r")); // windows
            } else {
                shell_exec($command . " 1>> $log 2>&1 >/dev/null &"); //linux
            }

            exit();
        }
        
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');

        if (rex_request::get('progress', 'boolean', false)) {
            $getContent = rex_file::get($log);
            $operation = rex_session('ffmpeg_operation', 'string', 'convert');
            
            // Debug: Logfile content
            if (empty($getContent)) {
                exit(json_encode([
                    'progress' => 0, 
                    'log' => 'Prozess wurde gestartet. Bitte warten...'
                ]));
            }
            
            // Different progress tracking for different operations
            if ($operation == 'poster') {
                // Poster generation is typically fast, check for completion
                if (strpos($getContent, 'frame=') !== false && strpos($getContent, 'fps=') !== false) {
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
                        $results = 0;
                    }
                } else {
                    // Can't determine progress, check for completion
                    if (strpos($getContent, 'Qavg') !== false || strpos($getContent, 'kb/s:') !== false) {
                        $results = 'done';
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
                // 1. Delete original if configured and operation is convert
                if ($operation == 'convert' && rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                    rex_mediapool_deleteMedia(pathinfo($inputFile, PATHINFO_BASENAME));
                    rex_unset_session('ffmpeg_input_video_file');
                    rex_file::put($log, sprintf("Source file %s deletion was successful", $inputFile) . PHP_EOL, FILE_APPEND);
                }
                
                // 2. Add media to mediapool
                if (file_exists($outputFile)) {
                    rex_file::put($log, "Output file exists: " . $outputFile . PHP_EOL, FILE_APPEND);
                    
                    // 2.1 Manuell versuchen, die Datei in den Medienpool zu importieren
                    $filename = pathinfo($outputFile, PATHINFO_BASENAME);
                    $category_id = 0; // "Keine Kategorie"
                    
                    try {
                        // 2.1.1 Datei in den Medienpool-Ordner kopieren (falls sie noch nicht dort ist)
                        if (!file_exists(rex_path::media($filename))) {
                            rex_file::put($log, "Copying file to media directory: $filename" . PHP_EOL, FILE_APPEND);
                            copy($outputFile, rex_path::media($filename));
                        }
                        
                        // 2.1.2 Eintrag in der Datenbank erstellen (falls sie noch nicht existiert)
                        $sql = rex_sql::factory();
                        $media = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filename = :filename', [':filename' => $filename]);
                        
                        if (empty($media)) {
                            rex_file::put($log, "Creating media entry in database for: $filename" . PHP_EOL, FILE_APPEND);
                            
                            $fileInfo = pathinfo($filename);
                            $fileType = rex_file::mimeType($outputFile);
                            $fileSize = filesize($outputFile);
                            
                            $sql->setTable(rex::getTable('media'));
                            $sql->setValue('filename', $filename);
                            $sql->setValue('originalname', $filename);
                            $sql->setValue('filetype', $fileType);
                            $sql->setValue('filesize', $fileSize);
                            $sql->setValue('category_id', $category_id);
                            $sql->setValue('updateuser', rex::getUser()->getLogin());
                            $sql->setValue('updatedate', date('Y-m-d H:i:s'));
                            $sql->setValue('createuser', rex::getUser()->getLogin());
                            $sql->setValue('createdate', date('Y-m-d H:i:s'));
                            
                            $sql->insert();
                            rex_file::put($log, "Media entry created successfully" . PHP_EOL, FILE_APPEND);
                            
                            // 3. Copy metadata from original to the new file
                            if (!empty($originalMeta) && rex_addon::get('ffmpeg')->getConfig('inherit_meta') == 1) {
                                $mediaId = $sql->getLastId();
                                
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
                            }
                            
                            // 4. Generiere Cachefile für Media Manager
                            rex_media_manager::deleteCacheByFilename($filename);
                            
                            // 5. Update Medienpool-Cache
                            rex_media_cache::delete($filename);
                        } else {
                            rex_file::put($log, "Media already exists in database: $filename" . PHP_EOL, FILE_APPEND);
                        }
                        
                        rex_file::put($log, "File $filename was successfully added to rex_mediapool" . PHP_EOL, FILE_APPEND);
                    } catch (Exception $e) {
                        rex_file::put($log, "Error adding file to mediapool: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                    }
                } else {
                    rex_file::put($log, "ERROR: Output file does not exist: " . $outputFile . PHP_EOL, FILE_APPEND);
                }
                
                rex_unset_session('ffmpeg_output_video_file');
                rex_unset_session('ffmpeg_output_video_file');
                rex_unset_session('ffmpeg_operation');
                rex_unset_session('ffmpeg_original_meta');
            } else {
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
