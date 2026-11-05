<?php

$content = '';
$buttons = '';

$csrfToken = rex_csrf_token::factory('ffmpeg');
/** @var rex_addon $this */

// Preset mapping for UI (available both when saving and when rendering) --
// zentrale Definition in FriendsOfRedaxo\FFmpeg\Presets, auch von
// Api\Converter genutzt (Preset-Wahl beim Anlegen einer zusaetzlichen
// Variante, siehe Issue #20).
$presets = \FriendsOfRedaxo\FFmpeg\Presets::all();
// 'custom' allows keeping the command field as-is for manual editing
$presets['custom'] = '';

// Einstellungen speichern
if (rex_post('formsubmit', 'string') == '1' && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
} elseif (rex_post('formsubmit', 'string') == '1') {
    $config = rex_post('config', [
        ['command_overwrite', 'string'],
        ['command_new_file', 'string'],
        ['delete', 'string'],
        ['preset_overwrite', 'string'],
        ['preset_new_file', 'string'],
        ['category_id', 'int'],
    ]);

    // Preset (falls nicht 'custom'/'none') ueberschreibt das jeweilige
    // Kommandofeld -- getrennt pro Modus, siehe Issue #20/#21-Diskussion:
    // "Original ueberschreiben" und "Webversion erstellen" hatten bisher
    // ein gemeinsames Preset, obwohl beide fachlich unterschiedliche Ziele
    // haben (Original ersetzen vs. schlanke Web-Variante).
    if (!empty($config['preset_overwrite']) && 'custom' !== $config['preset_overwrite'] && 'none' !== $config['preset_overwrite'] && isset($presets[$config['preset_overwrite']])) {
        $config['command_overwrite'] = $presets[$config['preset_overwrite']];
    }
    if (!empty($config['preset_new_file']) && 'custom' !== $config['preset_new_file'] && 'none' !== $config['preset_new_file'] && isset($presets[$config['preset_new_file']])) {
        $config['command_new_file'] = $presets[$config['preset_new_file']];
    }

    $this->setConfig($config);

    echo rex_view::success($this->i18n('config_saved'));
}

// Abwaertskompatibilitaet: vor der Trennung gab es nur ein gemeinsames
// config['command']/config['preset'] -- das war der bisher einzige
// tatsaechlich variabel genutzte Pfad (Webversion), siehe
// Converter::resolveBaseCommand(). Als Vorbelegung nur anzeigen, wenn noch
// keine eigene Webversion-Einstellung existiert.
$legacyCommand = (string) $this->getConfig('command', '');
$commandOverwrite = (string) $this->getConfig('command_overwrite', (string) ($presets['archive'] ?? ''));
$commandNewFile = (string) $this->getConfig('command_new_file', '' !== $legacyCommand ? $legacyCommand : (string) ($presets['web'] ?? ''));
$presetOverwrite = (string) $this->getConfig('preset_overwrite', 'archive');
$presetNewFile = (string) $this->getConfig('preset_new_file', '' !== $legacyCommand ? 'custom' : 'web');

/**
 * Baut einen Preset+Kommando+Vorschau-Block fuer einen Modus (overwrite
 * oder new_file). Beide Bloecke sind strukturell identisch, nur Feldnamen/
 * Labels/Defaults unterscheiden sich -- siehe Issue #20/#21: der fruehere,
 * einzelne Block liess unklar, wofuer die Einstellung eigentlich gilt.
 *
 * @return string
 */
