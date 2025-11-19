<?php

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');
/** @var rex_addon $this */

// Preset mapping for UI (available both when saving and when rendering)
$presets = [
    // Standard legacy setting (keeps the old default command and extension)
    'standard' => 'ffmpeg -y -i INPUT -vcodec h264 OUTPUT.mp4',
    'web' => 'ffmpeg -y -i INPUT -c:v libx264 -preset slow -crf 28 -c:a aac -b:a 128k OUTPUT.mp4',
    'mobile' => 'ffmpeg -y -i INPUT -c:v libx264 -preset veryfast -crf 30 -c:a aac -b:a 96k -vf scale=-2:720 OUTPUT.mp4',
    'archive' => 'ffmpeg -y -i INPUT -c:v libx264 -preset veryslow -crf 22 -c:a copy OUTPUT.mp4',
    // 'custom' allows keeping the current 'command' field for manual editing
    'custom' => '',
];

// Einstellungen speichern
if (rex_post('formsubmit', 'string') == '1' && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
} elseif (rex_post('formsubmit', 'string') == '1') {
    $config = rex_post('config', [
        ['command', 'string'],
        ['delete', 'string'],
        ['preset', 'string'],
    ]);

    // Keep the same preset mapping as the top-level one — always include the ffmpeg prefix

    // If a preset was selected (and not 'custom' or 'none'), override command
    if (!empty($config['preset']) && $config['preset'] !== 'custom' && $config['preset'] !== 'none' && isset($presets[$config['preset']])) {
        $config['command'] = $presets[$config['preset']];
    }

    // The 'custom' preset leaves the 'command' field as-is (manually edited by the user)

    $this->setConfig($config);

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
$content .= '<p class="help-block">' . $this->i18n('config_command_help') . '</p>';

$content .= '<script>' . PHP_EOL;
$content .= 'const ffmpegPresets = ' . json_encode($presets) . ';' . PHP_EOL;
$content .= "jQuery(document).on('rex:ready', function () {" . PHP_EOL;
$content .= "  var sel = document.getElementById('ffmpeg-config-preset');" . PHP_EOL;
$content .= "  var cmd = document.getElementById('ffmpeg-config-command');" . PHP_EOL;
$content .= "  if (!sel || !cmd) { return; }" . PHP_EOL;
$content .= "  var custom = null;" . PHP_EOL;
$content .= "  function updatePreview() {" . PHP_EOL;
$content .= "    var val = sel.value;" . PHP_EOL;
$content .= "    var effective = cmd.value;" . PHP_EOL;
$content .= "    if (val === 'custom') {" . PHP_EOL;
$content .= "      effective = cmd.value;" . PHP_EOL;
$content .= "    } else if (val && val in ffmpegPresets) {" . PHP_EOL;
$content .= "      effective = ffmpegPresets[val];" . PHP_EOL;
$content .= "    }" . PHP_EOL;
$content .= "    var preview = document.getElementById('ffmpeg-config-preview');" . PHP_EOL;
$content .= "    if (preview) preview.textContent = effective;" . PHP_EOL;
$content .= "  }" . PHP_EOL;
$content .= "  function onChange() {" . PHP_EOL;
$content .= "    var val = sel.value;" . PHP_EOL;
$content .= "    if (val === 'custom') { /* keep the current command for manual editing */ } else if (val && val in ffmpegPresets) { cmd.value = ffmpegPresets[val]; }" . PHP_EOL;
$content .= "    updatePreview();" . PHP_EOL;
$content .= "  }" . PHP_EOL;
$content .= "  jQuery(sel).off('change.ffmpeg').on('change.ffmpeg', onChange);" . PHP_EOL;
$content .= "  jQuery(cmd).off('input.ffmpeg').on('input.ffmpeg', updatePreview);" . PHP_EOL;
$content .= "  // no custom textarea anymore so no event binding for it" . PHP_EOL;
$content .= "  updatePreview();" . PHP_EOL;
$content .= "});" . PHP_EOL;
$content .= '</script>' . PHP_EOL;

// Preset selection
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-preset">' . $this->i18n('config_preset') . '</label>';
$select = new rex_select();
$select->setId('ffmpeg-config-preset');
$select->setAttribute('class', 'form-control');
$select->setName('config[preset]');
$select->addOption($this->i18n('config_preset_none'), 'none');
$select->addOption($this->i18n('config_preset_standard'), 'standard');
$select->addOption($this->i18n('config_preset_web'), 'web');
$select->addOption($this->i18n('config_preset_mobile'), 'mobile');
$select->addOption($this->i18n('config_preset_archive'), 'archive');
// Allow the user to select "Custom" — do not overwrite the command field
$select->addOption($this->i18n('config_preset_custom'), 'custom');
$select->setSelected($this->getConfig('preset', 'none'));
$n['field'] = $select->get();
$formElements[] = $n;
$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');

$content .= '<p class="help-block">' . $this->i18n('config_preset_help') . '</p>';

// Preset descriptions
$content .= '<ul class="list-unstyled small">';
$content .= '<li><strong>' . $this->i18n('config_preset_web') . ':</strong> ' . $this->i18n('config_preset_web_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_mobile') . ':</strong> ' . $this->i18n('config_preset_mobile_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_archive') . ':</strong> ' . $this->i18n('config_preset_archive_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_standard') . ':</strong> ' . $this->i18n('config_preset_standard_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_custom') . ':</strong> ' . $this->i18n('config_preset_custom_desc') . '</li>';
$content .= '</ul>' . PHP_EOL;

// Hinweis: custom command kann direkt im 'command' Feld eingegeben werden

// Preview and explanation
$preview = '<div class="form-group"><label>' . $this->i18n('config_preview_label') . '</label><div id="ffmpeg-config-preview" class="well well-sm" style="white-space:pre-wrap;">' . htmlspecialchars((string) $this->getConfig('command')) . '</div></div>';
$content .= $preview;

$content .= '<p class="text-muted">' . $this->i18n('config_preset_priority') . '</p>';

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
n['field'] = '<textarea class="form-control" id="ffmpeg-config-custom" name="config[custom_command]" rows="3">' . htmlspecialchars((string) $this->getConfig('custom_command')) . '</textarea>';
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
