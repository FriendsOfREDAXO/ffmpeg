<?php

namespace FriendsOfRedaxo\FFmpeg;

/**
 * Video-Einstellungen als Optionen statt frei eingegebener ffmpeg-Kommandos (ab 4.10.0).
 *
 * Jede Konvertierung entsteht aus drei Entscheidungen -- Größe, Qualität, Ton. Immer enthalten:
 *  - H.264 + AAC in MP4: spielt in allen Browsern
 *  - "-pix_fmt yuv420p": 10-Bit- oder 4:2:2/4:4:4-Quellen (Smartphones, Kameras) spielen sonst in
 *    vielen Browsern nicht ab
 *  - "-movflags +faststart": Index an den Dateianfang, das Video startet sofort, statt dass der
 *    Browser erst große Teile der Datei laden muss
 *  - nie vergrößern: kleinere Videos behalten ihre Größe, Hochformat bleibt Hochformat
 *
 * Aufbau nach dem Vorbild des video_editor-AddOns (REDAXO 6): jedes Argument einzeln maskiert,
 * nur die erste Bild- und Tonspur (Smartphone-Videos enthalten oft Metadaten-Spuren, an denen die
 * MP4-Ausgabe scheitert), Stereo-Ton, Lanczos-Skalierung, H.264 High Profile.
 *
 * Frei eingegebene Kommandos gibt es nicht mehr: sie waren fehleranfällig, für Redakteure nicht
 * verständlich und ein unnötiges Risiko (Shell-Kommando aus einem Formularfeld). Alte Einstellungen
 * werden beim Lesen auf die passenden Optionen abgebildet (self::settings()).
 */
class Presets
{
    public const SIZES = ['original', '1080p', '720p', '480p', '360p'];
    public const QUALITIES = ['high', 'balanced', 'small'];
    public const AUDIO = ['keep', 'normalize', 'remove'];

    /** Qualität => [CRF, x264-Preset]: niedrigere CRF = bessere Qualität, größere Datei */
    private const QUALITY = [
        'high' => [20, 'slow'],
        'balanced' => [23, 'medium'],
        'small' => [28, 'medium'],
    ];

    /** Voreinstellungen je Modus: Optimieren (Original ersetzen) / Webversion (neue Datei) */
    private const DEFAULTS = [
        // „ausgewogen“: von der Vorlage kaum zu unterscheiden, deutlich kleiner (bis 5.0.0 „hoch“ – das machte manche
        // Videos beim Optimieren größer)
        'overwrite' => ['size' => 'original', 'quality' => 'balanced', 'audio' => 'keep'],
        'new_file' => ['size' => '1080p', 'quality' => 'balanced', 'audio' => 'keep'],
    ];

    /** Presets bis 4.9 => Optionen (Abbildung alter Einstellungen) */
    private const LEGACY = [
        'standard' => ['size' => 'original', 'quality' => 'balanced'],
        'web' => ['size' => 'original', 'quality' => 'small'],
        'mobile' => ['size' => '720p', 'quality' => 'small'],
        'archive' => ['size' => 'original', 'quality' => 'high'],
        '1080p' => ['size' => '1080p', 'quality' => 'balanced'],
        '720p' => ['size' => '720p', 'quality' => 'balanced'],
        '480p' => ['size' => '480p', 'quality' => 'balanced'],
        '360p' => ['size' => '360p', 'quality' => 'balanced'],
    ];

    /** Verlustfrei umsortieren: Index an den Dateianfang, ohne neu zu kodieren (Sekunden statt Minuten) */
    public const FASTSTART_REMUX = "ffmpeg -y -i INPUT -map '0:v' -map '0:a?' -c copy -movflags +faststart OUTPUT.mp4";

    /**
     * ffmpeg-Kommando aus den Optionen (Platzhalter INPUT/OUTPUT.mp4 ersetzt Api\Converter)
     */
    public static function build(string $size, string $quality = 'balanced', string $audio = 'keep'): string
    {
        return 'ffmpeg -y -i INPUT ' . implode(' ', array_map('escapeshellarg', self::args($size, $quality, $audio))) . ' OUTPUT.mp4';
    }

