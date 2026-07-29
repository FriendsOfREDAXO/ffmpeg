<?php

$content = '';
$buttons = '';

// CSRF Token für Sicherheit
$csrf = rex_csrf_token::factory('ffmpeg_trimmer');

// Prüfen ob FFmpeg verfügbar ist
$ffmpegPath = 'ffmpeg';
exec('which ffmpeg', $ffmpegCheck, $ffmpegReturn);
if ($ffmpegReturn !== 0) {
    echo rex_view::error($this->i18n('ffmpeg_info_ffmpeg_missing'));
    return;
}

// Parameter verarbeiten
$videoFile = rex_request('video', 'string');
// HTML-Entity-Dekodierung für URLs mit &amp;
if (empty($videoFile)) {
    $videoFile = html_entity_decode(rex_request('video', 'string'));
}
$action = rex_request('action', 'string');

// Video-Info laden falls Video ausgewählt
$videoInfo = null;
if ($videoFile) {
    $sql = rex_sql::factory();
    $videoData = $sql->getArray('SELECT * FROM ' . rex::getTable('media') . ' WHERE filename = ?', [$videoFile]);
    if (count($videoData) > 0) {
        $videoInfo = $videoData[0];
    }
}

// Video-Trimming verarbeiten
if ($action === 'trim' && $csrf->isValid() && $videoFile && $videoInfo) {
    $startTime = rex_request('start_time', 'string');
    $endTime = rex_request('end_time', 'string');
    
    // Zeiten validieren
    if (!empty($startTime) && !empty($endTime) && $startTime < $endTime) {
        $videoPath = rex_path::media($videoFile);
        
        // Neue Dateinamen erstellen
        $pathInfo = pathinfo($videoFile);
        $baseName = $pathInfo['filename'];
        $extension = $pathInfo['extension'];
        
        // Intelligente Präfix-Behandlung
        if (strpos($baseName, 'web_') === 0) {
            // Video hat bereits ein web_ Präfix, nur "trimmed" hinzufügen
            if (strpos($baseName, 'trimmed') === false) {
                $baseFilename = str_replace('web_', 'web_trimmed_', $baseName);
            } else {
                // Bereits getrimmt, neue Version erstellen
                $baseFilename = $baseName . '_new';
            }
        } else {
            // Original-Video, normales Präfix hinzufügen
            $baseFilename = 'web_trimmed_' . $baseName;
        }
        
        // Prüfen ob Datei bereits existiert und eindeutigen Namen generieren
        $newFilename = $baseFilename . '.' . $extension;
        $counter = 1;
        
        while (rex_media::get($newFilename) !== null) {
            $newFilename = $baseFilename . '_' . $counter . '.' . $extension;
            $counter++;
        }
        
        $outputPath = rex_path::media($newFilename);
        
        // Dauer berechnen
        $duration = $endTime - $startTime;
        
        // FFmpeg-Befehl für Trimming
        $ffmpegCmd = sprintf(
            'ffmpeg -y -ss %s -t %s -i "%s" -c copy "%s"',
            $startTime,
            $duration,
            $videoPath,
            $outputPath
        );
        
        // Trimming ausführen
        exec($ffmpegCmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($outputPath)) {
            // Video in Medienpool importieren
            try {
                // Prüfen ob Datei bereits in der Datenbank existiert
                $checkSql = rex_sql::factory();
                $existingMedia = $checkSql->getArray('SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?', [$newFilename]);
                
                $sql = rex_sql::factory();
                $sql->setTable(rex::getTable('media'));
                $sql->setValue('filename', $newFilename);
                $sql->setValue('originalname', $newFilename);
                $sql->setValue('filetype', 'video/mp4');
                $sql->setValue('filesize', filesize($outputPath));
                $sql->setValue('width', 0);
                $sql->setValue('height', 0);
                $sql->setValue('title', $videoInfo['title'] . ' (geschnitten)');
                $sql->setValue('updatedate', date('Y-m-d H:i:s'));
                $sql->setValue('updateuser', rex::getUser()->getLogin());
                
                if (count($existingMedia) > 0) {
                    // UPDATE: Datei existiert bereits
                    $sql->setWhere('filename = :filename', ['filename' => $newFilename]);
                    $sql->update();
                } else {
                    // INSERT: Neue Datei
                    $sql->setValue('createdate', date('Y-m-d H:i:s'));
                    $sql->setValue('createuser', rex::getUser()->getLogin());
                    $sql->insert();
                }
                
                echo rex_view::success($this->i18n('ffmpeg_trimmer_success') . ': ' . $newFilename);
                
            } catch (Exception $e) {
                echo rex_view::error($this->i18n('ffmpeg_trimmer_error_import') . ': ' . $e->getMessage());
            }
        } else {
            echo rex_view::error($this->i18n('ffmpeg_trimmer_error_cutting') . ': ' . implode('<br>', $output));
        }
    } else {
        echo rex_view::error($this->i18n('ffmpeg_trimmer_error_times'));
    }
}

