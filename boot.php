<?php

if (rex::isBackend() && rex::getUser()) {

    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }
    $log = $this->getDataPath('log' . rex_session('ffmpeg_uid', 'string', '') . '.txt');

    // Eigene Hauptseite statt Medienpool-Integration
    if (rex_be_controller::getCurrentPagePart(1) == 'ffmpeg') {
        rex_view::addJsFile($this->getAssetsUrl('js/script.js'));
    }

    // Cronjob für die Überprüfung alter nicht-importierter Videos (wird täglich ausgeführt)
    if (rex_request::get('ffmpeg_cleanup', 'boolean', false)) {
        $outputDir = rex_path::media();
        $files = glob($outputDir . 'ffmpeg_temp_*');
        $oneDayAgo = time() - (24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $oneDayAgo) {
                unlink($file);
                rex_logger::factory()->info('FFMPEG: Removed old temporary video: ' . basename($file));
            }
        }
        exit('Cleanup complete');
    }

    if (rex_request::get('ffmpeg_video', 'boolean', false)) {

        if (rex_request::get('start', 'boolean', false)) {
            rex_file::put($log, ''); // Create or clear log file

            $input = rex_path::media(rex_request::get('video', 'string'));
            // Temporäres Ausgabeverzeichnis mit Timestamp für spätere Verfolgung
            $timestamp = time();
            $outputBasename = 'ffmpeg_temp_' . $timestamp . '_' . pathinfo($input, PATHINFO_FILENAME);
            $output = rex_path::media($outputBasename);
            $command = trim($this->getConfig('command')) . " ";
            
            // E-Mail-Empfänger speichern (falls angegeben)
            $emailTo = rex_request::get('email', 'string', '');
            if (!empty($emailTo)) {
                rex_set_session('ffmpeg_notification_email', $emailTo);
            }

            preg_match_all('/OUTPUT.(.*) /m', $command, $matches, PREG_SET_ORDER, 0);
            if (count($matches) > 0) {
                $file = (trim($matches[0][0]));
                rex_set_session('ffmpeg_input_video_file', $input);
                rex_set_session('ffmpeg_output_video_file', $output . '.' . pathinfo($file, PATHINFO_EXTENSION));
                
                // Speichern von Informationen zu diesem Konvertierungsvorgang in einer Datenbanktabelle
                $sql = rex_sql::factory();
                $sql->setTable(rex::getTable('ffmpeg_queue'));
                $sql->setValue('input_file', basename($input));
                $sql->setValue('output_file', $outputBasename . '.' . pathinfo($file, PATHINFO_EXTENSION));
                $sql->setValue('status', 'processing');
                $sql->setValue('created', date('Y-m-d H:i:s'));
                $sql->setValue('email', $emailTo);
                $sql->setValue('user_id', rex::getUser()->getId());
                $sql->insert();
                
                // Job-ID für spätere Referenz speichern
                $jobId = $sql->getLastId();
                rex_set_session('ffmpeg_current_job_id', $jobId);
            }

            $command = str_ireplace(['INPUT', 'OUTPUT'], [$input, $output], $command);

            // Starte im Hintergrund ohne zu warten
            if (str_starts_with(PHP_OS, 'WIN')) {
                pclose(popen("start /B " . $command . " 1> $log 2>&1 && php -f " . rex_path::addon('ffmpeg', 'finish_conversion.php') . " jobId=$jobId", "r")); // windows
            } else {
                shell_exec($command . " 1> $log 2>&1 && php -f " . rex_path::addon('ffmpeg', 'finish_conversion.php') . " jobId=$jobId >/dev/null 2>&1 &"); //linux
            }

            // Rückmeldung, dass der Job gestartet wurde
            exit(json_encode(['status' => 'started', 'job_id' => $jobId]));
        }
        
        // Status eines Jobs abfragen
        if (rex_request::get('check_status', 'boolean', false)) {
            $jobId = rex_request::get('job_id', 'int', 0);
            
            if ($jobId > 0) {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT * FROM ' . rex::getTable('ffmpeg_queue') . ' WHERE id = :id', ['id' => $jobId]);
                
                if ($sql->getRows() > 0) {
                    exit(json_encode([
                        'status' => $sql->getValue('status'),
                        'output_file' => $sql->getValue('output_file'),
                        'created' => $sql->getValue('created')
                    ]));
                }
            }
            
            exit(json_encode(['status' => 'not_found']));
        }
        
        // Video importieren
        if (rex_request::get('import', 'boolean', false)) {
            $fileId = rex_request::get('file_id', 'int', 0);
            
            if ($fileId > 0) {
                $sql = rex_sql::factory();
                $sql->setQuery('SELECT * FROM ' . rex::getTable('ffmpeg_queue') . ' WHERE id = :id', ['id' => $fileId]);
                
                if ($sql->getRows() > 0) {
                    $outputFile = $sql->getValue('output_file');
                    
                    if (!function_exists('rex_mediapool_syncFile')) {
                        require rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
                    }
                    
                    // Importieren und umbenennen (web_ Präfix)
                    $newFilename = 'web_' . substr($outputFile, strpos($outputFile, '_', strpos($outputFile, '_') + 1) + 1);
                    rename(rex_path::media($outputFile), rex_path::media($newFilename));
                    rex_mediapool_syncFile($newFilename, 0, '');
                    
                    // Status aktualisieren
                    $sql = rex_sql::factory();
                    $sql->setTable(rex::getTable('ffmpeg_queue'));
                    $sql->setWhere(['id' => $fileId]);
                    $sql->setValue('status', 'imported');
                    $sql->setValue('updated', date('Y-m-d H:i:s'));
                    $sql->update();
                    
                    exit(json_encode(['status' => 'success', 'file' => $newFilename]));
                }
            }
            
            exit(json_encode(['status' => 'error', 'message' => 'File not found']));
        }
    }
}
