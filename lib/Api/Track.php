<?php

namespace FriendsOfRedaxo\FFmpeg\Api;

use FriendsOfRedaxo\FFmpeg\Links;
use rex_api_function;
use rex_api_result;
use rex_media;
use rex_path;
use rex_response;

/**
 * Untertitel für die Website (GET, öffentlich): liefert eine verknüpfte Spur als text/vtt aus – Webserver kennen die
 * Endung .vtt oft nicht und schicken text/plain, das <track> ablehnt. Nur Dateien, die als Spur verknüpft sind.
 */
class Track extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        $media = rex_media::get(rex_get('file', 'string', ''));
        rex_response::cleanOutputBuffers();
        // nur als Spur verknüpfte .vtt-Dateien, und nur wenn der Besucher das Medium sehen darf (z. B. YCom-Medienschutz)
        if (!$media || 'vtt' !== strtolower($media->getExtension()) || !Links::isTrack($media->getFileName()) || !$media->isPermitted() || !is_file(rex_path::media($media->getFileName()))) {
            rex_response::setStatus(rex_response::HTTP_NOT_FOUND);
            rex_response::sendContent('', 'text/plain');
            exit;
        }
        // YRewrite o. ä. haben ggf. schon 404 gesetzt (die Adresse ist kein Artikel)
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::setHeader('Cache-Control', 'public, max-age=86400');
        rex_response::setHeader('X-Content-Type-Options', 'nosniff');
        rex_response::sendFile(rex_path::media($media->getFileName()), 'text/vtt; charset=utf-8');
        exit;
    }
}
