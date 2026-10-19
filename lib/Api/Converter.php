<?php

namespace FriendsOfRedaxo\FFmpeg\Api;

use Exception;
use FriendsOfRedaxo\FFmpeg\Job\VideoJob;
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
    protected $published = true;

    public function execute()
    {
        try {
            $user = rex_backend_login::createUser();
            if (!$user) {
                throw new rex_api_exception('Unauthorized access - no backend login');
            }

            $func = rex_request('func', 'string', '');

            switch ($func) {
                case 'start':
                    $result = $this->handleStart(
                        rex_request('video', 'string', ''),
                        rex_request('mode', 'string', 'new_file'),
                        (bool) rex_request('confirm_overwrite', 'boolean', false),
                        $user,
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
     * @return array<string, mixed>
     */
    protected function handleStart(string $video, string $mode, bool $confirmOverwrite, \rex_user $user): array
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

        if ('new_file' === $mode) {
            $outputFilename = 'web_' . pathinfo($video, PATHINFO_FILENAME) . '.mp4';
            $mediaSql = rex_sql::factory();
            $existingMedia = $mediaSql->getArray(
                'SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?',
                [$outputFilename],
            );
            if (!empty($existingMedia) && !$confirmOverwrite) {
                return [
                    'status' => 'confirm_overwrite',
                    'message' => 'Ein Video mit dem Namen "' . $outputFilename . '" existiert bereits. Überschreiben?',
                ];
            }
        }

        // Kommando aus Konfiguration -- unveraendertes Platzhalter-Prinzip
        // (INPUT/OUTPUT.<ext>), damit bestehende individuelle Kommandos
        // weiter funktionieren.
        $command = trim((string) rex_addon::get('ffmpeg')->getConfig('command')) . ' ';
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
            $outputPath = rex_path::media('web_' . pathinfo($video, PATHINFO_FILENAME)) . '.' . $fileExt;
        }

        $job = VideoJob::create($mode, $video, $outputPath, (string) $user->getLogin());
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
        $job->progress = $this->parseProgress($logContent);

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

            return $this->jobResponse($job);
        }

        $job->save();

        return $this->jobResponse($job);
    }

    private function parseProgress(string $logContent): int
    {
        preg_match('/Duration: (.*?), start:/ms', $logContent, $matches);
        $duration = 0.0;
        if (!empty($matches[1])) {
            $duration = $this->timecodeToSeconds($matches[1]);
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
     * Bisheriges Verhalten unveraendert: neue "web_<name>"-Mediendatei
     * anlegen, Metadaten vom Original mit "(weboptimiert)"-Suffix kopieren,
     * optional Quelldatei loeschen (Konfiguration "delete").
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

        $this->copyMetadataForNewFile($job->inputFile, $outputFileName);

        if (1 === (int) rex_addon::get('ffmpeg')->getConfig('delete')) {
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

    private function copyMetadataForNewFile(string $originalFilename, string $outputFileName): void
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
                        $value .= ' (weboptimiert)';
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