$renderModeBlock = static function (rex_addon $addon, array $presets, string $idPrefix, string $fieldSuffix, string $introKey, string $command, string $preset) {
    $block = '<p class="text-muted">' . $addon->i18n($introKey) . '</p>';

    $formElements = [];
    $n = [];
    $n['label'] = '<label for="ffmpeg-config-preset-' . $idPrefix . '">' . $addon->i18n('config_preset') . '</label>';
    $select = new rex_select();
    $select->setId('ffmpeg-config-preset-' . $idPrefix);
    $select->setAttribute('class', 'form-control');
    $select->setName('config[preset_' . $fieldSuffix . ']');
    $select->addOption($addon->i18n('config_preset_standard'), 'standard');
    $select->addOption($addon->i18n('config_preset_web'), 'web');
    $select->addOption($addon->i18n('config_preset_mobile'), 'mobile');
    $select->addOption($addon->i18n('config_preset_archive'), 'archive');
    $select->addOption($addon->i18n('config_preset_1080p'), '1080p');
    $select->addOption($addon->i18n('config_preset_720p'), '720p');
    $select->addOption($addon->i18n('config_preset_480p'), '480p');
    $select->addOption($addon->i18n('config_preset_360p'), '360p');
    $select->addOption($addon->i18n('config_preset_custom'), 'custom');
    $select->setSelected($preset);
    $n['field'] = $select->get();
    $formElements[] = $n;
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $block .= $fragment->parse('core/form/container.php');
    $block .= '<p class="help-block">' . $addon->i18n('config_preset_help') . '</p>';

    $formElements = [];
    $n = [];
    $n['label'] = '<label for="ffmpeg-config-command-' . $idPrefix . '">' . $addon->i18n('command') . '</label>';
    $n['field'] = '<input class="form-control" type="text" id="ffmpeg-config-command-' . $idPrefix . '" name="config[command_' . $fieldSuffix . ']" value="' . htmlspecialchars($command) . '"/>';
    $formElements[] = $n;
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $block .= $fragment->parse('core/form/container.php');
    $block .= '<p class="help-block">' . $addon->i18n('config_command_help') . '</p>';

    $block .= '<div class="form-group"><label>' . $addon->i18n('config_preview_label') . '</label><div id="ffmpeg-config-preview-' . $idPrefix . '" class="well well-sm" style="white-space:pre-wrap;">' . htmlspecialchars($command) . '</div></div>';

    return $block;
};

$content .= '<fieldset><legend>' . $this->i18n('config_legend_general') . '</legend>';

$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-category">' . $this->i18n('config_media_category') . '</label>';
$select = new rex_media_category_select();
$select->setId('ffmpeg-config-category');
$select->setAttribute('class', 'form-control');
$select->setName('config[category_id]');
$select->addOption($this->i18n('config_media_category_none'), 0);
$select->setSelected((int) $this->getConfig('category_id', 0));
$n['field'] = $select->get();
$formElements[] = $n;
$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/container.php');
$content .= '<p class="help-block">' . $this->i18n('config_media_category_help') . '</p>';

$content .= '</fieldset>';

$content .= '<fieldset><legend>' . $this->i18n('config_legend_overwrite') . '</legend>';
$content .= $renderModeBlock($this, $presets, 'overwrite', 'overwrite', 'config_intro_overwrite', $commandOverwrite, $presetOverwrite);
$content .= '</fieldset>';

$content .= '<fieldset><legend>' . $this->i18n('config_legend_new_file') . '</legend>';
$content .= $renderModeBlock($this, $presets, 'new-file', 'new_file', 'config_intro_new_file', $commandNewFile, $presetNewFile);

// Quell-Datei loeschen gilt NUR beim Erstellen einer Webversion (siehe
// Converter::finalizeNewFile()) -- beim Ueberschreiben gibt es keine
// separate Quelldatei zu loeschen, das Original IST die Ausgabedatei.
// Deshalb hier im new_file-Block platziert statt lose am Seitenende.
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-delete">' . $this->i18n('config_delete') . '</label>';
$n['field'] = '<input type="checkbox" id="ffmpeg-config-delete" name="config[delete]"' . (!empty($this->getConfig('delete')) && $this->getConfig('delete') == '1' ? ' checked="checked"' : '') . ' value="1" />';
$formElements[] = $n;
$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$content .= $fragment->parse('core/form/checkbox.php');
$content .= '<p class="help-block">' . $this->i18n('config_delete_help') . '</p>';

$content .= '</fieldset>';

