<?php

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Initialize tables for SQL queries
$media_table = rex::getTable('media');
$meta_table = rex::getTable('metainfo_field');

// Get all video files from media pool
$sql = rex_sql::factory();
$result = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Get already optimized videos (with web_ prefix)
$optimized_videos = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filename LIKE \'web_%\' AND filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Get videos with generated posters (with poster_ prefix)
$poster_images = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filename LIKE \'poster_%\' AND filetype LIKE \'image/%\' ORDER BY updatedate DESC');

// Get trimmed videos (with trim_ prefix)
$trimmed_videos = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filename LIKE \'trim_%\' AND filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Handle delete request for optimized videos
if (rex_request('ffmpeg_video', 'boolean', false) && rex_request('delete_optimized', 'boolean', false)) {
    $filename = rex_request('filename', 'string', '');
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

// Create tab navigation
$content .= '<ul class="nav nav-tabs" role="tablist">
    <li role="presentation" class="active"><a href="#converter" aria-controls="converter" role="tab" data-toggle="tab">Video konvertieren</a></li>
    <li role="presentation"><a href="#optimized" aria-controls="optimized" role="tab" data-toggle="tab">Optimierte Videos</a></li>
    <li role="presentation"><a href="#posters" aria-controls="posters" role="tab" data-toggle="tab">Poster</a></li>
    <li role="presentation"><a href="#trimmed" aria-controls="trimmed" role="tab" data-toggle="tab">Gekürzte Videos</a></li>
</ul>';

$content .= '<div class="tab-content" style="padding-top: 20px;">';

// TAB: Video Converter
$content .= '<div role="tabpanel" class="tab-pane active" id="converter">';

if (count($result) > 0) {
    // Operation Type Selector
    $content .= '<div class="operation-selector">
        <div class="btn-group" role="group" aria-label="Operationstyp">
            <button type="button" class="btn btn-default operation-select active" data-operation="convert">Konvertieren</button>
            <button type="button" class="btn btn-default operation-select" data-operation="trim">Kürzen</button>
            <button type="button" class="btn btn-default operation-select" data-operation="poster">Poster erstellen</button>
        </div>
    </div>';
    
    $content .= '<div class="operation-options" id="convert-options" style="margin-top: 20px;">
        <h4>Videokonvertierung</h4>
        <p class="help-block">' . $this->i18n('ffmpeg_convert_info') . '</p>
    </div>';
    
    $content .= '<div class="operation-options" id="trim-options" style="display:none; margin-top: 20px;">
        <h4>Video kürzen</h4>
        <p class="help-block">Erstellen Sie eine gekürzte Version des Videos für Previews oder Snippets.</p>
        <div class="row form-group">
            <div class="col-sm-6">
                <label for="trim_start_time">Startzeit (HH:MM:SS)</label>
                <input type="text" class="form-control" id="trim_start_time" name="trim_start_time" placeholder="00:00:00">
            </div>
            <div class="col-sm-6">
                <label for="trim_end_time">Endzeit (HH:MM:SS)</label>
                <input type="text" class="form-control" id="trim_end_time" name="trim_end_time" placeholder="00:10:00">
            </div>
        </div>
        <div class="form-group">
            <label for="trim-range">Zeitbereich:</label>
            <div id="trim-range"></div>
        </div>
        <input type="hidden" id="video_duration" value="100">
    </div>';
    
    $content .= '<div class="operation-options" id="poster-options" style="display:none; margin-top: 20px;">
        <h4>Poster aus Video</h4>
        <p class="help-block">Erstellen Sie ein Standbild aus dem Video als Poster.</p>
        <div class="form-group">
            <label for="poster_timestamp">Zeitpunkt (HH:MM:SS)</label>
            <input type="text" class="form-control" id="poster_timestamp" name="poster_timestamp" placeholder="00:00:05" value="00:00:05">
            <p class="help-block">Wählen Sie den Zeitpunkt im Video, an dem das Poster erstellt werden soll.</p>
        </div>
    </div>';

    // Video selection list
    $content .= '<fieldset style="margin-top: 20px;"><legend>' . $this->i18n('legend_video') . '</legend>';
   
    $formElements = [];
    $n = [];
    $n['label'] = '<label></label>';
    $n['field'] = [];
    
    foreach ($result as $key => $item) {
        // Skip already processed videos
        if (substr($item['filename'], 0, 4) == 'web_' || substr($item['filename'], 0, 7) == 'poster_' || substr($item['filename'], 0, 5) == 'trim_') continue;
        
        // Get processed versions of this video
        $has_optimized = false;
        $has_poster = false;
        $has_trim = false;
        
        foreach ($optimized_videos as $opt) {
            if ($opt['filename'] == 'web_' . $item['filename']) {
                $has_optimized = true;
                break;
            }
        }
        
        foreach ($poster_images as $poster) {
            if (strpos($poster['filename'], pathinfo($item['filename'], PATHINFO_FILENAME)) !== false) {
                $has_poster = true;
                break;
            }
        }
        
        foreach ($trimmed_videos as $trim) {
            if (strpos($trim['filename'], pathinfo($item['filename'], PATHINFO_FILENAME)) !== false) {
                $has_trim = true;
                break;
            }
        }
        
        // Prepare badges for video item
        $badges = '';
        if ($has_optimized) $badges .= ' <span class="badge">Optimiert</span>';
        if ($has_poster) $badges .= ' <span class="badge">Poster</span>';
        if ($has_trim) $badges .= ' <span class="badge">Gekürzt</span>';
        
        // Get video metadata for display
        $width = $item['med_width'] ? $item['med_width'] . 'px' : 'N/A';
        $height = $item['med_height'] ? $item['med_height'] . 'px' : 'N/A';
        $filesize = rex_formatter::bytes($item['filesize']);
        
        // Preview link
        $preview = ' <a href="#" class="video-preview-link" title="Vorschau" data-video="' . rex_url::media($item['filename']) . '"><i class="fa fa-eye"></i></a>';
        
        $n['field'][] = '
        <div class="panel panel-default">
            <div class="panel-heading">
                <label>
                    <input class="mycheckbox" id="v' . $key . '" type="radio" name="video" value="' . $item['filename'] . '" data-video="' . $item['filename'] . '"> 
                    <strong>' . $item['filename'] . '</strong>
                    ' . $badges . $preview . '
                </label>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>Größe:</strong> ' . $filesize . '
                    </div>
                    <div class="col-md-4">
                        <strong>Maße:</strong> ' . $width . ' x ' . $height . '
                    </div>
                    <div class="col-md-4">
                        <strong>Typ:</strong> ' . $item['filetype'] . '
                    </div>
                </div>
            </div>
        </div>';
    }

    if (count($n['field']) > 0) {
        $n['field'] = implode('', $n['field']);
        $formElements[] = $n;
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $content .= $fragment->parse('core/form/container.php');
    } else {
        $content .= '<div class="alert alert-info">Keine Videos zur Bearbeitung vorhanden. Bitte laden Sie zuerst Videos in den Medienpool hoch.</div>';
    }
    
    $content .= '</fieldset>';
    
    // Operation form hidden field
    $content .= '<input type="hidden" name="operation_type" value="convert">';
    
    // Save-Button
    $formElements = [];
    $n = [];
    $n['field'] = '<button class="btn btn-save rex-form-aligned btn-start disabled" id="start" type="submit" name="save" value="' . $this->i18n('execute') . '">' . $this->i18n('execute') . '</button>';
    $formElements[] = $n;
    
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $buttons = $fragment->parse('core/form/submit.php');
    $buttons = '<fieldset class="rex-form-action">' . $buttons . '</fieldset>';
} else {
    $content .= '<div class="alert alert-info">Keine Videos im Medienpool gefunden. Bitte laden Sie zuerst Videos in den Medienpool hoch.</div>';
}

$content .= '</div>'; // End convert tab

// TAB: Optimized Videos
$content .= '<div role="tabpanel" class="tab-pane" id="optimized">';

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

$content .= '</div>'; // End optimized tab

// TAB: Posters
$content .= '<div role="tabpanel" class="tab-pane" id="posters">';

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

$content .= '</div>'; // End posters tab

// TAB: Trimmed
$content .= '<div role="tabpanel" class="tab-pane" id="trimmed">';

if (count($trimmed_videos) > 0) {
    $content .= '<h4>Gekürzte Videos</h4>';
    $content .= '<div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Video</th>
                    <th>Original</th>
                    <th>Größe</th>
                    <th>Dauer</th>
                    <th>Datum</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>';
            
    foreach ($trimmed_videos as $video) {
        // Extract original name from trim_ prefix
        $parts = explode('_', $video['filename']);
        array_shift($parts); // Remove 'trim' part
        $timeInfo = array_pop($parts); // Remove time info
        $originalName = implode('_', $parts);
        
        // Find original file
        $originalVideo = null;
        foreach ($result as $item) {
            if (pathinfo($item['filename'], PATHINFO_FILENAME) == $originalName) {
                $originalVideo = $item;
                break;
            }
        }
        
        // Duration display
        $duration = $video['med_duration'] ? rex_formatter::format($video['med_duration'], 'strftime', '%M:%S') : 'N/A';
        $originalDuration = $originalVideo && $originalVideo['med_duration'] ? 
            rex_formatter::format($originalVideo['med_duration'], 'strftime', '%M:%S') : 'N/A';
        
        $content .= '
            <tr>
                <td>
                    <a href="' . rex_url::media($video['filename']) . '" target="_blank">' . $video['filename'] . '</a>
                    <a href="#" class="video-preview-link" title="Vorschau" data-video="' . rex_url::media($video['filename']) . '"><i class="fa fa-eye"></i></a>
                </td>
                <td>' . ($originalVideo ? $originalVideo['filename'] : '<span class="text-muted">Unbekannt</span>') . '</td>
                <td>' . rex_formatter::bytes($video['filesize']) . '</td>
                <td>' . $duration . ' 
                    ' . ($originalDuration != 'N/A' ? '<small class="text-muted">(Original: ' . $originalDuration . ')</small>' : '') . '
                </td>
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
    $content .= '<div class="alert alert-info">Keine gekürzten Videos vorhanden.</div>';
}

$content .= '</div>'; // End trimmed tab

$content .= '</div>'; // End tab-content

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

// Ausgabe Formular
$fragment = new rex_fragment();
$fragment->setVar('class', 'info');
$fragment->setVar('title', $this->i18n('ffmpeg_video_convert'));
$fragment->setVar('body', $content, false);
$fragment->setVar('buttons', $buttons, false);
$output = $fragment->parse('core/page/section.php');

$output = '
<form action="' . rex_url::currentBackendPage() . '" method="post">
    <input type="hidden" name="formsubmit" value="1" />
    ' . $csrfToken->getHiddenField() . '
    ' . $output . '
    
    <div class="rex-page-section progress-section" style="display:none;">
        <div class="panel panel-info">
            <div class="progress">
                <div class="progress-bar" id="prog" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div id="log" class="log" style="padding:15px;margin:5px 0;"><pre style="height:200px;overflow-y: auto"></pre></div>
        </div>
    </div>
</form>';

echo $output;
?>
