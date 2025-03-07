<?php

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Initialize tables for SQL queries
$media_table = rex::getTable('media');

// Get all video files from media pool
$sql = rex_sql::factory();
$result = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filetype LIKE \'video/%\' ORDER BY updatedate DESC');

// Remove already processed videos
$videos = [];
foreach ($result as $item) {
    if (substr($item['filename'], 0, 4) != 'web_' && 
        substr($item['filename'], 0, 7) != 'poster_' && 
        substr($item['filename'], 0, 5) != 'trim_') {
        $videos[] = $item;
    }
}

// Get already optimized videos
$optimized_videos = [];
$poster_images = [];
$trimmed_videos = [];

foreach ($result as $item) {
    if (substr($item['filename'], 0, 4) == 'web_') {
        $optimized_videos[] = $item;
    } else if (substr($item['filename'], 0, 5) == 'trim_') {
        $trimmed_videos[] = $item;
    }
}

// Get poster images
$poster_images = $sql->getArray('SELECT * FROM ' . $media_table . ' WHERE filename LIKE \'poster_%\' AND filetype LIKE \'image/%\' ORDER BY updatedate DESC');

// Main content
if (count($videos) > 0) {
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
    
    $has_original_videos = false;
    
    foreach ($videos as $key => $item) {
        $has_original_videos = true;
        
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
                    <input class="mycheckbox video-select" id="v' . $key . '" type="radio" name="video" value="' . $item['filename'] . '" data-video="' . $item['filename'] . '"> 
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

    if ($has_original_videos) {
        $n['field'] = implode('', $n['field']);
        $formElements[] = $n;
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $content .= $fragment->parse('core/form/container.php');
    } else {
        $content .= '<div class="alert alert-info">Keine unverarbeiteten Videos zur Bearbeitung vorhanden. Bitte laden Sie neue Videos in den Medienpool hoch.</div>';
    }
    
    $content .= '</fieldset>';
    
    // Operation form hidden field
    $content .= '<input type="hidden" name="operation_type" value="convert">';
    
    // Save-Button
    $formElements = [];
    $n = [];
    $n['field'] = '<button class="btn btn-primary rex-form-aligned btn-start disabled" id="start" type="submit" name="save" value="' . $this->i18n('execute') . '">' . $this->i18n('execute') . '</button>';
    $formElements[] = $n;
    
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $buttons = $fragment->parse('core/form/submit.php');
    $buttons = '<fieldset class="rex-form-action">' . $buttons . '</fieldset>';
} else {
    $content .= '<div class="alert alert-info">Keine Videos im Medienpool gefunden. Bitte laden Sie zuerst Videos in den Medienpool hoch.</div>';
}

// Debugging-Info ausgeben
$content .= '
<script>
console.log("FFMPEG main page loaded");
</script>';

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
<form action="' . rex_url::currentBackendPage() . '" method="post" id="ffmpeg-form">
    <input type="hidden" name="page" value="ffmpeg/main" />
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
