<?php

$content = '';
$buttons = '';

// CSRF Token für Sicherheit
$csrf = rex_csrf_token::factory('ffmpeg_trimmer');

// Prüfen ob FFmpeg verfügbar ist
$ffmpegPath = 'ffmpeg';
if (!\FriendsOfRedaxo\FFmpeg\VideoInfo::isAvailable()) {
    echo rex_view::error($this->i18n(\FriendsOfRedaxo\FFmpeg\Shell::canRun() ? 'ffmpeg_info_ffmpeg_missing' : 'ffmpeg_shell_disabled'));
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
    // Schnitt-Editor (assets/js/editor.js): Zeitleiste mit Vorschaubildern, mehrere Abschnitte, schnell/genau,
    // Ton entfernen, Ping-Pong-Schleife, Poster-Bild – läuft als Hintergrund-Auftrag über die API ffmpeg_editor
    $cutI18n = [];
    foreach (['timeline', 'frame', 'play_pause', 'key_space', 'key_ctrl', 'remove', 'undo', 'reset', 'split', 'set_in', 'set_out', 'play_result', 'stop', 'pingpong_test', 'loop_preview', 'start_time', 'end_time', 'segment', 'length', 'snap_note', 'snap_none', 'method', 'fast', 'fast_text', 'exact', 'exact_text', 'options', 'remove_audio', 'remove_audio_help', 'no_audio', 'create_loop', 'create_loop_help', 'mode', 'mode_version', 'mode_version_text', 'mode_replace', 'mode_replace_text', 'start', 'poster_save', 'poster_help', 'poster_done', 'back', 'help', 'keys', 'running', 'starting', 'done', 'open', 'error'] as $key) {
        $cutI18n[$key] = rex_i18n::rawMsg('ffmpeg_cut_' . $key);
    }
    $content .= '
    <div class="panel panel-default ffmpeg-trimmer-panel ffmpeg-trimmer-editor-panel">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="rex-icon fa-cut"></i> ' . $this->i18n('ffmpeg_trimmer_cut_video') . ': ' . rex_escape($videoInfo['filename']) . '
            </h3>
        </div>
        <div class="panel-body">
            <div class="ffmpeg-cut" data-ffmpeg-cut="' . rex_escape(json_encode([
                'file' => $videoFile,
                'i18n' => $cutI18n,
                'backUrl' => rex_url::currentBackendPage([], false),
                'mediaUrl' => rex_url::backendPage('mediapool/media', [], false) . '&file_name=',
            ])) . '"></div>
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
                                <a href="' . rex_url::currentBackendPage(['video' => $video['filename']]) . '" class="btn btn-primary btn-sm" title="' . $this->i18n('ffmpeg_trimmer_cut_video') . '" aria-label="' . $this->i18n('ffmpeg_trimmer_cut_video') . '">
                                    <i class="rex-icon fa-cut" aria-hidden="true"></i>
                                </a>
                                <a href="' . rex_url::backendPage('mediapool/ffmpeg/subtitles', ['video' => $video['filename']]) . '" class="btn btn-default btn-sm" title="' . $this->i18n('ffmpeg_subtitles') . '" aria-label="' . $this->i18n('ffmpeg_subtitles') . '">
                                    <i class="rex-icon fa-cc" aria-hidden="true"></i>
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
                            <label class="checkbox-inline" style="margin-right: 15px;">
                                <input type="checkbox" class="video-preview-loop-toggle" data-preview-target="modalVideo"> ' . $this->i18n('ffmpeg_preview_loop') . '
                            </label>
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
$content .= '<div id="ffmpeg-trimmer-context" data-media-base="' . rex_escape(rex_url::media('')) . '" data-preview-template="' . rex_escape($this->i18n('ffmpeg_trimmer_preview')) . '" data-mode-label-ready="' . rex_escape($this->i18n('ffmpeg_trimmer_mode_ready')) . '" data-mode-label-once="' . rex_escape($this->i18n('ffmpeg_trimmer_mode_once')) . '" data-mode-label-loop="' . rex_escape($this->i18n('ffmpeg_trimmer_mode_loop')) . '" data-mode-label-pingpong="' . rex_escape($this->i18n('ffmpeg_trimmer_mode_pingpong')) . '"></div>';

// Fragment erstellen
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('ffmpeg_trimmer'));
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
