<?php

namespace FriendsOfRedaxo\FFmpeg;

use FriendsOfRedaxo\FFmpeg\Api\Converter;
use FriendsOfRedaxo\FFmpeg\Job\VideoJob;
use rex_addon;
use rex_dir;
use rex_file;
use rex_i18n;
use rex_media;
use rex_path;

/**
 * Schneiden (Schnitt-Editor im Video-Trimmer): Abschnitte, die erhalten bleiben, werden aneinandergesetzt.
 * Nach dem Vorbild des AddOns video_editor (REDAXO 6), ergänzt um die Funktionen des bisherigen Trimmers
 * (Ton entfernen, Ping-Pong-Schleife, Dateinamen „web_trimmed_…“).
 *
 *  - schnell: ohne Neukodierung (stream copy) – sofort fertig, Schnitt am Schlüsselbild davor
 *  - genau:   bildgenau, dafür neu kodiert (H.264/AAC bzw. VP9/Opus bei WebM)
 *  - Ping-Pong-Schleife: Ergebnis vorwärts + rückwärts (nur genau, ohne Ton – wie bisher)
 *
 * Läuft als Hintergrund-Auftrag über die Job-Engine des Converters; danach werden Untertitel mitgeschnitten.
 */
final class Cut
{
    /** kürzester Abschnitt in Sekunden */
    public const MIN = 0.1;