// Video-Liste laden - alle Videos anzeigen (auch web-optimierte)
$sql = rex_sql::factory();
$videos = $sql->getArray('SELECT filename, title, filesize, updatedate, filetype FROM ' . rex::getTable('media') . ' WHERE (filetype LIKE \'video/%\' OR filetype IN (\'video/mp4\', \'video/avi\', \'video/mov\', \'video/wmv\', \'video/webm\', \'video/mkv\')) ORDER BY updatedate DESC');

// Debug: Anzahl Videos anzeigen (kann später entfernt werden)
if (rex_request('debug', 'bool')) {
    echo '<div class="alert alert-info">Debug: ' . count($videos) . ' Videos gefunden</div>';
    foreach ($videos as $v) {
        echo '<div class="alert alert-info">Video: ' . $v['filename'] . ' - Type: ' . $v['filetype'] . '</div>';
    }
}

// Hauptinhalt
if ($videoFile && $videoInfo) {
    // Video-Editor anzeigen
    $content .= '
    <div class="panel panel-default ffmpeg-trimmer-panel ffmpeg-trimmer-editor-panel">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="rex-icon fa-cut"></i> ' . $this->i18n('ffmpeg_trimmer_cut_video') . ': ' . rex_escape($videoInfo['filename']) . '
            </h3>
        </div>
        <div class="panel-body">
            <div class="video-trimmer-container">
                <div class="trimmer-video-stage">
                    <video id="trimmer-video" class="trimmer-video" controls>
                        <source src="' . rex_url::media($videoFile) . '" type="video/mp4">
                        ' . $this->i18n('ffmpeg_browser_no_support') . '
                    </video>
                    <div class="trimmer-video-hud" aria-label="Video-Steuerung">
                        <div class="trimmer-video-hud-top">
                            <span class="trimmer-time-chip">Start <strong id="trimmer-chip-start">0.0s</strong></span>
                            <span class="trimmer-time-chip">Jetzt <strong id="trimmer-chip-current">0.0s</strong></span>
                            <span class="trimmer-time-chip">Ende <strong id="trimmer-chip-end">0.0s</strong></span>
                        </div>
                        <input id="trimmer-scrubber" class="trimmer-scrubber" type="range" min="0" max="0" step="0.1" value="0" aria-label="Video Position">
                        <div class="trimmer-video-hud-controls btn-group" role="group" aria-label="Trimmer Schnellsteuerung">
                            <button type="button" class="btn btn-default btn-sm trimmer-video-control" data-action="seek" data-seconds="-5" title="5 Sekunden zurück">-5s</button>
                            <button type="button" class="btn btn-default btn-sm trimmer-video-control" data-action="seek" data-seconds="-1" title="1 Sekunde zurück">-1s</button>
                            <button type="button" class="btn btn-primary btn-sm trimmer-video-control" data-action="toggle-play" title="Abspielen/Pausieren">Play/Pause</button>
                            <button type="button" class="btn btn-default btn-sm trimmer-video-control" data-action="seek" data-seconds="1" title="1 Sekunde vor">+1s</button>
                            <button type="button" class="btn btn-default btn-sm trimmer-video-control" data-action="seek" data-seconds="5" title="5 Sekunden vor">+5s</button>
                            <button type="button" class="btn btn-info btn-sm trimmer-video-control" data-action="mark-start" title="Startzeit setzen">Start setzen</button>
                            <button type="button" class="btn btn-info btn-sm trimmer-video-control" data-action="mark-end" title="Endzeit setzen">Ende setzen</button>
                            <button type="button" class="btn btn-success btn-sm trimmer-video-control" data-action="play-selection" title="Ausgewählten Bereich abspielen">Bereich testen</button>
                        </div>
                    </div>
                </div>

                <form method="post" class="trimmer-form">
                    ' . $csrf->getHiddenField() . '
                    <input type="hidden" name="video" value="' . rex_escape($videoFile) . '">
                    <input type="hidden" name="action" value="trim">

                    <div class="video-controls-wrapper">
                        <label class="control-label trimmer-range-label">' . $this->i18n('ffmpeg_trimmer_time_range_label') . '</label>
                        <p class="trimmer-duration-hint" id="trimmer-duration-hint">' . str_replace('{0}', '0', $this->i18n('ffmpeg_trimmer_preview')) . '</p>
                        <div class="row">
                            <div class="col-sm-6">
                                <label class="control-label">' . $this->i18n('ffmpeg_trimmer_start_time') . ':</label>
                                <div class="input-group">
                                    <input type="number" name="start_time" id="start_time" step="0.1" min="0" class="form-control" required>
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-info trimmer-set-current" data-target="start" title="' . $this->i18n('ffmpeg_trimmer_set_current') . '">
                                            <i class="rex-icon fa-clock-o"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label class="control-label">' . $this->i18n('ffmpeg_trimmer_end_time') . ':</label>
                                <div class="input-group">
                                    <input type="number" name="end_time" id="end_time" step="0.1" min="0" class="form-control" required>
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-info trimmer-set-current" data-target="end" title="' . $this->i18n('ffmpeg_trimmer_set_current') . '">
                                            <i class="rex-icon fa-clock-o"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center trimmer-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="rex-icon fa-cut"></i> ' . $this->i18n('ffmpeg_trimmer_cut_video') . '
                        </button>
                        <a href="' . rex_url::currentBackendPage() . '" class="btn btn-default">
                            <i class="rex-icon fa-arrow-left"></i> ' . $this->i18n('ffmpeg_trimmer_back_to_list') . '
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>';
} else {
    // Video-Liste anzeigen
    $content .= '
    <div class="panel panel-default ffmpeg-trimmer-panel ffmpeg-trimmer-list-panel">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="rex-icon fa-cut"></i> ' . $this->i18n('ffmpeg_trimmer') . '
            </h3>
        </div>
        <div class="panel-body">
            <p>' . $this->i18n('ffmpeg_trimmer_select_video') . '</p>';

    if (count($videos) > 0) {
        $content .= '
            <div class="table-responsive">
                <table class="table table-striped ffmpeg-trimmer-table">
                    <thead>
                        <tr>
                            <th class="ffmpeg-trimmer-col-file">' . $this->i18n('ffmpeg_trimmer_table_filename') . '</th>
                            <th>' . $this->i18n('ffmpeg_trimmer_table_title') . '</th>
                            <th class="ffmpeg-trimmer-col-size">' . $this->i18n('ffmpeg_trimmer_table_size') . '</th>
                            <th class="ffmpeg-trimmer-col-date">' . $this->i18n('ffmpeg_trimmer_table_date') . '</th>
                            <th class="ffmpeg-trimmer-col-actions">' . $this->i18n('ffmpeg_trimmer_table_actions') . '</th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($videos as $video) {
            $filesize = rex_formatter::bytes($video['filesize']);
            $date = rex_formatter::strftime($video['updatedate'], 'date');
            $escapedFilename = rex_escape($video['filename']);

            $content .= '
                        <tr>
                            <td class="video-filename" title="' . $escapedFilename . '">
                                <span class="filename-truncate">' . $escapedFilename . '</span>
                            </td>
                            <td>' . rex_escape($video['title']) . '</td>
                            <td>' . $filesize . '</td>
                            <td>' . $date . '</td>
                            <td class="video-actions">
                                <button type="button" class="btn btn-default btn-sm video-preview-btn" data-filename="' . $escapedFilename . '" title="' . $this->i18n('ffmpeg_trimmer_preview_show') . '">
                                    <i class="rex-icon fa-eye"></i>
                                </button>
                                <a href="' . rex_url::currentBackendPage(['video' => $video['filename']]) . '" class="btn btn-primary btn-sm" title="' . $this->i18n('ffmpeg_trimmer_cut_video') . '">
                                    <i class="rex-icon fa-cut"></i>
                                </a>
                            </td>
                        </tr>';
        }

        $content .= '
                    </tbody>
                </table>
            </div>';

        $content .= '
            <div class="modal fade" id="videoPreviewModal" tabindex="-1" role="dialog" aria-labelledby="videoPreviewModalLabel">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="' . $this->i18n('ffmpeg_trimmer_modal_close') . '">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            <h4 class="modal-title" id="videoPreviewModalLabel">' . $this->i18n('ffmpeg_trimmer_modal_title') . '</h4>
                        </div>
                        <div class="modal-body">
                            <video id="modalVideo" controls>
                                <source id="modalVideoSource" src="" type="video/mp4">
                                ' . $this->i18n('ffmpeg_browser_no_support') . '
                            </video>
                            <p class="video-filename-display"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">' . $this->i18n('ffmpeg_trimmer_modal_close') . '</button>
                        </div>
                    </div>
                </div>
            </div>';
    } else {
        $content .= '
            <div class="alert alert-info">
                <p>' . $this->i18n('ffmpeg_no_videos_mediapool') . '</p>
                <p>' . str_replace('{0}', rex_url::backendPage('media'), $this->i18n('ffmpeg_trimmer_upload_videos')) . '</p>
            </div>';
    }

    $content .= '
        </div>
    </div>';
}

// Kontext-Daten für Trimmer-JS
$content .= '<div id="ffmpeg-trimmer-context" data-media-base="' . rex_escape(rex_url::media('')) . '" data-preview-template="' . rex_escape($this->i18n('ffmpeg_trimmer_preview')) . '"></div>';

// Fragment erstellen
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('ffmpeg_trimmer'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
