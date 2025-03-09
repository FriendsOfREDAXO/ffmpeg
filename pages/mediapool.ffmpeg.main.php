<?php

$content = '';
$buttons = '';
$optimizedContent = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Prüfen, ob eine aktive Konvertierung läuft
$conversionActive = false;
$conversionInfo = [];

// Konversionsstatus aus Session und Datei ermitteln
$conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
if (!empty($conversionId)) {
    $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
    if (file_exists($log)) {
        $logContent = rex_file::get($log);
        
        // Wenn das Log existiert, aber keine "Fertig"-Marke enthält, läuft die Konvertierung noch
        if (strpos($logContent, 'was successfully added to rex_mediapool') === false &&
            strpos($logContent, 'registration was not successful') === false) {
            
            // Prüfen, ob der Prozess wirklich noch läuft (einfacher Check: wurde in den letzten 30 Sekunden aktualisiert)
            $lastModified = filemtime($log);
            $currentTime = time();
            
            if ($currentTime - $lastModified < 30) {
                $conversionActive = true;
                
                // Dateinamen aus Log extrahieren
                preg_match('/Konvertierung für "(.*?)" gestartet/', $logContent, $matches);
                $videoName = $matches[1] ?? '';
                
                $conversionInfo = [
                    'video' => $videoName,
                    'log' => $logContent
                ];
            } else {
                // Log existiert, wurde aber längere Zeit nicht aktualisiert
                // Konvertierung scheint abgebrochen zu sein, Session-Variable löschen
                rex_unset_session('ffmpeg_conversion_id');
            }
        } else {
            // Konvertierung ist abgeschlossen, Session-Variable löschen
            rex_unset_session('ffmpeg_conversion_id');
        }
    } else {
        // Log existiert nicht, Session-Variable löschen
        rex_unset_session('ffmpeg_conversion_id');
    }
}

// Videos aus dem Medienpool holen
$sql = rex_sql::factory();

// Bereits optimierte Videos sammeln (um zu prüfen, welche Originale bereits konvertiert wurden)
$optimizedVideos = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filetype LIKE \'video/%\' AND filename LIKE \'web_%\' ORDER BY updatedate DESC');

// Liste der bereits konvertierten Videos (ohne 'web_' Präfix)
$alreadyConverted = [];
foreach ($optimizedVideos as $video) {
    $originalName = substr($video['filename'], 4); // "web_" entfernen
    $alreadyConverted[] = $originalName;
}

// Unkonvertierte Videos
$result = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filetype LIKE \'video/%\' AND filename NOT LIKE \'web_%\' ORDER BY updatedate DESC');

