<?php

namespace FriendsOfRedaxo\FFmpeg;

use function array_filter;
use function array_map;
use function array_reverse;
use function array_search;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function html_entity_decode;
use function implode;
use function intdiv;
use function is_array;
use function is_numeric;
use function max;
use function mb_check_encoding;
use function mb_convert_encoding;
use function mb_substr;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function round;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strip_tags;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function usort;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Übernommen aus dem AddOn video_editor (REDAXO 6), angepasst an PHP 8.1.
 *
 * Untertitel lesen, säubern und schreiben: WebVTT und SubRip (SRT) rein, sauberes WebVTT raus. Nur erlaubte
 * WebVTT-Tags, keine STYLE-/REGION-Blöcke, Zeiten in Millisekunden.
 *
 * Cue = ['start' => ms, 'end' => ms, 'text' => '…', 'settings' => '…']
 *
 * @phpstan-type Cue array{start: int, end: int, text: string, settings: string}
 */
final class Subtitles
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_CUES = 5000;
    public const ROLES = ['subtitles', 'captions', 'chapters'];

    /**
     * Datei-Inhalt einlesen.
     *
     * @return list<Cue>|null null, wenn das keine Untertitel sind
     */
    public static function parse(string $raw): ?array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return null;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = (string) @mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (str_contains($raw, "\0")) {
            return null;
        }
        $cues = [];
        $time = '(?:(\d{1,3}):)?(\d{1,2}):(\d{1,2})[.,](\d{1,3})';
        foreach (preg_split("~\n[ \t]*\n~", trim($raw)) ?: [] as $i => $block) {
            $lines = explode("\n", trim($block, "\n"));
            if (0 === $i && 1 === preg_match('~^WEBVTT\b~', $lines[0])) {
                array_shift($lines);
                while ([] !== $lines && !str_contains($lines[0], '-->')) {
                    array_shift($lines);
                }
                if ([] === $lines) {
                    continue;
                }
            }
            if (1 === preg_match('~^(NOTE|STYLE|REGION)\b~', $lines[0])) {
                continue;
            }
            if (!str_contains($lines[0], '-->') && isset($lines[1]) && str_contains($lines[1], '-->')) {
                array_shift($lines);
            }
            if (1 !== preg_match('~^' . $time . '\s*-->\s*' . $time . '(.*)$~', trim($lines[0]), $m)) {
                continue;
            }
            $cues[] = [
                'start' => self::ms($m[1], $m[2], $m[3], $m[4]),
                'end' => self::ms($m[5], $m[6], $m[7], $m[8]),
                'text' => implode("\n", array_slice($lines, 1)),
                'settings' => $m[9],
            ];
            if (count($cues) > self::MAX_CUES) {
                return null;
            }
        }

        return [] !== $cues ? self::clean($cues) : null;
    }

    /**
     * Cues aus einer Anfrage (Editor) oder Datei säubern: Zeiten gültig, Text bereinigt, sortiert, leere entfernt.
     *
     * @param array<mixed> $cues
     * @return list<Cue>
     */
    public static function clean(array $cues): array
    {
        $out = [];
        foreach (array_slice($cues, 0, self::MAX_CUES) as $cue) {
            if (!is_array($cue) || !is_numeric($cue['start'] ?? null) || !is_numeric($cue['end'] ?? null)) {
                continue;
            }
            $text = self::cleanText((string) ($cue['text'] ?? ''));
            if ('' === $text) {
                continue;
            }
            $start = max(0, min(359_999_999, (int) $cue['start']));
            $end = (int) $cue['end'] <= $start ? $start + 1000 : min(359_999_999, (int) $cue['end']);
            $out[] = ['start' => $start, 'end' => $end, 'text' => $text, 'settings' => self::cleanSettings((string) ($cue['settings'] ?? ''))];
        }
        usort($out, static fn (array $a, array $b) => $a['start'] <=> $b['start'] ?: $a['end'] <=> $b['end']);

        return $out;
    }

    /** @param list<Cue> $cues */
    public static function toVtt(array $cues): string
    {
        $out = "WEBVTT\n";
        foreach ($cues as $i => $cue) {
            $out .= "\n" . ($i + 1) . "\n" . self::stamp($cue['start']) . ' --> ' . self::stamp($cue['end'])
                . ('' !== $cue['settings'] ? ' ' . $cue['settings'] : '') . "\n" . $cue['text'] . "\n";
        }

        return $out;
    }

    /**
     * Zeiten nach einem Schnitt umrechnen: Cues in entfernten Teilen fallen weg, angeschnittene werden gekürzt, alles
     * danach rückt nach vorn.
     *
     * @param list<Cue> $cues
     * @param list<array{float, float}> $segments erhaltene Abschnitte in Sekunden
     * @return list<Cue>
     */
    public static function remap(array $cues, array $segments): array
    {
        $out = [];
        foreach ($cues as $cue) {
            $offset = 0;
            foreach ($segments as [$a, $b]) {
                $sa = (int) round($a * 1000);
                $sb = (int) round($b * 1000);
                $start = max($cue['start'], $sa);
                $end = min($cue['end'], $sb);
                // mindestens eine Viertelsekunde muss übrig bleiben, sonst blitzt der Text nur auf
                if ($end - $start >= 250) {
                    $out[] = ['start' => $start - $sa + $offset, 'end' => $end - $sa + $offset, 'text' => $cue['text'], 'settings' => $cue['settings']];
                }
                $offset += $sb - $sa;
            }
        }
        usort($out, static fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /** Sichtbarer Text eines Cues (ohne Tags, Sprecher als „Name: “) */
    public static function plain(string $text): string
    {
        return trim(html_entity_decode(strip_tags((string) preg_replace('~<v\s+([^>]+)>~u', '$1: ', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Cue-Text: nur erlaubte WebVTT-Tags, Entities normalisiert, höchstens 500 Zeichen und 4 Zeilen */
    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('~\{\\\\[^}]*\}~', '', $text);
        $text = str_replace('-->', '→', $text);
        $text = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text);
        $out = '';
        $open = [];
        foreach (preg_split('~(<[^>\n]{0,120}>)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if ('<' === $part[0] && str_ends_with($part, '>')) {
                if (1 === preg_match('~^<(\d{1,3}:)?\d{1,2}:\d{2}\.\d{3}>$~', $part)) {
                    $out .= $part;
                } elseif (1 === preg_match('~^</(b|i|u|c|v|lang|ruby|rt)>$~i', $part, $m)) {
                    $tag = strtolower($m[1]);
                    $k = array_search($tag, array_reverse($open, true), true);
                    if (false !== $k) {
                        unset($open[$k]);
                        $open = array_values($open);
                        $out .= '</' . $tag . '>';
                    }
                } elseif (1 === preg_match('~^<(b|i|u|ruby|rt)>$~i', $part, $m)) {
                    $open[] = strtolower($m[1]);
                    $out .= '<' . strtolower($m[1]) . '>';
                } elseif (1 === preg_match('~^<c((?:\.[A-Za-z0-9_-]{1,30}){0,4})>$~', $part, $m)) {
                    $open[] = 'c';
                    $out .= '<c' . $m[1] . '>';
                } elseif (1 === preg_match('~^<v(?:\.[A-Za-z0-9_-]{1,30})*\s+([^<>&]{1,60})>$~u', $part, $m)) {
                    $open[] = 'v';
                    $out .= '<v ' . trim($m[1]) . '>';
                } elseif (1 === preg_match('~^<lang\s+([a-zA-Z]{2,3}(?:-[A-Za-z0-9]{2,8})?)>$~', $part, $m)) {
                    $open[] = 'lang';
                    $out .= '<lang ' . $m[1] . '>';
                }
                // alles andere (HTML, <font>, <script> …) entfällt
                continue;
            }
            $out .= str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        foreach (array_reverse($open) as $tag) {
            $out .= '</' . $tag . '>';
        }
        $lines = array_values(array_filter(
            array_map(static fn (string $l) => trim((string) preg_replace('~[ \t]+~u', ' ', $l)), explode("\n", $out)),
            static fn (string $l) => '' !== $l,
        ));

        return mb_substr(implode("\n", array_slice($lines, 0, 4)), 0, 500);
    }

    private static function cleanSettings(string $settings): string
    {
        $out = [];
        foreach (preg_split('~\s+~', trim($settings)) ?: [] as $p) {
            if (1 === preg_match('~^(line|position|size):-?\d{1,3}(\.\d+)?%?(,(start|center|end|line-left|line-right|auto))?$~', $p)
                || 1 === preg_match('~^align:(start|center|end|left|right)$~', $p)) {
                $out[] = $p;
            }
        }

        return implode(' ', array_slice($out, 0, 4));
    }

    private static function ms(string $h, string $m, string $s, string $f): int
    {
        return (((int) $h * 60 + (int) $m) * 60 + (int) $s) * 1000 + (int) str_pad($f, 3, '0');
    }

    private static function stamp(int $ms): string
    {
        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3_600_000), intdiv($ms % 3_600_000, 60_000), intdiv($ms % 60_000, 1000), $ms % 1000);
    }
}
