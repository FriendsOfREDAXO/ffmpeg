<?php

if (rex::isBackend() && rex::getUser()) {
    // Add JavaScript to ffmpeg page
    if (rex_be_controller::getCurrentPagePart(2) == 'ffmpeg') {
        rex_view::addJsFile(rex_addon::get('ffmpeg')->getAssetsUrl('js/script.js'));
        rex_view::addCssFile(rex_addon::get('ffmpeg')->getAssetsUrl('css/style.css'));
    }
    
    // Create session variables if needed
    if (is_null(rex_session('ffmpeg_uid', 'string', null))) {
        rex_set_session('ffmpeg_uid', uniqid());
    }
    
    // Sicherstellen, dass die Datenverzeichnisse existieren
    $dataPath = rex_addon::get('ffmpeg')->getDataPath();
    if (!file_exists($dataPath)) {
        mkdir($dataPath, 0777, true);
    }
    
    // Veraltete Status- und Log-Dateien aufräumen (älter als 7 Tage)
    $cleanupTime = time() - (7 * 24 * 60 * 60); // 7 Tage
    
    // Status-Dateien aufräumen
    $statusFiles = glob($dataPath . 'status_*.json');
    foreach ($statusFiles as $file) {
        if (filemtime($file) < $cleanupTime) {
            @unlink($file);
        }
    }
    
    // Log-Dateien aufräumen
    $logFiles = glob($dataPath . 'log*.txt');
    foreach ($logFiles as $file) {
        if (filemtime($file) < $cleanupTime) {
            @unlink($file);
        }
    }
    
    // Prüfe auf abgeschlossene Konvertierungen und importiere sie automatisch
    $importedVideos = []; // Sammlung erfolgreich importierter Videos
    $failedVideos = [];   // Sammlung fehlgeschlagener Imports
    
    $statusFiles = glob($dataPath . 'status_*.json');
    foreach ($statusFiles as $file) {
        // Nur Status-Dateien prüfen, die nicht zu alt sind (max. 24 Stunden)
        if (filemtime($file) > time() - 86400) {
            $statusData = json_decode(file_get_contents($file), true);
            
            // Prüfe, ob Konvertierung abgeschlossen, aber der Import noch aussteht
            if ($statusData && isset($statusData['status']) && $statusData['status'] === 'converting') {
                $conversionId = $statusData['conversion_id'] ?? '';
                $videoName = $statusData['video'] ?? '';
                
                if (!empty($conversionId) && !empty($videoName)) {
                    $logFile = $dataPath . 'log' . $conversionId . '.txt';
                    if (file_exists($logFile)) {
                        $logContent = file_get_contents($logFile);
                        
                        // Prüfe, ob Konvertierung abgeschlossen ist
                        $conversionComplete = (
                            strpos($logContent, 'video:') !== false || 
                            strpos($logContent, 'Qavg') !== false || 
                            strpos($logContent, 'kb/s:') !== false
                        );
                        
                        // Prüfe, ob der Import noch nicht erfolgt ist
                        $importNotDone = (
                            strpos($logContent, 'was successfully added to rex_mediapool') === false && 
                            strpos($logContent, 'Konvertierung abgeschlossen') === false
                        );
                        
                        // Wenn Konvertierung fertig, aber Import noch nicht, automatisch importieren
                        if ($conversionComplete && $importNotDone) {
                            // Import-Funktion aufrufen
                            if (!class_exists('rex_api_ffmpeg_converter')) {
                                require_once(rex_path::addon('ffmpeg', 'lib/ffmpeg_api.php'));
                            }
                            
                            // Falls notwendig, mediapool-Funktionen laden
                            if (!function_exists('rex_mediapool_syncFile')) {
                                require_once(rex_path::addon('mediapool', 'functions/function_rex_mediapool.php'));
                            }
                            
                            // Setze Session-Variablen, damit die API-Methode funktioniert
                            rex_set_session('ffmpeg_conversion_id', $conversionId);
                            rex_set_session('ffmpeg_conversion_status', 'importing');
                            
                            // API-Klasse instanziieren und handleDone aufrufen
                            $api = new rex_api_ffmpeg_converter();
                            
                            // Methode aufrufen über Reflection, da handleDone protected ist
                            $reflection = new ReflectionObject($api);
                            $method = $reflection->getMethod('handleDone');
                            $method->setAccessible(true);
                            $result = $method->invoke($api);
                            
                            // Status aktualisieren (Datei)
                            $statusData['status'] = 'done';
                            $statusData['completed_at'] = time();
                            file_put_contents($file, json_encode($statusData));
                            
                            // Video zur entsprechenden Liste hinzufügen
                            if (isset($result['status']) && $result['status'] === 'success') {
                                $importedVideos[] = $videoName;
                            } else {
                                $failedVideos[] = $videoName;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Falls wir Videos importiert haben, Benachrichtigungen anzeigen (bei JEDEM Backend-Aufruf)
    if (!empty($importedVideos) || !empty($failedVideos)) {
        // Speichere die Listen in der Session, falls wir sie nicht sofort anzeigen können
        if (!empty($importedVideos)) {
            $existingImported = rex_session('ffmpeg_auto_imported', 'array', []);
            rex_set_session('ffmpeg_auto_imported', array_merge($existingImported, $importedVideos));
        }
        
        if (!empty($failedVideos)) {
            $existingFailed = rex_session('ffmpeg_auto_import_failed', 'array', []);
            rex_set_session('ffmpeg_auto_import_failed', array_merge($existingFailed, $failedVideos));
        }
        
        // Bei der Ausgabe die Benachrichtigungen einfügen
        rex_extension::register('OUTPUT_FILTER', function(rex_extension_point $ep) {
            $subject = $ep->getSubject();
            
            $messages = '';
            
            // Erfolgsmeldungen
            $importedVideos = rex_session('ffmpeg_auto_imported', 'array', []);
            if (!empty($importedVideos)) {
                $messages .= rex_view::success(
                    count($importedVideos) > 1 
                    ? 'Folgende Videos wurden automatisch importiert: ' . implode(', ', $importedVideos)
                    : 'Video "' . $importedVideos[0] . '" wurde automatisch importiert.'
                );
                rex_unset_session('ffmpeg_auto_imported');
            }
            
            // Fehlermeldungen
            $failedVideos = rex_session('ffmpeg_auto_import_failed', 'array', []);
            if (!empty($failedVideos)) {
                $messages .= rex_view::error(
                    count($failedVideos) > 1 
                    ? 'Bei folgenden Videos ist der Import fehlgeschlagen: ' . implode(', ', $failedVideos)
                    : 'Beim automatischen Import von "' . $failedVideos[0] . '" ist ein Fehler aufgetreten.'
                );
                rex_unset_session('ffmpeg_auto_import_failed');
            }
            
            // Meldungen einfügen, wenn vorhanden
            if (!empty($messages)) {
                // Typische Position für Backend-Meldungen finden
                $pos = strpos($subject, '<section class="rex-page-main">');
                if ($pos !== false) {
                    $subject = substr_replace($subject, '<section class="rex-page-main">' . $messages, $pos, strlen('<section class="rex-page-main">'));
                }
            }
            
            $ep->setSubject($subject);
        });
    }
}
