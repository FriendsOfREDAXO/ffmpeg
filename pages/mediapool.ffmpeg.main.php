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
