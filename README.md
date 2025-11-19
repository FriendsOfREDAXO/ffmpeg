# REDAXO-AddOn: FFmpeg Video Tools v4.0

Vollständige Video-Management-Lösung für REDAXO CMS – Konvertierung, Trimming und detaillierte Video-Analyse, alles in einem Addon!

## 🔧 Voraussetzungen

- PHP-Funktion `exec` aktiviert (für FFmpeg-Kommandos)
- FFmpeg und FFprobe installiert und im PATH verfügbar
- REDAXO 5.18.1 oder höher
- PHP 8.1 oder höher
- **Wichtig:** Das VideoPreview-Addon muss deinstalliert werden (Funktionalität ist integriert)

## 🚀 Features im Überblick

### Video-Konverter
- **Hintergrundverarbeitung** – Browser schließen während der Konvertierung möglich
- **Intelligente Medienpool-Integration** mit `web_`-Prefix
- **Metadaten-Erhaltung** (Titel, Beschreibung, Copyright)
- **Kompressionsanzeige** zeigt eingesparten Speicherplatz
- **Auto-Cleanup** für Originaldateien nach erfolgreicher Konvertierung
- **🆕 Vorgefertigte Presets** für Web, Mobile, Archive und Standard-Konvertierungen
- **🆕 Command-Vorschau** zeigt das generierte FFmpeg-Kommando in Echtzeit
- **🆕 Direkte Command-Eingabe** ohne separate Textarea

### 🆕 Video-Trimmer
- **Präzises Schneiden** direkt im Browser
- **Verlustfreies Trimming** mit FFmpeg Stream-Copy
- **Intuitive Bedienung** mit Video-Player-Integration
- **Keyboard-Shortcuts** für professionellen Workflow
- **Alle Video-Typen** unterstützt (Original + web-optimiert)

### 🆕 Video-Informationen 
- **Detaillierte technische Analyse** aller Video-Parameter
- **Responsive Darstellung** mit Layout-Schutz
- **Optimierungsempfehlungen** für Web-Performance
- **Audio/Video-Stream-Details** komplett verfügbar

### 🆕 Video-Thumbnails (Media Manager)
- **Automatische Thumbnail-Generierung** aus Videos
- **Media Manager Integration** für responsive Bildgrößen
- **Konfigurierbarer Zeitpunkt** für Thumbnail-Extraktion
- **Fallback-Placeholder** bei FFmpeg-Problemen

### 🆕 PHP-API für Entwickler
- **Module-Integration** mit `VideoInfo` Klasse (`FriendsOfRedaxo\FFmpeg\VideoInfo`)
- **Performance-optimierte Methoden** für häufige Abfragen
- **Template-Integration** für Video-Galerien und responsive Player
- **Optimierungs-Checks** für automatische Qualitätsbewertung

## 📋 Workflow

### Video-Konverter
1. **Medienpool → Video-Tools → Video-Konverter** öffnen
2. Video aus der Liste auswählen
3. "Video konvertieren" klicken
4. **Browser schließen möglich** – Konvertierung läuft weiter
5. Später "Status prüfen" → Fertig optimiertes Video im Medienpool

### Video-Trimmer
1. **Medienpool → Video-Tools → Video-Trimmer** aufrufen
2. Video aus der Liste auswählen (alle Typen verfügbar)
3. **Video-Player nutzen** um Position zu finden
4. **Start-/Endzeit setzen**:
   - Position im Player pausieren
   - "Aktuelle Zeit setzen" klicken (oder Strg+S/E)
5. "Video schneiden" → Geschnittenes Video als `web_trimmed_*` gespeichert

### Video-Informationen
1. **Medienpool → Video-Tools → Video-Informationen** öffnen
2. Video aus der Liste auswählen
3. **Komplette technische Analyse** ansehen:
   - Auflösung, Bitrate, Codec, Framerate
   - Audio-Details, Seitenverhältnis
   - Optimierungsempfehlungen mit Score
4. **Direkter Zugang** zu Trimmer und Konverter

## 🔌 PHP-API für Module & Templates

### Basis-Verwendung

```php
<?php
use FriendsOfRedaxo\FFmpeg;

// Video-Informationen in Modulen abrufen
$videoFile = 'REX_MEDIA[1]';
$info = VideoInfo::getBasicInfo($videoFile);

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

### Schnelle Einzelwerte

```php
<?php
use FriendsOfRedaxo\FFmpeg;

