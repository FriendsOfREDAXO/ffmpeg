<?php

namespace FriendsOfRedaxo\FFmpeg;

use FriendsOfRedaxo\FFmpeg\Api\Converter;
use FriendsOfRedaxo\FFmpeg\Cronjob\AutoOptimizeCronjob;
use FriendsOfRedaxo\FFmpeg\Job\VideoJob;
use rex_addon;
use rex_file;
use rex_string;

/**
 * Warteschlange für eigene Workflows (z. B. aus einem kleinen Projekt-AddOn): Aufträge vormerken, sie laufen
 * nacheinander – es läuft immer nur eine Konvertierung gleichzeitig. Weiter geht es nach jedem fertigen Auftrag,
 * beim Cronjob „FFmpeg: Videos automatisch optimieren“ und mit `php redaxo/bin/console ffmpeg:optimize --wait`.
 *
 * Beispiel – drei Fassungen nach jedem Upload:
 *
 *     rex_extension::register('MEDIA_ADDED', static function (rex_extension_point $ep): void {
 *         $file = (string) $ep->getParam('filename');
 *         if (!VideoInfo::isSupportedVideoFile($file)) {
 *             return;
 *         }
 *         foreach (['480p', '720p', '1080p'] as $size) {
 *             Queue::add($file, Presets::build($size), pathinfo($file, PATHINFO_FILENAME) . '-' . $size . '.mp4');
 *         }
 *     });
 *
 * Kommandos enthalten die Platzhalter INPUT und OUTPUT.<endung> (siehe Presets::build()). Sie kommen nur aus PHP-Code,
 * nie aus Formularen.
 *
 * Auch die Buttons im Backend („Optimieren“, „Webversion erstellen“) reihen ein, solange eine Konvertierung läuft
 * ({@see self::addRequest()}). Gespeichert wird dann nur, was angeklickt wurde (Modus, Variante, Größe) – das Kommando
 * entsteht erst beim Start aus den Einstellungen, genau wie bei einem direkten Klick.
 */
final class Queue
{
    /**
     * Auftrag vormerken (und gleich starten, wenn gerade nichts läuft)
     *
     * @param string $video Dateiname im Medienpool
     * @param string $command ffmpeg-Kommando mit INPUT / OUTPUT.<endung>, z. B. Presets::build('720p')
     * @param string|null $output Dateiname der neuen Fassung im Medienpool; null = Original ersetzen
     * @param array<string, mixed> $extra wird im Auftrag gespeichert und in FFMPEG_JOB_DONE mitgegeben
     * @return string Kennung des Auftrags
     */
    public static function add(string $video, string $command, ?string $output = null, array $extra = [], string $login = 'ffmpeg-queue'): string
    {
        if (!str_contains($command, 'INPUT') || 1 !== preg_match('/\sOUTPUT\.[a-z0-9]{2,4}\b/i', $command)) {
            throw new \InvalidArgumentException('FFmpeg-Queue: das Kommando braucht die Platzhalter INPUT und OUTPUT.<endung>.');
        }
        if (null !== $output) {
            $output = rex_string::normalize(pathinfo($output, PATHINFO_FILENAME), '_', '-') . '.' . strtolower(pathinfo($output, PATHINFO_EXTENSION) ?: 'mp4');
        }
        $id = sprintf('%.6F', microtime(true)) . '_' . bin2hex(random_bytes(4));
        rex_file::put(self::path($id), (string) json_encode([
            'id' => $id, 'video' => $video, 'command' => $command, 'output' => $output, 'extra' => $extra, 'login' => $login,
        ]));
        // damit es auch ohne offenes Backend weitergeht
        AutoOptimizeCronjob::ensure(true);
        self::run();

        return $id;
    }

    /**
     * Klick im Backend vormerken, während eine Konvertierung läuft. Derselbe Klick (Video, Modus, Variante, Größe)
     * steht höchstens einmal in der Warteschlange.
     *
     * @param string $mode new_file | overwrite
     * @return string Kennung des Auftrags
     */
    public static function addRequest(string $video, string $mode, bool $createNew, string $preset, string $login): string
    {
        $request = ['mode' => $mode, 'create_new' => $createNew, 'preset' => $createNew ? $preset : ''];
        foreach (self::pending() as $item) {
            if ($item['video'] === $video && ($item['request'] ?? null) == $request) {
                return $item['id'];
            }
        }
        $id = sprintf('%.6F', microtime(true)) . '_' . bin2hex(random_bytes(4));
        rex_file::put(self::path($id), (string) json_encode([
            'id' => $id, 'video' => $video, 'request' => $request, 'output' => null, 'extra' => [], 'login' => $login,
        ]));
        AutoOptimizeCronjob::ensure(true);

        return $id;
    }

