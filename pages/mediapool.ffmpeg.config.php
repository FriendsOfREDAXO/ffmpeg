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
    ]));

    echo rex_view::success($this->i18n('config_saved'));
}

$content .= '<fieldset><legend>' . $this->i18n('config_legend1') . '</legend>';

// Einfaches Textfeld
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-url">' . $this->i18n('command') . '</label>';
$n['field'] = '<input class="form-control" type="text" id="ffmpeg-config-command" name="config[command]" value="' . $this->getConfig('command') . '"/>';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');

// Checkbox
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-delete">' . $this->i18n('config_delete') . '</label>';
$n['field'] = '<input type="checkbox" id="ffmpeg-config-delete" name="config[delete]"' . (!empty($this->getConfig('delete')) && $this->getConfig('delete') == '1' ? ' checked="checked"' : '') . ' value="1" />';
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/checkbox.php');

/*
 *
// Select
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-ids">' . $this->i18n('config_select') . '</label>';
$select = new rex_select();
$select->setId('ffmpeg-config-select');
$select->setAttribute('class', 'form-control');
$select->setName('config[select]');
$select->addOption(0, 0);
$select->addOption(1, 1);
$select->setSelected($this->getConfig('select'));
$n['field'] = $select->get();
$formElements[] = $n;

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');
*/

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