$videoFile = 'REX_MEDIA[1]';

// Nur Dauer ermitteln (performance-optimiert)
$duration = VideoInfo::getDuration($videoFile);
echo 'Dauer: ' . $duration . ' Sekunden';

// Nur Seitenverhältnis
$ratio = VideoInfo::getAspectRatio($videoFile);
echo 'Format: ' . $ratio;
?>
```

### Web-Optimierung prüfen

```php
<?php
use FriendsOfRedaxo\FFmpeg;

$videoFile = 'REX_MEDIA[1]';
$status = VideoInfo::getOptimizationStatus($videoFile);

if ($status['optimized']) {
    echo '<span class="badge badge-success">Web-optimiert</span>';
} else {
    echo '<span class="badge badge-warning">Nicht optimiert</span>';
    foreach ($status['recommendations'] as $rec) {
        echo '<li>' . $rec . '</li>';
    }
}

echo '<p>Score: ' . $status['score'] . '/100</p>';
?>
```

### Responsive Video-Templates

```php
<?php
use FriendsOfRedaxo\FFmpeg;

$videoFile = 'REX_MEDIA[1]';
$info = VideoInfo::getBasicInfo($videoFile);

if ($info) {
    // CSS-Klasse basierend auf Seitenverhältnis
    $aspectClass = match($info['aspect_ratio']) {
        '16:9' => 'video-widescreen',
        '9:16' => 'video-portrait', 
        '1:1' => 'video-square',
        default => 'video-standard'
    };
    
    echo '<div class="video-container ' . $aspectClass . '">';
    echo '<video controls>';
    echo '<source src="' . rex_url::media($videoFile) . '" type="video/mp4">';
    echo '</video>';
    echo '</div>';
}
?>
```

### Video-Thumbnails generieren

```php
<?php
$videoFile = 'REX_MEDIA[1]';

// WebP-Vorschau (animiert) - benötigt Media Manager Typ mit 'rex_effect_video_to_webp'
$webpPreview = rex_media_manager::getUrl('video_webp', $videoFile);
echo '<img src="' . $webpPreview . '" alt="Video WebP Preview">';

// MP4-Vorschau (ohne Ton) - benötigt Media Manager Typ mit 'rex_effect_video_to_preview'
$mp4Preview = rex_media_manager::getUrl('video_preview', $videoFile);
echo '<video autoplay muted loop><source src="' . $mp4Preview . '" type="video/mp4"></video>';

// Kombination: WebP als Fallback für MP4
echo '<video autoplay muted loop poster="' . $webpPreview . '">';
echo '<source src="' . $mp4Preview . '" type="video/mp4">';
echo '<img src="' . $webpPreview . '" alt="Video Preview">';
echo '</video>';
?>
```

### Video-Galerie mit Thumbnails

```php
<?php
use FriendsOfRedaxo\FFmpeg;

// Video-Liste aus dem Medienpool
$sql = rex_sql::factory();
$videos = $sql->getArray('SELECT filename FROM rex_media WHERE filetype LIKE "video/%"');

echo '<div class="video-gallery">';
foreach ($videos as $video) {
    $filename = $video['filename'];
    $info = VideoInfo::getBasicInfo($filename);
    
    // WebP-Thumbnail für bessere Performance
    $thumbnail = rex_media_manager::getUrl('video_webp_thumb', $filename);
    
    if ($info && $thumbnail) {
        echo '<div class="video-item">';
        echo '<a href="' . rex_url::media($filename) . '" data-lightbox="videos">';
        echo '<img src="' . $thumbnail . '" alt="' . $info['filename'] . '">';
        echo '<div class="video-overlay">';
        echo '<span class="play-button">▶</span>';
        echo '<span class="duration">' . $info['duration_formatted'] . '</span>';
        echo '</div>';
        echo '</a>';
        echo '</div>';
    }
}
echo '</div>';
?>
```

### Verfügbare API-Methoden

- `getInfo($filename)` – Vollständige Video-Informationen
- `getBasicInfo($filename)` – Grundlegende Infos für Templates
- `getDuration($filename)` – Nur Video-Dauer (schnell)
- `getAspectRatio($filename)` – Nur Seitenverhältnis
- `getOptimizationStatus($filename)` – Web-Optimierung mit Score
- `isMobileOptimized($filename)` – Mobile-Kompatibilität prüfen

## ⚙️ Installation & Konfiguration

### FFmpeg Installation

**Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install ffmpeg
```

