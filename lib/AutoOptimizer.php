<?php

namespace FriendsOfRedaxo\FFmpeg;

use FriendsOfRedaxo\FFmpeg\Api\Converter;
use FriendsOfRedaxo\FFmpeg\Job\OptimizedVideoRegistry;
use FriendsOfRedaxo\FFmpeg\Job\VideoJob;
use rex;
use rex_addon;
use rex_file;
use rex_media;
use rex_sql;

/**
 * Automatische Optimierung: rechnet neue Videos im Medienpool auf eine Standardgröße herunter
 * (Einstellungen → „Automatische Optimierung“). Läuft über den Cronjob „FFmpeg: Videos automatisch
 * optimieren“, den Konsolenbefehl `ffmpeg:optimize` und direkt nach jedem Upload.
 *
 * Grundsätze:
 *  - nie vergrößern (Presets::scaleFilter()), nur Videos, die es nötig haben: höher als die
 *    Standardgröße, kein H.264, zu hohe Bitrate, oder Farbformat, das Browser nicht abspielen
 *  - das Original wird nur ersetzt, wenn das Ergebnis kleiner ist -- außer das Original spielt in
 *    Browsern nicht zuverlässig ab (Codec/Farbformat), dann zählt die Abspielbarkeit; Titel und
 *    Metadaten bleiben
 *  - ein Video nach dem anderen (gleiche Sperre wie die manuellen Buttons, VideoJob::findActive())
 *  - jedes Video wird nur einmal geprüft; ändert sich die Datei (neuer Upload) oder die
 *    Standardgröße, erneut
 *  - nur mp4/m4v/mov: beim Ersetzen bleibt der Dateiname gleich, andere Container (webm) können
 *    nicht verlustfrei als H.264 unter demselben Namen gespeichert werden
 */
final class AutoOptimizer
{
    public const LOGIN = 'ffmpeg-auto';

    /** Erlaubte Standardgrößen (Presets, die nie vergrößern) */
    public const PRESETS = ['1080p', '720p', '480p'];

    private const EXTENSIONS = ['mp4', 'm4v', 'mov'];

    /** ab dieser Videobitrate (kbit/s) je Standardhöhe lohnt eine Neuberechnung */
    private const MAX_KBPS = [1080 => 6000, 720 => 3500, 480 => 1800];

    /** höchstens so viele Videos pro Lauf prüfen (ffprobe ist schnell, aber nicht kostenlos) */
    private const PROBES_PER_RUN = 25;

    public static function isEnabled(): bool
    {
        return (bool) rex_addon::get('ffmpeg')->getConfig('auto_enabled', false);
    }

    public static function preset(): string
    {
        $preset = (string) rex_addon::get('ffmpeg')->getConfig('auto_preset', '1080p');

        return in_array($preset, self::PRESETS, true) ? $preset : '1080p';
    }

