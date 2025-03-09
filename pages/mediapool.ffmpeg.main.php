<?php

$content = '';
$buttons = '';
$optimizedContent = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Prüfen, ob API-Klasse verfügbar ist
if (class_exists('rex_api_ffmpeg_converter')) {
    $ffmpegApi = new rex_api_ffmpeg_converter();
    $statusData = $ffmpegApi->getStatus();
    $conversionActive = $statusData['active'];
    $conversionInfo = $statusData['info'];
} else {
    $conversionActive = false;
    $conversionInfo = [];
}

// Videos aus dem Medienpool holen
$sql = rex_sql::factory();

// Unkonvertierte Videos
$result = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filetype LIKE \'video/%\' AND filename NOT LIKE \'web_%\' ORDER BY updatedate DESC');

// Bereits optimierte Videos
$optimizedVideos = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filetype LIKE \'video/%\' AND filename LIKE \'web_%\' ORDER BY updatedate DESC');

// Unkonvertierte Videos auflisten
if ($result) {
    $n = [];
    $n['field'] = [];
    foreach ($result as $key => $item) {
        $isProcessing = $conversionActive && $conversionInfo && $conversionInfo['video'] === $item['filename'];
        
        $n['field'][] = '
        <div class="video-item' . ($isProcessing ? ' processing' : '') . '">
            <label>
                <input class="mycheckbox" id="v' . $key . '" type="radio" name="video" value="' . $item['filename'] . '" data-video="' . $item['filename'] . '"' . ($conversionActive ? ' disabled' : '') . '> 
                <strong>' . $item['filename'] . '</strong>
                ' . ($isProcessing ? '<span class="badge badge-info">Wird konvertiert...</span>' : '') . '
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
/* Styles für die Videoübersicht */
.video-item {
    padding: 10px;
    border-bottom: 1px solid #eee;
    display: flex;
    flex-direction: column;
    margin-bottom: 10px;
}

.video-meta {
    display: flex;
    color: #888;
    font-size: 0.85em;
    margin-top: 5px;
    margin-left: 20px;
}

.video-size, .video-date {
    margin-right: 15px;
}

.optimized {
    background-color: #f8f8f8;
    border-radius: 5px;
    padding: 12px;
}

.processing {
    background-color: #f0f7ff;
    border-radius: 5px;
}

.compression-rate {
    color: #fff;
    background-color: #5cb85c;
    margin-left: 10px;
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
    background-color: #3498db;
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
    background-color: #f5f5f5;
    border-left: 4px solid #3498db;
    padding-left: 8px;
}
</style>
