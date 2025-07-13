<?php

/**
 * FFmpeg Video Info Klasse
 * 
 * Ermöglicht das Auslesen von Video-Informationen in Modulen und Templates
 * 
 * @package redaxo\ffmpeg
 * @author KLXM Crossmedia GmbH
 */
class rex_ffmpeg_video_info
{
    /**
     * Video-Informationen für eine Datei ermitteln
     * 
     * @param string $filename Dateiname im Medienpool (z.B. "mein_video.mp4")
     * @return array|null Array mit Video-Informationen oder null bei Fehler
     */
    public static function getInfo($filename)
    {
        // Prüfen ob Datei existiert
        $videoPath = rex_path::media($filename);
        if (!file_exists($videoPath)) {
            return null;
        }
        
        // Prüfen ob FFmpeg verfügbar ist
        if (!self::isFFmpegAvailable()) {
            return null;
        }
        
        return self::extractVideoInfo($videoPath, $filename);
    }
    
    /**
     * Kurze Video-Informationen für Template-Verwendung
     * 
     * @param string $filename Dateiname im Medienpool
     * @return array|null Vereinfachte Video-Daten
     */
    public static function getBasicInfo($filename)
    {
        $info = self::getInfo($filename);
        if (!$info) {
            return null;
        }
        
        return [
            'filename' => $filename,
            'duration' => $info['duration'],
            'duration_formatted' => $info['duration_formatted'],
            'width' => $info['video']['width'] ?? 0,
            'height' => $info['video']['height'] ?? 0,
            'aspect_ratio' => $info['aspect_ratio'],
            'filesize' => $info['filesize'],
            'filesize_formatted' => $info['filesize_formatted'],
            'codec' => $info['video']['codec_name'] ?? 'unknown'
        ];
    }
    
    /**
     * Nur die Videodauer ermitteln (schnell)
     * 
     * @param string $filename Dateiname im Medienpool
     * @return float|null Dauer in Sekunden oder null
     */
    public static function getDuration($filename)
    {
        $videoPath = rex_path::media($filename);
        if (!file_exists($videoPath)) {
            return null;
        }
        
        // Schnelle Dauer-Abfrage mit ffprobe
        $cmd = 'ffprobe -v quiet -show_entries format=duration -of csv=p=0 ' . escapeshellarg($videoPath);
        $duration = shell_exec($cmd);
        
        return $duration ? (float) trim($duration) : null;
    }
    
    /**
     * Seitenverhältnis ermitteln
     * 
     * @param string $filename Dateiname im Medienpool
     * @return string|null Seitenverhältnis (z.B. "16:9") oder null
     */
    public static function getAspectRatio($filename)
    {
        $info = self::getBasicInfo($filename);
        return $info ? $info['aspect_ratio'] : null;
    }
    
    /**
     * Prüfen ob Video für Web optimiert ist
     * 
     * @param string $filename Dateiname im Medienpool
     * @return array Optimierungsstatus mit Empfehlungen
     */
    public static function getOptimizationStatus($filename)
    {
        $info = self::getInfo($filename);
        if (!$info) {
            return ['optimized' => false, 'recommendations' => ['Video konnte nicht analysiert werden']];
        }
        
        $recommendations = [];
        $optimized = true;
        
        // Video-Stream prüfen
        if (isset($info['video'])) {
            $width = $info['video']['width'];
            $height = $info['video']['height'];
            $codec = $info['video']['codec_name'] ?? '';
            
            // Auflösung prüfen
            if ($width > 1920) {
                $optimized = false;
                $recommendations[] = 'Auflösung zu hoch für Web (' . $width . 'x' . $height . ')';
            }
            
            // Codec prüfen
            if ($codec !== 'h264') {
                $optimized = false;
                $recommendations[] = 'Codec nicht optimal für Web (aktuell: ' . $codec . ')';
            }
        }
        
        // Bitrate prüfen
        if ($info['bitrate'] > 8000000) { // 8 Mbps
            $optimized = false;
            $recommendations[] = 'Bitrate zu hoch für Web (' . self::formatBitrate($info['bitrate']) . ')';
        }
        
        // Dateigröße prüfen (über 50MB problematisch)
        if ($info['filesize'] > 50 * 1024 * 1024) {
            $optimized = false;
            $recommendations[] = 'Dateigröße sehr groß (' . $info['filesize_formatted'] . ')';
        }
        
        if ($optimized) {
            $recommendations[] = 'Video ist optimal für Web-Verwendung';
        }
        
        return [
            'optimized' => $optimized,
            'recommendations' => $recommendations,
            'score' => self::calculateOptimizationScore($info)
        ];
    }
    