    /**
     * Ein Durchgang: fertigen Job abschließen, sonst das nächste Video starten.
     *
     * @return array{status: string, message: string, video?: string, job?: string}
     *     status: disabled | unavailable | busy | started | finished | idle | error
     */
    public static function run(): array
    {
        if (!VideoInfo::isAvailable() || !Shell::canRunInBackground()) {
            return ['status' => 'unavailable', 'message' => 'FFmpeg ist auf diesem Server nicht nutzbar.'];
        }

        $finished = Converter::finalizePending();
        // vorgemerkte Aufträge (Queue, eigene Workflows) haben Vorrang
        $queued = Queue::run();
        if (null !== $queued) {
            return $queued;
        }
        if (!self::isEnabled()) {
            if (VideoJob::findActive() || [] !== Queue::pending()) {
                return ['status' => 'busy', 'message' => 'Es laufen vorgemerkte Aufträge.'];
            }

            return ['status' => 'disabled', 'message' => 'Automatische Optimierung ist ausgeschaltet.'];
        }

        $active = VideoJob::findActive();
        if ($active) {
            return ['status' => 'busy', 'message' => 'Es läuft bereits eine Konvertierung (' . $active->inputFile . ').', 'video' => $active->inputFile, 'job' => $active->id];
        }

        $probes = 0;
        foreach (self::candidates() as $media) {
            if ($probes++ >= self::PROBES_PER_RUN) {
                break;
            }
            $check = self::check($media);
            // Erweiterungspunkt: Entscheidung ändern (eigenes Array [Grund, kompatibel, umsortieren]) oder mit
            // null überspringen – z. B. wenn ein eigener Workflow das Video bereits bearbeitet
            $check = \rex_extension::registerPoint(new \rex_extension_point('FFMPEG_AUTO_OPTIMIZE', $check, [
                'media' => $media,
                'preset' => self::preset(),
            ]));
            if (!is_array($check) || !isset($check[0], $check[1], $check[2])) {
                self::markChecked($media, 'ok');
                continue;
            }
            [$reason, $compat, $remux] = $check;
            $result = Converter::startJobWithCommand(
                $media->getFileName(),
                // nur Fast Start fehlt: verlustfrei umsortieren statt neu kodieren
                $remux ? Presets::FASTSTART_REMUX : Presets::build(self::preset(), 'balanced', 'keep'),
                self::LOGIN,
                // compat: Original ist nicht webtauglich -> auch ersetzen, wenn das Ergebnis größer ist
                ['auto' => true, 'reason' => $reason, 'compat' => $compat],
            );
            if ('started' === ($result['status'] ?? '')) {
                return ['status' => 'started', 'message' => $media->getFileName() . ' wird optimiert (' . $reason . ').', 'video' => $media->getFileName(), 'job' => (string) $result['job']];
            }
            if ('busy' === ($result['status'] ?? '')) {
                return ['status' => 'busy', 'message' => (string) ($result['message'] ?? '')];
            }
            self::markChecked($media, 'error');

            return ['status' => 'error', 'message' => $media->getFileName() . ': ' . (string) ($result['message'] ?? 'Start fehlgeschlagen')];
        }

        if ($finished) {
            return ['status' => 'finished', 'message' => $finished->inputFile . ': ' . rtrim('' !== $finished->message ? $finished->message : 'optimiert', '.') . '.', 'video' => $finished->inputFile];
        }

        return ['status' => 'idle', 'message' => 'Keine Videos zu optimieren.'];
    }

    /**
     * Videos, die noch nicht geprüft/optimiert sind (neueste zuerst)
     *
     * @return \Generator<rex_media>
     */
    public static function candidates(): \Generator
    {
        $where = [];
        $params = [];
        foreach (self::EXTENSIONS as $i => $ext) {
            $where[] = 'filename LIKE :ext' . $i;
            $params['ext' . $i] = '%.' . $ext;
        }
        $sql = 'SELECT filename FROM ' . rex::getTable('media') . ' WHERE (' . implode(' OR ', $where) . ')';
        $since = (int) rex_addon::get('ffmpeg')->getConfig('auto_since', 0);
        if ('new' === rex_addon::get('ffmpeg')->getConfig('auto_scope', 'new') && $since > 0) {
            $sql .= ' AND createdate >= :since';
            $params['since'] = date('Y-m-d H:i:s', $since);
        }
        $sql .= ' ORDER BY createdate DESC';

        foreach (rex_sql::factory()->getArray($sql, $params) as $row) {
            $media = rex_media::get((string) $row['filename']);
            if (!$media || !is_file(\rex_path::media($media->getFileName()))) {
                continue;
            }
            if (null !== self::checked($media) || null !== OptimizedVideoRegistry::getIfCurrent($media->getFileName(), $media->getSize(), $media->getUpdateDate())) {
                continue;
            }
            yield $media;
        }
    }

    /** Anzahl der Videos, die noch auf die Prüfung/Optimierung warten (Einstellungsseite) */
    public static function pending(): int
    {
        return iterator_count(self::candidates());
    }

    /** Warum ein Video optimiert werden sollte -- null, wenn es schon passt */
    public static function reason(rex_media $media): ?string
    {
        return self::check($media)[0] ?? null;
    }

