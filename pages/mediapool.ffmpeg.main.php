<?php

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

$sql = rex_sql::factory();
$result = $sql->getArray('select * from ' . rex::getTable('media') . ' where filetype like \'video/%\'');

if ($result) {
    $n = [];
    $n['field'] = [];
    foreach ($result as $key => $item) {
        if (substr($item['filename'], 0, 4) == 'web_') continue;
        $n['field'][] = '<label><input class="mycheckbox" id="v' . $key . '" type="radio" name="video" value="' . $item['filename'] . '" data-video="' . $item['filename'] . '"> ' . $item['filename'] . '</label>';
    }
    $content .= '<h3>' . $this->i18n('ffmpeg_convert_info') . '</h3>';
    
    if (count($n['field']) > 0) {
        $content .= '<fieldset><legend>' . $this->i18n('legend_video') . '</legend>';
        
        $formElements = [];
        $n['label'] = '<label></label>';
        $n['field'] = implode('<br>', $n['field']);
        $formElements[] = $n;
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $content .= $fragment->parse('core/form/container.php');
        
        $content .= '</fieldset>';
        
        // Action Buttons
        $formElements = [];
        
        // Convert Button
        $n = [];
        $n['field'] = '<button class="btn btn-save rex-form-aligned btn-start" id="start" type="button" name="save" value="' . $this->i18n('execute') . '">' . $this->i18n('execute') . '</button>';
        $formElements[] = $n;
        
        // Optional: Add a "Check Status" button for ongoing conversions
        $n = [];
        $n['field'] = '<button class="btn btn-default rex-form-aligned" id="check_status" type="button" name="check" value="Check Status">Check Conversion Status</button>';
        $formElements[] = $n;
        
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $formElements, false);
        $buttons = $fragment->parse('core/form/submit.php');
        $buttons = '<fieldset class="rex-form-action">' . $buttons . '</fieldset>';

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
    } else {
        echo rex_view::info('No video files found for conversion.');
    }
} else {
    echo rex_view::info('No video files found for conversion.');
}