// Unkonvertierte Videos auflisten
if ($result) {
    $n = [];
    $n['field'] = [];
    foreach ($result as $key => $item) {
        $isProcessing = $conversionActive && isset($conversionInfo['video']) && $conversionInfo['video'] === $item['filename'];
        $isAlreadyConverted = in_array($item['filename'], $alreadyConverted);
        
        $n['field'][] = '
        <div class="video-item' . ($isProcessing ? ' processing' : '') . ($isAlreadyConverted ? ' already-converted' : '') . '">
            <label>
                <input class="mycheckbox" id="v' . $key . '" type="radio" name="video" value="' . $item['filename'] . '" data-video="' . $item['filename'] . '"' . 
                (($conversionActive || $isAlreadyConverted) ? ' disabled' : '') . '> 
                <strong>' . $item['filename'] . '</strong>
                ' . ($isProcessing ? '<span class="badge badge-info conversion-badge">Wird konvertiert...</span>' : '') . '
                ' . ($isAlreadyConverted ? '<span class="badge badge-success conversion-badge">' . $this->i18n('ffmpeg_already_converted') . '</span>' : '') . '
            </label>
            <div class="video-meta">
                <span class="video-size">' . rex_formatter::bytes($item['filesize']) . '</span>
                <span class="video-date">' . rex_formatter::strftime(strtotime($item['updatedate']), 'datetime') . '</span>
            </div>
        </div>';
    }
    
    $content .= '<h3>' . $this->i18n('ffmpeg_convert_info') . '</h3>';
    
    if ($conversionActive) {
        $content .= rex_view::warning($this->i18n('ffmpeg_conversion_in_progress'));
    }
    
    if (count($n['field']) > 0) {
        $content .= '<fieldset><legend>' . $this->i18n('legend_video') . '</legend>';
        
        $formElements = [];
        $n['label'] = '<label></label>';
        $n['field'] = implode('', $n['field']);
        $formElements[] = $n;
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $content .= $fragment->parse('core/form/container.php');
        
        $content .= '</fieldset>';
        
        // Action Buttons
        $formElements = [];
        
        // Convert Button
        $n = [];
        $n['field'] = '<button class="btn btn-primary rex-form-aligned btn-start" id="start" type="button" name="save" value="' . $this->i18n('execute') . '"' . ($conversionActive ? ' disabled' : '') . '>' . $this->i18n('execute') . '</button>';
        $formElements[] = $n;
        
        // Status Button
        $n = [];
        $n['field'] = '<button class="btn btn-default rex-form-aligned" id="check_status" type="button" name="check" value="' . $this->i18n('ffmpeg_check_status') . '">' . $this->i18n('ffmpeg_check_status') . '</button>';
        $formElements[] = $n;
        
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $buttons = $fragment->parse('core/form/submit.php');
        $buttons = '<fieldset class="rex-form-action">' . $buttons . '</fieldset>';

        // Ausgabe Formular
        $fragment = new rex_fragment();
        $fragment->setVar('class', 'info');
        $fragment->setVar('title', $this->i18n('ffmpeg_video_convert'));
        $fragment->setVar('body', $content, false);
        $fragment->setVar('buttons', $buttons, false);
        $output = $fragment->parse('core/page/section.php');

        $output = '
        <form action="' . rex_url::currentBackendPage() . '" method="post">
            <input type="hidden" name="formsubmit" value="1" />
            ' . $csrfToken->getHiddenField() . '
            ' . $output . '
            
            <div class="rex-page-section progress-section" style="display:' . ($conversionActive ? 'block' : 'none') . ';">
                <div class="panel panel-info">
                    <div class="conversion-status">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped active" id="prog" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <div class="conversion-details">
                            <div class="spinner">
                                <div class="bounce1"></div>
                                <div class="bounce2"></div>
                                <div class="bounce3"></div>
                            </div>
                            <span id="progress-text">0%</span>
                        </div>
                    </div>
                    <div id="log" class="log" style="padding:15px;margin:5px 0;"><pre style="height:200px;overflow-y: auto">' . 
                    ($conversionActive && isset($conversionInfo['log']) ? $conversionInfo['log'] : '') . 
                    '</pre></div>
                </div>
            </div>
        </form>';

        echo $output;
    } else {
        echo rex_view::info($this->i18n('ffmpeg_no_videos_found'));
    }
} else {
    echo rex_view::info($this->i18n('ffmpeg_no_videos_found'));
}

// Optimierte Videos auflisten, wenn vorhanden
if ($optimizedVideos && count($optimizedVideos) > 0) {
    $n = [];
    $n['field'] = [];
    
    foreach ($optimizedVideos as $key => $item) {
        // Ermittle das Originalvideo (ohne web_ Präfix)
        $originalName = substr($item['filename'], 4);
        
        // Prüfe, ob das Originalvideo noch existiert
        $originalExists = false;
        $originalSize = 0;
        $compressionRate = 0;
        
        $originalVideo = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filename = :name', [':name' => $originalName]);
        if (count($originalVideo) > 0) {
            $originalExists = true;
            $originalSize = $originalVideo[0]['filesize'];
            
            // Berechne Kompressionsrate
            if ($originalSize > 0) {
                $compressionRate = round(100 - (($item['filesize'] / $originalSize) * 100));
            }
        }
        
        $n['field'][] = '
        <div class="video-item optimized">
            <div class="video-title"><strong>' . $item['filename'] . '</strong></div>
            <div class="video-meta">
                <span class="video-size">' . rex_formatter::bytes($item['filesize']) . '</span>
                <span class="video-date">' . rex_formatter::strftime(strtotime($item['updatedate']), 'datetime') . '</span>
                ' . ($originalExists && $compressionRate > 0 ? '<span class="compression-rate badge">' . $compressionRate . '% ' . $this->i18n('ffmpeg_smaller') . '</span>' : '') . '
            </div>
            <div class="video-actions">
                <a href="' . rex_url::backendPage('mediapool/media', ['file_id' => $item['id']]) . '" class="btn btn-xs btn-default">' . $this->i18n('ffmpeg_view_in_mediapool') . '</a>
            </div>
        </div>';
    }
    
    $optimizedContent .= '<fieldset><legend>' . $this->i18n('ffmpeg_optimized_videos') . ' (' . count($optimizedVideos) . ')</legend>';
    
    $formElements = [];
    $n['label'] = '<label></label>';
    $n['field'] = implode('', $n['field']);
    $formElements[] = $n;
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $optimizedContent .= $fragment->parse('core/form/container.php');
    
    $optimizedContent .= '</fieldset>';
    
    // Ausgabe Formular
    $fragment = new rex_fragment();
    $fragment->setVar('class', 'success');
    $fragment->setVar('title', $this->i18n('ffmpeg_optimized_videos_list'));
    $fragment->setVar('body', $optimizedContent, false);
    $output = $fragment->parse('core/page/section.php');
    
    echo $output;
}
?>