// Preset-Uebersicht als aufklappbares Akkordeon statt dauerhaft sichtbarer
// Liste -- reine Referenz/Nachschlagehilfe, muss nicht permanent Platz auf
// der Seite beanspruchen (Bootstrap panel-collapse, bereits an anderer
// Stelle im Backend verwendet, z.B. snippets-Addon). Pfeil-Icon dreht sich
// per reinem CSS (kein zusaetzliches JS) je nach aria-expanded-Zustand, den
// Bootstraps collapse-Plugin selbst auf dem Toggle-Link pflegt.
$content .= '<style>
    .ffmpeg-preset-reference-toggle .rex-icon { transition: transform 0.15s ease; }
    .ffmpeg-preset-reference-toggle[aria-expanded="true"] .rex-icon { transform: rotate(90deg); }
</style>';
$content .= '<fieldset><div class="panel panel-default" style="margin-bottom:0; border:0; box-shadow:none;">
    <a data-toggle="collapse" href="#ffmpeg-preset-reference" class="collapsed panel-title ffmpeg-preset-reference-toggle" style="display:block;" aria-expanded="false">
        <i class="rex-icon fa-solid fa-angle-right"></i> ' . $this->i18n('config_legend_preset_reference') . '
    </a>
    <div id="ffmpeg-preset-reference" class="panel-collapse collapse">
        <ul class="list-unstyled small" style="margin-top:10px;">';
$content .= '<li><strong>' . $this->i18n('config_preset_web') . ':</strong> ' . $this->i18n('config_preset_web_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_mobile') . ':</strong> ' . $this->i18n('config_preset_mobile_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_archive') . ':</strong> ' . $this->i18n('config_preset_archive_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_standard') . ':</strong> ' . $this->i18n('config_preset_standard_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_1080p') . ':</strong> ' . $this->i18n('config_preset_1080p_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_720p') . ':</strong> ' . $this->i18n('config_preset_720p_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_480p') . ':</strong> ' . $this->i18n('config_preset_480p_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_360p') . ':</strong> ' . $this->i18n('config_preset_360p_desc') . '</li>';
$content .= '<li><strong>' . $this->i18n('config_preset_custom') . ':</strong> ' . $this->i18n('config_preset_custom_desc') . '</li>';
$content .= '</ul>
    </div>
</div></fieldset>';

// Preset-Auswahl -> Kommandofeld + Vorschau live aktualisieren, getrennt
// pro Block (Suffixe 'overwrite'/'new-file'). Vorschau nur sichtbar bzw.
// aktualisiert, wenn tatsaechlich ein Preset gewaehlt ist -- bei 'custom'
// zeigt sie einfach den manuell eingegebenen Befehl.
$content .= '<script>' . PHP_EOL;
$content .= 'const ffmpegPresets = ' . json_encode($presets) . ';' . PHP_EOL;
$content .= "jQuery(document).on('rex:ready', function () {" . PHP_EOL;
$content .= "  ['overwrite', 'new-file'].forEach(function (idPrefix) {" . PHP_EOL;
$content .= "    var sel = document.getElementById('ffmpeg-config-preset-' + idPrefix);" . PHP_EOL;
$content .= "    var cmd = document.getElementById('ffmpeg-config-command-' + idPrefix);" . PHP_EOL;
$content .= "    var preview = document.getElementById('ffmpeg-config-preview-' + idPrefix);" . PHP_EOL;
$content .= "    if (!sel || !cmd) { return; }" . PHP_EOL;
$content .= "    function updatePreview() {" . PHP_EOL;
$content .= "      if (preview) { preview.textContent = cmd.value; }" . PHP_EOL;
$content .= "    }" . PHP_EOL;
$content .= "    function onChange() {" . PHP_EOL;
$content .= "      var val = sel.value;" . PHP_EOL;
$content .= "      if (val !== 'custom' && val in ffmpegPresets) { cmd.value = ffmpegPresets[val]; }" . PHP_EOL;
$content .= "      updatePreview();" . PHP_EOL;
$content .= "    }" . PHP_EOL;
$content .= "    jQuery(sel).off('change.ffmpeg').on('change.ffmpeg', onChange);" . PHP_EOL;
$content .= "    jQuery(cmd).off('input.ffmpeg').on('input.ffmpeg', updatePreview);" . PHP_EOL;
$content .= "    updatePreview();" . PHP_EOL;
$content .= "  });" . PHP_EOL;
$content .= "});" . PHP_EOL;
$content .= '</script>' . PHP_EOL;

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
