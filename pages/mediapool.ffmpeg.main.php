<?php

use FriendsOfRedaxo\FFmpeg\Api\Converter;

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Prüfen, ob eine aktive Konvertierung läuft
$conversionActive = false;
$conversionInfo = [];

// Konversionsstatus aus API-Klasse ermitteln
$conversionData = Converter::getConversionInfo();
$conversionActive = $conversionData['active'];
$conversionInfo = $conversionData['info'];

// Alle Videos einlesen (sowohl normale als auch konvertierte)
$sql = rex_sql::factory();
$allMediaFiles = $sql->getArray('SELECT id, filename, filesize, updatedate, title FROM ' . rex::getTable('media') . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Videos mit Konvertierungsstatusinformationen anreichern
$allVideos = [];
$optimizedVideosMapping = [];

// Zuerst alle optimierten Videos sammeln
foreach ($allMediaFiles as $media) {
    if (strpos($media['filename'], 'web_') === 0) {
        $originalName = substr($media['filename'], 4);
        $optimizedVideosMapping[$originalName] = $media;
    }
}

// Dann alle Videos verarbeiten
foreach ($allMediaFiles as $media) {
    $isOptimized = strpos($media['filename'], 'web_') === 0;
    
    // Nur Originalvideos in die Liste aufnehmen (keine "web_"-Versionen)
    if (!$isOptimized) {
        $originalName = $media['filename'];
        $isProcessing = $conversionActive && isset($conversionInfo['video']) && $conversionInfo['video'] === $originalName;
        $isAlreadyConverted = isset($optimizedVideosMapping[$originalName]);
        $optimizedData = $isAlreadyConverted ? $optimizedVideosMapping[$originalName] : null;
        
        // Kompressionsrate berechnen, wenn konvertierte Version existiert
        $compressionRate = 0;
        if ($isAlreadyConverted && $media['filesize'] > 0) {
            $compressionRate = round(100 - (($optimizedData['filesize'] / $media['filesize']) * 100));
        }
        
        $allVideos[] = [
            'id' => $media['id'],
            'filename' => $media['filename'],
            'title' => $media['title'],
            'filesize' => $media['filesize'],
            'updatedate' => $media['updatedate'],
            'isProcessing' => $isProcessing,
            'isAlreadyConverted' => $isAlreadyConverted,
            'optimizedData' => $optimizedData,
            'compressionRate' => $compressionRate
        ];
    }
}

// Falls keine Videos gefunden wurden, Infomeldung anzeigen
if (empty($allVideos)) {
    echo rex_view::info($this->i18n('ffmpeg_no_videos_found'));
} else {
    // Videos in der konsolidierten Liste anzeigen
    $content .= '<h3>' . $this->i18n('ffmpeg_convert_info') . '</h3>';
    
    if ($conversionActive) {
        $content .= rex_view::warning($this->i18n('ffmpeg_conversion_in_progress'));
    }
    
    $content .= '<fieldset><legend>' . $this->i18n('legend_video') . '</legend>';
    
    $videoItems = [];
    foreach ($allVideos as $key => $video) {
        $statusClass = '';
        $statusBadge = '';
        
        if ($video['isProcessing']) {
            $statusClass = ' processing';
            $statusBadge = '<span class="badge badge-info conversion-badge"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> Wird konvertiert...</span>';
        } elseif ($video['isAlreadyConverted']) {
            $statusClass = ' already-converted';
            $statusBadge = '<span class="badge badge-success conversion-badge"><i class="fa fa-check" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_already_converted') . '</span>';
        }

        // Titel des Videos anzeigen, falls vorhanden
        $videoTitle = '';
        if (!empty($video['title'])) {
            $videoTitle = '<div class="video-title">' . $video['title'] . '</div>';
        }
        
        $item = '
        <div class="video-item' . $statusClass . '">
            <label>
                <input class="mycheckbox" id="v' . $key . '" type="radio" name="video" value="' . $video['filename'] . '" data-video="' . $video['filename'] . '"' . 
                (($conversionActive || $video['isAlreadyConverted']) ? ' disabled' : '') . '> 
                <strong>' . $video['filename'] . '</strong>
                ' . $statusBadge . '
            </label>
            ' . $videoTitle . '
            <div class="video-meta">
                <span class="video-size"><i class="fa fa-file" aria-hidden="true"></i> ' . rex_formatter::bytes($video['filesize']) . '</span>
                <span class="video-date"><i class="fa fa-calendar" aria-hidden="true"></i> ' . rex_formatter::strftime(strtotime($video['updatedate']), 'datetime') . '</span>';
        
        // Wenn konvertierte Version existiert, Details anzeigen
        if ($video['isAlreadyConverted'] && $video['compressionRate'] > 0) {
            $item .= '<span class="compression-rate badge"><i class="fa fa-compress" aria-hidden="true"></i> ' . $video['compressionRate'] . '% ' . $this->i18n('ffmpeg_smaller') . '</span>';
        }
        
        $item .= '</div>';
        
        // Aktionsbereich für Links
        $item .= '<div class="video-actions">';
        
        // Link zum Original im Medienpool
        $item .= '<a href="' . rex_url::backendPage('mediapool/media', ['file_id' => $video['id']]) . '" class="btn btn-xs btn-default" title="Original im Medienpool anzeigen"><i class="fa fa-film" aria-hidden="true"></i> Original</a> ';
        
        // Wenn konvertierte Version existiert, Link zum optimierten Video und dessen Titel anzeigen
        if ($video['isAlreadyConverted']) {
            // Titel der optimierten Version anzeigen, falls vorhanden
            $optimizedTitle = '';
            if (!empty($video['optimizedData']['title'])) {
                $optimizedTitle = ' <small class="text-muted">(' . $video['optimizedData']['title'] . ')</small>';
            }
            
            $item .= '<a href="' . rex_url::backendPage('mediapool/media', ['file_id' => $video['optimizedData']['id']]) . '" class="btn btn-xs btn-success" title="Optimierte Version im Medienpool anzeigen"><i class="fa fa-video" aria-hidden="true"></i> Web-Version' . $optimizedTitle . '</a>';
        }
        
        $item .= '</div>';
        $item .= '</div>';
        
        $videoItems[] = $item;
    }
    
    $formElements = [];
    $n = [];
    $n['label'] = '<label></label>';
    $n['field'] = implode('', $videoItems);
    $formElements[] = $n;
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $content .= $fragment->parse('core/form/container.php');
    
    $content .= '</fieldset>';
    
    // Action Buttons
    $formElements = [];
    
    // Convert Button
    $n = [];
    $n['field'] = '<button class="btn btn-primary rex-form-aligned btn-start" id="start" type="button" name="save" value="' . $this->i18n('execute') . '"' . ($conversionActive ? ' disabled' : '') . '><i class="fa fa-cogs" aria-hidden="true"></i> ' . $this->i18n('execute') . '</button>';
    $formElements[] = $n;
    
    // Status Button
    $n = [];
    $n['field'] = '<button class="btn btn-default rex-form-aligned" id="check_status" type="button" name="check" value="' . $this->i18n('ffmpeg_check_status') . '"><i class="fa fa-refresh" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_check_status') . '</button>';
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
                        <div class="progress-bar progress-bar-striped active" id="prog" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: ' . ($conversionActive ? '99' : '0') . '%"></div>
                    </div>
                    <div class="conversion-details">
                        <div class="spinner">
                            <div class="bounce1"></div>
                            <div class="bounce2"></div>
                            <div class="bounce3"></div>
                        </div>
                        <span id="progress-text">' . ($conversionActive ? 'Läuft...' : '0%') . '</span>
                    </div>
                </div>
                <div id="log" class="log" style="padding:15px;margin:5px 0;"><pre style="height:200px;overflow-y: auto">' . 
                ($conversionActive && isset($conversionInfo['log']) ? $conversionInfo['log'] : '') . 
                '</pre></div>
            </div>
        </div>
    </form>';

    echo $output;
    
    // Füge Data-Attribute für JavaScript hinzu
    echo '<div class="rex-addon-output" data-i18n-select-video="' . $this->i18n('ffmpeg_select_video') . '"></div>';
}
?>
