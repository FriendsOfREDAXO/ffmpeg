<?php

// Video-Info Klasse laden
require_once __DIR__ . '/lib/rex_ffmpeg_video_info.php';

// Media Manager Effekte für Videos registrieren
if (rex_addon::get('media_manager')->isAvailable()) {
    rex_media_manager::addEffect('rex_effect_video_to_webp');
    rex_media_manager::addEffect('rex_effect_video_to_preview');
}

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
    if ($statusFiles) {
        foreach ($statusFiles as $file) {
            if (filemtime($file) < $cleanupTime) {
                @unlink($file);
            }
        }
    }
    
    // Log-Dateien aufräumen
    $logFiles = glob($dataPath . 'log*.txt');
    if ($logFiles) {
        foreach ($logFiles as $file) {
            if (filemtime($file) < $cleanupTime) {
                @unlink($file);
            }
        }
    }
    
    // Prüfen auf aktive Konvertierungen ohne Session
    // Wenn ein Video gerade konvertiert wird, aber die Session abgelaufen ist,
    // kann durch diese Prüfung trotzdem der Status abgefragt werden
    if (rex_be_controller::getCurrentPagePart(2) == 'ffmpeg' && !rex_session('ffmpeg_conversion_id')) {
        $statusFiles = glob($dataPath . 'status_*.json');
        if ($statusFiles) {
            foreach ($statusFiles as $file) {
                // Nur Dateien prüfen, die in den letzten 30 Minuten geändert wurden
                if (filemtime($file) > time() - 1800) {
                    $statusData = json_decode(file_get_contents($file), true);
                    if ($statusData && isset($statusData['status']) && 
                        $statusData['status'] !== 'done' && $statusData['status'] !== 'error') {
                        
                        // Aktive Konvertierung gefunden, Session-Variablen wiederherstellen
                        if (isset($statusData['conversion_id'])) {
                            rex_set_session('ffmpeg_conversion_id', $statusData['conversion_id']);
                            rex_set_session('ffmpeg_conversion_status', $statusData['status']);
                            break;
                        }
                    }
                }
            }
        }
    }
}
