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

// Zuerst alle optimierten Videos sammeln (Mapping per Basename ohne Extension)
foreach ($allMediaFiles as $media) {
    if (strpos($media['filename'], 'web_') === 0) {
        // Verwende den Basename (ohne Dateiendung) als Schlüssel, damit verschiedene
        // Extensions (z.B. mov -> mp4) erkannt werden
        $originalBase = pathinfo(substr($media['filename'], 4), PATHINFO_FILENAME);
        $optimizedVideosMapping[$originalBase] = $media;
    }
}

// Dann alle Videos verarbeiten
foreach ($allMediaFiles as $media) {
    $isOptimized = strpos($media['filename'], 'web_') === 0;
    
    // Nur Originalvideos in die Liste aufnehmen (keine "web_"-Versionen)
    if (!$isOptimized) {
        $originalName = $media['filename'];
        $originalBase = pathinfo($originalName, PATHINFO_FILENAME);
        $isProcessing = $conversionActive && isset($conversionInfo['video']) && $conversionInfo['video'] === $originalName;
        // Prüfe anhand des Basename (ohne Extension), dann werden .mov -> .mp4 Varianten erkannt
        $isAlreadyConverted = isset($optimizedVideosMapping[$originalBase]);
        $optimizedData = $isAlreadyConverted ? $optimizedVideosMapping[$originalBase] : null;
        
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
    $content .= '<div class="ffmpeg-intro-card">' . $this->i18n('ffmpeg_convert_info') . '</div>';
    
    if ($conversionActive) {
        $content .= rex_view::warning($this->i18n('ffmpeg_conversion_in_progress'));
    }
    
    $content .= '<fieldset class="ffmpeg-video-list"><legend>' . $this->i18n('legend_video') . '</legend>';
    
    $videoItems = [];
    foreach ($allVideos as $key => $video) {
        $statusClass = '';
        $statusBadge = '';
        
        if ($video['isProcessing']) {
            $statusClass = ' processing';
            $statusBadge = '<span class="badge badge-info conversion-badge"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> Wird konvertiert...</span>';
        } elseif ($video['isAlreadyConverted']) {
            $statusClass = ' already-converted';
            $statusBadge = '';
        }

        // Titel des Videos anzeigen, falls vorhanden
        $videoTitle = '';
        if (!empty($video['title'])) {
            $videoTitle = '<div class="video-title">' . $video['title'] . '</div>';
        }

        $fileMetaLine = '<div class="video-file-date"><i class="fa fa-calendar" aria-hidden="true"></i> ' . rex_formatter::strftime(strtotime($video['updatedate']), 'datetime') . '</div>';

        $inlineStatusDisplay = 'block';
        $inlineLog = '';
        if ($video['isProcessing'] && isset($conversionInfo['log']) && is_string($conversionInfo['log'])) {
            $inlineLog = rex_escape($conversionInfo['log']);
        }

        $statusText = $video['isProcessing']
            ? $this->i18n('ffmpeg_status_processing')
            : ($video['isAlreadyConverted']
                ? (($video['compressionRate'] > 0)
                    ? ($video['compressionRate'] . '% ' . $this->i18n('ffmpeg_smaller'))
                    : $this->i18n('ffmpeg_status_converted'))
                : $this->i18n('ffmpeg_status_ready'));
        
        $item = '
        <div class="video-item' . $statusClass . '" data-video-item="' . rex_escape($video['filename']) . '">
            <div class="video-file-cell">
                <div class="video-head">
                    <strong class="video-filename">' . $video['filename'] . '</strong>
                    ' . $statusBadge . '
                </div>
                ' . $fileMetaLine . '
                ' . $videoTitle . '
            </div>
            <div class="video-meta">
                ';

        $item .= '<span class="video-size-original"><i class="fa fa-hdd-o" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_info_original_size') . ': ' . rex_formatter::bytes($video['filesize']) . '</span>';
        if ($video['isAlreadyConverted'] && isset($video['optimizedData']['filesize'])) {
            $item .= '<span class="video-size-web"><i class="fa fa-compress" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_info_web_size') . ': ' . rex_formatter::bytes((int) $video['optimizedData']['filesize']) . '</span>';
        } else {
            $item .= '<span class="video-size-web"><i class="fa fa-compress" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_info_web_size') . ': ' . $this->i18n('ffmpeg_not_available') . '</span>';
        }
        
        // Kompressionsangabe wird in der Status-Spalte dargestellt
        
        $item .= '</div>';
        
        // Aktionsbereich für Konvertierung und Links
        $item .= '<div class="video-actions">';

        $canConvert = !$video['isAlreadyConverted'] && !$video['isProcessing'];
        if ($canConvert) {
            $item .= '<div class="video-actions-group video-actions-group-primary">';
            $item .= '<button class="btn btn-xs btn-primary ffmpeg-start-conversion" type="button" data-video="' . rex_escape($video['filename']) . '"' . ($conversionActive ? ' disabled' : '') . '><i class="fa fa-cogs" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_convert_this_video') . '</button>';
            $item .= '</div>';
        }

        $item .= '<div class="video-actions-group">';
        
        // Link zum Original im Medienpool
        $item .= '<a href="' . rex_url::backendPage('mediapool/media', ['file_id' => $video['id']]) . '" class="btn btn-xs btn-default" title="Original im Medienpool anzeigen"><i class="fa fa-film" aria-hidden="true"></i> Original</a> ';
        
        $item .= '<button type="button" class="btn btn-xs btn-info ffmpeg-preview-link" data-media-url="' . rex_escape(rex_url::media($video['filename'])) . '" data-preview-label="' . rex_escape($this->i18n('ffmpeg_preview_original')) . '" data-video-title="' . rex_escape(!empty($video['title']) ? $video['title'] : $video['filename']) . '"><i class="fa fa-play-circle" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_preview') . '</button>';
        $item .= '</div>';
        
        // Wenn konvertierte Version existiert, Link zum optimierten Video und dessen Titel anzeigen
        if ($video['isAlreadyConverted']) {
            $optimizedFormat = '';
            if (isset($video['optimizedData']['filename']) && is_string($video['optimizedData']['filename'])) {
                $optimizedFormat = strtoupper((string) pathinfo($video['optimizedData']['filename'], PATHINFO_EXTENSION));
            }

            $webVariantLabel = 'Web' . ('' !== $optimizedFormat ? ' (' . $optimizedFormat . ')' : '');
            
            $item .= '<div class="video-actions-group">';
            $item .= '<a href="' . rex_url::backendPage('mediapool/media', ['file_id' => $video['optimizedData']['id']]) . '" class="btn btn-xs btn-success" title="Optimierte Version im Medienpool anzeigen"><i class="fa fa-video" aria-hidden="true"></i> ' . rex_escape($webVariantLabel) . '</a> ';
            $item .= '<button type="button" class="btn btn-xs btn-success ffmpeg-preview-link" data-media-url="' . rex_escape(rex_url::media($video['optimizedData']['filename'])) . '" data-preview-label="' . rex_escape($this->i18n('ffmpeg_preview_optimized')) . '" data-video-title="' . rex_escape(!empty($video['optimizedData']['title']) ? $video['optimizedData']['title'] : $video['optimizedData']['filename']) . '"><i class="fa fa-play-circle" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_preview_optimized_button') . '</button>';
            $item .= '</div>';
        }
        
        $item .= '</div>';

        $item .= '<div class="video-inline-status" style="display:' . $inlineStatusDisplay . ';" data-running-label="' . rex_escape($this->i18n('ffmpeg_status_processing')) . '">';
        $item .= '<div class="video-inline-main" style="display:' . ($video['isProcessing'] ? 'flex' : 'none') . ';">';
        $item .= '<div class="video-inline-donut" style="--progress:' . ($video['isProcessing'] ? '99' : '0') . ';">';
        $item .= '<span class="video-inline-donut-value">' . ($video['isProcessing'] ? '99%' : '0%') . '</span>';
        $item .= '</div>';
        $item .= '<div class="video-inline-status-meta">';
        $item .= '<span class="video-inline-status-text">' . $this->i18n('ffmpeg_status_processing') . '</span>';
        $item .= '<button type="button" class="btn btn-xs btn-link ffmpeg-toggle-inline-log" aria-expanded="false" data-show-label="' . rex_escape($this->i18n('ffmpeg_show_log')) . '" data-hide-label="' . rex_escape($this->i18n('ffmpeg_hide_log')) . '">' . $this->i18n('ffmpeg_show_log') . '</button>';
        $item .= '</div>';
        $item .= '</div>';
        $item .= '<div class="video-inline-log" style="display:none;"><pre>' . $inlineLog . '</pre></div>';

        $statusClass = $video['isAlreadyConverted'] ? 'is-converted' : 'is-ready';
        $statusIcon = $video['isAlreadyConverted'] ? 'fa-check-circle' : 'fa-clock-o';
        $item .= '<span class="video-inline-status-static ' . $statusClass . '" style="display:' . ($video['isProcessing'] ? 'none' : 'inline-flex') . ';"><i class="fa ' . $statusIcon . '" aria-hidden="true"></i> ' . $statusText . '</span>';
        $item .= '</div>';

        $item .= '</div>';
        
        $videoItems[] = $item;
    }
    
    $content .= '<div class="ffmpeg-list-head">'
        . '<span>' . $this->i18n('ffmpeg_col_file') . '</span>'
        . '<span>' . $this->i18n('ffmpeg_col_info') . '</span>'
        . '<span>' . $this->i18n('ffmpeg_col_actions') . '</span>'
        . '<span>' . $this->i18n('ffmpeg_col_status') . '</span>'
        . '</div>';
    $content .= '<div class="ffmpeg-video-items">' . implode('', $videoItems) . '</div>';
    
    $content .= '</fieldset>';
    
    $buttons = '<div class="ffmpeg-footer-actions">'
        . '<button class="btn btn-default ffmpeg-status-check" id="check_status" type="button" name="check" value="' . $this->i18n('ffmpeg_check_status') . '"><i class="fa fa-refresh" aria-hidden="true"></i> ' . $this->i18n('ffmpeg_check_status') . '</button>'
        . '</div>';

    // Ausgabe Formular
    $fragment = new rex_fragment();
    $fragment->setVar('class', 'info');
    $fragment->setVar('title', $this->i18n('ffmpeg_video_convert'));
    $fragment->setVar('body', $content, false);
    $fragment->setVar('buttons', $buttons, false);
    $output = $fragment->parse('core/page/section.php');

    $output = '
    <form action="' . rex_url::currentBackendPage() . '" method="post" class="ffmpeg-converter-ui">
        <input type="hidden" name="formsubmit" value="1" />
        ' . $csrfToken->getHiddenField() . '
        ' . $output . '
        
        <div class="rex-page-section progress-section" style="display:' . ($conversionActive ? 'block' : 'none') . ';">
            <div class="panel panel-info ffmpeg-global-status-panel">
                <div class="conversion-status ffmpeg-global-status">
                    <div class="video-inline-donut video-inline-donut-global" id="global-progress-donut" style="--progress:' . ($conversionActive ? '99' : '0') . ';">
                        <span class="video-inline-donut-value" id="global-progress-value">' . ($conversionActive ? '99%' : '0%') . '</span>
                    </div>
                    <div class="conversion-details">
                        <div class="spinner global-spinner">
                            <div class="bounce1"></div>
                            <div class="bounce2"></div>
                            <div class="bounce3"></div>
                        </div>
                        <span id="progress-text">' . ($conversionActive ? 'Konvertierung läuft…' : '0%') . '</span>
                    </div>
                </div>
                <div id="log" class="log" style="padding:15px;margin:5px 0;display:none;"><pre style="height:200px;overflow-y: auto">' . 
                ($conversionActive && isset($conversionInfo['log']) ? $conversionInfo['log'] : '') . 
                '</pre></div>
            </div>
        </div>
    </form>';

    echo $output;

    echo '<div class="modal fade" id="ffmpeg-video-preview-modal" tabindex="-1" role="dialog" aria-labelledby="ffmpeg-video-preview-title" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="ffmpeg-video-preview-title">' . $this->i18n('ffmpeg_preview_modal_title') . '</h4>
                </div>
                <div class="modal-body">
                    <div class="ffmpeg-preview-player-wrapper">
                        <video id="ffmpeg-preview-video" class="ffmpeg-preview-video" controls preload="metadata" playsinline></video>
                    </div>
                </div>
                <div class="modal-footer">
                    <label class="checkbox-inline" style="margin-right: 15px;">
                        <input type="checkbox" class="ffmpeg-preview-loop-toggle" data-preview-target="ffmpeg-preview-video"> ' . $this->i18n('ffmpeg_preview_loop') . '
                    </label>
                    <button type="button" class="btn btn-default" data-dismiss="modal">' . $this->i18n('ffmpeg_preview_modal_close') . '</button>
                </div>
            </div>
        </div>
    </div>';
    
    // Füge Data-Attribute für JavaScript hinzu
    echo '<div class="rex-addon-output" data-i18n-select-video="' . $this->i18n('ffmpeg_select_video') . '"></div>';
}
?>
