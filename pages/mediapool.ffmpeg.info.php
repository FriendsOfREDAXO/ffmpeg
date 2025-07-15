<?php

$content = '';

// Video-Parameter aus URL
$videoFile = rex_request('video', 'string', '');
$videoInfo = null;

if ($videoFile) {
    $videoPath = rex_path::media($videoFile);
    if (file_exists($videoPath)) {
        // Video-Informationen per FFmpeg auslesen
        $videoInfo = getVideoInfo($videoPath);
        
        // REDAXO Media-Daten
        $media = rex_media::get($videoFile);
    }
}

/**
 * Video-Informationen per FFmpeg ermitteln
 */
function getVideoInfo($videoPath) {
    // FFprobe für detaillierte Video-Informationen verwenden
    $cmd = 'ffprobe -v quiet -print_format json -show_format -show_streams "' . $videoPath . '"';
    $output = shell_exec($cmd);
    
    if (!$output) {
        return null;
    }
    
    $data = json_decode($output, true);
    if (!$data) {
        return null;
    }
    
    $videoStream = null;
    $audioStream = null;
    
    // Video- und Audio-Streams finden
    foreach ($data['streams'] as $stream) {
        if ($stream['codec_type'] === 'video' && !$videoStream) {
            $videoStream = $stream;
        } elseif ($stream['codec_type'] === 'audio' && !$audioStream) {
            $audioStream = $stream;
        }
    }
    
    $info = [
        'format' => $data['format'] ?? null,
        'video' => $videoStream,
        'audio' => $audioStream
    ];
    
    // Berechnete Werte hinzufügen
    if ($videoStream) {
        $info['duration'] = floatval($data['format']['duration'] ?? 0);
        $info['duration_formatted'] = formatDuration($info['duration']);
        $info['aspect_ratio'] = calculateAspectRatio($videoStream['width'], $videoStream['height']);
        $info['framerate'] = calculateFramerate($videoStream['r_frame_rate'] ?? '0/0');
        $info['filesize'] = intval($data['format']['size'] ?? 0);
        $info['filesize_formatted'] = rex_formatter::bytes($info['filesize']);
        $info['bitrate'] = intval($data['format']['bit_rate'] ?? 0);
        $info['bitrate_formatted'] = formatBitrate($info['bitrate']);
    }
    
    return $info;
}

function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    } else {
        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}

function calculateAspectRatio($width, $height) {
    if (!$width || !$height) return rex_i18n::msg('ffmpeg_info_no_data');
    
    $gcd = function($a, $b) use (&$gcd) {
        return $b ? $gcd($b, $a % $b) : $a;
    };
    
    $divisor = $gcd($width, $height);
    $ratioW = $width / $divisor;
    $ratioH = $height / $divisor;
    
    // Bekannte Seitenverhältnisse
    $knownRatios = [
        '16:9' => 16/9,
        '4:3' => 4/3,
        '21:9' => 21/9,
        '1:1' => 1/1,
        '9:16' => 9/16,
        '3:4' => 3/4
    ];
    
    $currentRatio = $width / $height;
    foreach ($knownRatios as $name => $ratio) {
        if (abs($currentRatio - $ratio) < 0.01) {
            return $name;
        }
    }
    
    return $ratioW . ':' . $ratioH;
}

function calculateFramerate($rFrameRate) {
    if (strpos($rFrameRate, '/') !== false) {
        list($num, $den) = explode('/', $rFrameRate);
        if ($den > 0) {
            return round($num / $den, 2);
        }
    }
    return 0;
}

function formatBitrate($bitrate) {
    if ($bitrate >= 1000000) {
        return round($bitrate / 1000000, 1) . ' Mbps';
    } elseif ($bitrate >= 1000) {
        return round($bitrate / 1000, 1) . ' kbps';
    }
    return $bitrate . ' bps';
}

