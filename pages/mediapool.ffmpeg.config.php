<?php

use FriendsOfRedaxo\FFmpeg\Presets;

/** @var rex_addon $this */

$csrfToken = rex_csrf_token::factory('ffmpeg');

// Einstellungen speichern
if (rex_post('formsubmit', 'string') == '1' && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
} elseif (rex_post('formsubmit', 'string') == '1') {
    $config = rex_post('config', [
        ['delete', 'string'],
        ['category_id', 'int'],
        ['overwrite_size', 'string'],
        ['overwrite_quality', 'string'],
        ['overwrite_audio', 'string'],
        ['new_file_size', 'string'],
        ['new_file_quality', 'string'],
        ['new_file_audio', 'string'],
        ['auto_enabled', 'bool'],
        ['auto_preset', 'string'],
        ['auto_scope', 'string'],
    ]);
    foreach (['overwrite', 'new_file'] as $mode) {
        foreach (['size' => Presets::SIZES, 'quality' => Presets::QUALITIES, 'audio' => Presets::AUDIO] as $key => $allowed) {
            if (!in_array($config[$mode . '_' . $key], $allowed, true)) {
                $config[$mode . '_' . $key] = Presets::settings($mode)[$key];
            }
        }
    }

    // Automatische Optimierung: "neu" heißt "ab dem Einschalten hochgeladen"
    if ($config['auto_enabled'] && !$this->getConfig('auto_enabled', false)) {
        $config['auto_since'] = time();
    }
    $config['auto_preset'] = in_array($config['auto_preset'], \FriendsOfRedaxo\FFmpeg\AutoOptimizer::PRESETS, true) ? $config['auto_preset'] : '1080p';
    $config['auto_scope'] = 'all' === $config['auto_scope'] ? 'all' : 'new';
    $cronjob = \FriendsOfRedaxo\FFmpeg\Cronjob\AutoOptimizeCronjob::ensure((bool) $config['auto_enabled']);

    // Einstellungen bis 4.9 (frei eingegebene Kommandos, Preset-Namen) sind jetzt übernommen
    foreach (['command', 'command_overwrite', 'command_new_file', 'preset', 'preset_overwrite', 'preset_new_file'] as $legacy) {
        $this->removeConfig($legacy);
    }

    $this->setConfig($config);

    echo rex_view::success($this->i18n('config_saved'));
    if ($config['auto_enabled'] && null === $cronjob) {
        echo rex_view::warning($this->i18n('auto_no_cronjob_addon'));
    }
}

// Ein Formularfeld (Label + Feld + Hilfetext) im Backend-Layout
$field = static function (string $id, string $label, string $field, string $help = ''): string {
    $fragment = new rex_fragment();
    $fragment->setVar('elements', [[
        'label' => '<label for="' . $id . '">' . $label . '</label>',
        'field' => $field . ('' !== $help ? '<p class="help-block" id="' . $id . '-help">' . $help . '</p>' : ''),
    ]], false);

    return $fragment->parse('core/form/container.php');
};

// Auswahl Größe / Qualität / Ton für einen Modus -- feste, erprobte Einstellungen statt frei
// eingegebener ffmpeg-Kommandos (siehe FriendsOfRedaxo\FFmpeg\Presets)
$modeBlock = function (string $mode, string $intro) use ($field): string {
    $current = Presets::settings($mode);
    $html = '<p class="text-muted">' . $intro . '</p>';
    foreach (['size' => Presets::SIZES, 'quality' => Presets::QUALITIES, 'audio' => Presets::AUDIO] as $key => $values) {
        $id = 'ffmpeg-config-' . $mode . '-' . $key;
        $select = new rex_select();
        $select->setId($id);
        $select->setName('config[' . $mode . '_' . $key . ']');
        $select->setAttribute('class', 'form-control');
        $select->setAttribute('aria-describedby', $id . '-help');
        foreach ($values as $value) {
            $select->addOption($this->i18n($key . '_' . $value), $value);
        }
        $select->setSelected($current[$key]);
        $html .= $field($id, $this->i18n('option_' . $key), $select->get(), $this->i18n('option_' . $key . '_help'));
    }

    return $html;
};

$sections = '';

// Allgemein (Medien-Kategorie)
$general = '';
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
$general .= $fragment->parse('core/form/container.php');
$general .= '<p class="help-block">' . $this->i18n('config_media_category_help') . '</p>';

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('config_legend_general'), false);
$fragment->setVar('body', $general, false);
$sections .= $fragment->parse('core/page/section.php');

// Automatische Optimierung (Cronjob) -- siehe FriendsOfRedaxo\FFmpeg\AutoOptimizer
$auto = '<p class="text-muted">' . $this->i18n('auto_intro') . '</p>';
$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-auto-enabled">' . $this->i18n('auto_enabled') . '</label>';
$n['field'] = '<input type="checkbox" id="ffmpeg-config-auto-enabled" name="config[auto_enabled]" value="1"' . ($this->getConfig('auto_enabled', false) ? ' checked="checked"' : '') . ' />';
$formElements[] = $n;
$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$auto .= $fragment->parse('core/form/checkbox.php');

