<?php

namespace FriendsOfRedaxo\FFmpeg\Api;

use FriendsOfRedaxo\FFmpeg\Cut;
use FriendsOfRedaxo\FFmpeg\Frames;
use FriendsOfRedaxo\FFmpeg\Poster;
use FriendsOfRedaxo\FFmpeg\Subtitles;
use FriendsOfRedaxo\FFmpeg\Tracks;
use FriendsOfRedaxo\FFmpeg\VideoInfo;
use rex;
use rex_api_exception;
use rex_api_function;
use rex_api_result;
use rex_clang;
use rex_i18n;
use rex_media;
use rex_path;
use rex_response;

/**
 * Schnitt- und Untertitel-Editor (POST, Backend, mit CSRF-Token): ?rex-api-call=ffmpeg_editor
 *
 *  - action=info          Dauer, Bildrate, Tonspur, Untertitel eines Videos
 *  - action=frames        Vorschaubilder und Schlüsselbilder für die Zeitleiste
 *  - action=cut           Schneiden: segments (JSON [[start, ende], …] in Sekunden, die bleiben), exact, audio, loop, mode
 *  - action=job           Fortschritt eines Auftrags (job)
 *  - action=poster        Standbild an der Stelle time (Sekunden) als Poster-Bild im Medienpool
 *  - action=track         Einträge einer Spur (track)
 *  - action=track_parse   VTT/SRT-Inhalt (content) einlesen und säubern – zum Übernehmen in den Editor
 *  - action=track_save    Spur speichern (track leer = neu, lang, role, label, cues JSON in ms)
 *  - action=track_delete  Spur lösen (und löschen, wenn sonst nicht verwendet)
 *
 * Rechte: ffmpeg[] und das Medienpool-Recht für die Kategorie des Videos.
 */
class Editor extends rex_api_function
{
    protected $published = false;

    protected function requiresCsrfProtection(): bool
    {
        return true;
    }

    public function execute(): rex_api_result
    {
        try {
            $data = ['ok' => true] + $this->handle();
        } catch (rex_api_exception $e) {
            $data = ['ok' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            \rex_logger::logException($e);
            $data = ['ok' => false, 'error' => rex::isDebugMode() ? $e->getMessage() : rex_i18n::msg('ffmpeg_error')];
        }
        rex_response::cleanOutputBuffers();
        rex_response::setHeader('Cache-Control', 'no-store');
        rex_response::sendJson($data);
        exit;
    }

    /** Darf der angemeldete Benutzer dieses Video bearbeiten? */
    public static function mayView(rex_media $media): bool
    {
        $user = rex::getUser();

        return null !== $user && $user->hasPerm('ffmpeg[]') && $user->getComplexPerm('media')->hasCategoryPerm($media->getCategoryId());
    }

    /** @return array<string, mixed> */
    private function handle(): array
    {
        $media = rex_media::get(rex_post('file', 'string', ''));
        if (!$media || !VideoInfo::isSupportedVideoFile($media->getFileName()) || !is_file(rex_path::media($media->getFileName()))) {
            throw new rex_api_exception(rex_i18n::msg('ffmpeg_not_found'));
        }
        if (!self::mayView($media)) {
            throw new rex_api_exception(rex_i18n::msg('ffmpeg_no_permission'));
        }
        $login = (string) rex::requireUser()->getLogin();

        switch (rex_post('action', 'string', 'info')) {
            case 'info':
                return $this->info($media);

            case 'frames':
                return [
                    'frames' => Frames::of($media, $this->duration($media)),
                    'keyframes' => Cut::keyframes(rex_path::media($media->getFileName())),
                ];

            case 'cut':
                $duration = $this->duration($media);
                $audio = !rex_post('remove_audio', 'bool', false);
                $loop = rex_post('loop', 'bool', false);
                // Ganzes Video nur, wenn sonst etwas passiert (Ton entfernen, Ping-Pong) – wie beim bisherigen Trimmer
                $segments = Cut::segments(rex_post('segments', 'string', ''), $duration, !$audio || $loop);
                $result = Cut::start($media, $segments, rex_post('exact', 'bool', false), $audio, $loop, 'replace' === rex_post('mode', 'string', 'version'), $login);
                if ('started' !== ($result['status'] ?? '')) {
                    throw new rex_api_exception((string) ($result['message'] ?? rex_i18n::msg('ffmpeg_error')));
                }

                return ['job' => $result['job']];

            case 'job':
                return ['job' => Converter::pollJob(rex_post('job', 'string', ''))];

            case 'poster':
                return ['poster' => Poster::capture($media, rex_post('time', 'float', 0.0), $login)];

            case 'track':
                foreach (Tracks::of($media->getFileName()) as $track) {
                    if ($track['file'] === rex_post('track', 'string', '')) {
                        return ['cues' => Tracks::read($track['file'])];
                    }
                }
                throw new rex_api_exception(rex_i18n::msg('ffmpeg_not_found'));

            case 'track_parse':
                $cues = Subtitles::parse(rex_post('content', 'string', ''));
                if (null === $cues) {
                    throw new rex_api_exception(rex_i18n::msg('ffmpeg_track_parse_error'));
                }

                return ['cues' => $cues];

            case 'track_save':
                $cues = json_decode(rex_post('cues', 'string', '[]'), true);
                $cues = Subtitles::clean(is_array($cues) ? $cues : []);
                if ([] === $cues) {
                    throw new rex_api_exception(rex_i18n::msg('ffmpeg_track_empty'));
                }
                $file = Tracks::save($media, rex_post('track', 'string', '') ?: null, rex_post('lang', 'string', ''),
                    rex_post('role', 'string', 'subtitles'), rex_post('label', 'string', ''), $cues, $login);

                return ['track' => $file, 'tracks' => Tracks::of($media->getFileName())];

            case 'track_delete':
                Tracks::remove($media, rex_post('track', 'string', ''));

                return ['tracks' => Tracks::of($media->getFileName())];
        }

        throw new rex_api_exception('Unbekannte Aktion.');
    }

    /** @return array<string, mixed> */
    private function info(rex_media $media): array
    {
        $info = VideoInfo::getInfo($media->getFileName()) ?? [];
        $video = $info['video'] ?? [];

        return [
            'file' => $media->getFileName(),
            'title' => $media->getTitle(),
            'extension' => strtolower($media->getExtension()),
            // direkt vom Webserver: springt sofort (Range-Anfragen ohne PHP); der PHP-Stream nur als Ausweg, falls der
            // Server /media nicht ausliefert (z. B. geschützte Medien)
            'stream' => \rex_url::media($media->getFileName()) . '?v=' . $media->getUpdateDate(),
            'streamFallback' => Stream::url($media),
            'duration' => (float) ($info['duration'] ?? 0),
            'fps' => (float) ($info['framerate'] ?? 0) ?: 25.0,
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'audio' => null !== ($info['audio'] ?? null),
            'tracks' => Tracks::of($media->getFileName()),
            'lang' => rex_clang::getCurrent()->getCode(),
        ];
    }

    private function duration(rex_media $media): float
    {
        return (float) (VideoInfo::getInfo($media->getFileName())['duration'] ?? 0);
    }
}
