<?php

namespace FriendsOfRedaxo\FFmpeg\Job;

/**
 * Merkt sich, dass eine Datei per 'overwrite'-Modus optimiert wurde (siehe
 * Converter::finalizeOverwrite()) -- im Gegensatz zum bisherigen "new_file"-
 * Modus gibt es dabei keine separate "web_<name>"-Begleitdatei mehr, an der
 * die Video-Liste (mediapool.ffmpeg.main.php) "bereits optimiert" erkennen
 * koennte. Speichert pro Datei einen Fingerabdruck (Groesse + updatedate zum
 * Zeitpunkt der Optimierung) statt nur eines nackten Flags: aendert sich die
 * Datei danach (erneuter Upload, Crop, manuelle Ersetzung), stimmt der
 * Fingerabdruck nicht mehr ueberein und isOptimized() liefert automatisch
 * wieder false -- kein Invalidierungs-Hook an anderer Stelle noetig.
 */
class OptimizedVideoRegistry
{
    public static function markOptimized(string $filename, int $originalSize, int $newSize, int $updatedate): void
    {
        \rex_file::put(self::path($filename), (string) json_encode([
            'originalSize' => $originalSize,
            'filesize' => $newSize,
            'updatedate' => $updatedate,
        ]));
    }

    /**
     * @return array{originalSize: int}|null Nur bei gueltigem (nicht veraltetem) Fingerabdruck.
     */
    public static function getIfCurrent(string $filename, int $currentFilesize, int $currentUpdatedate): ?array
    {
        $path = self::path($filename);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) \rex_file::get($path), true);
        if (!is_array($data)) {
            return null;
        }
        if ((int) ($data['filesize'] ?? -1) !== $currentFilesize || (int) ($data['updatedate'] ?? -1) !== $currentUpdatedate) {
            // Datei hat sich seit der Optimierung veraendert (erneuter Upload,
            // Crop, ...) -- Fingerabdruck stimmt nicht mehr, veraltete Datei
            // gleich aufraeumen statt sie liegen zu lassen.
            @unlink($path);

            return null;
        }

        return ['originalSize' => (int) ($data['originalSize'] ?? 0)];
    }

    public static function forget(string $filename): void
    {
        @unlink(self::path($filename));
    }

    private static function path(string $filename): string
    {
        return \rex_addon::get('ffmpeg')->getDataPath('optimized_' . md5($filename) . '.json');
    }
}