    /**
     * Abschnitte aus der Anfrage prüfen: innerhalb der Dauer, sortiert, überlappende zusammengefasst.
     *
     * @throws \rex_api_exception
     * @return list<array{0: float, 1: float}>
     */
    public static function segments(string $json, float $duration, bool $allowWhole = false): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_cut_invalid'));
        }
        $list = [];
        foreach ($raw as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                throw new \rex_api_exception(rex_i18n::msg('ffmpeg_cut_invalid'));
            }
            $start = max(0.0, min($duration, round((float) $pair[0], 3)));
            $end = max(0.0, min($duration, round((float) $pair[1], 3)));
            if ($end - $start >= self::MIN) {
                $list[] = [$start, $end];
            }
        }
        usort($list, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($list as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        if ([] === $merged) {
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_cut_empty'));
        }
        // nichts herausgeschnitten: nur sinnvoll, wenn trotzdem etwas passiert (Ton entfernen, Ping-Pong)
        if (!$allowWhole && 1 === count($merged) && $merged[0][0] < self::MIN && $merged[0][1] > $duration - self::MIN) {
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_cut_nothing'));
        }

        return $merged;
    }

    /**
     * Zeitpunkte der Schlüsselbilder (aus den Paketen, ohne zu dekodieren – auch bei langen Videos schnell)
     *
     * @return list<float>
     */
    public static function keyframes(string $path): array
    {
        $out = Shell::output('ffprobe -v error -select_streams v:0 -show_entries packet=pts_time,flags -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        $times = [];
        foreach (explode("\n", (string) $out) as $line) {
            $parts = explode(',', trim($line));
            if (isset($parts[1]) && str_contains($parts[1], 'K') && is_numeric($parts[0])) {
                $times[] = round((float) $parts[0], 3);
            }
        }
        sort($times);

        return array_values(array_unique($times));
    }

    /**
     * Schnell schneiden heißt: ab dem Schlüsselbild davor. Anfänge entsprechend vorziehen, Überlappungen zusammenfassen.
     *
     * @param list<array{0: float, 1: float}> $segments
     * @param list<float> $keyframes
     * @return list<array{0: float, 1: float}>
     */
    public static function snap(array $segments, array $keyframes): array
    {
        if ([] === $keyframes) {
            return $segments;
        }
        $snapped = [];
        foreach ($segments as [$start, $end]) {
            $key = 0.0;
            foreach ($keyframes as $k) {
                if ($k > $start + 0.001) {
                    break;
                }
                $key = $k;
            }
            $last = count($snapped) - 1;
            if ($last >= 0 && $key <= $snapped[$last][1]) {
                $snapped[$last][1] = max($snapped[$last][1], $end);
            } else {
                $snapped[] = [$key, $end];
            }
        }

        return $snapped;
    }

    /** @param list<array{0: float, 1: float}> $segments */
    public static function length(array $segments): float
    {
        $sum = 0.0;
        foreach ($segments as [$start, $end]) {
            $sum += $end - $start;
        }

        return $sum;
    }

    /** Endung des Ergebnisses: schnell wie das Original, genau als MP4/MOV/WebM bzw. sonst MP4 */
    public static function extension(string $source, bool $exact): string
    {
        $source = strtolower($source);

        return !$exact || in_array($source, ['mp4', 'm4v', 'mov', 'webm'], true) ? $source : 'mp4';
    }

    /**
     * Dateiname des Ergebnisses wie beim bisherigen Trimmer: „web_trimmed_<name>“, bei schon geschnittenen „…_new“,
     * Schleife mit „_loop“, bei Bedarf mit Zähler
     */
    public static function outputName(string $source, string $extension, bool $loop): string
    {
        $base = pathinfo($source, PATHINFO_FILENAME);
        if (str_starts_with($base, 'web_')) {
            $base = !str_contains($base, 'trimmed') ? 'web_trimmed_' . substr($base, 4) : $base . '_new';
        } else {
            $base = 'web_trimmed_' . $base;
        }
        if ($loop && !str_contains($base, '_loop')) {
            $base .= '_loop';
        }
        $name = $base . '.' . $extension;
        for ($i = 1; rex_media::get($name) || is_file(rex_path::media($name)); ++$i) {
            $name = $base . '_' . $i . '.' . $extension;
        }

        return $name;
    }

    /**
     * Auftrag starten
     *
     * @param list<array{0: float, 1: float}> $segments erhaltene Abschnitte
     * @return array<string, mixed> Antwort des Converters (status, job …)
     */
    public static function start(rex_media $media, array $segments, bool $exact, bool $audio, bool $loop, bool $replace, string $login): array
    {
        $source = $media->getFileName();
        $path = rex_path::media($source);
        if ($loop) {
            // Rückwärts geht nur mit Neukodierung, und wie bisher ohne Ton
            $exact = true;
            $audio = false;
        }
        if (!$exact) {
            $segments = self::snap($segments, self::keyframes($path));
        }
        $extension = self::extension($media->getExtension(), $exact);
        if ($replace && $extension !== strtolower($media->getExtension())) {
            throw new \rex_api_exception(rex_i18n::msg('ffmpeg_cut_replace_extension'));
        }
        $hasAudio = $audio && self::hasAudio($path);
        $tmpDir = rex_path::addonData('ffmpeg', 'cut_' . bin2hex(random_bytes(6)));
        $command = self::command($segments, $exact, $hasAudio, $loop, $extension, $tmpDir);
        $duration = self::length($segments) * ($loop ? 2 : 1);

        return Converter::startJobWithCommand($source, $command, $login, [
            'cut' => true,
            'segments' => $segments,
            'tmp' => $tmpDir,
            'duration' => $duration,
            'output' => $replace ? null : self::outputName($source, $extension, $loop),
        ], $replace ? 'overwrite' : 'new_file');
    }

    /**
     * Shell-Kommando mit den Platzhaltern INPUT / OUTPUT.<ext> (ersetzt der Converter); alle Argumente einzeln maskiert.
     *
     * @param list<array{0: float, 1: float}> $segments
     */
    public static function command(array $segments, bool $exact, bool $audio, bool $loop, string $extension, string $tmpDir): string
    {
        $mp4 = in_array($extension, ['mp4', 'm4v', 'mov'], true) ? ['-movflags', '+faststart'] : [];
        $q = static fn (array $args): string => implode(' ', array_map('escapeshellarg', $args));

        if ($exact) {
            // ein Durchgang: trim/atrim je Abschnitt, dann concat – bildgenau
            $filter = [];
            $pads = '';
            foreach ($segments as $i => [$start, $end]) {
                $filter[] = sprintf('[0:v:0]trim=start=%.3F:end=%.3F,setpts=PTS-STARTPTS[v%d]', $start, $end, $i);
                $pads .= '[v' . $i . ']';
                if ($audio) {
                    $filter[] = sprintf('[0:a:0]atrim=start=%.3F:end=%.3F,asetpts=PTS-STARTPTS[a%d]', $start, $end, $i);
                    $pads .= '[a' . $i . ']';
                }
            }
            $filter[] = $pads . 'concat=n=' . count($segments) . ':v=1:a=' . ($audio ? 1 : 0) . '[cv]' . ($audio ? '[a]' : '');
            if ($loop) {
                // Ping-Pong: vorwärts und rückwärts aneinander – nahtlose Schleife (wie der bisherige Trimmer)
                $filter[] = '[cv]split[pf][pr];[pr]reverse[prv];[pf][prv]concat=n=2:v=1:a=0[v]';
            } else {
                $filter[] = '[cv]null[v]';
            }
            $codec = 'webm' === $extension
                ? ['-c:v', 'libvpx-vp9', '-crf', '32', '-b:v', '0', '-row-mt', '1', '-deadline', 'good', '-cpu-used', '2', ...($audio ? ['-c:a', 'libopus', '-b:a', '128k'] : [])]
                : ['-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', ...($audio ? ['-c:a', 'aac', '-b:a', '160k'] : [])];

            return 'ffmpeg -y -i INPUT ' . $q(['-filter_complex', implode(';', $filter), '-map', '[v]', ...($audio ? ['-map', '[a]'] : ['-an']), ...$codec, ...$mp4]) . ' OUTPUT.' . $extension;
        }

        // schnell: jeden Abschnitt ohne Neukodierung herauskopieren, dann verlustfrei aneinanderhängen
        $copy = static fn (float $start, float $end): array => ['-ss', sprintf('%.3F', $start), '-i', '__IN__', '-t', sprintf('%.3F', $end - $start),
            '-map', '0:v:0', ...($audio ? ['-map', '0:a:0?'] : ['-an']), '-c', 'copy', '-avoid_negative_ts', 'make_zero', ...$mp4];
        if (1 === count($segments)) {
            return 'ffmpeg -y ' . str_replace("'__IN__'", 'INPUT', $q($copy($segments[0][0], $segments[0][1]))) . ' OUTPUT.' . $extension;
        }
        rex_dir::create($tmpDir);
        $steps = [];
        $parts = [];
        foreach ($segments as $i => [$start, $end]) {
            $parts[] = $part = $tmpDir . '/part-' . $i . '.' . $extension;
            $steps[] = 'ffmpeg -y ' . str_replace("'__IN__'", 'INPUT', $q($copy($start, $end))) . ' ' . escapeshellarg($part);
        }
        $list = $tmpDir . '/parts.txt';
        rex_file::put($list, implode("\n", array_map(static fn (string $p) => "file '" . str_replace("'", "'\\''", $p) . "'", $parts)) . "\n");
        $steps[] = 'ffmpeg -y ' . $q(['-f', 'concat', '-safe', '0', '-i', $list, '-map', '0', '-c', 'copy', ...$mp4]) . ' OUTPUT.' . $extension;

        // als Gruppe, damit die Ausgabe-Umleitung des Converters für alle Schritte gilt
        // Leerzeichen vor dem Semikolon: der Converter liest die Endung aus „OUTPUT.<ext>“
        return '{ ' . implode(' && ', $steps) . ' ; }';
    }

    /** Nach Abschluss des Auftrags: Untertitel mitschneiden, Zwischendateien entfernen (Converter::handleJobStatus()) */
    public static function afterJob(VideoJob $job): void
    {
        if (empty($job->extra['cut'])) {
            return;
        }
        if (!empty($job->extra['tmp']) && str_starts_with((string) $job->extra['tmp'], rex_path::addonData('ffmpeg'))) {
            rex_dir::delete((string) $job->extra['tmp']);
        }
        $result = (string) ($job->extra['result']['filename'] ?? '');
        if (VideoJob::STATUS_DONE !== $job->status || '' === $result) {
            return;
        }
        try {
            Tracks::afterCut($job->inputFile, $result, (array) ($job->extra['segments'] ?? []), $job->userLogin);
        } catch (\Throwable $e) {
            \rex_logger::logException($e);
        }
    }

    public static function hasAudio(string $path): bool
    {
        return '' !== trim((string) Shell::output('ffprobe -v error -select_streams a:0 -show_entries stream=index -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null'));
    }

    public static function clock(float $seconds): string
    {
        $s = (int) round($seconds);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
