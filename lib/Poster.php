<?php

namespace FriendsOfRedaxo\FFmpeg;

use rex;
use rex_i18n;
use rex_media;
use rex_media_cache;
use rex_path;
use rex_sql;

/**
 * Poster-Bild: Standbild an einer Stelle des Videos als JPEG im Medienpool (<name>_poster.jpg, in der Kategorie des
 * Videos), z. B. für <video poster="…">. Aus dem bisherigen Trimmer übernommen.
 */
final class Poster
{
    /** @throws \rex_api_exception */
    public static function capture(rex_media $video, float $time, string $login): string
    {
        if ($time < 0) {
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_trimmer_error_times'));
        }
        $base = pathinfo($video->getFileName(), PATHINFO_FILENAME) . '_poster';
        $file = $base . '.jpg';
        for ($i = 1; rex_media::get($file) || is_file(rex_path::media($file)); ++$i) {
            $file = $base . '_' . $i . '.jpg';
        }
        $path = rex_path::media($file);
        // -ss vor -i: schneller Sprung; genau genug, die Stelle wurde im Player gewählt
        $code = Shell::run(sprintf('ffmpeg -y -ss %.3F -i %s -frames:v 1 -q:v 2 %s 2>&1', $time, escapeshellarg(rex_path::media($video->getFileName())), escapeshellarg($path)), $output);
        if (0 !== $code || !is_file($path)) {
            @unlink($path);
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_trimmer_poster_error') . ': ' . implode(' ', array_slice((array) $output, -3)));
        }
        [$width, $height] = getimagesize($path) ?: [0, 0];
        $sql = rex_sql::factory()->setTable(rex::getTable('media'));
        $sql->setValues([
            'category_id' => $video->getCategoryId(),
            'attributes' => '',
            'filetype' => 'image/jpeg',
            'filename' => $file,
            'originalname' => $file,
            'filesize' => filesize($path),
            'width' => $width,
            'height' => $height,
            'title' => ($video->getTitle() ?: $video->getFileName()) . ' (Poster)',
        ]);
        // ab REDAXO 5.18 Pflichtfeld ohne Vorgabewert (der bisherige Trimmer scheiterte daran)
        if (\rex_sql_table::get(rex::getTable('media'))->hasColumn('revision')) {
            $sql->setValue('revision', 0);
        }
        $sql->addGlobalCreateFields($login);
        $sql->addGlobalUpdateFields($login);
        $sql->insert();
        rex_media_cache::deleteList($video->getCategoryId());
        rex_media::clearInstance($file);

        return $file;
    }
}