    /**
     * Argumente zwischen Eingabe und Ausgabe (unmaskiert)
     *
     * @return list<string>
     */
    public static function args(string $size, string $quality = 'balanced', string $audio = 'keep'): array
    {
        [$crf, $speed] = self::QUALITY[$quality] ?? self::QUALITY['balanced'];
        $height = self::height($size);
        $filter = (null !== $height ? self::scaleFilter($height) . ',' : '') . 'format=yuv420p';

        $args = ['-map', '0:v:0', '-vf', $filter, '-c:v', 'libx264', '-preset', $speed, '-crf', (string) $crf, '-profile:v', 'high'];
        if ('remove' === $audio) {
            $args[] = '-an';
        } else {
            // "0:a:0?": Tonspur, falls vorhanden (Videos ohne Ton funktionieren weiter)
            array_push($args, '-map', '0:a:0?', '-c:a', 'aac', '-b:a', '128k', '-ac', '2');
            if ('normalize' === $audio) {
                // Lautheit wie bei Streaming-Diensten: -16 LUFS, Spitzen höchstens -1,5 dBTP
                array_push($args, '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11');
            }
        }
        array_push($args, '-movflags', '+faststart');

        return $args;
    }

    /**
     * Gespeicherte Optionen für einen Modus ('overwrite' | 'new_file'), alte Einstellungen
     * (preset_overwrite / preset_new_file bis 4.9) werden übernommen
     *
     * @return array{size: string, quality: string, audio: string}
     */
    public static function settings(string $mode): array
    {
        $addon = \rex_addon::get('ffmpeg');
        $mode = 'overwrite' === $mode ? 'overwrite' : 'new_file';
        $settings = self::DEFAULTS[$mode];
        $legacy = (string) $addon->getConfig('preset_' . $mode, '');
        if (isset(self::LEGACY[$legacy])) {
            $settings = self::LEGACY[$legacy] + $settings;
        }
        foreach (['size' => self::SIZES, 'quality' => self::QUALITIES, 'audio' => self::AUDIO] as $key => $allowed) {
            $value = (string) $addon->getConfig($mode . '_' . $key, '');
            if (in_array($value, $allowed, true)) {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    /** Kommando für einen Modus aus den gespeicherten Optionen */
    public static function forMode(string $mode): string
    {
        $s = self::settings($mode);

        return self::build($s['size'], $s['quality'], $s['audio']);
    }

    /**
     * Kommando für eine Größe (z. B. zusätzliche Variante, automatische Optimierung) -- Qualität und
     * Ton wie beim jeweiligen Modus eingestellt. Auch alte Preset-Namen (web, mobile …) werden
     * verstanden. null bei unbekanntem Namen.
     */
    public static function command(string $name, string $mode = 'new_file'): ?string
    {
        $s = self::settings($mode);
        if (in_array($name, self::SIZES, true)) {
            return self::build($name, $s['quality'], $s['audio']);
        }
        if (isset(self::LEGACY[$name])) {
            $legacy = self::LEGACY[$name];

            return self::build($legacy['size'], $legacy['quality'], $s['audio']);
        }

        return null;
    }

    /** Lesbare Zusammenfassung, z. B. „Full HD · Ausgewogen · mit Ton“ */
    public static function label(string $mode): string
    {
        $s = self::settings($mode);

        return \rex_i18n::msg('ffmpeg_size_' . $s['size']) . ' · ' . \rex_i18n::msg('ffmpeg_quality_' . $s['quality']) . ' · ' . \rex_i18n::msg('ffmpeg_audio_' . $s['audio']);
    }

    /** Zielhöhe einer Größe (1080p -> 1080), sonst null (Originalgröße) */
    public static function height(string $size): ?int
    {
        return 1 === preg_match('/^(\d+)p$/', $size, $m) ? (int) $m[1] : null;
    }

    /**
     * Skalierungsfilter für "<höhe>p": das Video passt danach in ein Quadrat mit der langen Seite
     * eines 16:9-Bildes dieser Höhe (1080 -> 1920). So wird Querformat höchstens 1920×1080,
     * Hochformat höchstens 1080×1920 -- und kleinere Videos bleiben unverändert (nie vergrößern,
     * "min(iw, …)"). force_divisible_by=2: gerade Maße für libx264/4:2:0. Die einfachen
     * Anführungszeichen sind für den Filter-Parser von ffmpeg (die Ausdrücke enthalten Kommas).
     */
    public static function scaleFilter(int $height): string
    {
        $long = self::longSide($height);

        return "scale='min(iw," . $long . ")':'min(ih," . $long . ")':force_original_aspect_ratio=decrease:force_divisible_by=2:flags=lanczos";
    }

    /** Lange Seite eines 16:9-Bildes mit dieser Höhe, gerade (1080 -> 1920, 480 -> 854) */
    public static function longSide(int $height): int
    {
        return (int) (2 * round($height * 16 / 9 / 2));
    }
}
