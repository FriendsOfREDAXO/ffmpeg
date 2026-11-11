<?php

namespace FriendsOfRedaxo\FFmpeg;

use rex;
use rex_clang;
use rex_file;
use rex_i18n;
use rex_media;
use rex_media_cache;
use rex_mediapool;
use rex_path;
use rex_sql;
use rex_url;

/**
 * Untertitel und Kapitel eines Videos: WebVTT-Dateien im Medienpool, mit dem Video verknüpft (Sprache, Art,
 * Bezeichnung). Gespeichert wird immer gesäubertes WebVTT (Subtitles). Nach dem Vorbild des AddOns video_editor.
 *
 * Die Dateien werden direkt angelegt statt über rex_media_service::addMedia(): dessen MIME-Prüfung erwartet für .vtt
 * „text/vtt“, die Dateierkennung meldet für WebVTT aber meist „text/plain“.
 */
final class Tracks
{
    /**
     * Spuren eines Videos (Untertitel vor Kapiteln, dann nach Sprache)
     *
     * @return list<array{file: string, lang: string, role: string, label: ?string, name: string, cues: int, url: string}>
     */
    public static function of(string $video): array
    {
        $list = [];
        foreach (Links::of($video, Links::TRACK) as $link) {
            $media = rex_media::get($link['file']);
            if (!$media) {
                continue;
            }
            $list[] = [
                'file' => $link['file'],
                'lang' => $link['lang'] ?? '',
                'role' => $link['role'] ?? 'subtitles',
                'label' => $link['label'],
                'name' => self::name($link['lang'] ?? '', $link['role'] ?? 'subtitles', $link['label']),
                'cues' => count(self::read($link['file'])),
                'url' => self::url($media),
            ];
        }
        usort($list, static fn (array $a, array $b) => ['chapters' === $a['role'], $a['lang']] <=> ['chapters' === $b['role'], $b['lang']]);

        return $list;
    }

    /** @return list<array{start: int, end: int, text: string, settings: string}> */
    public static function read(string $track): array
    {
        $media = rex_media::get($track);
        $path = rex_path::media($track);
        if (!$media || !in_array(strtolower($media->getExtension()), ['vtt', 'srt'], true) || !is_file($path)) {
            return [];
        }

        return Subtitles::parse((string) rex_file::get($path)) ?? [];
    }

    /**
     * Spur speichern – neu angelegt (Dateiname aus Video, Sprache, Art) oder die Datei der vorhandenen ersetzt.
     *
     * @param list<array{start: int, end: int, text: string, settings: string}> $cues
     * @throws \rex_api_exception
     * @return string Dateiname der Spur
     */
    public static function save(rex_media $video, ?string $track, string $lang, string $role, ?string $label, array $cues, string $login): string
    {
        $lang = strtolower(trim($lang));
        if (1 !== preg_match('~^[a-z]{2,3}(-[a-z0-9]{2,8})?$~', $lang)) {
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_track_lang_invalid'));
        }
        if (!in_array($role, Subtitles::ROLES, true)) {
            $role = 'subtitles';
        }
        $label = null !== $label && '' !== trim($label) ? mb_substr(trim($label), 0, 60) : null;
        $vtt = Subtitles::toVtt($cues);

        if (null !== $track && '' !== $track) {
            $existing = rex_media::get($track);
            if (!$existing || !self::belongsTo($video->getFileName(), $track)) {
                throw new \rex_api_exception(rex_i18n::msg('ffmpeg_not_found'));
            }
            rex_file::put(rex_path::media($track), $vtt);
            $sql = rex_sql::factory()->setTable(rex::getTable('media'))->setWhere(['filename' => $track]);
            $sql->setValue('filesize', strlen($vtt));
            $sql->addGlobalUpdateFields($login);
            $sql->update();
            rex_media_cache::delete($track);
            Links::updateTrack($video->getFileName(), $track, $lang, $role, $label);

            return $track;
        }

        $suffix = match ($role) {
            'captions' => '-cc',
            'chapters' => '-chapters',
            default => '',
        };
        $file = rex_mediapool::filename(pathinfo($video->getFileName(), PATHINFO_FILENAME) . '-' . $lang . $suffix . '.vtt');
        rex_file::put(rex_path::media($file), $vtt);
        $sql = rex_sql::factory()->setTable(rex::getTable('media'));
        $sql->setValues([
            'category_id' => $video->getCategoryId(),
            'attributes' => '',
            'filetype' => 'text/vtt',
            'filename' => $file,
            'originalname' => $file,
            'filesize' => strlen($vtt),
            'width' => 0,
            'height' => 0,
            'title' => trim(($video->getTitle() ?: $video->getFileName()) . ' (' . self::name($lang, $role, $label) . ')'),
        ]);
        // ab REDAXO 5.18 Pflichtfeld ohne Vorgabewert
        if (\rex_sql_table::get(rex::getTable('media'))->hasColumn('revision')) {
            $sql->setValue('revision', 0);
        }
        $sql->addGlobalCreateFields($login);
        $sql->addGlobalUpdateFields($login);
        $sql->insert();
        rex_media_cache::deleteList($video->getCategoryId());
        // rex_media::get() hat beim Wählen des Namens „nicht vorhanden“ zwischengespeichert
        rex_media::clearInstance($file);
        Links::add($video->getFileName(), $file, Links::TRACK, $label, $lang, $role);

        return $file;
    }