$formElements = [];
$n = [];
$n['label'] = '<label for="ffmpeg-config-auto-preset">' . $this->i18n('auto_preset') . '</label>';
$select = new rex_select();
$select->setId('ffmpeg-config-auto-preset');
$select->setAttribute('class', 'form-control');
$select->setAttribute('aria-describedby', 'ffmpeg-config-auto-preset-help');
$select->setName('config[auto_preset]');
foreach (\FriendsOfRedaxo\FFmpeg\AutoOptimizer::PRESETS as $autoPreset) {
    $select->addOption($this->i18n('auto_preset_' . $autoPreset), $autoPreset);
}
$select->setSelected(\FriendsOfRedaxo\FFmpeg\AutoOptimizer::preset());
$n['field'] = $select->get() . '<p class="help-block" id="ffmpeg-config-auto-preset-help">' . $this->i18n('auto_preset_help') . '</p>';
$formElements[] = $n;

$n = [];
$n['label'] = '<label for="ffmpeg-config-auto-scope">' . $this->i18n('auto_scope') . '</label>';
$select = new rex_select();
$select->setId('ffmpeg-config-auto-scope');
$select->setAttribute('class', 'form-control');
$select->setName('config[auto_scope]');
$select->addOption($this->i18n('auto_scope_new'), 'new');
$select->addOption($this->i18n('auto_scope_all'), 'all');
$select->setSelected((string) $this->getConfig('auto_scope', 'new'));
$n['field'] = $select->get();
$formElements[] = $n;
$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$auto .= $fragment->parse('core/form/container.php');

// Status: wartende Videos, laufende Konvertierung, Cronjob
if ($this->getConfig('auto_enabled', false)) {
    $status = [];
    $active = \FriendsOfRedaxo\FFmpeg\Job\VideoJob::findActive();
    if ($active) {
        $status[] = $this->i18n('auto_status_running', $active->inputFile, (int) $active->progress);
    }
    $status[] = $this->i18n('auto_status_pending', \FriendsOfRedaxo\FFmpeg\AutoOptimizer::pending());
    if (!rex_addon::get('cronjob')->isAvailable()) {
        $status[] = '<strong>' . $this->i18n('auto_no_cronjob_addon') . '</strong>';
    } elseif (rex::getUser()?->isAdmin()) {
        $status[] = '<a href="' . rex_url::backendPage('cronjob/cronjobs') . '">' . $this->i18n('auto_cronjob_link') . '</a>';
    }
    $status[] = $this->i18n('auto_console_hint');
    $auto .= '<div class="alert alert-info" role="status"><ul class="list-unstyled" style="margin:0"><li>' . implode('</li><li>', $status) . '</li></ul></div>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('auto_legend'), false);
$fragment->setVar('body', $auto, false);
$sections .= $fragment->parse('core/page/section.php');

// Original ersetzen („Optimieren“)
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('config_legend_overwrite'), false);
$fragment->setVar('body', $modeBlock('overwrite', $this->i18n('config_intro_overwrite')), false);
$sections .= $fragment->parse('core/page/section.php');

// Webversion erstellen (neue Datei) + "Quell-Datei löschen" -- gilt nur hier: beim Ersetzen gibt es
// keine separate Quelldatei (siehe Converter::finalizeNewFile())
$newFile = $modeBlock('new_file', $this->i18n('config_intro_new_file'));
$fragment = new rex_fragment();
$fragment->setVar('elements', [[
    'label' => '<label for="ffmpeg-config-delete">' . $this->i18n('config_delete') . '</label>',
    'field' => '<input type="checkbox" id="ffmpeg-config-delete" name="config[delete]" value="1"' . ('1' == $this->getConfig('delete') ? ' checked="checked"' : '') . ' />',
]], false);
$newFile .= $fragment->parse('core/form/checkbox.php');
$newFile .= '<p class="help-block">' . $this->i18n('config_delete_help') . '</p>';
$fragment = new rex_fragment();
$fragment->setVar('title', $this->i18n('config_legend_new_file'), false);
$fragment->setVar('body', $newFile, false);
$sections .= $fragment->parse('core/page/section.php');

// Immer enthalten (Hinweis statt Einstellung)
$sections .= '<p class="text-muted"><i class="rex-icon fa-info-circle" aria-hidden="true"></i> ' . $this->i18n('config_always') . '</p>';

// Speichern
$fragment = new rex_fragment();
$fragment->setVar('elements', [['field' => '<button class="btn btn-save rex-form-aligned" type="submit" name="save" value="1">' . $this->i18n('config_save') . '</button>']], false);
$buttons = '<fieldset class="rex-form-action">' . $fragment->parse('core/form/submit.php') . '</fieldset>';

echo '
<form action="' . rex_url::currentBackendPage() . '" method="post">
    <input type="hidden" name="formsubmit" value="1" />
    ' . $csrfToken->getHiddenField() . '
    ' . $sections . $buttons . '
</form>';
