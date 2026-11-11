<?php

namespace FriendsOfRedaxo\FFmpeg\Api;

use FriendsOfRedaxo\FFmpeg\VideoInfo;
use rex_api_function;
use rex_api_result;
use rex_media;
use rex_path;
use rex_response;

/**
 * Video für die Editoren ausliefern (GET, nur im Backend): mit Range-Anfragen, damit der Browser springen kann –
 * auch wenn der Webserver /media ohne Range ausliefert. Nur lesend, daher ohne CSRF-Token.
 */
class Stream extends rex_api_function
{
    protected $published = false;

    public function execute(): rex_api_result
    {
        $media = rex_media::get(rex_get('file', 'string', ''));
        rex_response::cleanOutputBuffers();
        if (!$media || !Editor::mayView($media) || !VideoInfo::isSupportedVideoFile($media->getFileName()) || !is_file(rex_path::media($media->getFileName()))) {
            rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
            rex_response::sendContent('', 'text/plain');
            exit;
        }
        rex_response::setHeader('Cache-Control', 'private, max-age=3600');
        rex_response::sendFile(rex_path::media($media->getFileName()), (string) $media->getType());
        exit;
    }

    public static function url(rex_media $media): string
    {
        return \rex_url::backendController(['rex-api-call' => 'ffmpeg_stream', 'file' => $media->getFileName(), 'v' => $media->getUpdateDate()], false);
    }
}
