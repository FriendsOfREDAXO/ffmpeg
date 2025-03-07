<?php

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Einstellungen speichern
if (rex_post('formsubmit', 'string') == '1' && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
} elseif (rex_post('formsubmit', 'string') == '1') {
    $this->setConfig(rex_post('config', [
        ['command', 'string'],
        ['delete', 'string'],
        ['poster_quality', 'string'],
        ['trim_preset', 'string'],
        ['inherit_meta', 'string'],
    ]));

    echo rex_view::success($this->i18n('config_saved'));
}

$content .= '<fieldset><legend>' . $this->i18n('config_legend1') . '</legend>';

// Einfaches Textfeld für FFMPEG-Command
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-command">' . $this->i18n('command') . '</label>';
$n['field'] = '<input class="form-control" type="text" id="ffmpeg-config-command" name="config[command]" value="' . $this->getConfig('command') . '"/>';
$n['note'] = '<p class="help-block">INPUT = Eingangsdatei, OUTPUT = Ausgangsdatei (z.B. OUTPUT.mp4)</p>';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');

// Poster-Qualitätseinstellung
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-poster-quality">' . $this->i18n('poster_quality') . '</label>';
$select = new rex_select();
$select->setId('ffmpeg-config-poster-quality');
$select->setAttribute('class', 'form-control');
$select->setName('config[poster_quality]');
$select->addOption('JPEG - Niedrig (70%)', 'jpeg_low');
$select->addOption('JPEG - Mittel (85%)', 'jpeg_medium');
$select->addOption('JPEG - Hoch (95%)', 'jpeg_high');
$select->addOption('PNG - Höchste Qualität', 'png');
$select->setSelected($this->getConfig('poster_quality', 'jpeg_medium'));
$n['field'] = $select->get();
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');

// Trim-Preset Auswahl
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-trim-preset">' . $this->i18n('trim_preset') . '</label>';
$select = new rex_select();
$select->setId('ffmpeg-config-trim-preset');
$select->setAttribute('class', 'form-control');
$select->setName('config[trim_preset]');
$select->addOption('Ultrafast - Sehr schnell, weniger Kompression', 'ultrafast');
$select->addOption('Superfast - Schnell, gute Qualität', 'superfast');
$select->addOption('Veryfast - Standard', 'veryfast');
$select->addOption('Slower - Hohe Qualität, langsamer', 'slower');
$select->addOption('Veryslow - Höchste Qualität, sehr langsam', 'veryslow');
$select->setSelected($this->getConfig('trim_preset', 'veryfast'));
$n['field'] = $select->get();
$n['note'] = '<p class="help-block">Diese Einstellung beeinflusst die Kodierqualität und Geschwindigkeit bei der Erstellung von gekürzten Videos.</p>';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');

// Checkbox - Datei löschen
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-delete">' . $this->i18n('config_delete') . '</label>';
$n['field'] = '<input type="checkbox" id="ffmpeg-config-delete" name="config[delete]"' . (!empty($this->getConfig('delete')) && $this->getConfig('delete') == '1' ? ' checked="checked"' : '') . ' value="1" />';
$n['note'] = '<p class="help-block">Nur bei Konvertierung, nicht bei Erstellung von Postern oder gekürzten Versionen.</p>';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/checkbox.php');

// Checkbox - Metadaten übernehmen
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-inherit-meta">' . $this->i18n('config_inherit_meta') . '</label>';
$n['field'] = '<input type="checkbox" id="ffmpeg-config-inherit-meta" name="config[inherit_meta]"' . (!empty($this->getConfig('inherit_meta')) && $this->getConfig('inherit_meta') == '1' ? ' checked="checked"' : '') . ' value="1" />';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/checkbox.php');

$content .= '</fieldset>';

// Info-Block für FFmpeg-Befehle
$content .= '<fieldset><legend>' . $this->i18n('command_examples') . '</legend>';
$content .= '<div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title">' . $this->i18n('command_examples_title') . '</h3></div>
    <div class="panel-body">
        <h4>' . $this->i18n('command_examples_convert') . ':</h4>
        <pre>ffmpeg -y -i INPUT -vcodec h264 -crf 23 -preset veryfast -acodec aac -strict experimental OUTPUT.mp4</pre>
        <p class="help-block">' . $this->i18n('command_examples_convert_info') . '</p>
        
        <h4>' . $this->i18n('command_examples_compress') . ':</h4>
        <pre>ffmpeg -y -i INPUT -vcodec h264 -crf 28 -preset faster -acodec aac -ar 44100 -ac 2 -b:a 128k OUTPUT.mp4</pre>
        <p class="help-block">' . $this->i18n('command_examples_compress_info') . '</p>
        
        <h4>' . $this->i18n('command_examples_webm') . ':</h4>
        <pre>ffmpeg -y -i INPUT -c:v libvpx-vp9 -crf 30 -b:v 0 -b:a 128k -c:a libopus OUTPUT.webm</pre>
        <p class="help-block">' . $this->i18n('command_examples_webm_info') . '</p>
    </div>
</div>';
$content .= '</fieldset>';

// Save-Button
$formElements = [];
$n = [];
$n['field'] = '<button class="btn btn-save rex-form-aligned" type="submit" name="save" value="' . $this->i18n('config_save') . '">' . $this->i18n('config_save') . '</button>';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$buttons = $fragment->parse('core/form/submit.php');
$buttons = '<fieldset class="rex-form-action">' . $buttons . '</fieldset>';

// Ausgabe Formular
$fragment = new rex_fragment();
$fragment->setVar('class', 'edit');
$fragment->setVar('title', $this->i18n('ffmpeg_video_config'));
$fragment->setVar('body', $content, false);
$fragment->setVar('buttons', $buttons, false);
$output = $fragment->parse('core/page/section.php');

$output = '
<form action="' . rex_url::currentBackendPage() . '" method="post">
    <input type="hidden" name="formsubmit" value="1" />
    ' . $csrfToken->getHiddenField() . '
    ' . $output . '
</form>';

echo $output;
