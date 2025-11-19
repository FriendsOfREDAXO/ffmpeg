<?php
namespace FriendsOfRedaxo\FFmpeg\Api;

use Exception;
use rex;
use rex_addon;
use rex_api_exception;
use rex_api_function;
use rex_backend_login;
use rex_file;
use rex_formatter;
use rex_logger;
use rex_path;
use rex_response;
use rex_sql;

class Converter extends rex_api_function
{
    protected $published = true;
    
    // Status-Konstanten
    const STATUS_PENDING = 'pending';
    const STATUS_CONVERTING = 'converting';
    const STATUS_IMPORTING = 'importing';
    const STATUS_DONE = 'done';
    const STATUS_ERROR = 'error';

    public function execute()
    {
        try {
            // Backend User Check
            $user = rex_backend_login::createUser();
            if (!$user) {
                throw new rex_api_exception('Unauthorized access - no backend login');
            }

            $func = rex_request('func', 'string', '');
            $video = rex_request('video', 'string', '');

            switch ($func) {
                case 'start':
                    $result = $this->handleStart($video);
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                case 'progress':
                    $result = $this->handleProgress();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;

                case 'done':
                    $result = $this->handleDone();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($result);
                    exit;
                    
                case 'status':
                    // Rufe die interne Status-Methode auf und sende das Ergebnis
                    $statusData = $this->checkStatus();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($statusData);
                    exit;

                case 'debug':
                    // Return tail of current log and status for debugging
                    $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
                    $log = '';
                    if (!empty($conversionId)) {
                        $logFile = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
                        if (file_exists($logFile)) {
                            $log = rex_file::get($logFile);
                        }
                    }
                    $debugData = ['conversion_id' => $conversionId, 'log' => $log, 'status' => $this->getConversionStatus(rex_request('video', 'string', ''))];
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($debugData);
                    exit;
                
                case 'check_all':
                    $allStatus = $this->checkAllVideos();
                    rex_response::cleanOutputBuffers();
                    rex_response::sendJson($allStatus);
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
    
    // Status in Session speichern
    /**
     * Store conversion status in session and persist to file
     *
     * @param string $status
     * @param string|null $videoName
     * @return void
     */
    private function setConversionStatus(string $status, ?string $videoName = null): void
    {
        // In Session speichern (wie bisher)
        rex_set_session('ffmpeg_conversion_status', $status);

        // Zusätzlich in Datei speichern, wenn Video-Name bekannt
        if ($videoName) {
            $statusFile = rex_addon::get('ffmpeg')->getDataPath('status_' . md5($videoName) . '.json');
            $statusData = [
                'status' => $status,
                'video' => $videoName,
                'timestamp' => time(),
                'conversion_id' => rex_session('ffmpeg_conversion_id', 'string', '')
            ];
            rex_file::put($statusFile, json_encode($statusData));
        }
    }
    
    // Status aus Session lesen oder aus Datei, wenn Video-Name bekannt
    /**
     * Get conversion status from session or status file
     *
     * @param string|null $videoName
     * @return string
     */
    private function getConversionStatus(?string $videoName = null): string
    {
        // Zuerst Session-Status prüfen (für bestehende Sessions)
        $sessionStatus = rex_session('ffmpeg_conversion_status', 'string', null);
        
        if ($sessionStatus) {
            return $sessionStatus;
        }
        
        // Wenn kein Video angegeben und keine Session, können wir nichts tun
        if (!$videoName) {
            return self::STATUS_PENDING;
        }
        
        // Status-Datei versuchen zu finden
        $statusFile = rex_addon::get('ffmpeg')->getDataPath('status_' . md5($videoName) . '.json');
        if (file_exists($statusFile)) {
            $statusData = json_decode(rex_file::get($statusFile), true);
            return $statusData['status'] ?? self::STATUS_PENDING;
        }
        
        return self::STATUS_PENDING;
    }
    
    // Öffentliche statische Methode, um den Konvertierungsstatus zu überprüfen
    /**
     * Get current conversion information by session or status file
     *
     * @param string|null $videoName
     * @return array<string,mixed>
     */
    public static function getConversionInfo(?string $videoName = null): array
    {
        // Session-basierte Infos (wie bisher)
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        $conversionStatus = rex_session('ffmpeg_conversion_status', 'string', self::STATUS_PENDING);
        $active = false;
        $processInfo = [];
        
        // Wenn wir eine Session haben, verwenden wir diese
        if (!empty($conversionId)) {
            $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
            if (file_exists($log)) {
                $logContent = rex_file::get($log);
                
                // Prüfe, ob der Prozess abgeschlossen ist
                $isComplete = (
                    strpos($logContent, 'was successfully added to rex_mediapool') !== false ||
                    strpos($logContent, 'registration was not successful') !== false ||
                    strpos($logContent, 'Konvertierung abgeschlossen') !== false
                );
                
                if (!$isComplete) {
                    // Prüfe, ob der Prozess noch aktiv ist
                    $lastModified = filemtime($log);
                    $currentTime = time();
                    
                    // Wenn die Datei in den letzten 30 Sekunden geändert wurde,
                    // oder wir im Importstatus sind, betrachten wir sie als aktiv
                    if ($currentTime - $lastModified < 30 || $conversionStatus === self::STATUS_IMPORTING) {
                        $active = true;
                        
                        // Dateinamen aus Log extrahieren
                        preg_match('/Konvertierung für "(.*?)" gestartet/', $logContent, $matches);
                        $videoName = $matches[1] ?? '';
                        
                        $processInfo = [
                            'video' => $videoName,
                            'status' => $conversionStatus,
                            'startTime' => '',
                            'log' => $logContent
                        ];
                        
                        // Startzeit extrahieren
                        preg_match('/gestartet um (.*?)\\n/', $logContent, $timeMatches);
                        if (!empty($timeMatches[1])) {
                            $processInfo['startTime'] = $timeMatches[1];
                        }
                    } else if ($conversionStatus !== self::STATUS_DONE) {
                        // Wenn der Prozess nicht mehr aktiv ist und wir nicht im DONE-Status sind,
                        // betrachten wir ihn als abgebrochen
                        rex_set_session('ffmpeg_conversion_status', self::STATUS_ERROR);
                    }
                } else if ($conversionStatus !== self::STATUS_DONE) {
                    // Wenn die Erfolgsmeldung im Log steht, setzen wir explizit auf DONE
                    rex_set_session('ffmpeg_conversion_status', self::STATUS_DONE);
                }
            } else if ($conversionStatus !== self::STATUS_DONE && $conversionStatus !== self::STATUS_ERROR) {
                // Wenn das Log nicht existiert, aber wir einen Status haben, setzen wir auf ERROR
                rex_set_session('ffmpeg_conversion_status', self::STATUS_ERROR);
            }
        }
        // Wenn kein Session-basierter Status, aber ein Video-Name angegeben wurde
        else if ($videoName) {
            $statusFile = rex_addon::get('ffmpeg')->getDataPath('status_' . md5($videoName) . '.json');
            if (file_exists($statusFile)) {
                $statusData = json_decode(rex_file::get($statusFile), true);
                
                // Nur aktiv, wenn Status nicht DONE und Datei nicht zu alt
                if ($statusData['status'] !== self::STATUS_DONE && $statusData['status'] !== self::STATUS_ERROR) {
                    // Zeitstempel prüfen (30 Minuten Gültigkeit)
                    if (time() - $statusData['timestamp'] < 1800) {
                        $active = true;
                        $conversionStatus = $statusData['status'];
                        $conversionId = $statusData['conversion_id'] ?? '';
                        
                        // Log-Datei suchen
                        $logFile = '';
                        if (!empty($conversionId)) {
                            $logFile = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
                        } else {
                            // Fallback: Neueste Log-Datei suchen
                            $logFiles = glob(rex_addon::get('ffmpeg')->getDataPath('log*.txt'));
                            if (!empty($logFiles)) {
                                usort($logFiles, function($a, $b) {
                                    return filemtime($b) - filemtime($a);
                                });
                                $logFile = $logFiles[0];
                            }
                        }
                        
                        $logContent = '';
                        if (file_exists($logFile)) {
                            $logContent = rex_file::get($logFile);
                        }
                        
                        $processInfo = [
                            'video' => $videoName,
                            'status' => $conversionStatus,
                            'startTime' => date('d.m.Y H:i:s', $statusData['timestamp']),
                            'log' => $logContent
                        ];
                    }
                }
            }
        }
        
        return [
            'active' => $active,
            'status' => $conversionStatus,
            'info' => $processInfo
        ];
    }
    
    // Private Methode für interne API-Aufrufe
    /**
     * Wrapper to use inside the API to check status
     *
     * @return array<string,mixed>
     */
    private function checkStatus(): array
    {
        $video = rex_request('video', 'string', '');
        return self::getConversionInfo($video);
    }
    
    // Neue Methode zum Prüfen aller Videos
    /**
     * Get all active conversions
     *
     * @return array<string,mixed>
     */
    private function checkAllVideos(): array
    {
        // Alle Status-Dateien prüfen
        $statusFiles = glob(rex_addon::get('ffmpeg')->getDataPath('status_*.json'));
        $activeConversion = null;
        
        foreach ($statusFiles as $statusFile) {
            $statusData = json_decode(rex_file::get($statusFile), true);
            
            // Nur aktive Konvertierungen (nicht DONE, nicht ERROR)
            if ($statusData['status'] !== self::STATUS_DONE && $statusData['status'] !== self::STATUS_ERROR) {
                // Nur aktuelle Konvertierungen (max. 30 Minuten alt)
                if (time() - $statusData['timestamp'] < 1800) {
                    $conversionId = $statusData['conversion_id'] ?? '';
                    $logContent = '';
                    
                    // Log-Datei suchen
                    if (!empty($conversionId)) {
                        $logFile = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
                        if (file_exists($logFile)) {
                            $logContent = rex_file::get($logFile);
                        }
                    }
                    
                    $activeConversion = [
                        'active' => true,
                        'status' => $statusData['status'],
                        'info' => [
                            'video' => $statusData['video'],
                            'startTime' => date('d.m.Y H:i:s', $statusData['timestamp']),
                            'log' => $logContent
                        ]
                    ];
                    
                    // Erste aktive Konvertierung zurückgeben
                    break;
                }
            }
        }
        
        if ($activeConversion) {
            return $activeConversion;
        }
        
        // Session-basiert prüfen (wenn keine dateibasierte aktive Konvertierung gefunden)
        return self::getConversionInfo();
    }
    
    /**
     * @param string $conversionId
     * @return bool
     */
    protected function isProcessRunning(string $conversionId): bool
    {
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            return false;
        }
        
        $logContent = rex_file::get($log);
        
        // Wenn das Log in den letzten 30 Sekunden geändert wurde, betrachten wir den Prozess als aktiv
        $lastModified = filemtime($log);
        $currentTime = time();
        
        if ($currentTime - $lastModified < 30) {
            return true;
        }
        
        // Alternativ könnten wir auch prüfen, ob der Prozess in den letzten Zeilen aktiv war
        // Extrahiere die letzten 100 Zeichen des Logs
        $tailLog = substr($logContent, -100);
        
        // Überprüfen auf typische ffmpeg-Ausgaben während der Verarbeitung
        $activeKeywords = [
            'frame=', 'fps=', 'time=', 'bitrate=', 'speed='
        ];
        
        foreach ($activeKeywords as $keyword) {
            if (strpos($tailLog, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Append content to a log file with exclusive lock
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function appendLog(string $path, string $content): void
    {
        // Use rex_file::append to keep behaviour consistent with other addons
        // rex_file::append handles directory creation and locking
        rex_file::append($path, $content);
    }

    /**
     * @param string $video
     * @return array<string,mixed>
     */
    protected function handleStart(string $video): array
    {
        if (empty($video)) {
            throw new rex_api_exception('No video file selected');
        }
        
        // Prüfen, ob bereits eine Konvertierung läuft
        $conversionInfo = self::getConversionInfo();
        if ($conversionInfo['active']) {
            throw new rex_api_exception('Eine Konvertierung läuft bereits');
        }
        
        // Überprüfen, ob das Ausgabe-Video bereits existiert
        $outputFilename = 'web_' . pathinfo($video, PATHINFO_FILENAME) . '.mp4';
        $mediaSql = rex_sql::factory();
        $existingMedia = $mediaSql->getArray(
            'SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?',
            [$outputFilename]
        );
        
        if (!empty($existingMedia)) {
            // Überschreiben bestätigen lassen oder abbrechen
            if (rex_request('confirm_overwrite', 'boolean', false) !== true) {
                return [
                    'status' => 'confirm_overwrite',
                    'message' => 'Ein Video mit dem Namen "' . $outputFilename . '" existiert bereits. Überschreiben?'
                ];
            }
        }

        // Create unique ID for this conversion
        $conversionId = uniqid();
        rex_set_session('ffmpeg_conversion_id', $conversionId);
        $this->setConversionStatus(self::STATUS_CONVERTING, $video);

        // Create or clear log file
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        rex_file::put($log, '');

        // Setup input and output paths
        $input = rex_path::media($video);
        $output = rex_path::media('web_' . pathinfo($video, PATHINFO_FILENAME));

        // Get command from config
        $command = trim(rex_addon::get('ffmpeg')->getConfig('command')) . " ";

        // Extract output file extension from command, e.g. "OUTPUT.mp4" or "OUTPUT.mkv"
        // Use a robust regex to match OUTPUT.<extension>
        preg_match('/OUTPUT\.([^\s]+)/', $command, $extMatch);
        if (!empty($extMatch[1])) {
            $fileExt = trim($extMatch[1]);
            $outputFile = $output . '.' . $fileExt;
            rex_set_session('ffmpeg_input_video_file', $input);
            rex_set_session('ffmpeg_output_video_file', $outputFile);
        }

        // Replace placeholders in command. Replace OUTPUT.<ext> before OUTPUT to avoid double extensions
        $escapedInput = escapeshellarg($input);
        // Use the fully qualified output file (with extension) if available, otherwise fallback to base path
        $escapedOutput = escapeshellarg($outputFile ?? $output);

        // If we found an extension for OUTPUT (e.g., OUTPUT.mp4) prefer replacing OUTPUT.<ext> first
        if (!empty($fileExt)) {
            // Replace the specific OUTPUT.<ext> pattern to avoid accidental replacement of the
            // 'OUTPUT' token inside the 'OUTPUT.<ext>' string which could lead to double extensions
            $command = str_ireplace('OUTPUT.' . $fileExt, $escapedOutput, $command);

            // Replace INPUT and any leftover OUTPUT tokens
            $command = str_ireplace(['INPUT', 'OUTPUT'], [$escapedInput, $escapedOutput], $command);
        } else {
            $command = str_ireplace(['INPUT', 'OUTPUT'], [$escapedInput, $escapedOutput], $command);
        }

        // Schreibe Startinformationen ins Log
            $this->appendLog($log, 'Konvertierung für "' . $video . '" gestartet um ' . date('d.m.Y H:i:s') . "\n");
            $this->appendLog($log, 'Kommando: ' . $command . "\n\n");

        // Execute ffmpeg command in background
        rex_logger::factory()->log('info', 'FFmpeg start for {video}', ['video' => $video, 'command' => $command]);
        if (str_starts_with(PHP_OS, 'WIN')) {
            pclose(popen("start /B " . $command . " 1> $log 2>&1", "r")); // windows
        } else {
            shell_exec($command . " 1> $log 2>&1 >/dev/null &"); //linux
        }

        return ['status' => 'started', 'conversion_id' => $conversionId];
    }

    /**
     * @return array<string,mixed>
     */
    protected function handleProgress(): array
    {
        $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
        $video = rex_request('video', 'string', '');
        
        // Wenn keine Session-ID vorhanden ist, aber ein Video angegeben wurde, versuche den Status aus der Datei zu lesen
        if (empty($conversionId) && !empty($video)) {
            $statusFile = rex_addon::get('ffmpeg')->getDataPath('status_' . md5($video) . '.json');
            if (file_exists($statusFile)) {
                $statusData = json_decode(rex_file::get($statusFile), true);
                if (isset($statusData['conversion_id'])) {
                    $conversionId = $statusData['conversion_id'];
                    $conversionStatus = $statusData['status'];
                }
            }
        }
        
        if (empty($conversionId)) {
            return ['progress' => 'error', 'log' => 'No active conversion found'];
        }

        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            return ['progress' => 'error', 'log' => 'Log file not found'];
        }
        
        $getContent = rex_file::get($log);
        $currentStatus = $this->getConversionStatus($video);
        
        // Wenn wir bereits im Import-Status sind, zeigen wir den Fortschritt als 99%
        if ($currentStatus === self::STATUS_IMPORTING) {
            return ['progress' => 99, 'log' => $getContent, 'status' => 'importing'];
        }
        
        // Wenn wir bereits im Done-Status sind, zeigen wir den Fortschritt als fertig
        if ($currentStatus === self::STATUS_DONE) {
            return ['progress' => 'done', 'log' => $getContent, 'status' => 'done'];
        }

        // Parse progress from ffmpeg output
        preg_match("/Duration: (.*?), start:/ms", $getContent, $matches);
        if (!empty($rawDuration = $matches[1] ?? '')) {
            $ar = array_reverse(explode(":", $rawDuration));
            $duration = floatval($ar[0]);
            if (!empty($ar[1])) $duration += intval($ar[1]) * 60;
            if (!empty($ar[2])) $duration += intval($ar[2]) * 60 * 60;
        } else {
            $duration = 0;
        }

        preg_match_all("/time=(.*?) bitrate/", $getContent, $matches);
        $rawTime = array_pop($matches);
        if (is_array($rawTime)) {
            $rawTime = array_pop($rawTime);
        }

        if (!empty($rawTime)) {
            $ar = array_reverse(explode(":", $rawTime));
            $time = floatval($ar[0]);
            if (!empty($ar[1])) $time += intval($ar[1]) * 60;
            if (!empty($ar[2])) $time += intval($ar[2]) * 60 * 60;

            // Calculate progress percentage
            if ($duration > 0) {
                $progress = round(($time / $duration) * 100);
            } else {
                $progress = 0;
            }
        } else {
            $progress = 0;
        }

        // Check if conversion is complete based on log content and process status
        $conversionComplete = (
            $progress > 98 || 
            strpos($getContent, 'Qavg') !== false || 
            strpos($getContent, 'kb/s:') !== false || 
            strpos($getContent, 'video:') !== false
        );
        
        if ($conversionComplete && !$this->isProcessRunning($conversionId)) {
            // Die Konvertierung ist abgeschlossen, jetzt Import starten
            $this->setConversionStatus(self::STATUS_IMPORTING, $video);
            rex_logger::factory()->log('debug', 'FFmpeg threshold reached for {conversion_id} progress {progress}', ['conversion_id' => $conversionId, 'progress' => $progress]);
            
            // Wir zeigen den Fortschritt als 99%, da der Import noch läuft
            return ['progress' => 99, 'log' => $getContent, 'status' => 'importing'];
        }
        
        // Normale Fortschrittsanzeige
        return ['progress' => $progress, 'log' => $getContent, 'status' => 'converting'];
    }

    // TODO: Im API Converter (ex ffmpeg_api.php), die handleDone-Methode verbessern

    /**
     * @return array<string,mixed>
     */
    protected function handleDone(): array
{
    // Versuche Konversionsinformationen aus mehreren Quellen zu finden
    $conversionId = rex_session('ffmpeg_conversion_id', 'string', '');
    $video = rex_request('video', 'string', '');
    $inputFile = rex_session('ffmpeg_input_video_file', 'string', null);
    $outputFile = rex_session('ffmpeg_output_video_file', 'string', null);
    
    // Wenn keine Session-ID und kein Video-Parameter, versuche alle Status-Dateien zu durchsuchen
    if (empty($conversionId) && empty($video)) {
        $statusFiles = glob(rex_addon::get('ffmpeg')->getDataPath('status_*.json'));
        
        // Suche nach der neuesten aktiven Konvertierung
        $newestStatusFile = null;
        $newestTimestamp = 0;
        
        foreach ($statusFiles as $statusFile) {
            $statusData = json_decode(rex_file::get($statusFile), true);
            
            // Nur Dateien betrachten, die nicht "DONE" oder "ERROR" sind
            if ($statusData && isset($statusData['status']) && 
                $statusData['status'] !== self::STATUS_DONE && 
                $statusData['status'] !== self::STATUS_ERROR) {
                
                if ($statusData['timestamp'] > $newestTimestamp) {
                    $newestTimestamp = $statusData['timestamp'];
                    $newestStatusFile = $statusFile;
                    
                    if (isset($statusData['video'])) {
                        $video = $statusData['video'];
                    }
                    
                    if (isset($statusData['conversion_id'])) {
                        $conversionId = $statusData['conversion_id'];
                    }
                }
            }
        }
    }
    
    // Versuche Video aus Status-Datei zu bekommen
    if (empty($video) && !empty($conversionId)) {
        // Suche nach Status-Dateien mit dieser Konversions-ID
        $statusFiles = glob(rex_addon::get('ffmpeg')->getDataPath('status_*.json'));
        foreach ($statusFiles as $statusFile) {
            $statusData = json_decode(rex_file::get($statusFile), true);
            if (isset($statusData['conversion_id']) && $statusData['conversion_id'] === $conversionId) {
                $video = $statusData['video'] ?? '';
                break;
            }
        }
    }
    
    // Wenn wir video und/oder conversionId haben, können wir weitermachen
    if (empty($conversionId) && empty($video)) {
        return ['status' => 'error', 'log' => 'No active conversion found'];
    }

    // Log-Datei finden
    $log = null;
    if (!empty($conversionId)) {
        $log = rex_addon::get('ffmpeg')->getDataPath('log' . $conversionId . '.txt');
        if (!file_exists($log)) {
            $log = null;
        }
    }
    
    // Wenn keine Log-Datei gefunden, suche die neueste Log-Datei
    if (!$log) {
        $logFiles = glob(rex_addon::get('ffmpeg')->getDataPath('log*.txt'));
        if (!empty($logFiles)) {
            usort($logFiles, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $log = $logFiles[0];
        }
    }
    
    if (!$log || !file_exists($log)) {
        return ['status' => 'error', 'log' => 'Log file not found'];
    }

    // Get current log content
    $logContent = rex_file::get($log);
    
    // Prüfen, ob der Import bereits erfolgreich war
    if (strpos($logContent, 'was successfully added to rex_mediapool') !== false) {
        $this->setConversionStatus(self::STATUS_DONE, $video);
        return ['status' => 'success', 'log' => $logContent];
    }
    
    // Setze den Status auf Importing, wenn wir an diesem Punkt sind
    $this->setConversionStatus(self::STATUS_IMPORTING, $video);

    // With rex_media_service we don't need the legacy mediapool function import

    // Versuche die Input/Output-Dateien zu ermitteln
    if (empty($inputFile) || empty($outputFile)) {
        // Aus Video-Name ermitteln
        if (!empty($video)) {
            $inputFile = rex_path::media($video);
            
            // Output-Datei aus Konvention ableiten
            // Zuerst Endung aus Konfiguration ermitteln
            $fileExt = 'mp4'; // default extension
            $command = trim(rex_addon::get('ffmpeg')->getConfig('command'));
            preg_match('/OUTPUT\.([^\s]+)/', $command, $extMatch);
            if (!empty($extMatch[1])) {
                $fileExt = trim($extMatch[1]);
            }
            
            $outputFile = rex_path::media('web_' . pathinfo($video, PATHINFO_FILENAME) . '.' . $fileExt);
        } else {
            // Finde das konvertierte File anhand der Log-Datei
            preg_match('/Destination file (.*?) was/m', $logContent, $matches);
            if (!empty($matches[1])) {
                $outputFile = $matches[1];
                
                // Versuche Input-File aus Log zu extrahieren
                preg_match('/Konvertierung für "(.*?)" gestartet/', $logContent, $matches);
                if (!empty($matches[1])) {
                    $inputFile = rex_path::media($matches[1]);
                }
            }
        }
    }

    // Fortfahren, wenn wir eine Output-Datei haben
    if (!empty($outputFile) && file_exists($outputFile)) {
        // Holen der Dateigröße vor dem Löschen
        $originalSize = 0;
        if (!empty($inputFile) && file_exists($inputFile)) {
            $originalSize = filesize($inputFile);
        }
        
        // Holen der Dateigröße des konvertierten Videos
        $convertedSize = filesize($outputFile);
        
        // Berechnen der Einsparung
        $savings = 0;
        if ($originalSize > 0 && $convertedSize > 0) {
            $savings = round(100 - (($convertedSize / $originalSize) * 100));
            $this->appendLog($log, sprintf("Dateigröße reduziert um %d%% (von %s auf %s)", 
                $savings,
                rex_formatter::bytes($originalSize),
                rex_formatter::bytes($convertedSize)
            ) . PHP_EOL);
        }
        
        // Prüfen, ob die Datei bereits im Medienpool existiert
        $fileName = pathinfo($outputFile, PATHINFO_BASENAME);
        $mediaSql = rex_sql::factory();
        $existingMedia = $mediaSql->getArray(
            'SELECT id FROM ' . rex::getTable('media') . ' WHERE filename = ?',
            [$fileName]
        );
        
        if (!empty($existingMedia)) {
            // Datei existiert bereits im Medienpool, Prozess als erfolgreich markieren
            $this->appendLog($log, sprintf("Destination file %s was already in rex_mediapool", $outputFile) . PHP_EOL);
            $this->appendLog($log, 'Konvertierung abgeschlossen um ' . date('d.m.Y H:i:s') . PHP_EOL);
            $this->setConversionStatus(self::STATUS_DONE, $video);
            return ['status' => 'success', 'log' => rex_file::get($log)];
        }
        
        // Add converted file to media pool
        // Replace deprecated rex_mediapool_syncFile with rex_media_service::addMedia
        $syncData = [
            'file' => [
                'name' => pathinfo($outputFile, PATHINFO_BASENAME),
                'path' => $outputFile,
            ],
            'category_id' => 0,
            'title' => '',
        ];
        try {
            $syncResultResult = \rex_media_service::addMedia($syncData, false);
            $syncResult = !empty($syncResultResult['ok']);
        } catch (\rex_api_exception $e) {
            $this->appendLog($log, 'rex_media_service::addMedia failed: ' . $e->getMessage() . PHP_EOL);
            rex_logger::factory()->log('warning', 'rex_media_service::addMedia failed: {exception}', ['exception' => $e->getMessage()]);
            $syncResult = false;
        }
        rex_unset_session('ffmpeg_output_video_file');

        // If addMedia didn't throw, we proceed with metadata and consider it successful.
            $this->appendLog($log, sprintf("Destination file %s was successfully added to rex_mediapool", $outputFile) . PHP_EOL);
        rex_logger::factory()->log('info', 'Destination file {file} was successfully added to media pool', ['file' => $outputFile, 'conversion_id' => $conversionId]);
            
            // Metadaten vom Original übernehmen, falls vorhanden
            if (!empty($inputFile)) {
                $originalFileName = pathinfo($inputFile, PATHINFO_BASENAME);
                $outputFileName = pathinfo($outputFile, PATHINFO_BASENAME);
                
                // Verfügbare Felder in der Tabelle ermitteln
                $tableFields = [];
                $sql = rex_sql::factory();
                $tableDescription = $sql->getArray('DESCRIBE ' . rex::getTable('media'));
                foreach ($tableDescription as $field) {
                    $tableFields[] = $field['Field'];
                }
                
                // Mögliche Metadaten-Felder definieren
                $metaFieldMappings = [
                    'title' => ['title'],
                    'description' => ['description', 'art_description', 'med_description'],
                    'copyright' => ['copyright', 'art_copyright', 'med_copyright']
                ];
                
                // Originaldaten abfragen
                $selectFields = ['filename'];
                foreach ($metaFieldMappings as $fieldType => $possibleFields) {
                    foreach ($possibleFields as $field) {
                        if (in_array($field, $tableFields)) {
                            $selectFields[] = $field;
                        }
                    }
                }
                
                // SQL mit den verfügbaren Feldern erstellen
                $sql = rex_sql::factory();
                $originalData = $sql->getArray(
                    'SELECT ' . implode(', ', $selectFields) . ' FROM ' . rex::getTable('media') . ' WHERE filename = ?',
                    [$originalFileName]
                );
                
                if (!empty($originalData)) {
                    // Update-Query vorbereiten
                    $updateSql = rex_sql::factory();
                    $updateSql->setTable(rex::getTable('media'));
                    $updateSql->setWhere(['filename' => $outputFileName]);
                    
                    $updatedFields = [];
                    
                    // Für jeden Metadaten-Typ die verfügbaren Felder prüfen
                    foreach ($metaFieldMappings as $fieldType => $possibleFields) {
                        foreach ($possibleFields as $field) {
                            if (in_array($field, $tableFields) && isset($originalData[0][$field]) && !empty($originalData[0][$field])) {
                                $value = $originalData[0][$field];
                                
                                // Titel-Feld mit Suffix versehen
                                if ($fieldType === 'title') {
                                    $value .= ' (weboptimiert)';
                                }
                                
                                $updateSql->setValue($field, $value);
                                $updatedFields[] = $field;
                            }
                        }
                    }
                    
                    // Nur updaten, wenn es Felder zu aktualisieren gibt
                    if (!empty($updatedFields)) {
                        $updateSql->update();
                        $this->appendLog($log, "Metadaten (" . implode(', ', $updatedFields) . ") vom Original übernommen" . PHP_EOL);
                    }
                }
            }
            
            // Konvertierung abgeschlossen
            $this->appendLog($log, 'Konvertierung abgeschlossen um ' . date('d.m.Y H:i:s') . PHP_EOL);
            $this->setConversionStatus(self::STATUS_DONE, $video);
            
            // Delete source file if configured (only if import was successful)
            if (!empty($inputFile) && rex_addon::get('ffmpeg')->getConfig('delete') == 1) {
                try {
                    \rex_media_service::deleteMedia(pathinfo($inputFile, PATHINFO_BASENAME));
                } catch (\rex_api_exception $e) {
                    $this->appendLog($log, 'rex_media_service::deleteMedia failed: ' . $e->getMessage() . PHP_EOL);
                }
                rex_unset_session('ffmpeg_input_video_file');
                $this->appendLog($log, sprintf("Source file %s deletion was successful", $inputFile) . PHP_EOL);
            }
        
        return ['status' => 'success', 'log' => rex_file::get($log)];
    } else {
        // If phpstan claims the log always exists, silence for this runtime check
        // @phpstan-ignore-next-line
        if (is_string($log) && file_exists($log)) {
            $this->appendLog($log, 'Fehler: Ausgabe-Datei konnte nicht gefunden oder erstellt werden.' . PHP_EOL);
            
            if (!empty($inputFile)) {
                $this->appendLog($log, sprintf("Input-Datei: %s", $inputFile) . PHP_EOL);
            }
            
            if (!empty($outputFile)) {
                $this->appendLog($log, sprintf("Output-Datei: %s (existiert nicht)", $outputFile) . PHP_EOL);
            }
            
            $this->appendLog($log, 'Bitte führen Sie eine manuelle Synchronisierung im Medienpool durch.' . PHP_EOL);
            $this->appendLog($log, 'Konvertierung fehlgeschlagen um ' . date('d.m.Y H:i:s') . PHP_EOL);
        }
        
        $this->setConversionStatus(self::STATUS_ERROR, $video);
        
        // @phpstan-ignore-next-line
        return ['status' => 'error', 'log' => (is_string($log) && file_exists($log) ? rex_file::get($log) : 'Keine Log-Datei gefunden')];
    }
}
}
