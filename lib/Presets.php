<?php

namespace FriendsOfRedaxo\FFmpeg;

/**
 * Zentrale Preset-Definitionen -- vorher nur lokal in
 * mediapool.ffmpeg.config.php, jetzt auch von Api\Converter (Preset-Wahl
 * beim Anlegen einer zusaetzlichen Variante, siehe Issue #20) genutzt.
 */
class Presets
{
    /**
     * @return array<string, string> Preset-Name => ffmpeg-Kommando (mit
     *     INPUT/OUTPUT.<ext>-Platzhaltern)
     */
    public static function all(): array
    {
        return [
            // Bisherige Standard-Einstellung (behaelt altes Default-Kommando/Extension)
            'standard' => 'ffmpeg -y -i INPUT -vcodec h264 OUTPUT.mp4',
            'web' => 'ffmpeg -y -i INPUT -c:v libx264 -preset slow -crf 28 -c:a aac -b:a 128k OUTPUT.mp4',
            'mobile' => 'ffmpeg -y -i INPUT -c:v libx264 -preset veryfast -crf 30 -c:a aac -b:a 96k -vf scale=-2:720 OUTPUT.mp4',
            'archive' => 'ffmpeg -y -i INPUT -c:v libx264 -preset veryslow -crf 22 -c:a copy OUTPUT.mp4',

            // Aufloesungs-Presets (siehe Issue #20) -- "scale=-2:<hoehe>"
            // skaliert die Breite proportional, -2 rundet auf eine gerade
            // Zahl (von libx264 fuer 4:2:0-Chroma-Subsampling verlangt).
            // CRF/Preset pro Groesse abgestimmt: kleinere Ausgabe braucht
            // keinen hohen CRF-Wert fuer eine kleine Datei, daher ueberall
            // CRF 23 (visuell kaum verlustbehaftet) und ein schnelleres
            // x264-Preset fuer kleinere Aufloesungen (weniger Pixel pro
            // Frame -> Encoding-Zeit ohnehin kuerzer, "faster" lohnt sich
            // trotzdem beim mehrfachen Erzeugen mehrerer Varianten).
            '1080p' => 'ffmpeg -y -i INPUT -c:v libx264 -preset medium -crf 23 -c:a aac -b:a 128k -vf scale=-2:1080 OUTPUT.mp4',
            '720p' => 'ffmpeg -y -i INPUT -c:v libx264 -preset medium -crf 23 -c:a aac -b:a 128k -vf scale=-2:720 OUTPUT.mp4',
            '480p' => 'ffmpeg -y -i INPUT -c:v libx264 -preset faster -crf 23 -c:a aac -b:a 96k -vf scale=-2:480 OUTPUT.mp4',
            '360p' => 'ffmpeg -y -i INPUT -c:v libx264 -preset faster -crf 23 -c:a aac -b:a 96k -vf scale=-2:360 OUTPUT.mp4',
        ];
    }

    /**
     * Kommando fuer einen Preset-Namen, oder null wenn unbekannt/"custom"
     * (dann gilt die globale config['command']-Einstellung).
     */
    public static function command(string $presetName): ?string
    {
        return self::all()[$presetName] ?? null;
    }
}
