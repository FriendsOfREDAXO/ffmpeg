<?php

// Media Manager Effekte für Videos registrieren

use FriendsOfRedaxo\FFmpeg\Api\Converter;

if (rex_addon::get('media_manager')->isAvailable()) {
    rex_media_manager::addEffect('rex_effect_video_to_webp');
    rex_media_manager::addEffect('rex_effect_video_to_preview');
}

// APIs – alle nur im Backend und mit Rechteprüfung; einzige Ausnahme ffmpeg_track (Untertitel für <track> auf der
// Website, liefert nur als Spur verknüpfte .vtt-Dateien, die ohnehin im Medienpool liegen, und prüft isPermitted())
rex_api_function::register('ffmpeg_convert', Converter::class);
rex_api_function::register('ffmpeg_editor', \FriendsOfRedaxo\FFmpeg\Api\Editor::class);
rex_api_function::register('ffmpeg_stream', \FriendsOfRedaxo\FFmpeg\Api\Stream::class);
rex_api_function::register('ffmpeg_track', \FriendsOfRedaxo\FFmpeg\Api\Track::class);

// Gelöschte Medien: Verknüpfungen (Untertitel) aufräumen
rex_extension::register('MEDIA_DELETED', static function (rex_extension_point $ep): void {
    \FriendsOfRedaxo\FFmpeg\Links::forget((string) $ep->getParam('filename'));
});

// Automatische Optimierung (Video-Tools → Einstellungen): Cronjob-Typ und sofortiger Start nach dem
// Hochladen -- der Cronjob arbeitet den Rest ab und übernimmt fertige Konvertierungen
if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType(\FriendsOfRedaxo\FFmpeg\Cronjob\AutoOptimizeCronjob::class);
}
if (\FriendsOfRedaxo\FFmpeg\AutoOptimizer::isEnabled()) {
    rex_extension::register('MEDIA_ADDED', static function (rex_extension_point $ep): void {
        if (\FriendsOfRedaxo\FFmpeg\VideoInfo::isSupportedVideoFile((string) $ep->getParam('filename'))) {
            \FriendsOfRedaxo\FFmpeg\AutoOptimizer::run();
        }
    }, rex_extension::LATE);
}


