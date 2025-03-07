<?php

$content = '';
$csrfToken = rex_csrf_token::factory('ffmpeg');

// Initialize tables for SQL queries
$media_table = rex::getTable('media');

// Get all video files from media pool
$sql = rex_sql::factory();
$result = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Get poster images
$poster_images = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filename LIKE \'poster_%\' AND filetype LIKE \'image/%\' ORDER BY updatedate DESC');

// Handle delete request for posters
if (rex_request::get('ffmpeg_video', 'boolean', false) && rex_request::get('delete_optimized', 'boolean', false)) {
    $filename = rex_request::get('filename', 'string', '');
    $success = false;
    $message = '';
    
    if (!empty($filename)) {
        try {
            if (!function_exists('rex_mediapool_deleteMedia')) {
                require_once rex_path::addon('mediapool', 'functions/function_rex_mediapool.php');
            }
            $success = rex_mediapool_deleteMedia($filename);
            if ($success) {
                $message = 'Poster erfolgreich gelöscht';
            } else {
                $message = 'Beim Löschen ist ein Fehler aufgetreten';
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
        }
    } else {
        $message = 'Kein Dateiname angegeben';
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);
    exit;
}

if (count($poster_images) > 0) {
    $content .= '<h4>Generierte Poster</h4>';
    $content .= '<div class="row">';
    
    foreach ($poster_images as $poster) {
        // Extract original video name (remove poster_ prefix and timestamp)
        $parts = explode('_', $poster['filename']);
        array_shift($parts); // Remove 'poster' part
        $timestamp = array_pop($parts); // Remove timestamp
        $originalName = implode('_', $parts);
        
        // Find file extension from original video files
        $originalExt = '';
        foreach ($result as $item) {
            if (pathinfo($item['filename'], PATHINFO_FILENAME) == $originalName) {
                $originalExt = pathinfo($item['filename'], PATHINFO_EXTENSION);
                break;
            }
        }
        
        $content .= '
        <div class="col-sm-6 col-md-4 col-lg-3">
            <div class="panel panel-default">
                <div class="panel-heading">
                    <h4 class="panel-title">' . $poster['filename'] . '</h4>
                </div>
                <div class="panel-body text-center">
                    <a href="' . rex_url::media($poster['filename']) . '" target="_blank">
                        <img src="' . rex_url::media($poster['filename']) . '" alt="' . $poster['filename'] . '" class="img-responsive img-thumbnail" style="max-height: 200px; margin: 0 auto;">
                    </a>
                </div>
                <div class="panel-footer">
                    <div class="row">
                        <div class="col-xs-7">
                            <small>Aus: ' . $originalName . ($originalExt ? '.' . $originalExt : '') . '</small>
                        </div>
                        <div class="col-xs-5 text-right">
                            <button type="button" class="btn btn-danger btn-xs delete-optimized" data-filename="' . $poster['filename'] . '">
                                <i class="fa fa-trash"></i> Löschen
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>';
    }
    
    $content .= '</div>'; // End row
} else {
    $content .= '<div class="alert alert-info">Keine Poster vorhanden.</div>';
}

// Ausgabe
$fragment = new rex_fragment();
$fragment->setVar('class', 'info');
$fragment->setVar('title', $this->i18n('ffmpeg_poster'));
$fragment->setVar('body', $content, false);
$output = $fragment->parse('core/page/section.php');

echo $output;