    /**
     * @return array{0: string, 1: bool, 2: bool}|null [Grund, nicht webtauglich (auch ersetzen, wenn
     *     nicht kleiner), nur umsortieren (Fast Start)]
     */
    private static function check(rex_media $media): ?array
    {
        $info = VideoInfo::getInfo($media->getFileName());
        $video = $info['video'] ?? null;
        if (!is_array($video)) {
            return null;
        }
        $height = (int) ($video['height'] ?? 0);
        $width = (int) ($video['width'] ?? 0);
        $target = (int) Presets::height(self::preset());
        // gleiche Regel wie Presets::scaleFilter(): die lange Seite zählt (Hochformat bleibt Hochformat)
        if (max($width, $height) > Presets::longSide($target)) {
            return [$width . '×' . $height . ' → ' . $target . 'p', false, false];
        }
        if ('h264' !== ($video['codec_name'] ?? '')) {
            return ['Codec ' . ($video['codec_name'] ?? '?') . ' → H.264', true, false];
        }
        if (!in_array($video['pix_fmt'] ?? 'yuv420p', ['yuv420p', 'yuvj420p'], true)) {
            return ['Farbformat ' . $video['pix_fmt'] . ' → yuv420p', true, false];
        }
        $kbps = (int) round(((int) ($video['bit_rate'] ?? $info['bitrate'] ?? 0)) / 1000);
        $limit = self::MAX_KBPS[$target] ?? 6000;
        if ($kbps > $limit) {
            return [$kbps . ' kbit/s → kleiner', false, false];
        }
        if (false === VideoInfo::hasFaststart($media->getFileName())) {
            return ['Fast Start (startet sofort im Browser)', true, true];
        }

        return null;
    }

    /** Aufgerufen beim Abschluss jedes Jobs (Converter::handleJobStatus()) */
    public static function afterJob(VideoJob $job): void
    {
        if (empty($job->extra['auto'])) {
            return;
        }
        $media = rex_media::get($job->inputFile);
        if (!$media) {
            return;
        }
        // nicht erneut versuchen: weder bei Fehler noch wenn das Original behalten wurde
        // (erfolgreich ersetzte Dateien merkt sich bereits OptimizedVideoRegistry)
        if (VideoJob::STATUS_ERROR === $job->status || !empty($job->extra['result']['kept'])) {
            self::markChecked($media, VideoJob::STATUS_ERROR === $job->status ? 'error' : 'kept');
        }
        \rex_logger::factory()->log('info', 'FFmpeg auto-optimize {video}: {status} {message}', ['video' => $job->inputFile, 'status' => $job->status, 'message' => $job->message]);
    }

    /** Ergebnis der Prüfung merken (Fingerabdruck wie OptimizedVideoRegistry: Größe + Änderungsdatum) */
    private static function markChecked(rex_media $media, string $result): void
    {
        rex_file::put(self::checkedPath($media->getFileName()), (string) json_encode([
            'result' => $result,
            // geprüft gegen diese Standardgröße -- wird sie geändert, wird neu geprüft
            'preset' => self::preset(),
            'filesize' => $media->getSize(),
            'updatedate' => $media->getUpdateDate(),
        ]));
    }

    private static function checked(rex_media $media): ?string
    {
        $path = self::checkedPath($media->getFileName());
        $data = is_file($path) ? json_decode((string) rex_file::get($path), true) : null;
        if (!is_array($data)) {
            return null;
        }
        if ((int) ($data['filesize'] ?? -1) !== $media->getSize() || (int) ($data['updatedate'] ?? -1) !== $media->getUpdateDate() || ($data['preset'] ?? '') !== self::preset()) {
            @unlink($path);

            return null;
        }

        return (string) ($data['result'] ?? 'ok');
    }

    private static function checkedPath(string $filename): string
    {
        return rex_addon::get('ffmpeg')->getDataPath('auto_' . md5($filename) . '.json');
    }
}
