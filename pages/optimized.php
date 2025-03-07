<?php

$content = '';
$csrfToken = rex_csrf_token::factory('ffmpeg');

// Initialize tables for SQL queries
$media_table = rex::getTable('media');

// Get all video files from media pool
$sql = rex_sql::factory();
// Get all video files from media pool
$sql = rex_sql::factory();
$result = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');
$result = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Get already optimized videos (with web_ prefix)
$optimized_videos = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filename LIKE \'web_%\' AND filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Handle delete request for optimized videos
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
                $message = 'Video erfolgreich gelöscht';
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

if (count($optimized_videos) > 0) {
    $content .= '<h4>Optimierte Videos</h4>';
    $content .= '<div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Video</th>
                    <th>Original</th>
                    <th>Größe</th>
                    <th>Dimensionen</th>
                    <th>Datum</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>';
            
    foreach ($optimized_videos as $video) {
        $originalName = substr($video['filename'], 4); // Remove 'web_' prefix
        $originalExists = false;
        $originalSize = 0;
        
        // Check if original exists
        foreach ($result as $item) {
            if ($item['filename'] == $originalName) {
                $originalExists = true;
                $originalSize = $item['filesize'];
                break;
            }
        }
        
        // Calculate size reduction
        $sizeReduction = $originalExists && $originalSize > 0 ? 
            round(($originalSize - $video['filesize']) / $originalSize * 100, 1) : 
            0;
            
        $content .= '
            <tr>
                <td>
                    <a href="' . rex_url::media($video['filename']) . '" target="_blank">' . $video['filename'] . '</a>
                    <a href="#" class="video-preview-link" title="Vorschau" data-video="' . rex_url::media($video['filename']) . '"><i class="fa fa-eye"></i></a>
                </td>
                <td>' . ($originalExists ? $originalName : '<span class="text-muted">Gelöscht</span>') . '</td>
                <td>' . rex_formatter::bytes($video['filesize']) . ' 
                    ' . ($sizeReduction > 0 ? '<span class="text-success">(-' . $sizeReduction . '%)</span>' : '') . '
                </td>
                <td>' . $video['med_width'] . 'x' . $video['med_height'] . '</td>
                <td>' . rex_formatter::strftime($video['updatedate'], 'date') . '</td>
                <td>
                    <button type="button" class="btn btn-danger btn-xs delete-optimized" data-filename="' . $video['filename'] . '">
                        <i class="fa fa-trash"></i> Löschen
                    </button>
                </td>
            </tr>';
    }
    
    $content .= '
            </tbody>
        </table>
    </div>';
} else {
    $content .= '<div class="alert alert-info">Keine optimierten Videos vorhanden.</div>';
}

// Video preview modal
$content .= '
<div class="modal fade" id="video-preview-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Video-Vorschau</h4>
      </div>
      <div class="modal-body">
        <video controls style="width: 100%; height: auto;">
          <source src="" type="video/mp4">
          Ihr Browser unterstützt keine Video-Wiedergabe.
        </video>
      </div>
    </div>
  </div>
</div>';

// Ausgabe
$fragment = new rex_fragment();
$fragment->setVar('class', 'info');
$fragment->setVar('title', $this->i18n('ffmpeg_optimized'));
$fragment->setVar('body', $content, false);
$output = $fragment->parse('core/page/section.php');

echo $output;
