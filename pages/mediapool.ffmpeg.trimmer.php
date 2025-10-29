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
                echo rex_view::error('Fehler beim Importieren: ' . $e->getMessage());
            }
        } else {
            echo rex_view::error('Fehler beim Schneiden des Videos. FFmpeg-Output: ' . implode('<br>', $output));
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
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="rex-icon fa-cut"></i> ' . $this->i18n('ffmpeg_trimmer_cut_video') . ': ' . rex_escape($videoInfo['filename']) . '
            </h3>
        </div>
        <div class="panel-body">
            <div class="video-trimmer-container" style="max-width: 800px; margin: 0 auto;">
                <video id="trimmer-video" controls style="width: 100%; margin-bottom: 20px;">
                    <source src="' . rex_url::media($videoFile) . '" type="video/mp4">
                    ' . $this->i18n('ffmpeg_browser_no_support') . '
                </video>
                
                <form method="post" style="margin-top: 20px;">
                    ' . $csrf->getHiddenField() . '
                    <input type="hidden" name="video" value="' . rex_escape($videoFile) . '">
                    <input type="hidden" name="action" value="trim">
                    
                    <div class="video-controls-wrapper">
                        <label class="control-label" style="font-weight: 600; margin-bottom: 15px; display: block;">Zeitbereich festlegen:</label>
                        <div class="row">
                            <div class="col-sm-6">
                                <label class="control-label">' . $this->i18n('ffmpeg_trimmer_start_time') . ':</label>
                                <div class="input-group">
                                    <input type="number" name="start_time" id="start_time" step="0.1" min="0" class="form-control" required>
                                    <span class="input-group-btn">
                                        <button type="button" class="btn btn-info" onclick="setCurrentTime(\'start\')" title="' . $this->i18n('ffmpeg_trimmer_set_current') . '">
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
                                        <button type="button" class="btn btn-info" onclick="setCurrentTime(\'end\')" title="' . $this->i18n('ffmpeg_trimmer_set_current') . '">
                                            <i class="rex-icon fa-clock-o"></i>
                                        </button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center" style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="rex-icon fa-cut"></i> ' . $this->i18n('ffmpeg_trimmer_cut_video') . '
                        </button>
                        <a href="' . rex_url::currentBackendPage() . '" class="btn btn-default">
                            <i class="rex-icon fa-arrow-left"></i> Zurück zur Übersicht
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    function setCurrentTime(type) {
        var video = document.getElementById(\'trimmer-video\');
        var currentTime = video.currentTime;
        
        if (type === \'start\') {
            document.getElementById(\'start_time\').value = currentTime.toFixed(1);
        } else if (type === \'end\') {
            document.getElementById(\'end_time\').value = currentTime.toFixed(1);
        }
    }
    
    // Keyboard shortcuts
    document.addEventListener(\'keydown\', function(e) {
        var video = document.getElementById(\'trimmer-video\');
        
        if (e.key === \'s\' && e.ctrlKey) {
            e.preventDefault();
            setCurrentTime(\'start\');
        } else if (e.key === \'e\' && e.ctrlKey) {
            e.preventDefault();
            setCurrentTime(\'end\');
        } else if (e.key === \' \') {
            e.preventDefault();
            if (video.paused) {
                video.play();
            } else {
                video.pause();
            }
        }
    });
    </script>';
    
} else {
    // Video-Liste anzeigen
    $content .= '
    <div class="panel panel-default">
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
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th style="width: 250px;">' . $this->i18n('ffmpeg_trimmer_table_filename') . '</th>
                            <th>' . $this->i18n('ffmpeg_trimmer_table_title') . '</th>
                            <th style="width: 100px;">' . $this->i18n('ffmpeg_trimmer_table_size') . '</th>
                            <th style="width: 120px;">' . $this->i18n('ffmpeg_trimmer_table_date') . '</th>
                            <th style="width: 180px;">' . $this->i18n('ffmpeg_trimmer_table_actions') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($videos as $video) {
            $filesize = rex_formatter::bytes($video['filesize']);
            $date = rex_formatter::strftime($video['updatedate'], 'date');
            // Prepare safe data attributes
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
        
        // Add modal for video preview
        $content .= '
            <!-- Video Preview Modal -->
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
                            <video id="modalVideo" controls style="width: 100%; max-height: 500px;">
                                <source id="modalVideoSource" src="" type="video/mp4">
                                ' . $this->i18n('ffmpeg_browser_no_support') . '
                            </video>
                            <p class="video-filename-display" style="margin-top: 10px; font-weight: bold;"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">' . $this->i18n('ffmpeg_trimmer_modal_close') . '</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // Event delegation for preview buttons
                $(\'body\').on(\'click\', \'.video-preview-btn\', function() {
                    var filename = $(this).data(\'filename\');
                    var modal = $(\'#videoPreviewModal\');
                    var video = modal.find(\'#modalVideo\')[0];
                    var source = modal.find(\'#modalVideoSource\')[0];
                    var filenameDisplay = modal.find(\'.video-filename-display\');
                    
                    // Pause video if playing
                    video.pause();
                    
                    // Set new source with proper encoding
                    source.src = \'' . rex_url::media('') . '\' + encodeURIComponent(filename);
                    filenameDisplay.text(filename);
                    
                    // Load and show modal
                    video.load();
                    modal.modal(\'show\');
                });
                
                // Stop video when modal is closed
                $(\'#videoPreviewModal\').on(\'hidden.bs.modal\', function () {
                    var video = $(this).find(\'#modalVideo\')[0];
                    video.pause();
                });
            });
            </script>';
    } else {
        $content .= '
            <div class="alert alert-info">
                <p>' . $this->i18n('ffmpeg_no_videos_mediapool') . '</p>
                <p>Laden Sie Videos über den <a href="' . rex_url::backendPage('media') . '">Medienpool</a> hoch.</p>
            </div>';
    }
    
    $content .= '
        </div>
    </div>';
}

// Zusätzliche Styles
$content .= '
<style>
.video-trimmer-container {
    display: block;
}

.video-trimmer-container video {
    border: 1px solid #ddd;
    border-radius: 4px;
}

.video-controls-wrapper {
    width: 100%;
}

.video-controls-wrapper .control-label {
    font-weight: 600;
    margin-bottom: 5px;
    display: block;
}

.video-controls-wrapper .input-group {
    margin-bottom: 10px;
}

.btn + .btn {
    margin-left: 5px;
}

/* Video list table styles */
.video-filename {
    max-width: 250px;
    word-wrap: break-word;
    word-break: break-all;
}

.video-filename .filename-truncate {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.video-actions .btn {
    margin-right: 3px;
}

/* Modal styles */
#videoPreviewModal .modal-body {
    padding: 15px;
}

#videoPreviewModal video {
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #000;
}

.video-filename-display {
    word-wrap: break-word;
    font-size: 12px;
    color: #666;
}

@media (max-width: 768px) {
    .video-trimmer-container {
        max-width: none !important;
        margin: 0 !important;
    }
    
    .video-controls-wrapper .row .col-sm-6 {
        margin-bottom: 15px;
    }
    
    .video-filename {
        max-width: 150px;
    }
    
    .video-actions .btn {
        margin-bottom: 3px;
    }
}
</style>';

// Fragment erstellen
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('ffmpeg_trimmer'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