// Video-Liste laden
$sql = rex_sql::factory();
$videos = $sql->getArray('SELECT filename, title, filesize, updatedate FROM ' . rex::getTable('media') . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Hauptinhalt
if ($videoFile && $videoInfo) {
    // Video-Details anzeigen
    $content .= '
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="rex-icon fa-info-circle"></i> ' . $this->i18n('ffmpeg_info') . '
                <br><small style="font-weight: normal; word-break: break-all;">' . rex_escape($videoFile) . '</small>
            </h3>
        </div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-6">
                    <video controls style="width: 100%; max-width: 500px; margin-bottom: 20px;">
                        <source src="' . rex_url::media($videoFile) . '" type="video/mp4">
                        ' . $this->i18n('ffmpeg_browser_no_support') . '
                    </video>
                </div>
                <div class="col-md-6">';
    
    // Allgemeine Informationen
    $content .= '
                    <h4><i class="rex-icon fa-file-video-o"></i> ' . $this->i18n('ffmpeg_info_general') . '</h4>
                    <table class="table table-striped video-info-table">
                        <tr><td>' . $this->i18n('ffmpeg_info_filename') . ':</td><td>' . rex_escape($videoFile) . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_filesize') . ':</td><td>' . $videoInfo['filesize_formatted'] . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_duration') . ':</td><td>' . $videoInfo['duration_formatted'] . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_format') . ':</td><td>' . rex_escape($videoInfo['format']['format_name'] ?? $this->i18n('ffmpeg_info_unknown')) . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_bitrate') . ':</td><td>' . $videoInfo['bitrate_formatted'] . '</td></tr>
                    </table>';
    
    // Video-Stream Informationen
    if ($videoInfo['video']) {
        $video = $videoInfo['video'];
        $content .= '
                    <h4><i class="rex-icon fa-video-camera"></i> ' . $this->i18n('ffmpeg_info_video_stream') . '</h4>
                    <table class="table table-striped video-info-table">
                        <tr><td>' . $this->i18n('ffmpeg_info_resolution') . ':</td><td>' . $video['width'] . ' × ' . $video['height'] . ' px</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_aspect_ratio') . ':</td><td>' . $videoInfo['aspect_ratio'] . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_framerate') . ':</td><td>' . $videoInfo['framerate'] . ' fps</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_codec') . ':</td><td>' . rex_escape($video['codec_name'] ?? $this->i18n('ffmpeg_info_unknown')) . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_profile') . ':</td><td>' . rex_escape($video['profile'] ?? $this->i18n('ffmpeg_info_unknown')) . '</td></tr>
                    </table>';
    }
    
    // Audio-Stream Informationen
    if ($videoInfo['audio']) {
        $audio = $videoInfo['audio'];
        $content .= '
                    <h4><i class="rex-icon fa-volume-up"></i> ' . $this->i18n('ffmpeg_info_audio_stream') . '</h4>
                    <table class="table table-striped video-info-table">
                        <tr><td>' . $this->i18n('ffmpeg_info_codec') . ':</td><td>' . rex_escape($audio['codec_name'] ?? $this->i18n('ffmpeg_info_unknown')) . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_samplerate') . ':</td><td>' . ($audio['sample_rate'] ?? $this->i18n('ffmpeg_info_unknown')) . ' Hz</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_channels') . ':</td><td>' . ($audio['channels'] ?? $this->i18n('ffmpeg_info_unknown')) . '</td></tr>
                        <tr><td>' . $this->i18n('ffmpeg_info_bitrate') . ':</td><td>' . formatBitrate($audio['bit_rate'] ?? 0) . '</td></tr>
                    </table>';
    }
    
    $content .= '
                </div>
            </div>
            
            <div class="row" style="margin-top: 20px;">
                <div class="col-md-12">
                    <h4><i class="rex-icon fa-cog"></i> Optimierungsempfehlungen</h4>
                    <div class="alert alert-info">';
    
    // Optimierungsempfehlungen basierend auf den Video-Daten
    $recommendations = [];
    
    if ($videoInfo['video']) {
        $width = $videoInfo['video']['width'];
        $height = $videoInfo['video']['height'];
        $bitrate = $videoInfo['bitrate'];
        
        // Auflösungsempfehlungen
        if ($width > 1920) {
            $recommendations[] = '📐 <strong>Auflösung:</strong> Video ist größer als 1080p. Für Web-Verwendung könnte eine Skalierung auf 1920×1080 sinnvoll sein.';
        }
        
        // Bitrate-Empfehlungen
        if ($bitrate > 8000000) { // 8 Mbps
            $recommendations[] = '⚡ <strong>Bitrate:</strong> Sehr hohe Bitrate erkannt. Für schnellere Ladezeiten könnte eine Komprimierung helfen.';
        }
        
        // Seitenverhältnis-Hinweise
        $aspectRatio = $videoInfo['aspect_ratio'];
        if ($aspectRatio === '9:16') {
            $recommendations[] = '📱 <strong>Format:</strong> Hochformat erkannt - perfekt für mobile Geräte und Social Media.';
        } elseif ($aspectRatio === '16:9') {
            $recommendations[] = '🖥️ <strong>Format:</strong> Standard-Breitformat - optimal für Web und Desktop.';
        }
        
        // Codec-Empfehlungen
        $codec = $videoInfo['video']['codec_name'] ?? '';
        if ($codec !== 'h264') {
            $recommendations[] = '🔧 <strong>Codec:</strong> H.264 wird für beste Browser-Kompatibilität empfohlen.';
        }
    }
    
    if (empty($recommendations)) {
        $recommendations[] = '✅ Video ist bereits gut für Web-Verwendung optimiert!';
    }
    
    $content .= implode('<br>', $recommendations);
    
    $content .= '
                    </div>
                    
                    <div class="btn-group">
                        <a href="' . rex_url::currentBackendPage(['func' => '']) . '" class="btn btn-default">
                            <i class="rex-icon fa-arrow-left"></i> Zurück zur Liste
                        </a>
                        <a href="' . rex_url::currentBackendPage(['page' => 'mediapool/ffmpeg/trimmer', 'video' => $videoFile]) . '" class="btn btn-primary">
                            <i class="rex-icon fa-cut"></i> Video schneiden
                        </a>
                        <a href="' . rex_url::currentBackendPage(['page' => 'mediapool/ffmpeg', 'video' => $videoFile]) . '" class="btn btn-success">
                            <i class="rex-icon fa-compress"></i> Video konvertieren
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>';
    
} else {
    // Video-Liste anzeigen
    $content .= '
    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title">
                <i class="rex-icon fa-info-circle"></i> Video-Informationen - Video auswählen
            </h3>
        </div>
        <div class="panel-body">
            <p class="text-muted">
                <i class="rex-icon fa-info-circle"></i> 
                ' . $this->i18n('ffmpeg_select_video_info') . '
            </p>
            
            <div class="table-responsive">
                <table class="table table-striped video-list-table">
                    <thead>
                        <tr>
                            <th>Dateiname</th>
                            <th>Titel</th>
                            <th>Größe</th>
                            <th>Datum</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    if (empty($videos)) {
        $content .= '<tr><td colspan="5" class="text-center text-muted">
                        <i class="rex-icon fa-video-camera"></i> ' . $this->i18n('ffmpeg_no_videos_mediapool') . '
                    </td></tr>';
    } else {
        foreach ($videos as $video) {
            $filesize = rex_formatter::bytes($video['filesize']);
            $date = rex_formatter::strftime($video['updatedate'], 'date');
            
            $content .= '<tr>
                <td style="max-width: 250px; word-break: break-all;">
                    <i class="rex-icon fa-file-video-o"></i> 
                    ' . rex_escape($video['filename']) . '
                </td>
                <td>' . rex_escape($video['title']) . '</td>
                <td>' . $filesize . '</td>
                <td>' . $date . '</td>
                <td>
                    <a href="' . rex_url::currentBackendPage(['video' => $video['filename']]) . '" 
                       class="btn btn-sm btn-info">
                        <i class="rex-icon fa-info-circle"></i> Details
                    </a>
                </td>
            </tr>';
        }
    }
    
    $content .= '</tbody>
                </table>
            </div>
        </div>
    </div>';
}