    /** Position in der Warteschlange (1 = als Nächstes), 0 = nicht (mehr) enthalten */
    public static function position(string $id): int
    {
        foreach (self::pending() as $i => $item) {
            if ($item['id'] === $id) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * Wartende Aufträge für die Anzeige im Backend
     *
     * @return list<array{id: string, video: string, mode: string, position: int, login: string}>
     */
    public static function overview(): array
    {
        $list = [];
        foreach (self::pending() as $i => $item) {
            $list[] = [
                'id' => $item['id'],
                'video' => $item['video'],
                'mode' => (string) ($item['request']['mode'] ?? (null === $item['output'] ? 'overwrite' : 'new_file')),
                'position' => $i + 1,
                'login' => $item['login'],
            ];
        }

        return $list;
    }

    /** @return array{id: string, video: string, command?: string, request?: array{mode: string, create_new: bool, preset: string}, output: ?string, extra: array<string, mixed>, login: string}|null */
    public static function get(string $id): ?array
    {
        $item = is_file(self::path($id)) ? json_decode((string) rex_file::get(self::path($id)), true) : null;

        return is_array($item) ? $item : null;
    }

    /** @return list<array{id: string, video: string, command?: string, request?: array{mode: string, create_new: bool, preset: string}, output: ?string, extra: array<string, mixed>, login: string}> */
    public static function pending(): array
    {
        $files = glob(rex_addon::get('ffmpeg')->getDataPath('queue_*.json')) ?: [];
        sort($files);
        $list = [];
        foreach ($files as $file) {
            $item = json_decode((string) rex_file::get($file), true);
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    public static function remove(string $id): void
    {
        @unlink(self::path($id));
    }

    /**
     * Nächsten Auftrag starten, falls gerade keine Konvertierung läuft
     *
     * @return array{status: string, message: string, video?: string, job?: string}|null null = nichts vorgemerkt oder belegt
     */
    public static function run(): ?array
    {
        if ([] === self::pending()) {
            return null;
        }
        // nicht zwei Aufträge gleichzeitig starten, wenn zwei Anfragen (Polling, Cronjob) zugleich hier ankommen
        $lock = fopen(rex_addon::get('ffmpeg')->getDataPath('queue.lock'), 'c');
        if (false === $lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        try {
            return self::runLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{status: string, message: string, video?: string, job?: string}|null */
    private static function runLocked(): ?array
    {
        foreach (self::pending() as $item) {
            if (VideoJob::findActive()) {
                return null;
            }
            self::remove($item['id']);
            if (!\rex_media::get($item['video'])) {
                continue;
            }
            try {
                $result = isset($item['request']) ? Converter::startRequest(
                    $item['video'],
                    (string) $item['request']['mode'],
                    (bool) $item['request']['create_new'],
                    (string) $item['request']['preset'],
                    $item['login'],
                ) : Converter::startJobWithCommand(
                    $item['video'],
                    (string) $item['command'],
                    $item['login'],
                    // Quelle nie löschen (Einstellung „Quell-Datei löschen“ gilt nur für die Webversion-Buttons)
                    ['queue' => $item['id'], 'keep_source' => true, 'output' => $item['output']] + (array) $item['extra'],
                    null !== $item['output'] ? 'new_file' : 'overwrite',
                );
            } catch (\Throwable $e) {
                \rex_logger::logException($e);
                continue;
            }
            if ('busy' === ($result['status'] ?? '')) {
                // wieder einreihen (vorne)
                rex_file::put(self::path($item['id']), (string) json_encode($item));

                return null;
            }
            if ('started' === ($result['status'] ?? '')) {
                return ['status' => 'started', 'message' => $item['video'] . ' → ' . ($item['output'] ?? $item['video']), 'video' => $item['video'], 'job' => (string) $result['job']];
            }
        }

        return null;
    }

    private static function path(string $id): string
    {
        return rex_addon::get('ffmpeg')->getDataPath('queue_' . preg_replace('/[^0-9a-f._]/', '', $id) . '.json');
    }
}