    /**
     * Prüfen ob Video im mobilen Format vorliegt
     * 
     * @param string $filename Dateiname im Medienpool
     * @return bool True wenn mobil-optimiert
     */
    public static function isMobileOptimized($filename)
    {
        $info = self::getBasicInfo($filename);
        if (!$info) {
            return false;
        }
        
        // Mobile Kriterien: Max 720p, unter 5MB, H.264
        return $info['height'] <= 720 && 
               $info['filesize'] < 5 * 1024 * 1024 && 
               $info['codec'] === 'h264';
    }
    
    /**
     * FFmpeg-Verfügbarkeit prüfen
     * 
     * @return bool True wenn FFmpeg verfügbar
     */
    private static function isFFmpegAvailable()
    {
        $output = shell_exec('ffmpeg -version 2>&1');
        return $output && strpos($output, 'ffmpeg version') !== false;
    }
    
    /**
     * Video-Informationen mit FFprobe extrahieren
     * 
     * @param string $videoPath Vollständiger Pfad zur Video-Datei
     * @param string $filename Original-Dateiname
     * @return array|null Video-Informationen
     */
    private static function extractVideoInfo($videoPath, $filename)
    {
        // FFprobe für detaillierte Video-Informationen verwenden
        $cmd = 'ffprobe -v quiet -print_format json -show_format -show_streams ' . escapeshellarg($videoPath);
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
            'filename' => $filename,
            'format' => $data['format'] ?? null,
            'video' => $videoStream,
            'audio' => $audioStream
        ];
        
        // Berechnete Werte hinzufügen
        if ($videoStream) {
            $info['duration'] = floatval($data['format']['duration'] ?? 0);
            $info['duration_formatted'] = self::formatDuration($info['duration']);
            $info['aspect_ratio'] = self::calculateAspectRatio($videoStream['width'], $videoStream['height']);
            $info['framerate'] = self::calculateFramerate($videoStream['r_frame_rate'] ?? '0/0');
            $info['filesize'] = intval($data['format']['size'] ?? 0);
            $info['filesize_formatted'] = rex_formatter::bytes($info['filesize']);
            $info['bitrate'] = intval($data['format']['bit_rate'] ?? 0);
            $info['bitrate_formatted'] = self::formatBitrate($info['bitrate']);
        }
        
        return $info;
    }
    
    /**
     * Dauer formatieren (HH:MM:SS oder MM:SS)
     */
    private static function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            return sprintf('%02d:%02d', $minutes, $seconds);
        }
    }
    
    /**
     * Seitenverhältnis berechnen
     */
    private static function calculateAspectRatio($width, $height)
    {
        if (!$width || !$height) return 'Unbekannt';
        
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
    
    /**
     * Framerate berechnen
     */
    private static function calculateFramerate($rFrameRate)
    {
        if (strpos($rFrameRate, '/') !== false) {
            list($num, $den) = explode('/', $rFrameRate);
            if ($den > 0) {
                return round($num / $den, 2);
            }
        }
        return 0;
    }
    
    /**
     * Bitrate formatieren
     */
    private static function formatBitrate($bitrate)
    {
        if ($bitrate >= 1000000) {
            return round($bitrate / 1000000, 1) . ' Mbps';
        } elseif ($bitrate >= 1000) {
            return round($bitrate / 1000, 1) . ' kbps';
        }
        return $bitrate . ' bps';
    }
    
    /**
     * Optimierungs-Score berechnen (0-100)
     */
    private static function calculateOptimizationScore($info)
    {
        $score = 100;
        
        if (isset($info['video'])) {
            // Auflösung (max 20 Punkte Abzug)
            if ($info['video']['width'] > 1920) {
                $score -= 20;
            } elseif ($info['video']['width'] > 1280) {
                $score -= 10;
            }
            
            // Codec (max 15 Punkte Abzug)
            if ($info['video']['codec_name'] !== 'h264') {
                $score -= 15;
            }
        }
        
        // Bitrate (max 20 Punkte Abzug)
        if ($info['bitrate'] > 10000000) {
            $score -= 20;
        } elseif ($info['bitrate'] > 8000000) {
            $score -= 10;
        }
        
        // Dateigröße (max 15 Punkte Abzug)
        if ($info['filesize'] > 100 * 1024 * 1024) {
            $score -= 15;
        } elseif ($info['filesize'] > 50 * 1024 * 1024) {
            $score -= 10;
        }
        
        return max(0, $score);
    }
}