**CentOS/RHEL:**
```bash
sudo yum install epel-release
sudo yum install ffmpeg
```

**macOS (Homebrew):**
```bash
brew install ffmpeg
```

**Test der Installation:**
```bash
ffmpeg -version
ffprobe -version
```

### REDAXO-Konfiguration

1. **Addon installieren** über den Installer oder manuell
2. **Einstellungen konfigurieren** unter Video-Tools → Einstellungen:
   - FFmpeg-Kommando anpassen (falls nötig)
   - Auto-Cleanup aktivieren/deaktivieren

### Standard-Kommando
```bash
ffmpeg -y -i INPUT -vcodec h264 OUTPUT.mp4
```

## 🎛️ Keyboard-Shortcuts (Video-Trimmer)

- **Strg + S** – Aktuelle Position als Startzeit setzen
- **Strg + E** – Aktuelle Position als Endzeit setzen  
- **Leertaste** – Video pausieren/fortsetzen

## 🔧 Technische Details

### Unterstützte Video-Formate
- MP4, AVI, MOV, WMV, WebM, MKV
- Alle von FFmpeg unterstützten Formate

### Naming-Konventionen
- **Konvertierte Videos:** `web_originalname.mp4`
- **Getrimmte Videos:** `web_trimmed_originalname.mp4`
- **Duplikate:** Automatischer Counter-Suffix

### Performance-Optimierungen
- **Stream-Copy** für verlustfreies Trimming
- **Lazy Loading** der Video-Informationen
- **Caching** von FFprobe-Ergebnissen
- **Background Processing** für Konvertierungen

## 🐛 Troubleshooting

### "FFmpeg ist nicht verfügbar"
- FFmpeg Installation prüfen: `which ffmpeg`
- PATH-Variable korrekt gesetzt?
- PHP `exec()` Funktion verfügbar?

### "Konflikt mit VideoPreview-Addon"
- Das VideoPreview-Addon muss deinstalliert werden
- Alle Thumbnail-Funktionen sind jetzt im FFmpeg-Addon integriert
- Media Manager Effekt "Video-Vorschau" bleibt unverändert funktional

### Konvertierung startet nicht
- PHP-Zeitlimits erhöhen
- Disk-Space prüfen
- Dateiberechtigungen kontrollieren

### Videos werden nicht angezeigt
- MIME-Types in der Datenbank prüfen
- Browser-Unterstützung für Video-Format

## 📝 Changelog v4.0

### Neue Features
- ✅ **Video-Konverter Presets** – Vorgefertigte Konvertierungsvorlagen (Web, Mobile, Archive, Standard)
- ✅ **Command-Vorschau** – Echtzeit-Anzeige des generierten FFmpeg-Kommandos
- ✅ **Verbesserte UI** – Direkte Command-Eingabe ohne separate Textarea
- ✅ **Type Hints & Statische Analyse** – Vollständige PHPStan/PSalm-Kompatibilität
- ✅ **Bugfixes** – Preset-Override, Video-Mapping, Transparenzen behoben

### Verbesserungen
- ✅ Code-Qualität mit REDAXO Core Methods (`rex_file`, `rex_media_service`, `rex_logger`)
- ✅ Performance-Optimierungen in VideoInfo-Klasse
- ✅ Erweiterte Fehlerbehandlung und Logging
- ✅ Debug-Endpunkt für Konvertierungsprozesse

## 📄 Lizenz

Dieses Addon steht unter der MIT-Lizenz. Beiträge sind willkommen!

---

**Entwickelt für REDAXO CMS** – Weil gute Videos eine gute Plattform verdienen! 🎬
   - "Aktuelle Position setzen" klicken (oder Strg+S/E)
4. **"Video schneiden"** klicken
5. Geschnittenes Video wird automatisch als `web_trimmed_dateiname.mp4` gespeichert

### 🆕 Video-Thumbnails (Media Manager)
1. **Media Manager → Medientypen** aufrufen
2. **Neuen Typ erstellen** (z.B. "video_thumb")
3. **Effekt hinzufügen:** "Video Preview"
4. **Parameter konfigurieren:**
   - Zeitpunkt: `00:00:03` (3. Sekunde)
   - Breite: `320`
   - Höhe: `240`