// Fragment ausgeben
$fragment = new rex_fragment();
$fragment->setVar('title', 'Video-Informationen');
$fragment->setVar('body', $content, false);

// CSS für besseres Layout bei langen Dateinamen
echo '<style>
.video-info-table {
    table-layout: fixed;
    width: 100%;
}

.video-info-table td:first-child {
    width: 120px;
    font-weight: bold;
}

.video-info-table td:last-child {
    word-break: break-all;
    max-width: 0; /* Trick für flexible Spaltenbreite */
}

.panel-title small {
    font-size: 0.8em;
    color: rgba(255,255,255,0.8);
    display: block;
    margin-top: 5px;
}

.video-list-table {
    table-layout: fixed;
}

.video-list-table th:nth-child(1),
.video-list-table td:nth-child(1) {
    width: 30%;
}

.video-list-table th:nth-child(2),
.video-list-table td:nth-child(2) {
    width: 25%;
}

.video-list-table th:nth-child(3),
.video-list-table td:nth-child(3) {
    width: 15%;
}

.video-list-table th:nth-child(4),
.video-list-table td:nth-child(4) {
    width: 15%;
}

.video-list-table th:nth-child(5),
.video-list-table td:nth-child(5) {
    width: 15%;
}

@media (max-width: 768px) {
    .video-list-table th:nth-child(2),
    .video-list-table td:nth-child(2),
    .video-list-table th:nth-child(4),
    .video-list-table td:nth-child(4) {
        display: none;
    }
    
    .video-list-table th:nth-child(1),
    .video-list-table td:nth-child(1) {
        width: 50%;
    }
}
</style>';

echo $fragment->parse('core/page/section.php');
