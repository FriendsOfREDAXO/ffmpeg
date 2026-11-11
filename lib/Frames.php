<?php

namespace FriendsOfRedaxo\FFmpeg;

use rex_dir;
use rex_media;
use rex_path;
use rex_url;

/**
 * Vorschaubilder für die Zeitleiste des Schnitt-Editors: gleichmäßig verteilte Standbilder, einmal erzeugt und
 * zwischengespeichert (assets/addons/ffmpeg/frames, je Datei und Änderungsdatum). Nach dem Vorbild des AddOns
 * video_editor.
 */
final class Frames
{
    public const COUNT = 16;

    /** @return list<string> URLs der Bilder, leer ohne ffmpeg */
    public static function of(rex_media $video, float $duration): array
    {
        if ($duration <= 0 || !VideoInfo::isAvailable()) {
            return [];
        }
        $key = md5($video->getFileName() . '|' . $video->getUpdateDate() . '|' . $video->getSize());
        $dir = rex_path::addonAssets('ffmpeg', 'frames/' . $key);
        $files = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $files[] = sprintf('%02d.jpg', $i);
        }

        if (!is_file($dir . '/' . $files[self::COUNT - 1])) {
            self::prune();
            rex_dir::create($dir);
            // ein Aufruf: je Bild ein Eingang mit schnellem Sprung (-ss vor -i) und eine Ausgabe
            $args = ['-hide_banner', '-nostdin', '-y'];
            for ($i = 0; $i < self::COUNT; ++$i) {
                array_push($args, '-ss', sprintf('%.3F', ($i + 0.5) * $duration / self::COUNT), '-i', rex_path::media($video->getFileName()));
            }
            for ($i = 0; $i < self::COUNT; ++$i) {
                array_push($args, '-map', $i . ':v:0', '-frames:v', '1', '-vf', 'scale=-2:72', '-q:v', '5', $dir . '/' . $files[$i]);
            }
            if (0 !== Shell::run('ffmpeg ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1')) {
                return [];
            }
        }

        return array_map(static fn (string $file) => rex_url::addonAssets('ffmpeg', 'frames/' . $key . '/' . $file), $files);
    }

    /** Bilder, die eine Woche nicht neu erzeugt wurden, entfernen (ersetzte oder gelöschte Videos) */
    private static function prune(): void
    {
        foreach (glob(rex_path::addonAssets('ffmpeg', 'frames/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if ((int) filemtime($dir) < time() - 7 * 86400) {
                rex_dir::delete($dir);
            }
        }
    }
}
