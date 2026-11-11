<?php

/**
 * Untertitel und Kapitel: Video wählen, Spuren verwalten, Editor mit Live-Vorschau (assets/js/editor.js).
 * Nach dem Vorbild des AddOns video_editor (REDAXO 6).
 *
 * @var rex_addon $this
 */

use FriendsOfRedaxo\FFmpeg\Links;

$video = rex_request('video', 'string', '');
$media = '' !== $video ? rex_media::get($video) : null;

if ($media && \FriendsOfRedaxo\FFmpeg\VideoInfo::isSupportedVideoFile($media->getFileName())) {
    $keys = ['title', 'tracks', 'none', 'add', 'edit', 'intro', 'cues', 'frame', 'new_cue', 'lang', 'role', 'label', 'label_help',
        'role_subtitles', 'role_captions', 'role_chapters', 'import', 'shift', 'shift_apply', 'list', 'from', 'to', 'text',
        'set_start', 'set_end', 'jump', 'delete_cue', 'time_invalid', 'save', 'saved', 'cancel', 'remove', 'remove_confirm', 'keys'];
    $i18n = [];
    foreach ($keys as $key) {
        $i18n[$key] = rex_i18n::rawMsg('ffmpeg_track_' . $key);
    }
    $body = '<div class="ffmpeg-tracks" data-ffmpeg-tracks="' . rex_escape(json_encode(['file' => $media->getFileName(), 'i18n' => $i18n])) . '"></div>'
        . '<p><a class="btn btn-default" href="' . rex_url::currentBackendPage() . '"><i class="rex-icon fa-arrow-left"></i> ' . $this->i18n('ffmpeg_trimmer_back_to_list') . '</a></p>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', '<i class="rex-icon fa-cc"></i> ' . rex_escape($this->i18n('ffmpeg_track_title') . ': ' . $media->getFileName()), false);
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');

    return;
}

// Liste der Videos mit Anzahl der Spuren
$videos = rex_sql::factory()->getArray('SELECT filename, title FROM ' . rex::getTable('media') . " WHERE filetype LIKE 'video/%' ORDER BY updatedate DESC");
$user = rex::requireUser();
$rows = '';
foreach ($videos as $row) {
    $item = rex_media::get((string) $row['filename']);
    if (!$item || !$user->getComplexPerm('media')->hasCategoryPerm($item->getCategoryId())) {
        continue;
    }
    $tracks = Links::of($item->getFileName(), Links::TRACK);
    $langs = implode(', ', array_unique(array_filter(array_map(static fn (array $t) => $t['lang'], $tracks))));
    $rows .= '<tr>
        <td class="video-filename"><span class="filename-truncate">' . rex_escape($item->getFileName()) . '</span></td>
        <td>' . rex_escape($item->getTitle()) . '</td>
        <td>' . ([] === $tracks ? '<span class="text-muted">–</span>' : count($tracks) . ' (' . rex_escape($langs) . ')') . '</td>
        <td class="text-right"><a class="btn btn-primary btn-sm" href="' . rex_url::currentBackendPage(['video' => $item->getFileName()]) . '"><i class="rex-icon fa-cc"></i> ' . $this->i18n('ffmpeg_track_edit_video') . '</a></td>
    </tr>';
}

$body = '<p>' . $this->i18n('ffmpeg_track_page_intro') . '</p>';
$body .= '' === $rows
    ? '<div class="alert alert-info">' . $this->i18n('ffmpeg_no_videos_mediapool') . '</div>'
    : '<div class="table-responsive"><table class="table table-striped"><thead><tr>
        <th>' . $this->i18n('ffmpeg_trimmer_table_filename') . '</th>
        <th>' . $this->i18n('ffmpeg_trimmer_table_title') . '</th>
        <th>' . $this->i18n('ffmpeg_track_tracks') . '</th>
        <th><span class="sr-only">' . $this->i18n('ffmpeg_trimmer_table_actions') . '</span></th>
    </tr></thead><tbody>' . $rows . '</tbody></table></div>';

$fragment = new rex_fragment();
$fragment->setVar('title', '<i class="rex-icon fa-cc"></i> ' . $this->i18n('ffmpeg_subtitles'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');