    /** Spur vom Video lösen und die Datei löschen, sofern sie nirgends sonst verwendet wird */
    public static function remove(rex_media $video, string $track): void
    {
        if (!self::belongsTo($video->getFileName(), $track)) {
            return;
        }
        Links::remove($video->getFileName(), $track, Links::TRACK);
        if (Links::isLinked($track)) {
            return;
        }
        try {
            \rex_media_service::deleteMedia($track);
        } catch (\rex_api_exception) {
            // noch in Verwendung – dann bleibt die Datei im Medienpool
        }
    }

    /**
     * Nach dem Schneiden: Spuren mitnehmen – bei neuer Datei als eigene Spuren, beim Ersetzen die vorhandenen anpassen.
     *
     * @param list<array{0: float, 1: float}> $segments erhaltene Abschnitte in Sekunden
     */
    public static function afterCut(string $source, string $result, array $segments, string $login): void
    {
        // Titel wurde nach dem Anlegen gesetzt – frisch aus der Datenbank lesen
        rex_media::clearInstance($result);
        $target = rex_media::get($result);
        if (!$target) {
            return;
        }
        foreach (Links::of($source, Links::TRACK) as $link) {
            $cues = Subtitles::remap(self::read($link['file']), $segments);
            if ([] === $cues) {
                continue;
            }
            $replace = $result === $source;
            self::save($target, $replace ? $link['file'] : null, $link['lang'] ?? 'de', $link['role'] ?? 'subtitles', $link['label'], $cues, $login);
        }
    }

    /** Adresse für <track src> auf der Website (liefert text/vtt, siehe Api\Track) */
    public static function url(rex_media $track): string
    {
        // pfad-absolut (Basis aus der Server-Einstellung), damit es auf jeder Seite der Website und im Backend passt;
        // gleiche Domain wie die Seite, sonst lehnt der Browser <track> ab
        $base = '/' . trim((string) parse_url(rex::getServer(), PHP_URL_PATH), '/');

        return rtrim($base, '/') . '/index.php?' . http_build_query(['rex-api-call' => 'ffmpeg_track', 'file' => $track->getFileName(), 'v' => $track->getUpdateDate()], '', '&');
    }

    /** Bezeichnung für die Auswahl im Player, z. B. „Deutsch“, „English (CC)“, „Deutsch – Kapitel“ */
    public static function name(string $lang, string $role, ?string $label = null): string
    {
        if (null !== $label && '' !== trim($label)) {
            return trim($label);
        }
        $names = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands',
            'pl' => 'Polski', 'tr' => 'Türkçe', 'ar' => 'العربية', 'ru' => 'Русский', 'uk' => 'Українська', 'pt' => 'Português',
            'el' => 'Ελληνικά', 'sl' => 'Slovenščina', 'hr' => 'Hrvatski', 'cs' => 'Čeština', 'da' => 'Dansk', 'sv' => 'Svenska'];
        foreach (rex_clang::getAll() as $clang) {
            $names[$clang->getCode()] ??= $clang->getName();
        }
        $name = $names[substr($lang, 0, 2)] ?? strtoupper($lang);

        return match ($role) {
            'captions' => $name . ' (CC)',
            'chapters' => $name . ' – ' . rex_i18n::msg('ffmpeg_track_role_chapters'),
            default => $name,
        };
    }

    private static function belongsTo(string $video, string $track): bool
    {
        foreach (Links::of($video, Links::TRACK) as $link) {
            if ($link['file'] === $track) {
                return true;
            }
        }

        return false;
    }
}