<style>
/* REDAXO Dark Mode kompatible Styles */
.video-item {
    padding: 10px;
    border-bottom: 1px solid rgba(var(--rex-color-border-text-rgb, 238, 238, 238), .2);
    display: flex;
    flex-direction: column;
    margin-bottom: 10px;
    transition: background-color 0.2s ease;
}

.video-meta {
    display: flex;
    color: var(--rex-color-text-muted, #888);
    font-size: 0.85em;
    margin-top: 5px;
    margin-left: 20px;
}

.video-size, .video-date {
    margin-right: 15px;
}

.optimized {
    background-color: rgba(var(--rex-color-background-rgb, 248, 248, 248), .1);
    border-radius: 5px;
    padding: 12px;
}

.processing {
    background-color: rgba(var(--rex-color-background-rgb, 240, 247, 255), .15);
    border-radius: 5px;
}

.already-converted {
    opacity: 0.7;
    background-color: rgba(var(--rex-color-success-rgb, 92, 184, 92), .1);
    border-radius: 5px;
}

.compression-rate {
    color: #fff;
    background-color: #5cb85c;
    margin-left: 10px;
}

.conversion-badge {
    margin-left: 10px;
    display: inline-block;
}

.video-actions {
    margin-top: 10px;
    margin-left: 20px;
}

/* Coole Statusanzeige */
.conversion-status {
    padding: 15px;
    position: relative;
}

.progress {
    height: 20px;
    margin-bottom: 0;
    background-color: rgba(var(--rex-color-border-text-rgb, 233, 236, 239), .2);
}

.progress-bar {
    background-color: var(--rex-color-brand, #3498db);
}

.conversion-details {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 10px;
}

#progress-text {
    font-size: 16px;
    font-weight: bold;
    margin-left: 10px;
    color: var(--rex-color-text, inherit);
}

/* Spinner Animation */
.spinner {
    margin-right: 10px;
    width: 70px;
    text-align: center;
}

.spinner > div {
    width: 12px;
    height: 12px;
    background-color: var(--rex-color-brand, #3498db);
    border-radius: 100%;
    display: inline-block;
    animation: sk-bouncedelay 1.4s infinite ease-in-out both;
}

.spinner .bounce1 {
    animation-delay: -0.32s;
}

.spinner .bounce2 {
    animation-delay: -0.16s;
}

@keyframes sk-bouncedelay {
    0%, 80%, 100% { 
        transform: scale(0);
    } 40% { 
        transform: scale(1.0);
    }
}

/* Hervorhebung aktiver Videos */
.active-video {
    background-color: rgba(var(--rex-color-brand-rgb, 52, 152, 219), .1);
    border-left: 4px solid var(--rex-color-brand, #3498db);
    padding-left: 8px;
}

/* Log-Bereich */
#log pre {
    background-color: var(--rex-color-panel-bg, #fff);
    color: var(--rex-color-text, #212529);
    border: 1px solid rgba(var(--rex-color-border-text-rgb, 221, 221, 221), .2);
    height: 200px;
    overflow-y: auto;
    padding: 10px;
    font-family: monospace;
    font-size: 0.9em;
    white-space: pre-wrap;
}

/* Speziell für den REDAXO Dark Mode */
.rex-theme-dark .video-item {
    border-bottom-color: rgba(255, 255, 255, 0.1);
}

.rex-theme-dark .optimized {
    background-color: rgba(255, 255, 255, 0.05);
}

.rex-theme-dark .processing {
    background-color: rgba(53, 152, 219, 0.15);
}

.rex-theme-dark .already-converted {
    background-color: rgba(92, 184, 92, 0.1);
}

.rex-theme-dark .active-video {
    background-color: rgba(53, 152, 219, 0.15);
}

.rex-theme-dark #log pre {
    background-color: rgba(0, 0, 0, 0.2);
    border-color: rgba(255, 255, 255, 0.1);
}

.rex-theme-dark .progress {
    background-color: rgba(255, 255, 255, 0.1);
}

/* Bessere Kontrastfarben für Text */
.text-success {
    color: var(--rex-color-success, #2ecc71) !important;
}

/* Hover-Effekte */
.video-item:hover {
    background-color: rgba(var(--rex-color-brand-rgb, 52, 152, 219), .05);
}

.rex-theme-dark .video-item:hover {
    background-color: rgba(255, 255, 255, 0.05);
}

/* Verbesserte Buttons im Dark Mode */
.rex-theme-dark .btn-default {
    background-color: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.2);
}

.rex-theme-dark .btn-default:hover {
    background-color: rgba(255, 255, 255, 0.15);
    border-color: rgba(255, 255, 255, 0.25);
}

/* Smooth Transitions */
.video-item, .progress-bar, .spinner > div, .btn {
    transition: all 0.3s ease;
}
</style>
