<?php

namespace FriendsOfRedaxo\FFmpeg\Api;

use Exception;
use FriendsOfRedaxo\FFmpeg\Job\VideoJob;
use FriendsOfRedaxo\FFmpeg\Presets;
use FriendsOfRedaxo\FFmpeg\Shell;
use rex;
use rex_addon;
use rex_api_exception;
use rex_api_function;
use rex_backend_login;
use rex_file;
use rex_i18n;
use rex_logger;
use rex_media_service;
use rex_path;
use rex_response;
use rex_sql;

/**
 * Job-Engine fuer Videokonvertierung (Redesign, siehe CHANGELOG). Ersetzt die
 * bisherige session-gebundene Status-Verfolgung (rex_session
 * 'ffmpeg_conversion_id'/'ffmpeg_conversion_status') und die Abschluss-
 * Erkennung per Text-Suche im ffmpeg-Log ("Konvertierung abgeschlossen",
 * "was successfully added to rex_mediapool") durch VideoJob (siehe dort) --
 * globale, session-unabhaengige Job-Datei + echter Exit-Code als Abschluss-
 * Signal statt Text-Heuristik.
 *
 * Zwei Modi:
 * - 'new_file' (Default, bisheriges Verhalten unveraendert): Ausgabe als
 *   neue "web_<name>.<ext>"-Mediendatei, Original bleibt unangetastet,
 *   Metadaten (Titel/Beschreibung/Copyright) werden mit "(weboptimiert)"-
 *   Suffix kopiert.
 * - 'overwrite' (neu, genutzt von MediaPlace's "Video optimieren"-Button):
 *   ersetzt die Original-Mediendatei in-place ueber rex_media_service::
 *   updateMedia() -- Metadaten bleiben automatisch erhalten (gleiche
 *   Datenbank-Zeile, nur die Datei wird ausgetauscht), siehe finalizeOverwrite().
 *
 * Nur ein Job gleichzeitig (VideoJob::findActive(), global -- nicht mehr nur
 * pro Session): ein zweiter Start-Versuch waehrend ein Job laeuft wird mit
 * status=busy abgelehnt, nicht eingereiht (bewusst kein echtes Queueing,
 * siehe VideoJob-Klassenkommentar).
 */
class Converter extends rex_api_function
{
    /**
     * Nur im Backend (bis 4.9 auch über das Frontend aufrufbar) und nur mit CSRF-Token: ohne Token hätte eine fremde
     * Webseite im Namen eines angemeldeten Redakteurs Konvertierungen starten und Originale überschreiben können.
     * Aufrufe aus dem Backend bekommen das Token über rex.ffmpeg_api (boot.php).
     */
    protected $published = false;

    protected function requiresCsrfProtection(): bool
    {
        return true;
    }

    public function execute()
    {
        try {
            $user = rex::getUser();
            if (!$user || !$user->hasPerm('ffmpeg[]')) {
                throw new rex_api_exception('Unauthorized access');
            }

            $func = rex_request('func', 'string', '');
            // Medienpool-Recht für die Kategorie des Videos (wie im Medienpool selbst)
            $media = 'start' === $func ? \rex_media::get(rex_request('video', 'string', '')) : null;
            if ('start' === $func && (!$media || !$user->getComplexPerm('media')->hasCategoryPerm($media->getCategoryId()))) {
                throw new rex_api_exception('Unauthorized access');
            }

            switch ($func) {
                case 'start':
                    $result = $this->handleStart(
                        rex_request('video', 'string', ''),
                        rex_request('mode', 'string', 'new_file'),
                        (bool) rex_request('confirm_overwrite', 'boolean', false),
                        $user,
                        (bool) rex_request('create_new', 'boolean', false),
                        rex_request('preset', 'string', ''),
                    );
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                case 'job_status':
                    $result = $this->handleJobStatus(rex_request('job', 'string', ''));
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                default:
                    throw new rex_api_exception('Invalid function');
            }
        } catch (Exception $e) {
            rex_logger::logException($e);
            rex_response::cleanOutputBuffers();
            rex_response::setStatus(rex_response::HTTP_INTERNAL_ERROR);
            rex_response::sendJson(['error' => $e->getMessage()]);
            exit;
        }
    }