5. **Templates nutzen:** `rex_media_manager::getUrl('video_thumb', 'mein_video.mp4')`

### 🆕 Video-Informationen
1. **Medienpool → Video-Konverter → Video-Informationen** aufrufen
2. Video aus der Liste **auswählen**
3. **Detaillierte Analyse** wird automatisch angezeigt:
   - Auflösung, Seitenverhältnis, Dauer
   - Video-/Audio-Codec-Details
   - Bitrate und Qualitäts-Metriken
   - Optimierungsempfehlungen
4. **Direkte Aktionen** verfügbar (Schneiden, Konvertieren)

## Power-Einstellungen

Hier wird's für Techies richtig interessant! Vollständige Kontrolle über die Konvertierungsparameter:

### Video-Konverter
```bash
ffmpeg -y -i INPUT -vcodec h264 -crf 23 -preset fast OUTPUT.mp4
```

### Video-Trimmer  
```bash
ffmpeg -y -ss START_TIME -t DURATION -i INPUT -c copy OUTPUT.mp4
```

### Video-Informationen
```bash
ffprobe -v quiet -print_format json -show_format -show_streams INPUT.mp4
```

Spiel mit den Optionen für unterschiedliche Szenarien:
- Max. Qualität für Produktvideos
- Ultraschnelles Laden für Landing Pages
- Platzsparende Archivierung für große Videokataloge
- Verlustfreies Schneiden für exakte Clips

## Fortgeschrittene Superkräfte

### Metadaten-Teleportation

Beim Konvertieren werden automatisch alle wichtigen Informationen übertragen – nichts geht verloren! Der Titel bekommt den stylischen Zusatz "weboptimiert", damit du sofort weißt, welche Version die optimierte ist.

### Ewige Konvertierung

Selbst wenn dein Rechner einen Bluescreen hat, dein Browser abstürzt oder die Putzfrau den Server-Stecker zieht (ok, das letzte vielleicht nicht) – sobald alles wieder läuft, kann der Prozess wiederaufgenommen werden!

### Live-Status-Tracking

Mit echtzeitähnlichen Updates siehst du genau, was gerade passiert:
- 🔄 Konvertierung läuft... 65%
- 📥 Importiere...
- ✅ Fertig! (Mit genauer Kompressionsrate)

## Tuning-Tipps für Geschwindigkeitsfanatiker

Hier ein paar ffmpeg-Geheimtipps für Spezialfälle:

- Ultra-Fast: `-preset ultrafast -crf 28` (für schnelle Previews)
- HD-Qualität: `-vf scale=1920:1080 -crf 18` (Bildschirmfüllend scharf)
- Audio-Optimierung: `-acodec aac -ab 128k -ac 2` (Stereo-Sound in Webqualität)
- Mobile-Fokus: `-vf scale=640:360 -crf 26` (kleine Dateien für unterwegs)

## API-Referenz für Codenerds

Die interne Power-API für alle, die programmieren wie Neo in Matrix:

### RESTful-Endpunkte

```
index.php?rex-api-call=ffmpeg_convert&func=FUNKTION&video=DATEINAME
```

#### `start` – Die Konvertierungsmaschine anwerfen
- Parameter: `video` (welcher Film soll in die Mangel?)
- Gibt dir eine persönliche Konversions-ID für späteres Tracking

#### `progress` – Wie weit ist die Maschine?
- Liefert Fortschritt in Prozent und aktuelle Log-Zeilen
- Perfekt für Progress-Bars und Live-Updates

#### `done` – Abpfiff und Import
- Holt das fertige Video ins Medienzentrum
- Überträgt alle Metadaten in einem Rutsch

#### `status` – Systemcheck
- Zeigt aktive Prozesse und deren Status
- Perfekt zum automatischen Wiederaufnehmen nach Unterbrechungen

## 👥 Credits

- **Lead Development:** Thomas Skerbis ([@skerbis](https://github.com/skerbis))
- **Original Version:** Joachim Dörr ([@joachimdoerr](https://github.com/joachimdoerr))
- **Konzept:** KLXM Crossmedia GmbH
- **Community:** Friends Of REDAXO

---

**Friends Of REDAXO** - https://github.com/FriendsOfREDAXO