if (rex::isBackend() && rex::getUser()) {
    // Hilfe in der Sprache des Backends (README.de.md / README.md)
    rex_extension::register('PAGES_PREPARED', static function (): void {
        $page = rex_be_controller::getPageObject('mediapool/ffmpeg/help');
        if ($page && str_starts_with(rex_i18n::getLanguage(), 'de')) {
            $page->setSubPath(rex_path::addon('ffmpeg', 'README.de.md'));
        }
    });
    // CSRF-Token für die Aufrufe aus dem Backend (script.js, Medienpool-Formular, Editoren)
    if (rex::getUser()->hasPerm('ffmpeg[]')) {
        rex_view::setJsProperty('ffmpeg_api', Converter::getUrlParams());
        rex_view::setJsProperty('ffmpeg_editor', \FriendsOfRedaxo\FFmpeg\Api\Editor::getUrlParams());
    }
    // Add JavaScript to ffmpeg page
    if (rex_be_controller::getCurrentPagePart(2) == 'ffmpeg') {
        $addon = rex_addon::get('ffmpeg');

        $jsUrl = $addon->getAssetsUrl('js/script.js');
        $cssUrl = $addon->getAssetsUrl('css/style.css');

        $jsPath = $addon->getAssetsPath('js/script.js');
        if (is_file($jsPath)) {
            $jsUrl .= '?v=' . (string) filemtime($jsPath);
        }

        $cssPath = $addon->getAssetsPath('css/style.css');
        if (is_file($cssPath)) {
            $cssUrl .= '?v=' . (string) filemtime($cssPath);
        }

        $trimmerJsUrl = $addon->getAssetsUrl('js/trimmer.js');
        $trimmerJsPath = $addon->getAssetsPath('js/trimmer.js');
        if (is_file($trimmerJsPath)) {
            $trimmerJsUrl .= '?v=' . (string) filemtime($trimmerJsPath);
        }

        $trimmerCssUrl = $addon->getAssetsUrl('css/trimmer.css');
        $trimmerCssPath = $addon->getAssetsPath('css/trimmer.css');
        if (is_file($trimmerCssPath)) {
            $trimmerCssUrl .= '?v=' . (string) filemtime($trimmerCssPath);
        }

        rex_view::addJsFile($jsUrl);
        rex_view::addCssFile($cssUrl);
        rex_view::addJsFile($trimmerJsUrl);
        rex_view::addCssFile($trimmerCssUrl);
        // Schnitt- und Untertitel-Editor
        foreach (['js/editor.js' => 'addJsFile', 'css/editor.css' => 'addCssFile'] as $asset => $method) {
            rex_view::$method($addon->getAssetsUrl($asset) . '?v=' . (string) @filemtime($addon->getAssetsPath($asset)));
        }
    }
    
    // Sicherstellen, dass die Datenverzeichnisse existieren
    $dataPath = rex_addon::get('ffmpeg')->getDataPath();
    if (!file_exists($dataPath)) {
        mkdir($dataPath, 0777, true);
    }

    // Alte Job-/Log-/Exit-Dateien aufraeumen (aelter als 7 Tage) -- Job-Status
    // ist seit dem Redesign global (nicht mehr session-gebunden), siehe
    // FriendsOfRedaxo\FFmpeg\Job\VideoJob. Die alten status_*/log*-Dateien
    // sowie die session-basierte Wiederherstellung entfallen damit komplett.
    \FriendsOfRedaxo\FFmpeg\Job\VideoJob::cleanupOld(7 * 24 * 60 * 60);

    // Fertige Konvertierung übernehmen, auch wenn niemand sie mehr im Browser verfolgt (Tab
    // geschlossen, vom Cronjob gestartet) -- sonst blieb das Ergebnis liegen, siehe
    // Converter::finalizePending(). Nicht bei den Polling-Aufrufen selbst (die schließen ohnehin ab).
    // Erst nach dem Laden aller AddOns: die Übernahme löst MEDIA_UPDATED aus.
    if ('ffmpeg_convert' !== rex_request('rex-api-call', 'string', '')) {
        rex_extension::register('PACKAGES_INCLUDED', static function (): void {
            \FriendsOfRedaxo\FFmpeg\Api\Converter::finalizePending();
        });
    }

    // "Optimieren"-Button auch im KLASSISCHEN Medienpool-Bearbeiten-Formular
    // (nicht nur in ffmpeg's eigener Video-Tools-Seite oder im MediaPlace-
    // Addon) -- gleicher Hook wie cropper (siehe dessen boot.php), gleiche
    // Job-Engine wie ueberall sonst (Converter, mode=overwrite). WICHTIG:
    // der Rueckgabewert MUSS $ep->getSubject() voranstellen statt ihn zu
    // ersetzen -- das metainfo-Addon haengt seine eigenen Formularfelder
    // ueber denselben Erweiterungspunkt an (siehe rex_metainfo_media_handler::
    // extendForm()); ein reines "return $html;" wie bei cropper's eigenem
    // Hook wuerde je nach Registrierungsreihenfolge dessen Felder verwerfen.
    // Kein separates JS/CSS noetig -- das kleine Steuerskript ist bewusst
    // inline (dieselbe Technik nutzt bereits das MediaPlace-Addon fuer
    // window.ffmpegActiveJob), damit dieser Hook keine Asset-Ladebedingung
    // in boot.php fuer eine dritte Seite braucht.
    rex_extension::register('MEDIA_FORM_EDIT', static function (rex_extension_point $ep) {
        $user = rex::getUser();
        if (!$user || !$user->hasPerm('ffmpeg[]')) {
            return;
        }

        $mediaSql = $ep->getParam('media');
        $filename = $mediaSql instanceof rex_sql ? (string) $mediaSql->getValue('filename') : '';

        // Gleiche Einschraenkung wie in mediapool.ffmpeg.main.php: "Optimieren"
        // (Original ersetzen) bleibt bewusst nur fuer mp4-Quellen angeboten,
        // die Ausgabe-Extension ist beim Ueberschreiben-Modus zwingend
        // identisch zur Quelle.
        if (!\FriendsOfRedaxo\FFmpeg\VideoInfo::isSupportedVideoFile($filename) || !\FriendsOfRedaxo\FFmpeg\VideoInfo::isAvailable()) {
            return;
        }
        // Schneiden und Untertitel für alle Videos
        $tools = '<p class="ffmpeg-media-form-tools">'
            . '<a class="btn btn-default btn-sm" href="' . rex_url::backendPage('mediapool/ffmpeg/trimmer', ['video' => $filename]) . '"><i class="rex-icon fa-cut" aria-hidden="true"></i> ' . rex_i18n::msg('ffmpeg_trimmer_cut_video') . '</a> '
            . '<a class="btn btn-default btn-sm" href="' . rex_url::backendPage('mediapool/ffmpeg/subtitles', ['video' => $filename]) . '"><i class="rex-icon fa-cc" aria-hidden="true"></i> ' . rex_i18n::msg('ffmpeg_subtitles') . '</a>'
            . '</p>';
        if ('mp4' !== strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            return $ep->getSubject() . $tools;
        }

        $media = rex_media::get($filename);
        if (!$media) {
            return;
        }

        $fingerprint = \FriendsOfRedaxo\FFmpeg\Job\OptimizedVideoRegistry::getIfCurrent($filename, $media->getSize(), $media->getUpdateDate());
        // rawMsg() statt msg(): der Wert wird unten in $html noch per rex_escape()
        // in den HTML-Kontext eingebettet -- msg()'s eigenes automatisches
        // Escaping wuerde sonst doppelt greifen.
        $buttonLabel = $fingerprint ? rex_i18n::rawMsg('ffmpeg_reoptimize_this_video') : rex_i18n::rawMsg('ffmpeg_optimize_this_video');

        $badge = '';
        if ($fingerprint && $fingerprint['originalSize'] > 0) {
            $rate = (int) round(100 - (($media->getSize() / $fingerprint['originalSize']) * 100));
            if ($rate > 0) {
                $badge = '<p class="ffmpeg-media-form-badge" style="color:#27ae60;margin:4px 0 0;font-size:12px;"><i class="fa fa-check" aria-hidden="true"></i> '
                    . rex_escape($rate . '% ' . rex_i18n::rawMsg('ffmpeg_smaller')) . '</p>';
            }
        }

        $widgetId = 'ffmpeg-optimize-' . md5($filename);
        $html = '
        <div id="' . rex_escape($widgetId) . '" class="ffmpeg-media-form-optimize" data-filename="' . rex_escape($filename) . '">
            <button type="button" class="btn btn-default ffmpeg-media-form-optimize-btn"><i class="fa fa-compress" aria-hidden="true"></i> ' . rex_escape($buttonLabel) . '</button>
            <span class="ffmpeg-media-form-optimize-status" style="margin-left:8px;font-size:12px;"></span>
            ' . $badge . '
        </div>
        <script>
        (function () {
            var root = document.getElementById(' . json_encode($widgetId) . ');
            if (!root) { return; }
            var btn = root.querySelector(".ffmpeg-media-form-optimize-btn");
            var status = root.querySelector(".ffmpeg-media-form-optimize-status");
            var filename = root.getAttribute("data-filename");
            function setStatus(html) { status.innerHTML = html; }
            function fail(msg) { setStatus(\'<span style="color:#c0392b">\' + msg + "</span>"); btn.disabled = false; }
            function poll(jobId) {
                fetch("index.php?" + new URLSearchParams(Object.assign({}, rex.ffmpeg_api, { func: "job_status", job: jobId })), { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if ("done" === data.status) {
                            setStatus(\'<i class="fa fa-check" aria-hidden="true"></i> \' + ' . json_encode(rex_i18n::rawMsg('ffmpeg_status_optimized')) . ');
                            setTimeout(function () { window.location.reload(); }, 800);
                            return;
                        }
                        if ("error" === data.status) { fail(data.message || "Fehlgeschlagen"); return; }
                        var p = "finalizing" === data.status ? 99 : (parseInt(data.progress, 10) || 0);
                        setStatus(p + "%");
                        setTimeout(function () { poll(jobId); }, 1500);
                    })
                    .catch(function (e) { fail(e.message); });
            }
            btn.addEventListener("click", function () {
                btn.disabled = true;
                setStatus(' . json_encode(rex_i18n::rawMsg('ffmpeg_optimize_video_starting_short')) . ');
                fetch("index.php?" + new URLSearchParams(Object.assign({}, rex.ffmpeg_api, { func: "start", video: filename, mode: "overwrite" })), { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.error) { fail(data.error); return; }
                        if ("busy" === data.status) { fail(data.message || ' . json_encode(rex_i18n::rawMsg('ffmpeg_optimize_video_busy_short')) . '); return; }
                        poll(data.job);
                    })
                    .catch(function (e) { fail(e.message); });
            });
        })();
        </script>';

        return $ep->getSubject() . $tools . $html;
    });
}
