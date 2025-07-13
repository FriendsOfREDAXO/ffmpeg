# FFmpeg Video-Info API für Module und Templates

Die `rex_ffmpeg_video_info` Klasse ermöglicht es, Video-Informationen in REDAXO-Modulen und Templates auszulesen.

## Verwendung in Modulen

### Basis-Informationen abrufen

```php
<?php
// Video aus REX_MEDIA_[ID] oder direkt als String
$videoFile = 'REX_MEDIA[1]';  // oder 'mein_video.mp4'

// Grundlegende Video-Infos
$info = rex_ffmpeg_video_info::getBasicInfo($videoFile);

if ($info) {
    echo '<div class="video-info">';
    echo '<h3>' . $info['filename'] . '</h3>';
    echo '<p>Dauer: ' . $info['duration_formatted'] . '</p>';
    echo '<p>Auflösung: ' . $info['width'] . ' × ' . $info['height'] . ' px</p>';
    echo '<p>Seitenverhältnis: ' . $info['aspect_ratio'] . '</p>';
    echo '<p>Dateigröße: ' . $info['filesize_formatted'] . '</p>';
    echo '</div>';
}
?>
```

### Vollständige Video-Analyse

```php
<?php
$videoFile = 'REX_MEDIA[1]';
$info = rex_ffmpeg_video_info::getInfo($videoFile);

if ($info) {
    // Video-Stream Daten
    $video = $info['video'];
    echo '<p>Codec: ' . $video['codec_name'] . '</p>';
    echo '<p>Framerate: ' . $info['framerate'] . ' fps</p>';
    echo '<p>Bitrate: ' . $info['bitrate_formatted'] . '</p>';
    
    // Audio-Stream Daten (falls vorhanden)
    if ($info['audio']) {
        $audio = $info['audio'];
        echo '<p>Audio-Codec: ' . $audio['codec_name'] . '</p>';
        echo '<p>Kanäle: ' . $audio['channels'] . '</p>';
    }
}
?>
```

### Schnelle Einzelwerte

```php
<?php
$videoFile = 'REX_MEDIA[1]';

// Nur die Dauer ermitteln (schnell)
$duration = rex_ffmpeg_video_info::getDuration($videoFile);
echo 'Dauer: ' . $duration . ' Sekunden';

// Nur das Seitenverhältnis
$ratio = rex_ffmpeg_video_info::getAspectRatio($videoFile);
echo 'Format: ' . $ratio;
?>
```

### Optimierungs-Check für responsives Design

```php
<?php
$videoFile = 'REX_MEDIA[1]';

// Prüfen ob Video web-optimiert ist
$status = rex_ffmpeg_video_info::getOptimizationStatus($videoFile);

if ($status['optimized']) {
    echo '<span class="badge badge-success">Web-optimiert</span>';
} else {
    echo '<span class="badge badge-warning">Nicht optimiert</span>';
    echo '<ul>';
    foreach ($status['recommendations'] as $rec) {
        echo '<li>' . $rec . '</li>';
    }
    echo '</ul>';
}

echo '<p>Optimierungs-Score: ' . $status['score'] . '/100</p>';
?>
```

### Mobile-Kompatibilität prüfen

```php
<?php
$videoFile = 'REX_MEDIA[1]';

if (rex_ffmpeg_video_info::isMobileOptimized($videoFile)) {
    echo '<i class="fa fa-mobile"></i> Mobil-optimiert';
} else {
    echo '<i class="fa fa-desktop"></i> Desktop-Version';
}
?>
```

## Template-Beispiele

### Video-Galerie mit technischen Details

```php
<?php
// Videos aus einer Kategorien laden
$videos = rex_media::getMediaByCategory(5); // Kategorie-ID

foreach ($videos as $video) {
    if (strpos($video->getType(), 'video/') === 0) {
        $info = rex_ffmpeg_video_info::getBasicInfo($video->getFileName());
        
        if ($info) {
            echo '<div class="video-item">';
            echo '<video controls>';
            echo '<source src="' . rex_url::media($video->getFileName()) . '" type="video/mp4">';
            echo '</video>';
            echo '<div class="video-meta">';
            echo '<span>' . $info['duration_formatted'] . '</span>';
            echo '<span>' . $info['aspect_ratio'] . '</span>';
            echo '<span>' . $info['filesize_formatted'] . '</span>';
            echo '</div>';
            echo '</div>';
        }
    }
}
?>
```

### Responsive Video mit Fallback

```php
<?php
$videoFile = 'REX_MEDIA[1]';
$info = rex_ffmpeg_video_info::getBasicInfo($videoFile);

if ($info) {
    // CSS-Klasse basierend auf Seitenverhältnis
    $aspectClass = '';
    switch ($info['aspect_ratio']) {
        case '16:9':
            $aspectClass = 'video-widescreen';
            break;
        case '9:16':
            $aspectClass = 'video-portrait';
            break;
        case '1:1':
            $aspectClass = 'video-square';
            break;
        default:
            $aspectClass = 'video-standard';
    }
    
    echo '<div class="video-container ' . $aspectClass . '">';
    echo '<video controls>';
    echo '<source src="' . rex_url::media($videoFile) . '" type="video/mp4">';
    echo 'Ihr Browser unterstützt das Video-Element nicht.';
    echo '</video>';
    echo '</div>';
}
?>
```

### Performance-Check vor Ausgabe

```php
<?php
$videoFile = 'REX_MEDIA[1]';
$info = rex_ffmpeg_video_info::getBasicInfo($videoFile);

if ($info) {
    // Warnung bei großen Dateien
    if ($info['filesize'] > 10 * 1024 * 1024) { // Größer als 10MB
        echo '<div class="alert alert-warning">';
        echo 'Achtung: Große Video-Datei (' . $info['filesize_formatted'] . '). ';
        echo 'Ladezeit kann länger sein.';
        echo '</div>';
    }
    
    // Video ausgeben
    echo '<video controls>';
    echo '<source src="' . rex_url::media($videoFile) . '" type="video/mp4">';
    echo '</video>';
}
?>
```

## API-Referenz

### Verfügbare Methoden

- `getInfo($filename)` - Vollständige Video-Informationen
- `getBasicInfo($filename)` - Grundlegende Informationen für Templates
- `getDuration($filename)` - Nur Video-Dauer (schnell)
- `getAspectRatio($filename)` - Nur Seitenverhältnis
- `getOptimizationStatus($filename)` - Web-Optimierung prüfen
- `isMobileOptimized($filename)` - Mobile-Kompatibilität

### Rückgabe-Format

```php
// getBasicInfo() Rückgabe:
[
    'filename' => 'video.mp4',
    'duration' => 120.5,
    'duration_formatted' => '02:00',
    'width' => 1920,
    'height' => 1080,
    'aspect_ratio' => '16:9',
    'filesize' => 15728640,
    'filesize_formatted' => '15 MB',
    'codec' => 'h264'
]
```

### Fehlerbehandlung

Alle Methoden geben `null` zurück, wenn:
- Die Video-Datei nicht existiert
- FFmpeg nicht verfügbar ist
- Die Datei beschädigt ist

```php
<?php
$info = rex_ffmpeg_video_info::getBasicInfo('nicht_vorhanden.mp4');
if ($info === null) {
    echo 'Video konnte nicht analysiert werden.';
}
?>
```
