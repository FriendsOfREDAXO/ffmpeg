<?php

namespace FriendsOfRedaxo\FFmpeg;

use rex;
use rex_media;
use rex_sql;

/**
 * Verknüpfungen zwischen einem Video und daraus entstandenen Medien (Tabelle rex_ffmpeg_link):
 *  - track  Untertitel/Kapitel (WebVTT) mit Sprache und Art
 *
 * Aufbau wie im AddOn video_editor (REDAXO 6), damit sich Daten später übernehmen lassen.
 */
final class Links
{
    public const TRACK = 'track';

    public static function table(): string
    {
        return rex::getTable('ffmpeg_link');
    }

    public static function add(string $source, string $target, string $kind, ?string $label = null, ?string $lang = null, ?string $role = null): void
    {
        $sql = rex_sql::factory()->setTable(self::table());
        $sql->setValue('source', $source);
        $sql->setValue('target', $target);
        $sql->setValue('kind', $kind);
        $sql->setValue('label', $label);
        $sql->setValue('lang', $lang);
        $sql->setValue('role', $role);
        $sql->setValue('createdate', date('Y-m-d H:i:s'));
        $sql->insert();
    }

    /**
     * Verknüpfte Medien eines Videos (nur noch vorhandene)
     *
     * @return list<array{file: string, kind: string, label: ?string, lang: ?string, role: ?string}>
     */
    public static function of(string $source, ?string $kind = null): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT target, kind, label, lang, role FROM ' . self::table() . ' WHERE source = ?' . (null !== $kind ? ' AND kind = ?' : '') . ' ORDER BY id DESC',
            null !== $kind ? [$source, $kind] : [$source],
        );
        $list = [];
        foreach ($rows as $row) {
            if (rex_media::get((string) $row['target'])) {
                $list[] = [
                    'file' => (string) $row['target'],
                    'kind' => (string) $row['kind'],
                    'label' => '' !== (string) $row['label'] ? (string) $row['label'] : null,
                    'lang' => '' !== (string) $row['lang'] ? (string) $row['lang'] : null,
                    'role' => '' !== (string) $row['role'] ? (string) $row['role'] : null,
                ];
            }
        }

        return $list;
    }

    public static function updateTrack(string $source, string $target, string $lang, string $role, ?string $label): void
    {
        rex_sql::factory()->setQuery('UPDATE ' . self::table() . ' SET lang = ?, role = ?, label = ? WHERE source = ? AND target = ? AND kind = ?',
            [$lang, $role, $label, $source, $target, self::TRACK]);
    }

    public static function isTrack(string $target): bool
    {
        return [] !== rex_sql::factory()->getArray('SELECT id FROM ' . self::table() . ' WHERE target = ? AND kind = ? LIMIT 1', [$target, self::TRACK]);
    }

    public static function isLinked(string $target): bool
    {
        return [] !== rex_sql::factory()->getArray('SELECT id FROM ' . self::table() . ' WHERE target = ? LIMIT 1', [$target]);
    }

    public static function remove(string $source, string $target, string $kind): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source = ? AND target = ? AND kind = ?', [$source, $target, $kind]);
    }

    /** Medium gelöscht: alle Verknüpfungen von und zu ihm entfernen */
    public static function forget(string $filename): void
    {
        rex_sql::factory()->setQuery('DELETE FROM ' . self::table() . ' WHERE source = ? OR target = ?', [$filename, $filename]);
    }
}
