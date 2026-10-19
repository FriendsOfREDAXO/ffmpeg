<?php

namespace FriendsOfRedaxo\FFmpeg;

/**
 * Shell-Aufrufe für ffmpeg/ffprobe.
 *
 * Viele Hoster sperren shell_exec/exec/proc_open per disable_functions. Gesperrte Funktionen
 * sind in PHP 8 nicht definiert – ein Aufruf endet mit einem Fatal Error (Issue #18).
 * Diese Klasse prüft die Funktionen vorher und weicht auf die nächste erlaubte aus.
 * Ist keine erlaubt, liefert sie null bzw. einen Fehlercode, und FFmpeg gilt als nicht verfügbar.
 */
final class Shell
{
    /** Kann überhaupt ein Befehl ausgeführt werden? */
    public static function canRun(): bool
    {
        return self::has('exec') || self::has('shell_exec') || self::has('proc_open');
    }

    /** Kann ein Befehl im Hintergrund gestartet werden (Konvertierung)? */
    public static function canRunInBackground(): bool
    {
        if (str_starts_with(PHP_OS, 'WIN')) {
            return self::has('popen') && self::has('pclose');
        }
        return self::canRun();
    }

    /**
     * Führt einen Befehl aus und liefert die Ausgabe (stdout) oder null, wenn nichts ausgeführt werden konnte.
     */
    public static function output(string $command): ?string
    {
        if (self::has('shell_exec')) {
            $result = \shell_exec($command);
            return is_string($result) ? $result : null;
        }
        if (self::has('exec')) {
            $lines = [];
            \exec($command, $lines);
            return implode("\n", $lines);
        }
        if (self::has('proc_open')) {
            return self::procOpen($command)[0];
        }
        return null;
    }

    /**
     * Führt einen Befehl aus wie exec(): Ausgabezeilen in $output, Rückgabe ist der Exit-Code
     * (127, wenn kein Befehl ausgeführt werden konnte).
     *
     * @param list<string>|null $output
     */
    public static function run(string $command, ?array &$output = null): int
    {
        $output = [];
        if (self::has('exec')) {
            $code = 0;
            \exec($command, $output, $code);
            return $code;
        }
        if (self::has('proc_open')) {
            [$stdout, $code] = self::procOpen($command);
            $output = null === $stdout ? [] : explode("\n", rtrim($stdout, "\n"));
            return $code;
        }
        if (self::has('shell_exec')) {
            // Ohne Exit-Code: Marker anhängen und auswerten
            $result = \shell_exec($command . '; echo "__FFMPEG_EXIT:$?"');
            if (!is_string($result)) {
                return 127;
            }
            $lines = explode("\n", rtrim($result, "\n"));
            $last = (string) array_pop($lines);
            $output = $lines;
            return preg_match('/__FFMPEG_EXIT:(\d+)$/', $last, $m) ? (int) $m[1] : 0;
        }
        return 127;
    }

    /** Startet einen Befehl im Hintergrund; false, wenn das auf diesem Server nicht möglich ist. */
    public static function background(string $command): bool
    {
        if (str_starts_with(PHP_OS, 'WIN')) {
            if (!self::has('popen') || !self::has('pclose')) {
                return false;
            }
            \pclose(\popen($command, 'r'));
            return true;
        }
        $command .= ' > /dev/null 2>&1 &';
        if (self::has('shell_exec')) {
            \shell_exec($command);
            return true;
        }
        if (self::has('exec')) {
            \exec($command);
            return true;
        }
        if (self::has('proc_open')) {
            self::procOpen($command);
            return true;
        }
        return false;
    }

    /** Funktion vorhanden und nicht per disable_functions gesperrt? */
    private static function has(string $function): bool
    {
        static $cache = [];
        if (!isset($cache[$function])) {
            $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
            $cache[$function] = function_exists($function) && !in_array($function, $disabled, true);
        }
        return $cache[$function];
    }

    /** @return array{0: ?string, 1: int} stdout und Exit-Code */
    private static function procOpen(string $command): array
    {
        $process = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [null, 127];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [$stdout, \proc_close($process)];
    }
}