    /**
     * Fuer main.php: aktuell laufender Job (falls einer existiert), fuer den
     * initialen Seiten-Render VOR jedem JS-Polling. Ersetzt das bisherige
     * statische Converter::getConversionInfo() (session-basiert).
     *
     * @return array{id: string, video: string, mode: string, status: string, progress: int}|null
     */
    public static function getActiveJobInfo(): ?array
    {
        $job = VideoJob::findActive();
        if (!$job) {
            return null;
        }

        return [
            'id' => $job->id,
            'video' => $job->inputFile,
            'mode' => $job->mode,
            'status' => $job->status,
            'progress' => $job->progress,
        ];
    }

    /**
     * Oeffentliche Schnittstelle fuer andere Addons (genutzt vom MediaPlace-
     * Addon fuer dessen "Video optimieren"-Button, siehe dortiges
     * FfmpegIntegration/rex_api_mediaplace_video_optimize.php): startet einen
     * Job im 'overwrite'-Modus (Original wird ersetzt statt eine neue
     * "web_"-Datei anzulegen). Der Aufrufer ist selbst fuer alle eigenen
     * Rechte-/Verfuegbarkeitspruefungen verantwortlich -- diese Methode
     * prueft nur, was Converter ohnehin fuer jeden Job prueft (Datei
     * vorhanden, kein anderer Job aktiv).
     *
     * rex_api_function hat einen protected Konstruktor, den handleStart()/
     * handleJobStatus() nicht brauchen (kein Request-Kontext-State wird
     * gelesen) -- newInstanceWithoutConstructor() ist hier bewusst in
     * Converter selbst gekapselt, statt Aufrufer dazu zu zwingen, das
     * Konstruktor-Detail dieser Klasse zu kennen.
     *
     * @return array<string, mixed>
     */
    public static function startOverwriteJob(string $video, \rex_user $user): array
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        return $instance->handleStart($video, 'overwrite', false, $user);
    }

    /**
     * Oeffentliches Pendant zu startOverwriteJob() fuers Polling -- siehe
     * dortigen Kommentar.
     *
     * @return array<string, mixed>
     */
    public static function pollJob(string $jobId): array
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        return $instance->handleJobStatus($jobId);
    }

    /**
     * Schliesst einen Job ab, dessen ffmpeg-Prozess fertig ist, den aber niemand mehr im Browser
     * verfolgt (Tab geschlossen, Job vom Cronjob gestartet). Bisher passierte die Uebernahme in den
     * Medienpool nur beim Polling aus dem Browser -- ohne offenen Tab blieb das Ergebnis liegen und
     * der Job galt nach 30 Minuten als "verwaist". Aufgerufen bei jedem Backend-Seitenaufruf, vom
     * Cronjob und vom Konsolenbefehl; kostet nichts, solange kein Job laeuft.
     *
     * @return VideoJob|null der abgeschlossene Job
     */
    public static function finalizePending(): ?VideoJob
    {
        $job = VideoJob::findActive();
        if (!$job || !$job->hasExited()) {
            return null;
        }
        self::pollJob($job->id);

        return VideoJob::load($job->id);
    }

    /**
     * Startet einen 'overwrite'-Job mit einem bestimmten Kommando ohne Backend-Benutzer (Cronjob,
     * Konsole) -- siehe AutoOptimizer. $extra landet im Job (z. B. ['auto' => true]).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function startJobWithCommand(string $video, string $command, string $login, array $extra = [], string $mode = 'overwrite'): array
    {
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        return $instance->handleStart($video, $mode, false, $login, false, '', $command, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param \rex_user|string $user Backend-Benutzer oder (Cronjob/Konsole) nur ein Login-Name fuers Protokoll
     * @param string|null $commandOverride Kommando statt der Einstellung (AutoOptimizer)
     * @param array<string, mixed> $extra
     */
    protected function handleStart(string $video, string $mode, bool $confirmOverwrite, \rex_user|string $user, bool $createNew = false, string $preset = '', ?string $commandOverride = null, array $extra = []): array
    {
        if ('' === $video) {
            throw new rex_api_exception('No video file selected');
        }
        if (!in_array($mode, ['new_file', 'overwrite'], true)) {
            $mode = 'new_file';
        }

        $activeJob = VideoJob::findActive();
        if ($activeJob) {
            return [
                'status' => 'busy',
                'message' => 'Es laeuft bereits eine Konvertierung (' . $activeJob->inputFile . '). Bitte warten.',
            ];
        }

        $input = rex_path::media($video);
        if (!is_file($input)) {
            throw new rex_api_exception('Input file not found: ' . $video);
        }

        $outputFilename = null;
        if ('new_file' === $mode && !empty($extra['output'])) {
            // Name vom Aufrufer vorgegeben und bereits eindeutig (z. B. Cut::outputName())
            $outputFilename = (string) $extra['output'];
        } elseif ('new_file' === $mode) {
            $baseName = pathinfo($video, PATHINFO_FILENAME);
            $outputFilename = 'web_' . $baseName . '.mp4';

            // "Neue Variante erstellen" statt Ueberschreiben (siehe Issue #20):
            // naechsten freien Namen suchen (web_<name>_2.mp4, _3.mp4, ...)
            // statt den Nutzer zu zwingen, die vorhandene Datei erst
            // herunterzuladen/umzubenennen/neu hochzuladen.
            if ($createNew) {
                $outputFilename = $this->findNextFreeVariantName($baseName);
            } else {
                $mediaSql = rex_sql::factory();
                $existingMedia = $mediaSql->getArray(
                    'SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?',
                    [$outputFilename],
                );
                if (!empty($existingMedia) && !$confirmOverwrite) {
                    return [
                        'status' => 'confirm_overwrite',
                        'message' => 'Ein Video mit dem Namen "' . $outputFilename . '" existiert bereits.',
                        'existing_filename' => $outputFilename,
                    ];
                }
            }
        }

        // Kommando aus den Einstellungen (Presets: Groesse/Qualitaet/Ton -- keine frei eingegebenen
        // Kommandos mehr, siehe Presets). Beim Anlegen einer zusaetzlichen Variante (Issue #20) kann
        // eine andere Groesse NUR fuer diesen einen Lauf gewaehlt werden. $commandOverride kommt nie
        // aus einer Anfrage, nur aus dem Code (AutoOptimizer).
        $presetCommand = $createNew && '' !== $preset ? Presets::command($preset, 'new_file') : null;
        $command = trim((string) ($commandOverride ?? $presetCommand ?? Presets::forMode($mode)));
        // Erweiterungspunkt: Kommando eines Auftrags anpassen (Platzhalter INPUT / OUTPUT.<endung> bleiben)
        $command = trim((string) \rex_extension::registerPoint(new \rex_extension_point('FFMPEG_JOB_COMMAND', $command, [
            'video' => $video,
            'mode' => $mode,
            'extra' => $extra,
        ]))) . ' ';
        preg_match('/OUTPUT\.([^\s]+)/', $command, $extMatch);
        $fileExt = '' !== ($extMatch[1] ?? '') ? trim($extMatch[1]) : 'mp4';

        // Fuer 'overwrite' landet die ffmpeg-Ausgabe zunaechst in einer
        // TEMPORAEREN Datei (nicht am finalen Zielpfad) -- erst nach
        // erfolgreichem Abschluss ersetzt finalizeOverwrite() damit die
        // Original-Mediendatei. Bricht ffmpeg mitten in der Konvertierung ab,
        // bleibt das Original unangetastet.
        //
        // Wichtig: die Ausgabe-EXTENSION wird fuer 'overwrite' bewusst vom
        // ORIGINAL uebernommen, nicht von der Konfiguration -- der Dateiname
        // (inkl. Extension) IST die Identitaet der Mediendatei in REDAXO
        // (rex_path::media($filename)), ein anderer Extension wuerde die
        // Original-Datei nicht "ersetzen", sondern eine zweite mit anderem
        // Namen erzeugen. $fileExt bleibt fuer 'new_file' unveraendert die
        // konfigurierte Ziel-Extension.
        if ('overwrite' === $mode) {
            $originalExt = strtolower(pathinfo($video, PATHINFO_EXTENSION)) ?: $fileExt;
            $command = str_ireplace('OUTPUT.' . $fileExt, 'OUTPUT.' . $originalExt, $command);
            $fileExt = $originalExt;
            $outputPath = rex_path::addonData('ffmpeg', 'job_tmp_' . bin2hex(random_bytes(8)) . '.' . $fileExt);
        } else {
            // $outputFilename (oben berechnet) hat bereits die konfigurierte
            // Ziel-Extension ".mp4" -- hier NICHT nochmal $fileExt anhaengen,
            // sonst Doppel-Extension bei findNextFreeVariantName()-Namen.
            $outputPath = rex_path::media((string) $outputFilename);
        }

        // Optimieren (Original ersetzen): spielt das Original nicht zuverlässig im Browser, wird es auch ersetzt, wenn das
        // Ergebnis größer ist – sonst nur, wenn es kleiner wird (finalizeOverwrite()). Schnitt und Warteschlange ersetzen
        // immer, die automatische Optimierung bringt ihre eigene Einschätzung mit.
        if ('overwrite' === $mode && empty($extra['cut']) && empty($extra['queue']) && !array_key_exists('compat', $extra)) {
            $extra['compat'] = false === \FriendsOfRedaxo\FFmpeg\VideoInfo::isWebCompatible($video);
        }
        $job = VideoJob::create($mode, $video, $outputPath, $user instanceof \rex_user ? (string) $user->getLogin() : $user);
        $job->extra = $extra;
        $job->save();

        $escapedInput = escapeshellarg($input);
        $escapedOutput = escapeshellarg($outputPath);
        $command = str_ireplace('OUTPUT.' . $fileExt, $escapedOutput, $command);
        $command = str_ireplace(['INPUT', 'OUTPUT'], [$escapedInput, $escapedOutput], $command);

        rex_file::put($job->logPath, 'Konvertierung für "' . $video . '" gestartet um ' . date('d.m.Y H:i:s') . "\n" . 'Kommando: ' . $command . "\n\n");

        rex_logger::factory()->log('info', 'FFmpeg start for {video}', ['video' => $video, 'command' => $command, 'job' => $job->id]);

        // Exit-Code der ffmpeg-Ausfuehrung selbst in eine Datei schreiben --
        // das ist das deterministische Abschluss-Signal (siehe
        // handleJobStatus()), statt wie bisher Text im Log zu suchen oder die
        // Prozess-Aktivitaet ueber die letzte Aenderungszeit der Log-Datei zu
        // erraten (beides war eine Fehlerquelle: ein kurzzeitig "stilles" aber
        // noch laufendes ffmpeg wurde faelschlich als fertig gewertet).
        $exitFile = escapeshellarg($job->exitCodePath);
        $logFile = $job->logPath;
        if (str_starts_with(PHP_OS, 'WIN')) {
            $started = Shell::background('start /B cmd /C "' . $command . ' 1> "' . $logFile . '" 2>&1 & echo %errorlevel% > ' . $exitFile . '"');
        } else {
            $started = Shell::background('(' . $command . ' 1> ' . escapeshellarg($logFile) . ' 2>&1; echo $? > ' . $exitFile . ')');
        }
        if (!$started) {
            rex_file::put($job->logPath, rex_i18n::msg('ffmpeg_shell_disabled') . "\n", FILE_APPEND);
            return ['status' => 'error', 'message' => rex_i18n::msg('ffmpeg_shell_disabled'), 'job' => $job->id];
        }

        return ['status' => 'started', 'job' => $job->id];
    }

    /**
     * Naechsten freien Dateinamen fuer eine weitere Webversion desselben
     * Originals finden (web_<name>.mp4, web_<name>_2.mp4, web_<name>_3.mp4,
     * ...) -- siehe Issue #20: bisher konnte man nur eine einzige
     * Webversion pro Original haben, ein zweiter Lauf konnte sie nur
     * ueberschreiben oder abbrechen. Deckt den Normalfall (ein paar
     * Varianten) ab, ohne eine neue Namens-/Preset-Verwaltung einzufuehren.
     */
    private function findNextFreeVariantName(string $baseName): string
    {
        $candidate = 'web_' . $baseName . '.mp4';
        $suffix = 2;

        $sql = rex_sql::factory();
        while (!empty($sql->getArray('SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?', [$candidate]))) {
            $candidate = 'web_' . $baseName . '_' . $suffix . '.mp4';
            ++$suffix;
        }

        return $candidate;
    }

    /**
     * Einziger Polling-Endpunkt fuer den Client (ersetzt die bisherigen
     * func=progress/done/status/check_all): liefert Fortschritt UND stoesst
     * beim echten Abschluss (Exit-Code-Datei vorhanden) automatisch die
     * Finalisierung (Medienpool-Registrierung/-Ueberschreiben) an -- der
     * Client muss dafuer keinen separaten Request mehr schicken.
     *
     * @return array<string, mixed>
     */
    protected function handleJobStatus(string $jobId): array
    {
        $job = VideoJob::load($jobId);
        if (!$job) {
            return ['status' => 'error', 'message' => 'Job nicht gefunden'];
        }

        if ($job->isTerminal()) {
            return $this->jobResponse($job);
        }

        $logContent = is_file($job->logPath) ? rex_file::get($job->logPath) : '';
        $job->progress = $this->parseProgress($logContent, (float) ($job->extra['duration'] ?? 0));

        if (is_file($job->exitCodePath)) {
            $exitCode = (int) trim((string) rex_file::get($job->exitCodePath));
            if (0 === $exitCode) {
                $job->status = VideoJob::STATUS_FINALIZING;
                $job->save();
                $job = 'overwrite' === $job->mode ? $this->finalizeOverwrite($job) : $this->finalizeNewFile($job);
            } else {
                $job->status = VideoJob::STATUS_ERROR;
                $job->message = 'ffmpeg beendet mit Exit-Code ' . $exitCode . '.';
                $job->save();
            }
            // egal wer den Job abschliesst (Browser, Backend, Cronjob, Konsole): Ergebnis der
            // automatischen Optimierung festhalten, damit ein Video nicht endlos wiederholt wird
            \FriendsOfRedaxo\FFmpeg\AutoOptimizer::afterJob($job);
            \FriendsOfRedaxo\FFmpeg\Cut::afterJob($job);
            // Erweiterungspunkt für eigene Workflows: jeder abgeschlossene Auftrag (fertig oder Fehler)
            \rex_extension::registerPoint(new \rex_extension_point('FFMPEG_JOB_DONE', null, [
                'job' => $job,
                'video' => $job->inputFile,
                'result' => $job->extra['result']['filename'] ?? null,
                'status' => $job->status,
                'mode' => $job->mode,
                'extra' => $job->extra,
            ], true));
            // vorgemerkte Aufträge (Queue) weiterlaufen lassen
            \FriendsOfRedaxo\FFmpeg\Queue::run();

            return $this->jobResponse($job);
        }

        $job->save();

        return $this->jobResponse($job);
    }

    /** @param float $expected erwartete Länge des Ergebnisses (z. B. beim Schneiden), sonst die Länge der Quelle */
    private function parseProgress(string $logContent, float $expected = 0.0): int
    {
        $duration = $expected;
        if ($duration <= 0.0) {
            preg_match('/Duration: (.*?), start:/ms', $logContent, $matches);
            if (!empty($matches[1])) {
                $duration = $this->timecodeToSeconds($matches[1]);
            }
        }
        if ($duration <= 0.0) {
            return 0;
        }

        preg_match_all('/time=(.*?) bitrate/', $logContent, $matches);
        $rawTime = !empty($matches[1]) ? end($matches[1]) : null;
        if (!$rawTime) {
            return 0;
        }

        $time = $this->timecodeToSeconds($rawTime);

        return (int) max(0, min(99, round(($time / $duration) * 100)));
    }

    private function timecodeToSeconds(string $timecode): float
    {
        $parts = array_reverse(explode(':', trim($timecode)));
        $seconds = (float) ($parts[0] ?? 0);
        $seconds += ((float) ($parts[1] ?? 0)) * 60;
        $seconds += ((float) ($parts[2] ?? 0)) * 3600;

        return $seconds;
    }

    /**
     * @return array<string, mixed>
     */
    private function jobResponse(VideoJob $job): array
    {
        return [
            'status' => $job->status,
            'progress' => $job->isTerminal() && VideoJob::STATUS_DONE === $job->status ? 100 : $job->progress,
            'message' => $job->message,
            'video' => $job->inputFile,
            'mode' => $job->mode,
            'log' => is_file($job->logPath) ? rex_file::get($job->logPath) : '',
            'result' => $job->extra['result'] ?? null,
        ];
    }

    /**
     * Neue "web_<name>"-Mediendatei anlegen, Metadaten vom Original mit
     * "(weboptimiert)"-Suffix kopieren, optional Quelldatei loeschen
     * (Konfiguration "delete"). Existiert die Webversion schon (erneuter
     * Lauf fuers selbe Original, siehe Issue #21), wird sie stattdessen
     * direkt ersetzt statt per addMedia() neu anzulegen.
     */
    private function finalizeNewFile(VideoJob $job): VideoJob
    {
        if (!is_file($job->outputPath) || 0 === filesize($job->outputPath)) {
            $job->status = VideoJob::STATUS_ERROR;
            $job->message = 'Ausgabedatei wurde nicht erzeugt.';
            $job->save();

            return $job;
        }

        $categoryId = (int) rex_addon::get('ffmpeg')->getConfig('category_id', 0);
        $outputFileName = pathinfo($job->outputPath, PATHINFO_BASENAME);

        // Webversion existiert bereits (z.B. erneuter Lauf fuer dasselbe
        // Original) -- addMedia() waere ein INSERT und scheitert am
        // bestehenden filename (siehe Issue #21). Direkt ersetzen, analog zu
        // finalizeOverwrite(): Datei austauschen, nur filetype/filesize/
        // updatedate aktualisieren, bestehende Metadaten (ggf. seither
        // manuell bearbeitet) bleiben unangetastet statt erneut vom
        // Original kopiert zu werden.
        $existingMedia = \rex_media::get($outputFileName);
        if ($existingMedia) {
            $dstFile = rex_path::media($outputFileName);
            $filetype = rex_file::mimeType($job->outputPath, $outputFileName) ?: $existingMedia->getType();

            if (!rex_file::move($job->outputPath, $dstFile)) {
                $job->status = VideoJob::STATUS_ERROR;
                $job->message = 'Konnte Webversion nicht an den Zielort verschieben.';
                @unlink($job->outputPath);
                $job->save();

                return $job;
            }
            @chmod($dstFile, rex::getFilePerm());

            $sql = rex_sql::factory();
            $sql->setTable(rex::getTable('media'));
            $sql->setWhere(['filename' => $outputFileName]);
            $sql->setValue('filetype', $filetype);
            $sql->setValue('filesize', filesize($dstFile));
            $sql->addGlobalUpdateFields($job->userLogin);
            $sql->update();

            \rex_media_cache::delete($outputFileName);
            \rex_extension::registerPoint(new \rex_extension_point('MEDIA_UPDATED', '', [
                'id' => $existingMedia->getId(),
                'filename' => $outputFileName,
                'title' => $existingMedia->getTitle(),
                'category_id' => $existingMedia->getCategoryId(),
                'type' => $filetype,
                'filetype' => $filetype,
            ]));
        } else {
            try {
                $syncResult = rex_media_service::addMedia([
                    'file' => ['name' => $outputFileName, 'path' => $job->outputPath],
                    'category_id' => $categoryId,
                    'title' => '',
                ], false);
                if (empty($syncResult['ok'])) {
                    throw new \rex_api_exception((string) ($syncResult['message'] ?? 'addMedia failed'));
                }
            } catch (\rex_api_exception $e) {
                $job->status = VideoJob::STATUS_ERROR;
                $job->message = 'Import fehlgeschlagen: ' . $e->getMessage();
                $job->save();

                return $job;
            }

            $this->copyMetadataForNewFile($job->inputFile, $outputFileName, !empty($job->extra['cut']) ? ' (' . \rex_i18n::rawMsg('ffmpeg_cut_title_suffix') . ')' : ' (weboptimiert)');
        }

        // „Quell-Datei löschen“ gilt nur für Webversionen, nie beim Schneiden (das Original bleibt Ausgangsmaterial)
        if (empty($job->extra['cut']) && empty($job->extra['keep_source']) && 1 === (int) rex_addon::get('ffmpeg')->getConfig('delete')) {
            try {
                rex_media_service::deleteMedia($job->inputFile);
            } catch (\rex_api_exception $e) {
                rex_logger::factory()->log('warning', 'ffmpeg: delete source failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        $job->status = VideoJob::STATUS_DONE;
        $job->progress = 100;
        $job->extra['result'] = ['filename' => $outputFileName];
        $job->save();

        return $job;
    }

    private function copyMetadataForNewFile(string $originalFilename, string $outputFileName, string $titleSuffix = ' (weboptimiert)'): void
    {
        $sql = rex_sql::factory();
        $tableFields = array_column($sql->getArray('DESCRIBE ' . rex::getTable('media')), 'Field');

        $metaFieldMappings = [
            'title' => ['title'],
            'description' => ['description', 'art_description', 'med_description'],
            'copyright' => ['copyright', 'art_copyright', 'med_copyright'],
        ];
        $selectFields = ['filename'];
        foreach ($metaFieldMappings as $possibleFields) {
            foreach ($possibleFields as $field) {
                if (in_array($field, $tableFields, true)) {
                    $selectFields[] = $field;
                }
            }
        }

        $originalData = $sql->getArray(
            'SELECT ' . implode(', ', $selectFields) . ' FROM ' . rex::getTable('media') . ' WHERE filename = ?',
            [$originalFilename],
        );
        if (empty($originalData)) {
            return;
        }

        $updateSql = rex_sql::factory();
        $updateSql->setTable(rex::getTable('media'));
        $updateSql->setWhere(['filename' => $outputFileName]);
        $updatedFields = [];

        foreach ($metaFieldMappings as $fieldType => $possibleFields) {
            foreach ($possibleFields as $field) {
                if (in_array($field, $tableFields, true) && !empty($originalData[0][$field])) {
                    $value = $originalData[0][$field];
                    if ('title' === $fieldType) {
                        $value .= $titleSuffix;
                    }
                    $updateSql->setValue($field, $value);
                    $updatedFields[] = $field;
                }
            }
        }

        if ([] !== $updatedFields) {
            $updateSql->update();
        }
    }

    /**
     * Neu: Original-Mediendatei in-place ersetzen statt eine neue anzulegen.
     * rex_media_service::updateMedia() mit 'file.path' spiegelt exakt das
     * Muster, das auch Chunk-Uploads/das cropper-Addon fuers Ersetzen einer
     * bestehenden Datei nutzen -- Titel/Kategorie/Metainfo-Felder bleiben
     * automatisch erhalten (gleiche Datenbank-Zeile), kein manuelles Kopieren
     * einzelner Felder noetig wie bei finalizeNewFile().
     */
    private function finalizeOverwrite(VideoJob $job): VideoJob
    {
        if (!is_file($job->outputPath) || 0 === filesize($job->outputPath)) {
            $job->status = VideoJob::STATUS_ERROR;
            $job->message = 'Ausgabedatei wurde nicht erzeugt.';
            @unlink($job->outputPath);
            $job->save();

            return $job;
        }

        $media = \rex_media::get($job->inputFile);
        if (!$media) {
            $job->status = VideoJob::STATUS_ERROR;
            $job->message = 'Original-Mediendatei nicht mehr vorhanden.';
            @unlink($job->outputPath);
            $job->save();

            return $job;
        }

        // Direktes Ersetzen statt rex_media_service::updateMedia(): dessen
        // 'file'-Zweig erwartet zwingend 'tmp_name' + identische Extension
        // zwischen Original und neuer Datei (sonst pool_file_upload_errortype) --
        // die Extension bleibt hier zwar durch handleStart() bewusst identisch,
        // aber der Rest des Uploads-Zweigs (Fehlercodes, MIME-Whitelist fuer
        // Bilder) ist auf Video-Transcoding nicht zugeschnitten. Titel/Kategorie/
        // Metainfo-Felder bleiben unangetastet (gleiche Datenbank-Zeile, nur
        // filetype/filesize/updatedate werden aktualisiert) -- genau die vom
        // Nutzer gewuenschte "Metas bleiben erhalten"-Semantik.
        $dstFile = rex_path::media($job->inputFile);
        $filetype = rex_file::mimeType($job->outputPath, $job->inputFile) ?: $media->getType();
        $originalSize = $media->getSize();

        // Optimieren (Button, MediaPlace, automatisch): nur ersetzen, wenn die Datei wirklich kleiner wird -- sonst
        // bleibt das Original und gilt als optimiert. Ausnahme 'compat': das Original spielt in Browsern nicht
        // zuverlaessig ab (Codec/Farbformat/Fast Start). Schnitt ("Original ersetzen") und Warteschlange ersetzen immer.
        if (empty($job->extra['cut']) && empty($job->extra['queue']) && empty($job->extra['compat']) && (int) filesize($job->outputPath) >= $originalSize) {
            @unlink($job->outputPath);
            \FriendsOfRedaxo\FFmpeg\Job\OptimizedVideoRegistry::markOptimized($job->inputFile, $originalSize, $originalSize, (int) $media->getUpdateDate());
            $job->status = VideoJob::STATUS_DONE;
            $job->progress = 100;
            $job->message = \rex_i18n::rawMsg('ffmpeg_optimize_kept');
            $job->extra['result'] = ['filename' => $job->inputFile, 'kept' => true];
            $job->save();

            return $job;
        }

        if (!rex_file::move($job->outputPath, $dstFile)) {
            $job->status = VideoJob::STATUS_ERROR;
            $job->message = 'Konnte optimierte Datei nicht an den Zielort verschieben.';
            @unlink($job->outputPath);
            $job->save();

            return $job;
        }
        @chmod($dstFile, rex::getFilePerm());

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('media'));
        $sql->setWhere(['filename' => $job->inputFile]);
        $sql->setValue('filetype', $filetype);
        $sql->setValue('filesize', filesize($dstFile));
        $sql->addGlobalUpdateFields($job->userLogin);
        $sql->update();

        \rex_media_cache::delete($job->inputFile);
        \rex_extension::registerPoint(new \rex_extension_point('MEDIA_UPDATED', '', [
            'id' => $media->getId(),
            'filename' => $job->inputFile,
            'title' => $media->getTitle(),
            'category_id' => $media->getCategoryId(),
            'type' => $filetype,
            'filetype' => $filetype,
        ]));

        // "Bereits optimiert" merken (siehe OptimizedVideoRegistry) --
        // mediapool.ffmpeg.main.php erkennt den bisherigen "new_file"-Modus an
        // der separaten "web_<name>"-Datei, die es beim Ueberschreiben-Modus
        // nicht gibt. Frisch aus der DB gelesen statt eines selbst
        // mitgefuehrten Zeitstempels, damit der Fingerabdruck exakt dem
        // entspricht, was addGlobalUpdateFields() oben tatsaechlich
        // gespeichert hat (kein Rundungs-/Zeitzonenrisiko).
        $updatedMedia = \rex_media::get($job->inputFile);
        if ($updatedMedia) {
            \FriendsOfRedaxo\FFmpeg\Job\OptimizedVideoRegistry::markOptimized(
                $job->inputFile,
                $originalSize,
                $updatedMedia->getSize(),
                $updatedMedia->getUpdateDate(),
            );
        }

        $job->status = VideoJob::STATUS_DONE;
        $job->progress = 100;
        $job->extra['result'] = ['filename' => $job->inputFile];
        $job->save();

        return $job;
    }
}
